<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\App\Database;
use Core\Branch\TenantBranchAccessService;
use Core\Organization\OrganizationLifecycleGate;
use Modules\Auth\Services\TenantEntryResolverService;

/**
 * Authoritative access-shape evaluation for audits, founder tooling, and principal-plane truth.
 * SUPER-ADMIN-LOGIN-CONTROL-PLANE-CANONICALIZATION-01.
 */
final class UserAccessShapeService
{
    /** @var list<string> Canonical states used by founder tenant-access filters (keep in sync with deriveCanonicalState). */
    public const ACCESS_SHAPE_CANONICAL_STATES = [
        'deactivated',
        'founder',
        'tenant_admin_or_staff_single_branch',
        'tenant_multi_branch',
        'tenant_orphan_blocked',
        'tenant_suspended_organization',
    ];

    public function __construct(
        private Database $db,
        private PrincipalAccessService $principalAccess,
        private TenantBranchAccessService $tenantBranchAccess,
        private TenantEntryResolverService $tenantEntry,
        private OrganizationLifecycleGate $lifecycleGate,
    ) {
    }

    public function principalPlaneForUserId(int $userId): string
    {
        if ($userId <= 0) {
            return PrincipalPlaneResolver::BLOCKED_AUTHENTICATED;
        }
        if ($this->principalAccess->isPlatformPrincipal($userId)) {
            return PrincipalPlaneResolver::CONTROL_PLANE;
        }
        if ($this->tenantBranchAccess->allowedBranchIdsForUser($userId) !== []) {
            return PrincipalPlaneResolver::TENANT_PLANE;
        }

        return PrincipalPlaneResolver::BLOCKED_AUTHENTICATED;
    }

