<?php

declare(strict_types=1);

namespace Modules\Sales\Providers;

use Core\App\Database;
use Core\App\SettingsService;
use Core\Contracts\ClientSalesProfileProvider;
use Modules\Clients\Services\ClientProfileAccessService;
use Modules\Sales\Services\SalesTenantScope;

final class ClientSalesProfileProviderImpl implements ClientSalesProfileProvider
{
    public function __construct(
        private Database $db,
        private SettingsService $settings,
        private ClientProfileAccessService $profileAccess,
        private SalesTenantScope $salesTenantScope,
    ) {
    }

    public function getSummary(int $clientId): array
    {
        if ($this->profileAccess->resolveForProviderRead($clientId) === null) {
            return $this->emptySummary();
        }

        $iFrag = $this->salesTenantScope->invoiceClause('i');

        $billedParams = array_merge([$clientId], $iFrag['params']);
        $billedSql = "SELECT i.currency,
                    COALESCE(SUM(i.total_amount), 0) AS total_billed,
                    COUNT(*) AS invoice_count
             FROM invoices i
             WHERE i.deleted_at IS NULL AND i.client_id = ?";
        $billedSql .= $iFrag['sql'] . '
             GROUP BY i.currency
             ORDER BY i.currency ASC';
        $billedRows = $this->db->fetchAll($billedSql, $billedParams);

        $billedByCurrency = [];
        $invoiceCount = 0;
        foreach ($billedRows as $r) {
            $cnt = (int) ($r['invoice_count'] ?? 0);
            $invoiceCount += $cnt;
            $billedByCurrency[] = [
                'currency' => strtoupper(trim((string) ($r['currency'] ?? ''))),
                'total_billed' => round((float) ($r['total_billed'] ?? 0), 2),
                'invoice_count' => $cnt,
            ];
        }

        $billedMixed = $this->profileMixedCurrencyBuckets($billedByCurrency);
        $totalBilled = null;
        if (!$billedMixed) {
            $totalBilled = 0.0;
            foreach ($billedByCurrency as $b) {
                $totalBilled += (float) ($b['total_billed'] ?? 0);
            }
            $totalBilled = round($totalBilled, 2);
        }

        $paidParams = array_merge([$clientId], $iFrag['params']);
        $paidSql = "SELECT p.currency,
                    COALESCE(SUM(CASE WHEN p.entry_type = 'refund' THEN -p.amount ELSE p.amount END), 0) AS total_paid,
                    COUNT(p.id) AS payment_count
             FROM payments p
             INNER JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
             WHERE i.client_id = ?
               AND p.status = 'completed'";
        $paidSql .= $iFrag['sql'] . '
             GROUP BY p.currency
             ORDER BY p.currency ASC';
        $paidRows = $this->db->fetchAll($paidSql, $paidParams);

        $paidByCurrency = [];
        foreach ($paidRows as $r) {
            $paidByCurrency[] = [
                'currency' => strtoupper(trim((string) ($r['currency'] ?? ''))),
                'total_paid' => round((float) ($r['total_paid'] ?? 0), 2),
                'payment_count' => (int) ($r['payment_count'] ?? 0),
            ];
        }

        $paidMixed = $this->profileMixedCurrencyBuckets($paidByCurrency);
        $totalPaid = null;
        if (!$paidMixed) {
            $totalPaid = 0.0;
            foreach ($paidByCurrency as $b) {
                $totalPaid += (float) ($b['total_paid'] ?? 0);
            }
            $totalPaid = round($totalPaid, 2);
        }

        $payCountParams = array_merge([$clientId], $iFrag['params']);
        $paymentCountRow = $this->db->fetchOne(
            "SELECT COUNT(*) AS payment_count
             FROM payments p
             INNER JOIN invoices i ON i.id = p.invoice_id
             WHERE i.deleted_at IS NULL
               AND i.client_id = ?"
                . $iFrag['sql'],
            $payCountParams
        ) ?? [];

        $totalDue = $this->clientTotalDueSafe(
            $billedMixed,
            $paidMixed,
            $totalBilled,
            $totalPaid,
            $billedByCurrency,
            $paidByCurrency
        );

        return [
            'invoice_count' => $invoiceCount,
            'total_billed' => $totalBilled,
            'billed_mixed_currency' => $billedMixed,
            'billed_by_currency' => $billedByCurrency,
            'total_paid' => $totalPaid,
            'paid_mixed_currency' => $paidMixed,
            'paid_by_currency' => $paidByCurrency,
            'total_due' => $totalDue,
            'payment_count' => (int) ($paymentCountRow['payment_count'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'invoice_count' => 0,
            'total_billed' => 0.0,
            'billed_mixed_currency' => false,
            'billed_by_currency' => [],
            'total_paid' => 0.0,
            'paid_mixed_currency' => false,
            'paid_by_currency' => [],
            'total_due' => 0.0,
            'payment_count' => 0,
        ];
    }

    public function listRecentInvoices(int $clientId, int $limit = 10): array
    {
        if ($this->profileAccess->resolveForProviderRead($clientId) === null) {
            return [];
        }
        $limit = max(1, (int) $limit);
        $iFrag = $this->salesTenantScope->invoiceClause('i');
        $rows = $this->db->fetchAll(
            "SELECT i.id, i.invoice_number, i.total_amount, i.paid_amount, i.status, i.created_at
             FROM invoices i
             WHERE i.deleted_at IS NULL
               AND i.client_id = ?"
                . $iFrag['sql'] . '
             ORDER BY i.created_at DESC
             LIMIT ?',
            array_merge([$clientId], $iFrag['params'], [$limit])
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'invoice_number' => (string) ($r['invoice_number'] ?? ''),
            'total_amount' => (float) ($r['total_amount'] ?? 0),
            'paid_amount' => (float) ($r['paid_amount'] ?? 0),
            'status' => (string) ($r['status'] ?? 'draft'),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ], $rows);
    }

    public function listInvoicesForClientFiltered(
        int $clientId,
        string $invoiceNumberContains,
        ?string $createdDateFromYmd,
        ?string $createdDateToYmd,
        int $page,
        int $perPage
    ): array {
        if ($this->profileAccess->resolveForProviderRead($clientId) === null) {
            return ['rows' => [], 'total' => 0];
        }

        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $iFrag = $this->salesTenantScope->invoiceClause('i');
        $where = 'i.deleted_at IS NULL AND i.client_id = ?' . $iFrag['sql'];
        $params = array_merge([$clientId], $iFrag['params']);

        $needle = trim($invoiceNumberContains);
        if ($needle !== '') {
            $where .= ' AND i.invoice_number LIKE ?';
            $params[] = '%' . $this->escapeSqlLike($needle) . '%';
        }
        if ($createdDateFromYmd !== null && $createdDateFromYmd !== '') {
            $where .= ' AND i.created_at >= ?';
            $params[] = $createdDateFromYmd . ' 00:00:00';
        }
        if ($createdDateToYmd !== null && $createdDateToYmd !== '') {
            $where .= ' AND i.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $createdDateToYmd;
        }

        $countRow = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM invoices i WHERE ' . $where,
            $params
        ) ?? [];

        $total = (int) ($countRow['c'] ?? 0);
        if ($total === 0) {
            return ['rows' => [], 'total' => 0];
        }

        $offset = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll(
            'SELECT i.id, i.invoice_number, i.total_amount, i.paid_amount, i.status, i.created_at
             FROM invoices i
             WHERE ' . $where . '
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        $mapped = array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'invoice_number' => (string) ($r['invoice_number'] ?? ''),
            'total_amount' => (float) ($r['total_amount'] ?? 0),
            'paid_amount' => (float) ($r['paid_amount'] ?? 0),
            'status' => (string) ($r['status'] ?? 'draft'),
            'created_at' => (string) ($r['created_at'] ?? ''),
        ], $rows);

