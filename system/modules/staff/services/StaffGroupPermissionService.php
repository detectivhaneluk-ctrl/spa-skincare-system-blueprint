<?php

declare(strict_types=1);

namespace Modules\Staff\Services;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Core\Permissions\PermissionService;
use Core\Permissions\StaffGroupPermissionRepository;
use Modules\Staff\Repositories\StaffGroupRepository;

/**
 * Validates and replaces `staff_group_permissions` rows. Used by {@see \Modules\Staff\Controllers\StaffGroupController}
 * admin JSON routes and may be called from scripts.
 */
final class StaffGroupPermissionService
{
    public function __construct(
        private StaffGroupRepository $groups,
        private StaffGroupPermissionRepository $links,
        private Database $db,
        private AuditService $audit,
        private BranchContext $branchContext,
        private PermissionService $permissions
    ) {
    }

    /**
     * Read model for admin: assigned permission ids + full catalog from `permissions`.
     * Enforces the same branch access rule as {@see replacePermissions} (via {@see BranchContext::assertBranchMatchOrGlobalEntity}).
     *
     * @return array{staff_group_id: int, assigned_permission_ids: list<int>, permission_catalog: list<array{id: int, code: string, name: string}>}
     */
    public function getAssignmentStateForAdmin(int $groupId): array
    {
        $group = $this->groups->find($groupId);
        if (!$group) {
            throw new \RuntimeException('Staff group not found.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($group['branch_id'] !== null ? (int) $group['branch_id'] : null);

        return [
            'staff_group_id' => $groupId,
            'assigned_permission_ids' => $this->links->listPermissionIdsForGroup($groupId),
            'permission_catalog' => $this->links->listPermissionCatalog(),
        ];
    }

    /**
     * Replace all permission links for a group. Group must exist, not deleted, and active.
     *
     * @param list<int> $permissionIds
     */
    public function replacePermissions(int $groupId, array $permissionIds): void
    {
        $captureGroupBranchId = null;
        $captureMemberUserIds = [];
        $this->transactional(function () use ($groupId, $permissionIds, &$captureGroupBranchId, &$captureMemberUserIds): void {
            $group = $this->groups->find($groupId);
            if (!$group) {
                throw new \RuntimeException('Staff group not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($group['branch_id'] !== null ? (int) $group['branch_id'] : null);
            if (!empty($group['deleted_at'])) {
                throw new \DomainException('Cannot assign permissions to a deleted staff group.');
            }
            if ((int) ($group['is_active'] ?? 0) !== 1) {
                throw new \DomainException('Cannot assign permissions to an inactive staff group.');
            }

            $unique = [];
            foreach ($permissionIds as $raw) {
                $pid = (int) $raw;
                if ($pid <= 0) {
                    continue;
                }
                $unique[$pid] = true;
            }
            $ids = array_keys($unique);
            foreach ($ids as $pid) {
                $perm = $this->db->fetchOne('SELECT id FROM permissions WHERE id = ?', [$pid]);
                if (!$perm) {
                    throw new \DomainException('Unknown permission id: ' . $pid);
                }
            }

            $before = $this->links->listPermissionIdsForGroup($groupId);
            $this->links->replaceLinksForGroup($groupId, $ids);
            $this->permissions->clearCache();
            foreach ($this->groups->listMemberUserIds($groupId) as $memberUserId) {
                $this->permissions->clearSharedPermissionCacheForStaffUser($memberUserId);
            }

            // WAVE-06: collect members for shared-cache invalidation after commit.
            $captureGroupBranchId = $group['branch_id'] !== null ? (int) $group['branch_id'] : null;
            $members = $this->db->fetchAll(
                'SELECT DISTINCT st.user_id FROM staff st INNER JOIN staff_group_members sgm ON sgm.staff_id = st.id WHERE sgm.staff_group_id = ? AND st.deleted_at IS NULL AND st.user_id IS NOT NULL',
                [$groupId]
            );
            foreach ($members as $m) {
                $uid = isset($m['user_id']) && $m['user_id'] !== null ? (int) $m['user_id'] : 0;
                if ($uid > 0) {
                    $captureMemberUserIds[] = $uid;
                }
            }

            $this->audit->log('staff_group_permissions_replaced', 'staff_group', $groupId, $this->currentUserId(), $group['branch_id'] !== null ? (int) $group['branch_id'] : null, [
                'before_permission_ids' => $before,
                'after_permission_ids' => $ids,
                'assignment_source' => 'staff_group_permission_replace',
            ]);
        }, 'staff-group permissions replace');
        // WAVE-06: clear shared permission cache for all users who are members of this group.
        foreach ($captureMemberUserIds as $uid) {
            try {
                $this->permissions->clearCachedForUser($uid, $captureGroupBranchId);
                if ($captureGroupBranchId !== null) {
                    $this->permissions->clearCachedForUser($uid, null);
                }
            } catch (\Throwable) {}
        }
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function transactional(callable $callback, string $action): void
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $callback();
            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'staff.group_permissions_transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Staff group permission update failed.');
        }
    }
}
