<?php

declare(strict_types=1);

namespace Modules\Notifications\Repositories;

use Core\App\Database;
use Core\Organization\OrganizationRepositoryScope;

/**
 * Internal in-app notifications (`notifications` + `notification_reads`).
 *
 * | Class | Methods |
 * | --- | --- |
 * | **1–2. Branch ∪ org-global-null (tenant)** | List/count paths apply {@see OrganizationRepositoryScope::notificationBranchOverlayOrGlobalNullFromOperationBranchClause()} when a **positive** branch context is known; **global-null-only** slice uses {@see OrganizationRepositoryScope::notificationGlobalNullBranchOrgAnchoredSql()}; **no branch filter** uses {@see OrganizationRepositoryScope::notificationTenantWideBranchOrGlobalNullClause()} (fail-closed: no silent full-table scan) |
 * | **3. Primary-key / id-only / insert** | {@see find} now uses {@see OrganizationRepositoryScope::notificationTenantWideBranchOrGlobalNullClause()} for tenant-safe id reads; {@see create}, {@see markReadByUser} — no org predicate on row (caller/FK discipline); {@see existsByTypeEntityAndTitle} is org-bounded via tenant-wide branch clause |
 * | **4. Control-plane unscoped** | *(none)* |
 *
 * **User targeting** (independent of branch org proof): `(user_id = ? OR user_id IS NULL)` means **direct** or **broadcast** within the already branch-bounded set.
 */
