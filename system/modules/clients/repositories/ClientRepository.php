<?php

declare(strict_types=1);

namespace Modules\Clients\Repositories;

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Clients\Support\ClientNormalizedSearchSchemaReadiness;
use Modules\Clients\Support\ClientSearchNormalization;
use Modules\Clients\Support\PublicContactNormalizer;

/**
 * `clients` table access.
 *
 * Tenant-protected operations are org-scoped through {@see OrganizationRepositoryScope} and fail closed when tenant
 * context is unresolved / not branch-derived. Anonymous public resolution ({@see lockActiveByEmailBranch()},
 * {@see lockActiveByPhoneDigitsBranch()}, {@see findActiveClientIdByPhoneDigitsExcluding()}) share the same contract:
 * positive {@code branch_id} pin + {@see OrganizationRepositoryScope::publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause()}
 * (no session org).
 *
 * ID loads and matching writes ({@see find()}, {@see findForUpdate()}, live-on-branch helpers, {@see findLiveReadableForProfile()},
 * {@see update()}, {@see softDelete()}, {@see restore()}) use {@see OrganizationRepositoryScope::clientProfileOrgMembershipExistsClause()}
 * so nullable {@code clients.branch_id} rows remain tenant-safe when org-anchored.
 * Email + phone public locks pin a **positive** branch id and append
 * {@see OrganizationRepositoryScope::publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause()} (live branch + org; no session org).
 * Staff {@see list()} / {@see count()} use {@see ClientNormalizedSearchSchemaReadiness}: when migration 119 columns are missing, list search
 * avoids {@code email_lc} / {@code phone_*_digits} and uses legacy LIKE / {@code LOWER(TRIM(email))} instead. Duplicate APIs return empty
 * / zero in that state. {@see findDuplicates()}, {@see searchDuplicates()}, and {@see countSearchDuplicates()} use the org clause plus, when a
 * concrete branch applies ({@see BranchContext} or explicit {@code branch_id} filter), the same row predicate as {@see findLiveReadableForProfile()}:
 * {@code (c.branch_id IS NULL OR c.branch_id = ?)}. Unset/HQ branch leaves org-wide listing only. Those surfaces require
 * {@code c.merged_into_client_id IS NULL} (canonical live rows only; merge secondaries are additionally soft-deleted).
 *
 * @see system/docs/PROTECTED-DATA-PLANE-SCOPE-CONTRACT-OPS.md
 * @see system/docs/CLIENTS-TENANT-PII-BOUNDARY-REPAIR-OPS.md
 * @see system/docs/CLIENT-BACKEND-CONTRACT-FREEZE.md (read models, branch envelope, visibility)
 */
