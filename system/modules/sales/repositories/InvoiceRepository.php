<?php

declare(strict_types=1);

namespace Modules\Sales\Repositories;

use Core\App\Database;
use PDOException;
use Modules\Clients\Support\PublicContactNormalizer;
use Modules\Sales\Services\SalesTenantScope;

final class InvoiceRepository
{
    /** Logical sequence name; rows are scoped by `organization_id` (composite primary key). */
    private const SEQUENCE_KEY_INVOICE = 'invoice';

    public function __construct(
        private Database $db,
        private SalesTenantScope $tenantScope,
    ) {
    }

    /**
     * Tenant invoice-plane read: explicit {@see SalesTenantScope::requireBranchDerivedOrganizationIdForInvoicePlane()} before SQL,
     * then {@see SalesTenantScope::invoiceClause()} (same entry contract as {@see list()}, {@see count()}, {@see allocateNextInvoiceNumber()}).
     *
     * @throws \Core\Errors\AccessDeniedException when tenant invoice-plane context is not branch-derived
     */
    public function find(int $id, bool $withTrashed = false): ?array
    {
        $this->tenantScope->requireBranchDerivedOrganizationIdForInvoicePlane();

        $sql = 'SELECT i.*, c.first_name as client_first_name, c.last_name as client_last_name
                FROM invoices i
                LEFT JOIN clients c ON i.client_id = c.id
                WHERE i.id = ?';
        $params = [$id];
        if (!$withTrashed) {
            $sql .= ' AND i.deleted_at IS NULL';
        }
        $scope = $this->tenantScope->invoiceClause('i');
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Anonymous {@code /api/public/commerce/*} only: load invoice when {@code $branchId} matches {@code i.branch_id} and the branch
     * references a live organization row. Does **not** use {@see SalesTenantScope::invoiceClause} — avoids requiring
     * {@see OrganizationContext::MODE_BRANCH_DERIVED} on token-driven requests. Caller must obtain {@code $branchId} from a
     * trusted bootstrap path (e.g. public-commerce purchase row or initiate payload).
     */
    public function findForPublicCommerceCorrelatedBranch(int $invoiceId, int $branchId): ?array
    {
        if ($invoiceId <= 0 || $branchId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT i.*, c.first_name as client_first_name, c.last_name as client_last_name
             FROM invoices i
             LEFT JOIN clients c ON i.client_id = c.id
             WHERE i.id = ?
               AND i.deleted_at IS NULL
               AND i.branch_id = ?
               AND EXISTS (
                   SELECT 1 FROM branches b
                   INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                   WHERE b.id = i.branch_id AND b.deleted_at IS NULL
               )',
            [$invoiceId, $branchId]
        ) ?: null;
    }

    /**
     * Tenant invoice-plane lock read: explicit {@see SalesTenantScope::requireBranchDerivedOrganizationIdForInvoicePlane()} before
     * {@code FOR UPDATE}, then {@see SalesTenantScope::invoiceClause()} (same entry contract as {@see find()} / {@see list()}).
     *
     * @throws \Core\Errors\AccessDeniedException when tenant invoice-plane context is not branch-derived
     */
    public function findForUpdate(int $id): ?array
    {
        $this->tenantScope->requireBranchDerivedOrganizationIdForInvoicePlane();

        $sql = 'SELECT i.* FROM invoices i WHERE i.id = ? AND i.deleted_at IS NULL';
        $params = [$id];
        $scope = $this->tenantScope->invoiceClause('i');
        $sql .= $scope['sql'] . ' FOR UPDATE';
        $params = array_merge($params, $scope['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Tenant invoice index rows: same explicit branch-derived entry and {@code clients} join policy as {@see count()}
     * ({@see requireBranchDerivedOrganizationIdForInvoicePlane}, {@see invoiceListRequiresClientsJoinForFilters}).
     * When name/phone filters are absent, client display columns use correlated subselects so {@code client_first_name} /
     * {@code client_last_name} stay populated without a {@code JOIN} (parity with {@see count()} eliding the join).
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $this->tenantScope->requireBranchDerivedOrganizationIdForInvoicePlane();

        $limit = (int) $limit;
        $offset = (int) $offset;
        $joinClients = $this->invoiceListRequiresClientsJoinForFilters($filters);
        if ($joinClients) {
            $sql = 'SELECT i.*, c.first_name as client_first_name, c.last_name as client_last_name
                    FROM invoices i
                    LEFT JOIN clients c ON c.id = i.client_id
                    WHERE i.deleted_at IS NULL';
        } else {
            $sql = 'SELECT i.*,
                    (SELECT cj.first_name FROM clients cj WHERE cj.id = i.client_id LIMIT 1) AS client_first_name,
                    (SELECT cj.last_name FROM clients cj WHERE cj.id = i.client_id LIMIT 1) AS client_last_name
                    FROM invoices i
                    WHERE i.deleted_at IS NULL';
        }
        $params = [];
        $scope = $this->tenantScope->invoiceClause('i');
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
        $this->appendListFilters($sql, $params, $filters);
        $sql .= ' ORDER BY i.created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Tenant invoice index total: explicit branch-derived entry + {@see invoiceListRequiresClientsJoinForFilters} (same as {@see list()}).
     * {@see requireBranchDerivedOrganizationIdForInvoicePlane()} before SQL; {@code clients} joined only when {@see appendListFilters} uses {@code c.*}.
     */
    public function count(array $filters = []): int
    {
        $this->tenantScope->requireBranchDerivedOrganizationIdForInvoicePlane();

        $joinClients = $this->invoiceListRequiresClientsJoinForFilters($filters);
        $sql = $joinClients
            ? 'SELECT COUNT(*) AS c
                FROM invoices i
                LEFT JOIN clients c ON c.id = i.client_id
                WHERE i.deleted_at IS NULL'
            : 'SELECT COUNT(*) AS c
                FROM invoices i
                WHERE i.deleted_at IS NULL';
        $params = [];
        $scope = $this->tenantScope->invoiceClause('i');
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
        $this->appendListFilters($sql, $params, $filters);
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * True when list/count filters use {@code clients} alias columns in SQL (must match {@see appendListFilters}).
     *
     * @param array<string, mixed> $filters
     */
    private function invoiceListRequiresClientsJoinForFilters(array $filters): bool
    {
        return !empty($filters['client_name']) || !empty($filters['client_phone']);
    }

    /**
     * @param list<mixed> $params
     * @param array<string, mixed> $filters
     */
    private function appendListFilters(string &$sql, array &$params, array $filters): void
    {
        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND i.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND i.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['invoice_number'])) {
            $invIn = (string) $filters['invoice_number'];
            $likeNeedle = '%' . $this->escapeLike($invIn) . '%';
            $invTrim = trim($invIn);
            // Canonical numbers: equality OR LIKE so uk_invoices_number can short-circuit on exact legacy or per-org forms.
            $canonicalExact = $invTrim !== ''
                && (preg_match('/^INV-[0-9]+$/', $invTrim) === 1
                    || preg_match('/^ORG[0-9]+-INV-[0-9]+$/', $invTrim) === 1);
            if ($canonicalExact) {
                $sql .= ' AND (i.invoice_number = ? OR i.invoice_number LIKE ? ESCAPE \'\\\\\')';
                $params[] = $invTrim;
                $params[] = $likeNeedle;
            } else {
                $sql .= ' AND i.invoice_number LIKE ? ESCAPE \'\\\\\'';
                $params[] = $likeNeedle;
            }
        }
        if (!empty($filters['client_name'])) {
            $q = '%' . $this->escapeLike((string) $filters['client_name']) . '%';
            $sql .= ' AND (c.first_name LIKE ? ESCAPE \'\\\\\' OR c.last_name LIKE ? ESCAPE \'\\\\\')';
            $params[] = $q;
            $params[] = $q;
        }
        if (!empty($filters['client_phone'])) {
            $phoneIn = (string) $filters['client_phone'];
            $likeNeedle = '%' . $this->escapeLike($phoneIn) . '%';
            $phoneTrim = trim($phoneIn);
            $digits = PublicContactNormalizer::normalizePhoneDigitsForMatch($phoneTrim !== '' ? $phoneTrim : null);
            if ($digits !== null) {
                $pe = PublicContactNormalizer::sqlExprNormalizedPhoneDigits('c.phone');
                $sql .= ' AND ((' . $pe . ' = ?) OR (c.phone LIKE ? ESCAPE \'\\\\\'))';
                $params[] = $digits;
                $params[] = $likeNeedle;
            } else {
                $sql .= ' AND c.phone LIKE ? ESCAPE \'\\\\\'';
                $params[] = $likeNeedle;
            }
        }
        if (!empty($filters['issued_from'])) {
            // Sargable OR-split: same calendar-day rule as legacy DATE on COALESCE(issued_at, created_at) >= :from (inclusive).
            $sql .= ' AND (
                (i.issued_at IS NOT NULL AND i.issued_at >= CONCAT(?, \' 00:00:00\'))
                OR (i.issued_at IS NULL AND i.created_at >= CONCAT(?, \' 00:00:00\'))
            )';
            $d = (string) $filters['issued_from'];
            $params[] = $d;
            $params[] = $d;
        }
        if (!empty($filters['issued_to'])) {
            // Same calendar-day upper bound as legacy DATE on COALESCE <= :to (inclusive); implemented as < start of next day.
            $sql .= ' AND (
                (i.issued_at IS NOT NULL AND i.issued_at < DATE_ADD(CONCAT(?, \' 00:00:00\'), INTERVAL 1 DAY))
                OR (i.issued_at IS NULL AND i.created_at < DATE_ADD(CONCAT(?, \' 00:00:00\'), INTERVAL 1 DAY))
            )';
            $d = (string) $filters['issued_to'];
            $params[] = $d;
            $params[] = $d;
        }
        if (!empty($filters['client_id'])) {
            $sql .= ' AND i.client_id = ?';
            $params[] = $filters['client_id'];
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function create(array $data): int
    {
        $this->db->insert('invoices', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $norm = $this->normalize($data);
        if (empty($norm)) return;
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $sql = 'UPDATE invoices i SET ' . implode(', ', $cols) . ' WHERE i.id = ?';
        $vals[] = $id;
        $scope = $this->tenantScope->invoiceClause('i');
        $sql .= $scope['sql'];
        $vals = array_merge($vals, $scope['params']);
        $this->db->query($sql, $vals);
    }

    public function softDelete(int $id): void
    {
        $sql = 'UPDATE invoices i SET deleted_at = NOW() WHERE i.id = ?';
        $params = [$id];
        $scope = $this->tenantScope->invoiceClause('i');
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
        $this->db->query($sql, $params);
    }

    public function getNextInvoiceNumber(): string
    {
        return $this->allocateNextInvoiceNumber();
    }

    /**
     * Tenant-owned, branch-derived only: locks/updates {@code invoice_number_sequences} for {@see OrganizationContext::MODE_BRANCH_DERIVED}
     * org resolution — same explicit branch-derived basis as {@see find()} / {@see findForUpdate()} / {@see list()} / {@see count()};
     * {@see update()} still scopes via {@see SalesTenantScope::invoiceClause()} only. No GlobalOps /
     * legacy {@code organization_id = 0} depot path; no allocation when org is membership-only or single-org fallback without branch derivation.
     *
     * @throws \Core\Errors\AccessDeniedException when tenant invoice-plane context is not branch-derived
     */
    public function allocateNextInvoiceNumber(): string
    {
        $organizationId = $this->tenantScope->requireBranchDerivedOrganizationIdForInvoicePlane();

        $key = self::SEQUENCE_KEY_INVOICE;
        $sequence = $this->db->fetchOne(
            'SELECT next_number
             FROM invoice_number_sequences
             WHERE organization_id = ? AND sequence_key = ?
             FOR UPDATE',
            [$organizationId, $key]
        );
        if (!$sequence) {
            $seed = $this->db->fetchOne(
                "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(i.invoice_number, '-', -1) AS UNSIGNED)), 0) + 1 AS next_number
                 FROM invoices i
                 INNER JOIN branches b ON b.id = i.branch_id
                 WHERE b.organization_id = ?
                   AND i.deleted_at IS NULL
                   AND (
                       i.invoice_number REGEXP '^INV-[0-9]+$'
                       OR i.invoice_number REGEXP CONCAT('^ORG', ?, '-INV-[0-9]+$')
                   )",
                [$organizationId, (string) $organizationId]
            );
            $start = max(1, (int) ($seed['next_number'] ?? 1));
            try {
                $this->db->insert('invoice_number_sequences', [
                    'organization_id' => $organizationId,
                    'sequence_key' => $key,
                    'next_number' => $start,
                ]);
                $sequence = ['next_number' => $start];
            } catch (PDOException $e) {
                if (!$this->isMysqlDuplicateKeyException($e)) {
                    throw $e;
                }
                $sequence = $this->db->fetchOne(
                    'SELECT next_number
                     FROM invoice_number_sequences
                     WHERE organization_id = ? AND sequence_key = ?
                     FOR UPDATE',
                    [$organizationId, $key]
                );
                if (!$sequence) {
                    throw new \RuntimeException('Invoice sequence row missing after concurrent create.');
                }
            }
        }

        $next = max(1, (int) ($sequence['next_number'] ?? 1));
        $this->db->query(
            'UPDATE invoice_number_sequences
             SET next_number = ?
             WHERE organization_id = ? AND sequence_key = ?',
            [$next + 1, $organizationId, $key]
        );

        return 'ORG' . $organizationId . '-INV-' . str_pad((string) $next, 8, '0', STR_PAD_LEFT);
    }

    private function isMysqlDuplicateKeyException(PDOException $e): bool
    {
        if ($e->getCode() === '23000') {
            return true;
        }
        $info = $e->errorInfo ?? null;

        return is_array($info) && isset($info[1]) && (int) $info[1] === 1062;
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'invoice_number', 'client_id', 'appointment_id', 'branch_id', 'currency', 'status',
            'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount', 'paid_amount',
            'notes', 'issued_at', 'created_by', 'updated_by',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