final class NotificationRepository
{
    public function __construct(
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * Tenant-safe id read: row must be visible in the resolved tenant org
     * ({@see OrganizationRepositoryScope::notificationTenantWideBranchOrGlobalNullClause()}).
     */
    public function find(int $id): ?array
    {
        $tw = $this->orgScope->notificationTenantWideBranchOrGlobalNullClause('n');

        return $this->db->fetchOne(
            'SELECT n.* FROM notifications n WHERE n.id = ? AND (' . $tw['sql'] . ')',
            array_merge([$id], $tw['params'])
        );
    }

    /**
     * @param array{branch_id?: int|null, user_id?: int|null, is_read?: bool} $filters
     * @return array<int, array>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT n.* FROM notifications n WHERE 1=1';
        $params = [];
        $sql .= $this->appendUserFilterSql($filters, 'n', $params);
        $sql .= $this->appendBranchScopeSqlForListCount($filters, 'n', $params);
        if (isset($filters['is_read']) && $filters['is_read'] !== null) {
            $sql .= ' AND n.is_read = ?';
            $params[] = $filters['is_read'] ? 1 : 0;
        }
        $sql .= ' ORDER BY n.created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function count(array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM notifications n WHERE 1=1';
        $params = [];
        $sql .= $this->appendUserFilterSql($filters, 'n', $params);
        $sql .= $this->appendBranchScopeSqlForListCount($filters, 'n', $params);
        if (isset($filters['is_read']) && $filters['is_read'] !== null) {
            $sql .= ' AND n.is_read = ?';
            $params[] = $filters['is_read'] ? 1 : 0;
        }
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array{branch_id?: int|null, user_id?: int|null, type: string, title: string, message?: string|null, entity_type?: string|null, entity_id?: int|null} $data
     */
    public function create(array $data): int
    {
        $payload = [
            'branch_id' => $data['branch_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'type' => $data['type'],
            'title' => $data['title'],
            'message' => $data['message'] ?? null,
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => isset($data['entity_id']) && $data['entity_id'] !== '' ? (int) $data['entity_id'] : null,
            'is_read' => 0,
        ];
        $this->db->insert('notifications', $payload);

        return $this->db->lastInsertId();
    }

    /**
     * Dedup guard for create flows — **tenant-bounded** (no cross-org entity_id collision).
     */
    public function existsByTypeEntityAndTitle(string $type, string $entityType, int $entityId, string $title): bool
    {
        $tw = $this->orgScope->notificationTenantWideBranchOrGlobalNullClause('n');
        $row = $this->db->fetchOne(
            'SELECT n.id FROM notifications n WHERE n.type = ? AND n.entity_type = ? AND n.entity_id = ? AND n.title = ? AND (' . $tw['sql'] . ') LIMIT 1',
            array_merge([$type, $entityType, $entityId, $title], $tw['params'])
        );

        return $row !== null;
    }

    /**
     * Record that the given user has read the notification. Idempotent; does not affect other users.
     */
    public function markReadByUser(int $notificationId, int $userId): void
    {
        $this->db->query(
            'INSERT INTO notification_reads (notification_id, user_id, read_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE read_at = NOW()',
            [$notificationId, $userId]
        );
    }

    /**
     * Record that the given user has read all notifications visible to them in the branch context.
     * Only inserts reads for notifications not already read by this user.
     */
    public function markAllReadByUser(int $userId, ?int $branchId): void
    {
        $sql = 'INSERT INTO notification_reads (notification_id, user_id, read_at)
             SELECT n.id, ?, NOW() FROM notifications n
             WHERE (n.user_id = ? OR n.user_id IS NULL)';
        $params = [$userId, $userId];
        if ($branchId !== null && $branchId > 0) {
            $u = $this->orgScope->notificationBranchOverlayOrGlobalNullFromOperationBranchClause('n', $branchId);
            $sql .= ' AND (' . $u['sql'] . ')';
            $params = array_merge($params, $u['params']);
        } else {
            $g = $this->orgScope->notificationGlobalNullBranchOrgAnchoredSql('n');
            $sql .= $g['sql'];
            $params = array_merge($params, $g['params']);
        }
        $sql .= ' AND n.id NOT IN (SELECT notification_id FROM notification_reads WHERE user_id = ?)';
        $params[] = $userId;
        $this->db->query($sql, $params);
    }

    /**
     * List notifications visible to the user with per-user read state from notification_reads.
     * Branch slice: operation-branch overlay ∪ org-global-null, or global-null-only when {@code $branchId} is null.
     *
     * @param array{is_read?: bool|null} $filters
     * @return array<int, array>
     */
    public function listForUser(int $userId, ?int $branchId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT n.*, (nr.user_id IS NOT NULL) AS is_read
                FROM notifications n
                LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
                WHERE (n.user_id = ? OR n.user_id IS NULL)';
        $params = [$userId, $userId];
        if ($branchId !== null && $branchId > 0) {
            $u = $this->orgScope->notificationBranchOverlayOrGlobalNullFromOperationBranchClause('n', $branchId);
            $sql .= ' AND (' . $u['sql'] . ')';
            $params = array_merge($params, $u['params']);
        } else {
            $g = $this->orgScope->notificationGlobalNullBranchOrgAnchoredSql('n');
            $sql .= $g['sql'];
            $params = array_merge($params, $g['params']);
        }
        if (isset($filters['is_read']) && $filters['is_read'] !== null) {
            if ($filters['is_read']) {
                $sql .= ' AND nr.user_id IS NOT NULL';
            } else {
                $sql .= ' AND nr.user_id IS NULL';
            }
        }
        $sql .= ' ORDER BY n.created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        $rows = $this->db->fetchAll($sql, $params);
        foreach ($rows as &$r) {
            $r['is_read'] = (bool) ($r['is_read'] ?? false);
        }

        return $rows;
    }

    /**
     * Count notifications visible to the user, with optional is_read filter (per notification_reads for this user).
     */
    public function countForUser(int $userId, ?int $branchId, array $filters = []): int
    {
        $sql = 'SELECT COUNT(*) AS c
                FROM notifications n
                LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
                WHERE (n.user_id = ? OR n.user_id IS NULL)';
        $params = [$userId, $userId];
        if ($branchId !== null && $branchId > 0) {
            $u = $this->orgScope->notificationBranchOverlayOrGlobalNullFromOperationBranchClause('n', $branchId);
            $sql .= ' AND (' . $u['sql'] . ')';
            $params = array_merge($params, $u['params']);
        } else {
            $g = $this->orgScope->notificationGlobalNullBranchOrgAnchoredSql('n');
            $sql .= $g['sql'];
            $params = array_merge($params, $g['params']);
        }
        if (isset($filters['is_read']) && $filters['is_read'] !== null) {
            if ($filters['is_read']) {
                $sql .= ' AND nr.user_id IS NOT NULL';
            } else {
                $sql .= ' AND nr.user_id IS NULL';
            }
        }
        $row = $this->db->fetchOne($sql, $params);

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array{branch_id?: int|null, user_id?: int|null, is_read?: bool} $filters
     * @param list<mixed> $params
     */
    private function appendUserFilterSql(array $filters, string $alias, array &$params): string
    {
        if (!array_key_exists('user_id', $filters)) {
            return '';
        }
        $uid = $filters['user_id'];
        if ($uid === null || $uid === '') {
            return '';
        }

        $params[] = (int) $uid;

        return " AND ({$alias}.user_id = ? OR {$alias}.user_id IS NULL)";
    }

    /**
     * Branch dimension: explicit positive branch ⇒ overlay; otherwise tenant-wide (no silent unscoped read).
     *
     * @param array{branch_id?: int|null, user_id?: int|null, is_read?: bool} $filters
     * @param list<mixed> $params
     */
    private function appendBranchScopeSqlForListCount(array $filters, string $alias, array &$params): string
    {
        $bid = $filters['branch_id'] ?? null;
        if ($bid !== null && $bid !== '' && (int) $bid > 0) {
            $u = $this->orgScope->notificationBranchOverlayOrGlobalNullFromOperationBranchClause($alias, (int) $bid);

            $params = array_merge($params, $u['params']);

            return ' AND (' . $u['sql'] . ')';
        }
        $tw = $this->orgScope->notificationTenantWideBranchOrGlobalNullClause($alias);
        $params = array_merge($params, $tw['params']);

        return ' AND (' . $tw['sql'] . ')';
    }
}