final class ClientRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
        private BranchContext $branchContext,
        private ClientNormalizedSearchSchemaReadiness $normalizedSearchSchema,
    ) {
    }

    public function isNormalizedSearchSchemaReady(): bool
    {
        return $this->normalizedSearchSchema->isReady();
    }

    public function find(int $id, bool $withTrashed = false): ?array
    {
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $sql = 'SELECT * FROM clients c WHERE c.id = ?';
        if (!$withTrashed) {
            $sql .= ' AND c.deleted_at IS NULL';
        }
        $sql .= $frag['sql'];
        $params = array_merge([$id], $frag['params']);

        return $this->db->fetchOne($sql, $params);
    }

    public function findForUpdate(int $id): ?array
    {
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $sql = 'SELECT * FROM clients c WHERE c.id = ? AND c.deleted_at IS NULL' . $frag['sql'] . ' FOR UPDATE';
        $params = array_merge([$id], $frag['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Write-boundary: live client (not merged/deleted) on the operation branch, tenant org-scoped, row-locked.
     *
     * @return array<string, mixed>|null
     */
    public function findLiveForUpdateOnBranch(int $clientId, int $operationBranchId): ?array
    {
        if ($clientId <= 0 || $operationBranchId <= 0) {
            return null;
        }
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $sql = 'SELECT * FROM clients c
             WHERE c.id = ?
               AND c.deleted_at IS NULL
               AND c.merged_into_client_id IS NULL
               AND c.branch_id = ?' . $frag['sql'] . '
             FOR UPDATE';
        $params = array_merge([$clientId, $operationBranchId], $frag['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Read-only: live client on the operation branch, tenant org-scoped (no row lock).
     * Use for provider/profile reads after {@see \Core\Branch\BranchContext} is set — not for writes.
     *
     * @return array<string, mixed>|null
     */
    public function findLiveReadOnBranch(int $clientId, int $operationBranchId): ?array
    {
        if ($clientId <= 0 || $operationBranchId <= 0) {
            return null;
        }
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $sql = 'SELECT * FROM clients c
             WHERE c.id = ?
               AND c.deleted_at IS NULL
               AND c.merged_into_client_id IS NULL
               AND c.branch_id = ?' . $frag['sql'];
        $params = array_merge([$clientId, $operationBranchId], $frag['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Read-only: live client row for staff profile satellite providers, aligned with {@see \Modules\Clients\Controllers\ClientController::ensureBranchAccess()}
     * + {@see \Core\Branch\BranchContext::assertBranchMatchOrGlobalEntity()} (not merged; not soft-deleted; tenant org-scoped).
     *
     * - HQ / unset branch ({@code $currentBranchId} null or ≤0): same visibility envelope as {@see find()} for the resolved org (no extra branch predicate).
     * - Branch context ({@code $currentBranchId} &gt; 0): same-branch clients or branchless ({@code c.branch_id IS NULL}) rows that pass
     *   {@see OrganizationRepositoryScope::clientProfileOrgMembershipExistsClause()}.
     *
     * @return array<string, mixed>|null
     */
    public function findLiveReadableForProfile(int $clientId, ?int $currentBranchId): ?array
    {
        if ($clientId <= 0) {
            return null;
        }
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $sql = 'SELECT * FROM clients c
             WHERE c.id = ?
               AND c.deleted_at IS NULL
               AND c.merged_into_client_id IS NULL';
        $params = [$clientId];
        if ($currentBranchId !== null && $currentBranchId > 0) {
            $sql .= ' AND (c.branch_id IS NULL OR c.branch_id = ?)';
            $params[] = $currentBranchId;
        }
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);

        return $this->db->fetchOne($sql, $params);
    }

    /**
     * Anonymous public resolution: exact normalized email, same branch (NULL branch matches NULL only).
     * **Fail-closed:** non-positive {@code $branchId} returns no row. Rows must reference a **live** branch in a **live**
     * organization ({@see OrganizationRepositoryScope::publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause()}) —
     * not branch-derived session context.
     * Use inside an open transaction; {@code FOR UPDATE} serializes concurrent flows for the same email key.
     *
     * @param string $emailNorm lowercase trimmed syntactically valid email (see {@see \Modules\Clients\Support\PublicContactNormalizer::normalizeEmail()})
     * @return array<string, mixed>|null
     */
    public function lockActiveByEmailBranch(int $branchId, string $emailNorm): ?array
    {
        if ($branchId <= 0 || $emailNorm === '') {
            return null;
        }

        $liveBranchFrag = $this->orgScope->publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause('c');

        if ($this->normalizedSearchSchema->isReady()) {
            return $this->db->fetchOne(
                'SELECT * FROM clients c
                 WHERE c.deleted_at IS NULL
                   AND c.merged_into_client_id IS NULL
                   AND c.branch_id <=> ?
                   AND c.email_lc = ?'
                . $liveBranchFrag['sql'] . '
                 ORDER BY c.id ASC
                 LIMIT 1
                 FOR UPDATE',
                array_merge([$branchId, $emailNorm], $liveBranchFrag['params'])
            ) ?: null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM clients c
             WHERE c.deleted_at IS NULL
               AND c.merged_into_client_id IS NULL
               AND c.branch_id <=> ?
               AND LOWER(TRIM(c.email)) = ?'
            . $liveBranchFrag['sql'] . '
             ORDER BY c.id ASC
             LIMIT 1
             FOR UPDATE',
            array_merge([$branchId, $emailNorm], $liveBranchFrag['params'])
        ) ?: null;
    }

    /**
     * Digit-only phone match against stored {@code clients.phone_digits} (legacy {@code clients.phone} only; unchanged vs pre-column behavior).
     * **Fail-closed:** non-positive {@code $branchId} returns no rows. Same anonymous-public contract as {@see lockActiveByEmailBranch()}.
     * Returns at most two rows (ambiguous detection). Caller must only treat a single row as a unique match.
     *
     * @return list<array<string, mixed>>
     */
    public function lockActiveByPhoneDigitsBranch(int $branchId, string $phoneDigits): array
    {
        if ($branchId <= 0 || $phoneDigits === '' || strlen($phoneDigits) < 7) {
            return [];
        }

        $liveBranchFrag = $this->orgScope->publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause('c');

        if ($this->normalizedSearchSchema->isReady()) {
            return $this->db->fetchAll(
                'SELECT * FROM clients c
                 WHERE c.deleted_at IS NULL
                   AND c.merged_into_client_id IS NULL
                   AND c.branch_id <=> ?
                   AND c.phone_digits IS NOT NULL
                   AND LENGTH(c.phone_digits) >= 7
                   AND c.phone_digits = ?'
                . $liveBranchFrag['sql'] . '
                 ORDER BY c.id ASC
                 LIMIT 2
                 FOR UPDATE',
                array_merge([$branchId, $phoneDigits], $liveBranchFrag['params'])
            );
        }

        $like = '%' . $phoneDigits . '%';

        return $this->db->fetchAll(
            'SELECT * FROM clients c
             WHERE c.deleted_at IS NULL
               AND c.merged_into_client_id IS NULL
               AND c.branch_id <=> ?
               AND (
                 c.phone LIKE ? OR c.phone_home LIKE ? OR c.phone_mobile LIKE ? OR c.phone_work LIKE ?
               )'
            . $liveBranchFrag['sql'] . '
             ORDER BY c.id ASC
             LIMIT 2
             FOR UPDATE',
            array_merge([$branchId, $like, $like, $like, $like], $liveBranchFrag['params'])
        );
    }

    /**
     * Read-only: another active client in-branch shares the same digit-normalized phone key (excluding {@code $excludeClientId}).
     * **Fail-closed:** non-positive {@code $branchId} returns {@code null}. Same anonymous-public contract as {@see lockActiveByEmailBranch()}.
     */
    public function findActiveClientIdByPhoneDigitsExcluding(int $branchId, string $phoneDigits, int $excludeClientId): ?int
    {
        if ($branchId <= 0 || $phoneDigits === '' || strlen($phoneDigits) < 7 || $excludeClientId <= 0) {
            return null;
        }

        $liveBranchFrag = $this->orgScope->publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause('c');

        if ($this->normalizedSearchSchema->isReady()) {
            $row = $this->db->fetchOne(
                'SELECT c.id FROM clients c
                 WHERE c.deleted_at IS NULL
                   AND c.merged_into_client_id IS NULL
                   AND c.branch_id <=> ?
                   AND c.id != ?
                   AND c.phone_digits IS NOT NULL
                   AND LENGTH(c.phone_digits) >= 7
                   AND c.phone_digits = ?'
                . $liveBranchFrag['sql'] . '
                 ORDER BY c.id ASC
                 LIMIT 1',
                array_merge([$branchId, $excludeClientId, $phoneDigits], $liveBranchFrag['params'])
            );
        } else {
            $like = '%' . $phoneDigits . '%';
            $row = $this->db->fetchOne(
                'SELECT c.id FROM clients c
                 WHERE c.deleted_at IS NULL
                   AND c.merged_into_client_id IS NULL
                   AND c.branch_id <=> ?
                   AND c.id != ?
                   AND (
                     c.phone LIKE ? OR c.phone_home LIKE ? OR c.phone_mobile LIKE ? OR c.phone_work LIKE ?
                   )'
                . $liveBranchFrag['sql'] . '
                 ORDER BY c.id ASC
                 LIMIT 1',
                array_merge([$branchId, $excludeClientId, $like, $like, $like, $like], $liveBranchFrag['params'])
            );
        }

        if ($row === null) {
            return null;
        }

        $id = (int) ($row['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $limit = (int) $limit;
        $offset = (int) $offset;
        $sql = 'SELECT * FROM clients c WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL';
        $params = [];
        $this->applyClientListFilters($sql, $params, $filters);
        $this->appendStaffClientRowBranchEnvelope($sql, $params, $filters);
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        $sql .= ' ORDER BY c.last_name, c.first_name LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function count(array $filters = []): int
    {
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $sql = 'SELECT COUNT(*) AS c FROM clients c WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL';
        $params = [];
        $this->applyClientListFilters($sql, $params, $filters);
        $this->appendStaffClientRowBranchEnvelope($sql, $params, $filters);
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Same branch row predicate as {@see findLiveReadableForProfile()} when a concrete branch applies.
     * Explicit {@code filters['branch_id']} wins over {@see BranchContext}; neither applies in HQ / unset branch.
     *
     * @param array<string, mixed> $filters
     */
    private function appendStaffClientRowBranchEnvelope(string &$sql, array &$params, array $filters): void
    {
        $bid = null;
        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $b = (int) $filters['branch_id'];
            if ($b > 0) {
                $bid = $b;
            }
        }
        if ($bid === null) {
            $cb = $this->branchContext->getCurrentBranchId();
            if ($cb !== null && $cb > 0) {
                $bid = $cb;
            }
        }
        if ($bid !== null) {
            $sql .= ' AND (c.branch_id IS NULL OR c.branch_id = ?)';
            $params[] = $bid;
        }
    }

    /**
     * Staff list search: indexed exact paths only for clearly-email or clearly-phone terms; broad LIKE otherwise.
     * Does not OR indexed predicates with the multi-column LIKE bundle (avoids negating index selectivity).
     *
     * @param array<string, mixed> $filters
     */
    private function applyClientListFilters(string &$sql, array &$params, array $filters): void
    {
        if (empty($filters['search'])) {
            return;
        }
        $rawSearch = (string) $filters['search'];
        $trimmed = trim($rawSearch);
        if ($trimmed === '') {
            return;
        }

        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL) !== false) {
            if ($this->normalizedSearchSchema->isReady()) {
                $sql .= ' AND c.email_lc = ?';
                $params[] = strtolower($trimmed);
            } else {
                $sql .= ' AND LOWER(TRIM(c.email)) = ?';
                $params[] = strtolower($trimmed);
            }

            return;
        }

        $digits = PublicContactNormalizer::normalizePhoneDigitsForMatch($trimmed);
        if ($digits !== null) {
            if ($this->normalizedSearchSchema->isReady()) {
                $sql .= ' AND (c.phone_digits = ? OR c.phone_home_digits = ? OR c.phone_mobile_digits = ? OR c.phone_work_digits = ?)';
                array_push($params, $digits, $digits, $digits, $digits);
            } else {
                $like = '%' . $digits . '%';
                $sql .= ' AND (c.phone LIKE ? OR c.phone_home LIKE ? OR c.phone_mobile LIKE ? OR c.phone_work LIKE ?)';
                array_push($params, $like, $like, $like, $like);
            }

            return;
        }

        $q = '%' . $rawSearch . '%';
        $likeSql = '(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.phone_home LIKE ? OR c.phone_mobile LIKE ? OR c.phone_work LIKE ?)';
        $likeParams = [$q, $q, $q, $q, $q, $q, $q];
        $sql .= ' AND ' . $likeSql;
        $params = array_merge($params, $likeParams);
    }

    public function create(array $data): int
    {
        $this->db->insert('clients', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    /**
     * Updates by primary key only; does not apply {@see OrganizationRepositoryScope} (same pattern as {@see self::softDelete()} / {@see self::restore()}).
     */
    public function update(int $id, array $data): void
    {
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $cols = [];
        $vals = [];
        foreach ($this->normalize($data) as $k => $v) {
            $cols[] = "{$k} = ?";
            $vals[] = $v;
        }
        if ($cols === []) {
            return;
        }
        $vals[] = $id;
        $vals = array_merge($vals, $frag['params']);
        $this->db->query('UPDATE clients c SET ' . implode(', ', $cols) . ' WHERE c.id = ?' . $frag['sql'], $vals);
    }

    public function softDelete(int $id): void
    {
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $params = array_merge([$id], $frag['params']);
        $this->db->query('UPDATE clients c SET c.deleted_at = NOW() WHERE c.id = ?' . $frag['sql'], $params);
    }

    public function restore(int $id): void
    {
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $params = array_merge([$id], $frag['params']);
        $this->db->query('UPDATE clients c SET c.deleted_at = NULL WHERE c.id = ?' . $frag['sql'], $params);
    }

    /** Prepare for duplicate merge: returns candidates by email/phone exact. */
    public function findDuplicates(int $excludeId, array $criteria): array
    {
        if (!$this->normalizedSearchSchema->isReady()) {
            return [];
        }
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $conditions = [];
        $condParams = [];
        if (!empty($criteria['email'])) {
            $elc = ClientSearchNormalization::emailLcForStorage((string) $criteria['email']);
            if ($elc !== null) {
                $conditions[] = 'c.email_lc = ?';
                $condParams[] = $elc;
            }
        }
        if (!empty($criteria['phone'])) {
            $digits = PublicContactNormalizer::normalizePhoneDigitsForMatch((string) $criteria['phone']);
            if ($digits !== null) {
                $conditions[] = '(c.phone_digits = ? OR c.phone_home_digits = ? OR c.phone_mobile_digits = ? OR c.phone_work_digits = ?)';
                array_push($condParams, $digits, $digits, $digits, $digits);
            }
        }
        if (empty($conditions)) {
            return [];
        }
        $sql = 'SELECT c.* FROM clients c WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL AND c.id != ?';
        $params = [$excludeId];
        $this->appendStaffClientRowBranchEnvelope($sql, $params, []);
        $sql .= ' AND (' . implode(' OR ', $conditions) . ')' . $frag['sql'];
        $params = array_merge($params, $condParams, $frag['params']);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Search possible duplicates by full name / phone / email with exact + partial matching.
     * Phone matches legacy {@code c.phone} and split columns {@code phone_home}, {@code phone_mobile}, {@code phone_work}.
     *
     * @param array{full_name?:string|null,phone?:string|null,email?:string|null} $criteria
     */
    public function searchDuplicates(
        array $criteria,
        ?int $excludeId = null,
        bool $exact = true,
        bool $partial = true,
        int $limit = 30,
        int $offset = 0,
    ): array {
        if (!$this->normalizedSearchSchema->isReady()) {
            return [];
        }
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $limit = max(1, (int) $limit);
        $offset = max(0, (int) $offset);
        $sql = 'SELECT c.* FROM clients c WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL';
        $params = [];
        if ($excludeId !== null) {
            $sql .= ' AND c.id != ?';
            $params[] = $excludeId;
        }

        $ors = [];
        if (!$this->appendDuplicateSearchOrConditions($criteria, $exact, $partial, $ors, $params)) {
            return [];
        }
        $sql .= ' AND (' . implode(' OR ', $ors) . ')';
        $this->appendStaffClientRowBranchEnvelope($sql, $params, []);
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        $sql .= ' ORDER BY c.last_name ASC, c.first_name ASC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Count rows matching {@see searchDuplicates} criteria (same org scope, no pagination).
     *
     * @param array{full_name?:string|null,phone?:string|null,email?:string|null} $criteria
     */
    public function countSearchDuplicates(
        array $criteria,
        ?int $excludeId = null,
        bool $exact = true,
        bool $partial = true,
    ): int {
        if (!$this->normalizedSearchSchema->isReady()) {
            return 0;
        }
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $sql = 'SELECT COUNT(*) AS c FROM clients c WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL';
        $params = [];
        if ($excludeId !== null) {
            $sql .= ' AND c.id != ?';
            $params[] = $excludeId;
        }
        $ors = [];
        if (!$this->appendDuplicateSearchOrConditions($criteria, $exact, $partial, $ors, $params)) {
            return 0;
        }
        $sql .= ' AND (' . implode(' OR ', $ors) . ')';
        $this->appendStaffClientRowBranchEnvelope($sql, $params, []);
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param list<string> $ors
     * @param list<mixed> $params
     * @param array{full_name?:string|null,phone?:string|null,email?:string|null} $criteria
     */
    private function appendDuplicateSearchOrConditions(array $criteria, bool $exact, bool $partial, array &$ors, array &$params): bool
    {
        $fullName = trim((string) ($criteria['full_name'] ?? ''));
        $phone = trim((string) ($criteria['phone'] ?? ''));
        $email = trim((string) ($criteria['email'] ?? ''));
        $added = false;

        if ($fullName !== '') {
            if ($exact) {
                $ors[] = 'LOWER(TRIM(CONCAT(c.first_name, " ", c.last_name))) = LOWER(?)';
                $params[] = $fullName;
                $added = true;
            }
            if ($partial) {
                $ors[] = 'LOWER(TRIM(CONCAT(c.first_name, " ", c.last_name))) LIKE LOWER(?)';
                $params[] = '%' . $fullName . '%';
                $added = true;
            }
        }
        if ($phone !== '') {
            $digits = PublicContactNormalizer::normalizePhoneDigitsForMatch($phone);
            if ($digits !== null) {
                if ($exact) {
                    $ors[] = '(c.phone_digits = ? OR c.phone_home_digits = ? OR c.phone_mobile_digits = ? OR c.phone_work_digits = ?)';
                    array_push($params, $digits, $digits, $digits, $digits);
                    $added = true;
                }
                if ($partial) {
                    $like = $digits . '%';
                    $ors[] = '(c.phone_digits LIKE ? OR c.phone_home_digits LIKE ? OR c.phone_mobile_digits LIKE ? OR c.phone_work_digits LIKE ?)';
                    array_push($params, $like, $like, $like, $like);
                    $added = true;
                }
            } else {
                $phoneCols = ['c.phone', 'c.phone_home', 'c.phone_mobile', 'c.phone_work'];
                if ($exact) {
                    $parts = [];
                    foreach ($phoneCols as $col) {
                        $parts[] = $col . ' = ?';
                        $params[] = $phone;
                    }
                    $ors[] = '(' . implode(' OR ', $parts) . ')';
                    $added = true;
                }
                if ($partial) {
                    $like = '%' . $phone . '%';
                    $parts = [];
                    foreach ($phoneCols as $col) {
                        $parts[] = $col . ' LIKE ?';
                        $params[] = $like;
                    }
                    $ors[] = '(' . implode(' OR ', $parts) . ')';
                    $added = true;
                }
            }
        }
        if ($email !== '') {
            $emailLc = ClientSearchNormalization::emailLcForStorage($email);
            if ($emailLc !== null) {
                if ($exact) {
                    $ors[] = 'c.email_lc = ?';
                    $params[] = $emailLc;
                    $added = true;
                }
                if ($partial) {
                    $ors[] = 'c.email_lc LIKE ?';
                    $params[] = '%' . $emailLc . '%';
                    $added = true;
                }
            }
        }

        return $added;
    }

    /**
     * @return array<string,int>
     */
    public function countLinkedRecords(int $clientId): array
    {
        $this->assertClientExistsInTenantScope($clientId);
        $clientIdTargets = [
            'appointments' => 'appointments',
            'invoices' => 'invoices',
            'gift_cards' => 'gift_cards',
            'client_packages' => 'client_packages',
            'waitlist' => 'appointment_waitlist',
            'client_notes' => 'client_notes',
            'custom_field_values' => 'client_field_values',
            'issue_flags' => 'client_issue_flags',
            'client_consents' => 'client_consents',
            'client_memberships' => 'client_memberships',
            'membership_sales' => 'membership_sales',
            'membership_benefit_usages' => 'membership_benefit_usages',
            'intake_form_assignments' => 'intake_form_assignments',
            'intake_form_submissions' => 'intake_form_submissions',
            'public_commerce_purchases' => 'public_commerce_purchases',
            'marketing_contact_list_members' => 'marketing_contact_list_members',
            'outbound_notification_messages_client_recipient' => 'outbound_notification_messages',
        ];
        $counts = [];
        foreach ($clientIdTargets as $key => $table) {
            if ($key === 'outbound_notification_messages_client_recipient') {
                $row = $this->db->fetchOne(
                    'SELECT COUNT(*) AS c FROM outbound_notification_messages WHERE recipient_type = \'client\' AND recipient_id = ?',
                    [$clientId]
                );
                $counts[$key] = (int) ($row['c'] ?? 0);
                continue;
            }
            $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM ' . $table . ' WHERE client_id = ?', [$clientId]);
            $counts[$key] = (int) ($row['c'] ?? 0);
        }
        $row = $this->db->fetchOne('SELECT COUNT(*) AS c FROM client_registration_requests WHERE linked_client_id = ?', [$clientId]);
        $counts['registration_links'] = (int) ($row['c'] ?? 0);
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM document_links WHERE owner_type = \'client\' AND owner_id = ? AND deleted_at IS NULL',
            [$clientId]
        );
        $counts['document_links_client'] = (int) ($row['c'] ?? 0);
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM clients WHERE merged_into_client_id = ? AND deleted_at IS NULL',
            [$clientId]
        );
        $counts['clients_pointing_merged_into'] = (int) ($row['c'] ?? 0);

        foreach (['appointment_series', 'marketing_campaign_recipients', 'client_profile_images'] as $optTable) {
            if ($this->databaseTableExists($optTable)) {
                $r = $this->db->fetchOne('SELECT COUNT(*) AS c FROM ' . $optTable . ' WHERE client_id = ?', [$clientId]);
                $counts[$optTable] = (int) ($r['c'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Re-link client_id references from secondary to primary.
     *
     * `client_field_values` are merged in {@see \Modules\Clients\Services\ClientService::mergeCustomFieldValues()} (not remapped here)
     * to respect per-field fill rules and avoid unique (client_id, field_definition_id) races.
     *
     * @return array<string,int> affected rows by table
     */
    public function remapClientReferences(int $primaryId, int $secondaryId): array
    {
        $this->assertClientExistsInTenantScope($primaryId);
        $this->assertClientExistsInTenantScope($secondaryId);
        $affected = [];

        $this->db->query(
            'DELETE s FROM client_consents s
             INNER JOIN client_consents p ON p.document_definition_id = s.document_definition_id AND p.client_id = ?
             WHERE s.client_id = ?',
            [$primaryId, $secondaryId]
        );
        $row = $this->db->fetchOne('SELECT ROW_COUNT() AS c');
        $affected['client_consents_dedup_deleted'] = (int) ($row['c'] ?? 0);

        $this->db->query(
            'DELETE m2 FROM marketing_contact_list_members m2
             INNER JOIN marketing_contact_list_members m1 ON m1.list_id = m2.list_id AND m1.client_id = ?
             WHERE m2.client_id = ?',
            [$primaryId, $secondaryId]
        );
        $row = $this->db->fetchOne('SELECT ROW_COUNT() AS c');
        $affected['marketing_contact_list_members_dedup_deleted'] = (int) ($row['c'] ?? 0);

        $simpleTables = [
            'appointment_series',
            'appointments',
            'invoices',
            'gift_cards',
            'client_packages',
            'appointment_waitlist',
            'client_notes',
            'client_issue_flags',
            'client_consents',
            'client_memberships',
            'membership_sales',
            'membership_benefit_usages',
            'intake_form_assignments',
            'intake_form_submissions',
            'public_commerce_purchases',
            'marketing_campaign_recipients',
            'client_profile_images',
        ];
        foreach ($simpleTables as $table) {
            if (!$this->databaseTableExists($table)) {
                $affected[$table] = 0;
                continue;
            }
            $this->db->query(
                'UPDATE ' . $table . ' SET client_id = ? WHERE client_id = ?',
                [$primaryId, $secondaryId]
            );
            $row = $this->db->fetchOne('SELECT ROW_COUNT() AS c');
            $affected[$table] = (int) ($row['c'] ?? 0);
        }

        $this->db->query(
            'UPDATE marketing_contact_list_members SET client_id = ? WHERE client_id = ?',
            [$primaryId, $secondaryId]
        );
        $row = $this->db->fetchOne('SELECT ROW_COUNT() AS c');
        $affected['marketing_contact_list_members'] = (int) ($row['c'] ?? 0);

        $this->db->query(
            'UPDATE document_links SET owner_id = ? WHERE owner_type = \'client\' AND owner_id = ? AND deleted_at IS NULL',
            [$primaryId, $secondaryId]
        );
        $row = $this->db->fetchOne('SELECT ROW_COUNT() AS c');
        $affected['document_links'] = (int) ($row['c'] ?? 0);

        $this->db->query(
            'UPDATE outbound_notification_messages SET recipient_id = ? WHERE recipient_type = \'client\' AND recipient_id = ?',
            [$primaryId, $secondaryId]
        );
        $row = $this->db->fetchOne('SELECT ROW_COUNT() AS c');
        $affected['outbound_notification_messages_client_recipient'] = (int) ($row['c'] ?? 0);

        $this->db->query(
            'UPDATE clients SET merged_into_client_id = ? WHERE merged_into_client_id = ? AND deleted_at IS NULL',
            [$primaryId, $secondaryId]
        );
        $row = $this->db->fetchOne('SELECT ROW_COUNT() AS c');
        $affected['clients_merged_into_repointed'] = (int) ($row['c'] ?? 0);

        $this->db->query(
            'UPDATE client_registration_requests SET linked_client_id = ? WHERE linked_client_id = ?',
            [$primaryId, $secondaryId]
        );
        $row = $this->db->fetchOne('SELECT ROW_COUNT() AS c');
        $affected['client_registration_requests'] = (int) ($row['c'] ?? 0);

        return $affected;
    }

    public function markMerged(int $secondaryId, int $primaryId, ?int $updatedBy = null): void
    {
        $this->assertClientExistsInTenantScope($primaryId);
        $this->assertClientExistsInTenantScope($secondaryId);
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $params = array_merge([$primaryId, $updatedBy, $secondaryId], $frag['params']);
        $this->db->query(
            'UPDATE clients c
             SET c.merged_into_client_id = ?, c.merged_at = NOW(), c.updated_by = ?, c.deleted_at = NOW()
             WHERE c.id = ?' . $frag['sql'],
            $params
        );
    }

    public function listNotes(int $clientId, int $limit = 20): array
    {
        $this->assertClientExistsInTenantScope($clientId);
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $limit = max(1, (int) $limit);
        $params = array_merge([$clientId], $frag['params'], [$limit]);
        return $this->db->fetchAll(
            'SELECT n.id AS id, n.content, n.created_by, n.created_at
             FROM client_notes n
             INNER JOIN clients c ON c.id = n.client_id
             WHERE n.deleted_at IS NULL
               AND c.deleted_at IS NULL
               AND n.client_id = ?' . $frag['sql'] . '
             ORDER BY n.created_at DESC
             LIMIT ?',
            $params
        );
    }

    public function createNote(int $clientId, string $content, ?int $createdBy): int
    {
        $this->assertClientExistsInTenantScope($clientId);
        $this->db->insert('client_notes', [
            'client_id' => $clientId,
            'content' => $content,
            'created_by' => $createdBy,
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Active note row (not soft-deleted).
     */
    public function findNoteForClient(int $clientId, int $noteId): ?array
    {
        $this->assertClientExistsInTenantScope($clientId);
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $params = array_merge([$noteId, $clientId], $frag['params']);
        $row = $this->db->fetchOne(
            'SELECT n.*
             FROM client_notes n
             INNER JOIN clients c ON c.id = n.client_id
             WHERE n.id = ?
               AND n.client_id = ?
               AND n.deleted_at IS NULL
               AND c.deleted_at IS NULL' . $frag['sql'],
            $params
        );

        return $row ?: null;
    }

    public function softDeleteNoteForClient(int $clientId, int $noteId): void
    {
        $this->assertClientExistsInTenantScope($clientId);
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $params = array_merge([$noteId, $clientId], $frag['params']);
        $this->db->query(
            'UPDATE client_notes n
             INNER JOIN clients c ON c.id = n.client_id
             SET n.deleted_at = NOW()
             WHERE n.id = ?
               AND n.client_id = ?
               AND n.deleted_at IS NULL
               AND c.deleted_at IS NULL' . $frag['sql'],
            $params
        );
    }

    public function listAuditHistory(int $clientId, int $limit = 20): array
    {
        $this->assertClientExistsInTenantScope($clientId);
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $limit = max(1, (int) $limit);
        $params = array_merge([$clientId], $frag['params'], [$limit]);
        return $this->db->fetchAll(
            "SELECT a.id AS id, a.action, a.metadata_json, a.created_at, a.actor_user_id
             FROM audit_logs a
             INNER JOIN clients c ON c.id = a.target_id
             WHERE a.target_type = 'client'
               AND a.target_id = ?
               AND c.deleted_at IS NULL{$frag['sql']}
             ORDER BY a.created_at DESC
             LIMIT ?",
            $params
        );
    }

    private function assertClientExistsInTenantScope(int $clientId): void
    {
        if ($clientId <= 0) {
            throw new \DomainException('Client id is required.');
        }
        $frag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $row = $this->db->fetchOne(
            'SELECT c.id FROM clients c WHERE c.id = ? AND c.deleted_at IS NULL' . $frag['sql'] . ' LIMIT 1',
            array_merge([$clientId], $frag['params'])
        );
        if ($row === null) {
            throw new \Core\Errors\AccessDeniedException('Client is outside tenant scope.');
        }
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'first_name', 'last_name', 'phone', 'email', 'birth_date', 'anniversary', 'gender', 'notes',
            'occupation', 'language',
            'preferred_contact_method', 'marketing_opt_in', 'receive_emails', 'receive_sms',
            'booking_alert', 'check_in_alert', 'check_out_alert',
            'referral_information', 'referral_history', 'referred_by', 'customer_origin',
            'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
            'inactive_flag',
            'phone_home', 'phone_mobile', 'mobile_operator', 'phone_work', 'phone_work_ext',
            'home_address_1', 'home_address_2', 'home_city', 'home_postal_code', 'home_country',
            'delivery_same_as_home', 'delivery_address_1', 'delivery_address_2', 'delivery_city',
            'delivery_postal_code', 'delivery_country',
            'branch_id', 'created_by', 'updated_by',
        ];
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }
        if ($this->normalizedSearchSchema->isReady()) {
            if (array_key_exists('email', $data)) {
                $out['email_lc'] = ClientSearchNormalization::emailLcForStorage($data['email']);
            }
            if (array_key_exists('phone', $data)) {
                $out['phone_digits'] = ClientSearchNormalization::phoneDigitsForStorage(
                    $data['phone'] === null ? null : (string) $data['phone']
                );
            }
            if (array_key_exists('phone_home', $data)) {
                $out['phone_home_digits'] = ClientSearchNormalization::phoneDigitsForStorage(
                    $data['phone_home'] === null ? null : (string) $data['phone_home']
                );
            }
            if (array_key_exists('phone_mobile', $data)) {
                $out['phone_mobile_digits'] = ClientSearchNormalization::phoneDigitsForStorage(
                    $data['phone_mobile'] === null ? null : (string) $data['phone_mobile']
                );
            }
            if (array_key_exists('phone_work', $data)) {
                $out['phone_work_digits'] = ClientSearchNormalization::phoneDigitsForStorage(
                    $data['phone_work'] === null ? null : (string) $data['phone_work']
                );
            }
        }

        return $out;
    }

    private function databaseTableExists(string $table): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table]
        );

        return $row !== null;
    }
}