    /**
     * Canonical browser redirect after authentication (login, guest → home, GET /).
     *
     * @see PostLoginHomePathResolver
     */
    public function expectedHomePathForUserId(int $userId): string
    {
        if ($userId <= 0) {
            return PostLoginHomePathResolver::PATH_TENANT_ENTRY;
        }
        if ($this->principalAccess->isPlatformPrincipal($userId)) {
            return PostLoginHomePathResolver::PATH_PLATFORM;
        }
        if ($this->lifecycleGate->isTenantUserBoundToSuspendedOrganization($userId)) {
            return PostLoginHomePathResolver::PATH_TENANT_ENTRY;
        }
        $decision = $this->tenantEntry->resolveForUser($userId);
        if ($decision['state'] === 'single') {
            return PostLoginHomePathResolver::PATH_TENANT_DASHBOARD;
        }

        return PostLoginHomePathResolver::PATH_TENANT_ENTRY;
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'user_id' => 0,
                'error' => 'invalid_user_id',
            ];
        }

        $user = $this->db->fetchOne(
            'SELECT id, email, name, branch_id, deleted_at FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );
        if ($user === null) {
            return ['user_id' => $userId, 'error' => 'user_not_found'];
        }

        $roleCodes = $this->roleCodesForUserId($userId);
        $isPlatform = $this->principalAccess->isPlatformPrincipal($userId);
        $usable = $this->tenantBranchAccess->allowedBranchIdsForUser($userId);
        $entry = $this->tenantEntry->resolveForUser($userId);
        $plane = $this->principalPlaneForUserId($userId);
        $suspendedBinding = !$isPlatform && $this->lifecycleGate->isTenantUserBoundToSuspendedOrganization($userId);
        $hasNonPlatformTenantRole = $this->hasTenantFacingRole($roleCodes, $isPlatform);

        $contradictions = [];
        if ($isPlatform && $hasNonPlatformTenantRole) {
            $contradictions[] = 'platform_founder_role_present_with_additional_tenant_roles';
        }
        if ($isPlatform && $usable !== []) {
            $contradictions[] = 'platform_principal_has_usable_tenant_branches';
        }

        $deletedAt = $user['deleted_at'] ?? null;
        $deletedAt = ($deletedAt === null || $deletedAt === '') ? null : $deletedAt;

        $canonicalState = $this->deriveCanonicalState(
            $isPlatform,
            $usable,
            $entry,
            $suspendedBinding,
            $deletedAt !== null
        );

        $repairs = [];
        if ($canonicalState === 'tenant_orphan_blocked') {
            $repairs[] = 'assign_active_organization_membership_and_consistent_branch_pin';
        }
        if ($contradictions !== []) {
            $repairs[] = 'remove_ambiguous_tenant_roles_from_platform_principal_or_remove_platform_role';
        }

        return [
            'user_id' => $userId,
            'email' => (string) ($user['email'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'deleted_at' => $deletedAt,
            'branch_id_pinned' => isset($user['branch_id']) && $user['branch_id'] !== null && $user['branch_id'] !== ''
                ? (int) $user['branch_id']
                : null,
            'role_codes' => $roleCodes,
            'is_platform_principal' => $isPlatform,
            'principal_plane' => $plane,
            'usable_branch_ids' => $usable,
            'tenant_entry_resolution' => $entry,
            'tenant_org_suspended_binding' => $suspendedBinding,
            'canonical_state' => $canonicalState,
            'expected_home_path' => $this->expectedHomePathForUserId($userId),
            'contradictions' => $contradictions,
            'suggested_repairs' => $repairs,
            'organization_memberships' => $this->membershipsForUserId($userId),
        ];
    }

    /**
     * Same semantics as {@see evaluate()} for many users, using a fixed query budget (no per-user N+1).
     *
     * @param list<int> $userIds
     * @return array<int, array<string, mixed>> map keyed by user id
     */
    public function evaluateForUserIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $userIds),
            static fn (int $id) => $id > 0
        )));
        if ($userIds === []) {
            return [];
        }

        $ph = implode(', ', array_fill(0, count($userIds), '?'));

        $memTable = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['user_organization_memberships']
        ) !== null;

        $users = $this->db->fetchAll(
            "SELECT id, email, name, branch_id, deleted_at FROM users WHERE id IN ({$ph})",
            $userIds
        );
        $userById = [];
        foreach ($users as $u) {
            $userById[(int) $u['id']] = $u;
        }

        $roleRows = $this->db->fetchAll(
            "SELECT ur.user_id, r.code AS code
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
             WHERE ur.user_id IN ({$ph})
             ORDER BY ur.user_id ASC, r.code ASC",
            $userIds
        );
        $rolesByUser = array_fill_keys($userIds, []);
        foreach ($roleRows as $rr) {
            $uid = (int) $rr['user_id'];
            $c = (string) ($rr['code'] ?? '');
            if ($c !== '' && array_key_exists($uid, $rolesByUser)) {
                $rolesByUser[$uid][] = $c;
            }
        }
        foreach ($rolesByUser as $uid => $codes) {
            $rolesByUser[$uid] = array_values(array_unique($codes));
        }

        $platformRows = $this->db->fetchAll(
            "SELECT DISTINCT ur.user_id AS id
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
             WHERE ur.user_id IN ({$ph}) AND r.code = 'platform_founder'",
            $userIds
        );
        $platformSet = [];
        foreach ($platformRows as $pr) {
            $platformSet[(int) $pr['id']] = true;
        }

        $pinRows = $this->db->fetchAll(
            "SELECT u.id AS user_id, b.id AS branch_id
             FROM users u
             INNER JOIN branches b ON b.id = u.branch_id AND b.deleted_at IS NULL
             WHERE u.id IN ({$ph}) AND u.deleted_at IS NULL",
            $userIds
        );
        $pinnedByUser = array_fill_keys($userIds, null);
        foreach ($pinRows as $pr) {
            $pinnedByUser[(int) $pr['user_id']] = (int) $pr['branch_id'];
        }

        $membershipRows = [];
        if ($memTable) {
            $membershipRows = $this->db->fetchAll(
                "SELECT m.user_id, m.organization_id, m.status, m.default_branch_id,
                        (o.suspended_at IS NOT NULL) AS org_suspended
                 FROM user_organization_memberships m
                 INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
                 WHERE m.user_id IN ({$ph})
                 ORDER BY m.user_id ASC, m.organization_id ASC",
                $userIds
            );
        }

        $membershipsByUser = array_fill_keys($userIds, []);
        $activeOrgIds = [];
        foreach ($membershipRows as $mr) {
            $uid = (int) $mr['user_id'];
            if (!array_key_exists($uid, $membershipsByUser)) {
                continue;
            }
            $membershipsByUser[$uid][] = [
                'organization_id' => (int) ($mr['organization_id'] ?? 0),
                'status' => (string) ($mr['status'] ?? ''),
                'default_branch_id' => isset($mr['default_branch_id']) && $mr['default_branch_id'] !== null && $mr['default_branch_id'] !== ''
                    ? (int) $mr['default_branch_id']
                    : null,
                'org_suspended' => (int) ($mr['org_suspended'] ?? 0) === 1,
            ];
            if (($mr['status'] ?? '') === 'active') {
                $activeOrgIds[(int) $mr['organization_id']] = true;
            }
        }

        $branchesByOrg = [];
        $activeOrgIdList = array_keys($activeOrgIds);
        if ($activeOrgIdList !== []) {
            $oph = implode(', ', array_fill(0, count($activeOrgIdList), '?'));
            $brows = $this->db->fetchAll(
                "SELECT b.id, b.organization_id
                 FROM branches b
                 INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                 WHERE b.deleted_at IS NULL AND b.organization_id IN ({$oph})
                 ORDER BY b.organization_id ASC, b.id ASC",
                $activeOrgIdList
            );
            foreach ($brows as $br) {
                $oid = (int) $br['organization_id'];
                $bid = (int) $br['id'];
                $branchesByOrg[$oid][] = $bid;
            }
        }

        $pinnedBranchIds = array_values(array_unique(array_filter(
            $pinnedByUser,
            static fn ($v) => $v !== null && $v > 0
        )));
        $branchOrgByBranchId = [];
        if ($pinnedBranchIds !== []) {
            $pbh = implode(', ', array_fill(0, count($pinnedBranchIds), '?'));
            $borg = $this->db->fetchAll(
                "SELECT b.id, b.organization_id
                 FROM branches b
                 INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                 WHERE b.deleted_at IS NULL AND b.id IN ({$pbh})",
                $pinnedBranchIds
            );
            foreach ($borg as $row) {
                $branchOrgByBranchId[(int) $row['id']] = (int) $row['organization_id'];
            }
        }

        $pinSuspendedByUser = array_fill_keys($userIds, false);
        $pinSuspRows = $this->db->fetchAll(
            "SELECT u.id AS user_id, o.suspended_at AS suspended_at
             FROM users u
             INNER JOIN branches b ON b.id = u.branch_id AND b.deleted_at IS NULL
             INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
             WHERE u.id IN ({$ph}) AND u.deleted_at IS NULL",
            $userIds
        );
        foreach ($pinSuspRows as $row) {
            $uid = (int) $row['user_id'];
            $sat = $row['suspended_at'] ?? null;
            $pinSuspendedByUser[$uid] = $sat !== null && $sat !== '';
        }

        $out = [];
        foreach ($userIds as $uid) {
            $userRow = $userById[$uid] ?? null;
            if ($userRow === null) {
                $out[$uid] = ['user_id' => $uid, 'error' => 'user_not_found'];
                continue;
            }

            $roleCodes = $rolesByUser[$uid] ?? [];
            $isPlatform = isset($platformSet[$uid]);
            $usable = $this->computeAllowedBranchIdsBatch(
                $memTable,
                $pinnedByUser[$uid],
                $membershipsByUser[$uid],
                $branchesByOrg,
                $branchOrgByBranchId
            );
            $entry = $this->tenantEntryFromAllowedBranchIds($usable);
            $plane = $this->principalPlaneFromFacts($isPlatform, $usable);
            $suspendedBinding = !$isPlatform && (
                ($pinSuspendedByUser[$uid] ?? false)
                || $this->activeMembershipOnSuspendedOrg($membershipsByUser[$uid])
            );

            $contradictions = [];
            if ($isPlatform && $this->hasTenantFacingRole($roleCodes, $isPlatform)) {
                $contradictions[] = 'platform_founder_role_present_with_additional_tenant_roles';
            }
            if ($isPlatform && $usable !== []) {
                $contradictions[] = 'platform_principal_has_usable_tenant_branches';
            }

            $deletedAt = $userRow['deleted_at'] ?? null;
            $deletedAt = ($deletedAt === null || $deletedAt === '') ? null : $deletedAt;

            $canonicalState = $this->deriveCanonicalState(
                $isPlatform,
                $usable,
                $entry,
                $suspendedBinding,
                $deletedAt !== null
            );

            $repairs = [];
            if ($canonicalState === 'tenant_orphan_blocked') {
                $repairs[] = 'assign_active_organization_membership_and_consistent_branch_pin';
            }
            if ($contradictions !== []) {
                $repairs[] = 'remove_ambiguous_tenant_roles_from_platform_principal_or_remove_platform_role';
            }

            $out[$uid] = [
                'user_id' => $uid,
                'email' => (string) ($userRow['email'] ?? ''),
                'name' => (string) ($userRow['name'] ?? ''),
                'deleted_at' => $deletedAt,
                'branch_id_pinned' => isset($userRow['branch_id']) && $userRow['branch_id'] !== null && $userRow['branch_id'] !== ''
                    ? (int) $userRow['branch_id']
                    : null,
                'role_codes' => $roleCodes,
                'is_platform_principal' => $isPlatform,
                'principal_plane' => $plane,
                'usable_branch_ids' => $usable,
                'tenant_entry_resolution' => $entry,
                'tenant_org_suspended_binding' => $suspendedBinding,
                'canonical_state' => $canonicalState,
                'expected_home_path' => $this->expectedHomePathFromFacts($isPlatform, $suspendedBinding, $entry),
                'contradictions' => $contradictions,
                'suggested_repairs' => $repairs,
                'organization_memberships' => $membershipsByUser[$uid],
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $usable
     * @return array{state:'single', branch_id:int}|array{state:'multiple', branch_ids:list<int>}|array{state:'none'}
     */
    private function tenantEntryFromAllowedBranchIds(array $usable): array
    {
        if (count($usable) === 1) {
            return ['state' => 'single', 'branch_id' => (int) $usable[0]];
        }
        if (count($usable) > 1) {
            return ['state' => 'multiple', 'branch_ids' => array_values($usable)];
        }

        return ['state' => 'none'];
    }

    private function principalPlaneFromFacts(bool $isPlatform, array $usable): string
    {
        if ($isPlatform) {
            return PrincipalPlaneResolver::CONTROL_PLANE;
        }
        if ($usable !== []) {
            return PrincipalPlaneResolver::TENANT_PLANE;
        }

        return PrincipalPlaneResolver::BLOCKED_AUTHENTICATED;
    }

    /**
     * @param list<array{organization_id:int,status:string,default_branch_id:?int,org_suspended:bool}> $members
     */
    private function activeMembershipOnSuspendedOrg(array $members): bool
    {
        foreach ($members as $m) {
            if (($m['status'] ?? '') === 'active' && !empty($m['org_suspended'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors {@see TenantBranchAccessService::allowedBranchIdsForUser()} for batch evaluation.
     *
     * @param list<array{organization_id:int,status:string,default_branch_id:?int,org_suspended:bool}> $membersList
     * @param array<int, list<int>> $branchesByOrg
     * @param array<int, int> $branchOrgByBranchId
     * @return list<int>
     */
    private function computeAllowedBranchIdsBatch(
        bool $memTable,
        ?int $pinnedBranchId,
        array $membersList,
        array $branchesByOrg,
        array $branchOrgByBranchId
    ): array {
        if (!$memTable) {
            if ($pinnedBranchId !== null && $pinnedBranchId > 0) {
                return [$pinnedBranchId];
            }

            return [];
        }

        $orgIds = [];
        foreach ($membersList as $m) {
            if (($m['status'] ?? '') === 'active') {
                $oid = (int) ($m['organization_id'] ?? 0);
                if ($oid > 0) {
                    $orgIds[] = $oid;
                }
            }
        }
        $orgIds = array_values(array_unique($orgIds));

        if ($pinnedBranchId !== null && $pinnedBranchId > 0) {
            if ($orgIds === []) {
                return [];
            }
            $pOrg = $branchOrgByBranchId[$pinnedBranchId] ?? null;
            if ($pOrg !== null && in_array($pOrg, $orgIds, true)) {
                return [$pinnedBranchId];
            }

            return [];
        }
        if ($orgIds === []) {
            return [];
        }

        $out = [];
        foreach ($orgIds as $oid) {
            foreach ($branchesByOrg[$oid] ?? [] as $bid) {
                $out[] = $bid;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param array{state:string, branch_id?:int, branch_ids?:list<int>} $entry
     */
    private function expectedHomePathFromFacts(bool $isPlatform, bool $suspendedBinding, array $entry): string
    {
        if ($isPlatform) {
            return PostLoginHomePathResolver::PATH_PLATFORM;
        }
        if ($suspendedBinding) {
            return PostLoginHomePathResolver::PATH_TENANT_ENTRY;
        }
        if (($entry['state'] ?? '') === 'single') {
            return PostLoginHomePathResolver::PATH_TENANT_DASHBOARD;
        }

        return PostLoginHomePathResolver::PATH_TENANT_ENTRY;
    }

    /**
     * @param list<string> $roleCodes
     */
    private function hasTenantFacingRole(array $roleCodes, bool $isPlatform): bool
    {
        if (!$isPlatform) {
            return $roleCodes !== [];
        }

        foreach ($roleCodes as $code) {
            if ($code !== 'platform_founder') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int> $usable
     * @param array{state:string, branch_id?:int, branch_ids?:list<int>} $entry
     */
    private function deriveCanonicalState(
        bool $isPlatform,
        array $usable,
        array $entry,
        bool $suspendedBinding,
        bool $isDeleted,
    ): string {
        if ($isDeleted) {
            return 'deactivated';
        }
        if ($isPlatform) {
            return 'founder';
        }
        if ($suspendedBinding) {
            return 'tenant_suspended_organization';
        }
        if ($entry['state'] === 'single') {
            return 'tenant_admin_or_staff_single_branch';
        }
        if ($entry['state'] === 'multiple') {
            return 'tenant_multi_branch';
        }

        return 'tenant_orphan_blocked';
    }

    /**
     * @return list<string>
     */
    private function roleCodesForUserId(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT r.code AS code
             FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
             WHERE ur.user_id = ?
             ORDER BY r.code ASC',
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $c = (string) ($row['code'] ?? '');
            if ($c !== '') {
                $out[] = $c;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array{organization_id:int,status:string,default_branch_id:?int,org_suspended:bool}>
     */
    private function membershipsForUserId(int $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['user_organization_memberships']
        );
        if ($row === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT m.organization_id AS organization_id, m.status AS status, m.default_branch_id AS default_branch_id,
                    (o.suspended_at IS NOT NULL) AS org_suspended
             FROM user_organization_memberships m
             INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
             WHERE m.user_id = ?
             ORDER BY m.organization_id ASC',
            [$userId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'organization_id' => (int) ($r['organization_id'] ?? 0),
                'status' => (string) ($r['status'] ?? ''),
                'default_branch_id' => isset($r['default_branch_id']) && $r['default_branch_id'] !== null && $r['default_branch_id'] !== ''
                    ? (int) $r['default_branch_id']
                    : null,
                'org_suspended' => (int) ($r['org_suspended'] ?? 0) === 1,
            ];
        }

        return $out;
    }
}