        return ['rows' => $mapped, 'total' => $total];
    }

    private function escapeSqlLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function listRecentPayments(int $clientId, int $limit = 10): array
    {
        if ($this->profileAccess->resolveForProviderRead($clientId) === null) {
            return [];
        }
        $limit = max(1, (int) $limit);
        $iFrag = $this->salesTenantScope->invoiceClause('i');
        $rows = $this->db->fetchAll(
            "SELECT p.id,
                    p.invoice_id,
                    p.payment_method,
                    p.amount,
                    p.currency AS payment_currency,
                    i.currency AS invoice_currency,
                    i.branch_id AS invoice_branch_id,
                    p.status,
                    p.paid_at,
                    p.created_at
             FROM payments p
             INNER JOIN invoices i ON i.id = p.invoice_id
             WHERE i.deleted_at IS NULL
               AND i.client_id = ?"
                . $iFrag['sql'] . '
             ORDER BY p.created_at DESC
             LIMIT ?',
            array_merge([$clientId], $iFrag['params'], [$limit])
        );

        $out = [];
        foreach ($rows as $r) {
            $branchId = isset($r['invoice_branch_id']) && $r['invoice_branch_id'] !== '' && $r['invoice_branch_id'] !== null
                ? (int) $r['invoice_branch_id']
                : null;
            $out[] = [
                'id' => (int) $r['id'],
                'invoice_id' => (int) $r['invoice_id'],
                'payment_method' => (string) ($r['payment_method'] ?? ''),
                'amount' => (float) ($r['amount'] ?? 0),
                'currency' => $this->resolvePaymentReadCurrency(
                    (string) ($r['payment_currency'] ?? ''),
                    isset($r['invoice_currency']) && $r['invoice_currency'] !== null ? (string) $r['invoice_currency'] : '',
                    $branchId
                ),
                'status' => (string) ($r['status'] ?? 'pending'),
                'paid_at' => $r['paid_at'] ?? null,
                'created_at' => (string) ($r['created_at'] ?? ''),
            ];
        }

        return $out;
    }

    public function listRecentProductInvoiceLines(int $clientId, int $limit = 15): array
    {
        if ($this->profileAccess->resolveForProviderRead($clientId) === null) {
            return [];
        }
        $limit = max(1, min(50, (int) $limit));
        $iFrag = $this->salesTenantScope->invoiceClause('i');
        $rows = $this->db->fetchAll(
            'SELECT ii.id AS invoice_item_id,
                    ii.invoice_id,
                    ii.source_id AS product_id,
                    ii.description,
                    ii.quantity,
                    ii.unit_price,
                    ii.line_total,
                    i.invoice_number,
                    i.status AS invoice_status,
                    i.currency AS invoice_currency,
                    i.branch_id AS invoice_branch_id,
                    COALESCE(i.issued_at, i.created_at) AS invoice_sort_at,
                    p.name AS catalog_product_name
             FROM invoice_items ii
             INNER JOIN invoices i ON i.id = ii.invoice_id AND i.deleted_at IS NULL
             LEFT JOIN products p ON p.id = ii.source_id AND p.deleted_at IS NULL
             WHERE i.client_id = ?
               AND ii.item_type = \'product\'
               AND ii.source_id IS NOT NULL
               AND ii.source_id > 0'
                . $iFrag['sql'] . '
             ORDER BY invoice_sort_at DESC, ii.id DESC
             LIMIT ?',
            array_merge([$clientId], $iFrag['params'], [$limit])
        );

        $out = [];
        foreach ($rows as $r) {
            $branchId = isset($r['invoice_branch_id']) && $r['invoice_branch_id'] !== '' && $r['invoice_branch_id'] !== null
                ? (int) $r['invoice_branch_id']
                : null;
            $pid = (int) ($r['product_id'] ?? 0);
            $catName = isset($r['catalog_product_name']) && trim((string) $r['catalog_product_name']) !== ''
                ? trim((string) $r['catalog_product_name'])
                : '';
            $desc = trim((string) ($r['description'] ?? ''));
            $productName = $catName !== '' ? $catName : ($desc !== '' ? $desc : ('Product #' . $pid));

            $out[] = [
                'invoice_item_id' => (int) ($r['invoice_item_id'] ?? 0),
                'invoice_id' => (int) ($r['invoice_id'] ?? 0),
                'product_id' => $pid,
                'product_name' => $productName,
                'description' => $desc,
                'quantity' => (float) ($r['quantity'] ?? 0),
                'unit_price' => round((float) ($r['unit_price'] ?? 0), 2),
                'line_total' => round((float) ($r['line_total'] ?? 0), 2),
                'invoice_number' => (string) ($r['invoice_number'] ?? ''),
                'invoice_status' => (string) ($r['invoice_status'] ?? 'draft'),
                'currency' => strtoupper(trim((string) ($r['invoice_currency'] ?? ''))) !== ''
                    ? strtoupper(trim((string) $r['invoice_currency']))
                    : $this->settings->getEffectiveCurrencyCode($branchId),
                'invoice_sort_at' => (string) ($r['invoice_sort_at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * True when more than one distinct currency bucket (after trim/upper on the `currency` field). Empty string is its own bucket.
     *
     * @param list<array{currency: string}> $rows
     */
    private function profileMixedCurrencyBuckets(array $rows): bool
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys[(string) ($row['currency'] ?? '')] = true;
        }

        return count($keys) > 1;
    }

    /**
     * Due only when both billed and paid sides are single-currency-safe and currency keys are compatible (or one side has no bucket).
     *
     * @param list<array{currency: string}> $billedByCurrency
     * @param list<array{currency: string}> $paidByCurrency
     */
    private function clientTotalDueSafe(
        bool $billedMixed,
        bool $paidMixed,
        ?float $totalBilled,
        ?float $totalPaid,
        array $billedByCurrency,
        array $paidByCurrency
    ): ?float {
        if ($billedMixed || $paidMixed) {
            return null;
        }
        $tb = $totalBilled ?? 0.0;
        $tp = $totalPaid ?? 0.0;
        $bKey = $this->profileSingleBucketCurrencyKey($billedByCurrency);
        $pKey = $this->profileSingleBucketCurrencyKey($paidByCurrency);
        if ($bKey !== null && $pKey !== null && $bKey !== $pKey) {
            return null;
        }

        return max(0.0, round($tb - $tp, 2));
    }

    /**
     * @param list<array{currency: string}> $rows
     */
    private function profileSingleBucketCurrencyKey(array $rows): ?string
    {
        if ($rows === []) {
            return null;
        }

        return (string) ($rows[0]['currency'] ?? '');
    }

    /**
     * Read truth: persisted payment currency first; if empty, invoice.currency; else SettingsService (same stack as writes).
     */
    private function resolvePaymentReadCurrency(string $paymentCurrency, string $invoiceCurrency, ?int $invoiceBranchId): string
    {
        $pc = strtoupper(trim($paymentCurrency));
        if ($pc !== '') {
            return $pc;
        }
        $ic = strtoupper(trim($invoiceCurrency));
        if ($ic !== '') {
            return $ic;
        }

        return $this->settings->getEffectiveCurrencyCode($invoiceBranchId);
    }
}
