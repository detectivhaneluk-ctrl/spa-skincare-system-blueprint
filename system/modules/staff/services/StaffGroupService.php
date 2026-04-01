<?php

declare(strict_types=1);

namespace Modules\Staff\Services;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Core\Kernel\Authorization\AuthorizerInterface;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;
use Core\Kernel\RequestContextHolder;
use Core\Organization\OrganizationRepositoryScope;
use Core\Permissions\PermissionService;
use Modules\Staff\Repositories\StaffGroupRepository;
use Modules\Staff\Repositories\StaffRepository;

final class StaffGroupService
{
    public function __construct(
        private StaffGroupRepository $groups,
        private StaffRepository $staff,
        private AuditService $audit,
        private BranchContext $branchContext,
        private Database $db,
        private OrganizationRepositoryScope $orgScope,
        private PermissionService $permissions,
        private RequestContextHolder $contextHolder,
        private AuthorizerInterface $authorizer,
    ) {
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::STAFF_MANAGE, ResourceRef::collection('staff'));
            $branchId = $this->resolveBranchScope($data['branch_id'] ?? null);
            $name = $this->normalizeName((string) ($data['name'] ?? ''));
            if ($this->groups->activeNameExists($branchId, $name)) {
                throw new \DomainException('An active staff group with this name already exists in scope.');
            }
            $userId = $this->currentUserId();
            $id = $this->groups->create([
                'branch_id' => $branchId,
                'name' => $name,
                'description' => $this->normalizeDescription($data['description'] ?? null),
                'is_active' => true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $this->audit->log('staff_group_created', 'staff_group', $id, $userId, $branchId, [
                'name' => $name,
            ]);
            return $id;
        }, 'staff-group create');
    }

    public function update(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::STAFF_MANAGE, ResourceRef::instance('staff', $id));
            $group = $this->requireGroup($id);
            $name = $this->normalizeName((string) ($data['name'] ?? ''));
            if ($this->groups->activeNameExistsInTenantScope($this->tenantBranchContextId(), $group['branch_id'] !== null ? (int) $group['branch_id'] : null, $name, $id)) {
                throw new \DomainException('An active staff group with this name already exists in scope.');
            }
            $userId = $this->currentUserId();
            $patch = [
                'name' => $name,
                'description' => $this->normalizeDescription($data['description'] ?? null),
                'updated_by' => $userId,
            ];
            if (array_key_exists('is_active', $data)) {
                $patch['is_active'] = (bool) $data['is_active'];
            }
            $this->groups->update($id, $patch);
            if (array_key_exists('is_active', $patch) && $patch['is_active'] === false) {
                $this->invalidateStaffGroupMemberPermissionCaches($id);
            }
            $this->audit->log('staff_group_updated', 'staff_group', $id, $userId, $group['branch_id'] !== null ? (int) $group['branch_id'] : null, [
                'before' => $group,
                'after' => array_merge($group, $patch),
            ]);
        }, 'staff-group update');
    }

    public function deactivate(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::STAFF_MANAGE, ResourceRef::instance('staff', $id));
            $group = $this->requireGroup($id);
            if ((int) ($group['is_active'] ?? 0) === 0) {
                return;
            }
            $this->invalidateStaffGroupMemberPermissionCaches($id);
            $this->groups->update($id, [
                'is_active' => false,
                'updated_by' => $this->currentUserId(),
            ]);
            $this->audit->log('staff_group_deactivated', 'staff_group', $id, $this->currentUserId(), $group['branch_id'] !== null ? (int) $group['branch_id'] : null, [
                'group' => $group,
            ]);
        }, 'staff-group deactivate');
    }

    public function attachStaff(int $groupId, int $staffId): void
    {
        $this->transactional(function () use ($groupId, $staffId): void {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::STAFF_MANAGE, ResourceRef::instance('staff', $groupId));
            $group = $this->requireGroup($groupId);
            if ((int) ($group['is_active'] ?? 0) !== 1) {
                throw new \DomainException('Cannot attach staff to an inactive group.');
            }
            $staff = $this->requireStaff($staffId);
            $this->assertSameBranchScope($group, $staff);
            if ($this->groups->hasMember($groupId, $staffId)) {
                throw new \DomainException('Staff member is already attached to this group.');
            }
            $pivotId = $this->groups->attachStaff($groupId, $staffId, $this->currentUserId());
            $branchId = $group['branch_id'] !== null ? (int) $group['branch_id'] : null;
            $this->audit->log('staff_group_member_attached', 'staff_group_member', $pivotId, $this->currentUserId(), $branchId, [
                'staff_group_id' => $groupId,
                'staff_id' => $staffId,
            ]);
            $this->invalidatePermissionCacheForStaffUserFromStaffId($staffId);
        }, 'staff-group attach');
    }

    public function detachStaff(int $groupId, int $staffId): void
    {
        $this->transactional(function () use ($groupId, $staffId): void {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $this->authorizer->requireAuthorized($ctx, ResourceAction::STAFF_MANAGE, ResourceRef::instance('staff', $groupId));
            $group = $this->requireGroup($groupId);
            if (!$this->groups->hasMember($groupId, $staffId)) {
                throw new \DomainException('Staff member is not attached to this group.');
            }
            $this->invalidatePermissionCacheForStaffUserFromStaffId($staffId);
            $this->groups->detachStaff($groupId, $staffId);
            $branchId = $group['branch_id'] !== null ? (int) $group['branch_id'] : null;
            $this->audit->log('staff_group_member_detached', 'staff_group', $groupId, $this->currentUserId(), $branchId, [
                'staff_group_id' => $groupId,
                'staff_id' => $staffId,
            ]);
        }, 'staff-group detach');
    }

    /**
     * Authoritative staff-group scope resolver. Use for scheduling/assignment validation.
     * Rule: when the branch has active staff groups, only staff in at least one such group are in scope.
     * When the branch has no active groups, all staff pass (unchanged behavior).
     * Inactive/deleted group memberships do not grant scope.
     */
    public function isStaffInScopeForBranch(int $staffId, ?int $branchId): bool
    {
        if (!$this->groups->hasActiveGroupsForBranch($branchId)) {
            return true;
        }

        return $this->groups->isStaffInAnyActiveGroupForBranch($staffId, $branchId);
    }

    private function requireGroup(int $id): array
    {
        $group = $this->groups->find($id);
        if (!$group) {
            throw new \RuntimeException('Staff group not found.');
        }
        $this->branchContext->assertBranchMatchOrGlobalEntity($group['branch_id'] !== null ? (int) $group['branch_id'] : null);
        return $group;
    }

    private function requireStaff(int $id): array
    {
        $staff = $this->staff->find($id);
        if (!$staff) {
            throw new \DomainException('Staff member not found.');
        }
        if ((int) ($staff['is_active'] ?? 0) !== 1) {
            throw new \DomainException('Staff member is not active.');
        }
        return $staff;
    }

    private function assertSameBranchScope(array $group, array $staff): void
    {
        $groupBranch = $group['branch_id'] !== null ? (int) $group['branch_id'] : null;
        $staffBranch = $staff['branch_id'] !== null ? (int) $staff['branch_id'] : null;
        if ($groupBranch !== $staffBranch) {
            throw new \DomainException('Staff and group must belong to the same branch scope.');
        }
    }

    private function resolveBranchScope(mixed $branchId): ?int
    {
        $scope = $branchId !== null && $branchId !== '' ? (int) $branchId : null;
        $this->branchContext->assertBranchMatchOrGlobalEntity($scope);
        return $scope;
    }

    /**
     * Anchor for tenant-scoped repository calls when {@see BranchContext} has no current branch (e.g. org-wide operator).
     */
    private function tenantBranchContextId(): int
    {
        $b = $this->branchContext->getCurrentBranchId();
        if ($b !== null && $b > 0) {
            return $b;
        }
        $any = $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
        if ($any !== null && $any > 0) {
            return $any;
        }
        throw new \DomainException(OrganizationRepositoryScope::EXCEPTION_DATA_PLANE_ORGANIZATION_REQUIRED);
    }

    private function normalizeName(string $name): string
    {
        $normalized = trim($name);
        if ($normalized === '') {
            throw new \InvalidArgumentException('Group name is required.');
        }
        return $normalized;
    }

    private function normalizeDescription(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $value = trim((string) $description);
        return $value !== '' ? $value : null;
    }

    private function invalidateStaffGroupMemberPermissionCaches(int $groupId): void
    {
        foreach ($this->groups->listMemberUserIds($groupId) as $uid) {
            $this->permissions->clearSharedPermissionCacheForStaffUser($uid);
        }
    }

    private function invalidatePermissionCacheForStaffUserFromStaffId(int $staffId): void
    {
        $row = $this->staff->find($staffId);
        if ($row === null) {
            return;
        }
        $uid = $row['user_id'] ?? null;
        if ($uid === null || (int) $uid <= 0) {
            return;
        }
        $this->permissions->clearSharedPermissionCacheForStaffUser((int) $uid);
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function transactional(callable $callback, string $action): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $callback();
            if ($started) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'staff.groups_transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Staff group operation failed.');
        }
    }
}
