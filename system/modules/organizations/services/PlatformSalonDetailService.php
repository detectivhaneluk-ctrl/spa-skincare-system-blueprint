<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\Auth\PrincipalAccessService;
use Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository;

/**
 * Unified salon (organization) detail read model for /platform-admin/salons/{id}.
 */
final class PlatformSalonDetailService
{
    private const PLAN_PLACEHOLDER = '—';

    public function __construct(
        private OrganizationRegistryReadService $registryRead,
        private PlatformSalonRegistryReadRepository $salonReads,
        private PlatformSalonProblemsService $problems,
        private PlatformSalonIssuesSectionService $issuesSection,
        private PlatformSalonAdminAccessService $adminAccess,
        private PrincipalAccessService $principalAccess,
    ) {
    }

    /**
     * @return array<string, mixed>|null Full detail payload or null if salon missing.
     */
    public function build(int $organizationId, bool $canManage): ?array
    {
        $org = $this->registryRead->getOrganizationById($organizationId);
        if ($org === null) {
            return null;
        }
        $oid = (int) ($org['id'] ?? 0);
        $branchCounts = $this->salonReads->countBranchesByOrganizationIds([$oid]);
        $bc = (int) ($branchCounts[$oid] ?? 0);
        $admins = $this->salonReads->batchPrimaryAdminForOrganizations([$oid]);
        $primary = $admins[$oid] ?? null;

        $resolved = $this->adminAccess->resolve($primary);
        $shape = $resolved['shape'] ?? null;
        $user = $resolved['user'] ?? null;

        $problemRows = $this->problems->buildProblems($org, $bc, $primary, is_array($shape) ? $shape : null, $oid);
        $lifecycle = $this->lifecycleLabel($org);
        $mgmt = $this->managementActions($org, $canManage);
        $issues = $this->issuesSection->presentForSalonDetail($problemRows, $mgmt, $canManage, $lifecycle);
        $branches = $this->salonReads->listBranchesForOrganization($oid);
        $heroLifecycle = $this->buildHeroLifecycle($org, $canManage, $oid, $bc);
        $peopleSection = $this->buildPeopleSection($oid, $primary, $org, $bc, $canManage);

        return [
            'salon' => [
                'id' => $oid,
                'name' => (string) ($org['name'] ?? ''),
                'code' => $org['code'] !== null && $org['code'] !== '' ? (string) $org['code'] : null,
                'lifecycle_status' => $lifecycle,
                'created_at' => (string) ($org['created_at'] ?? ''),
                'updated_at' => (string) ($org['updated_at'] ?? ''),
                'plan_summary' => self::PLAN_PLACEHOLDER,
                'branch_count' => $bc,
            ],
            'primary_admin' => $this->formatPrimaryAdmin($user, $shape, $oid, $canManage, $org),
            'branches' => $branches,
            'problems' => $issues,
            'management_actions' => $mgmt,
            'hero_lifecycle' => $heroLifecycle,
            'people_section' => $peopleSection,
            'danger_actions' => [],
        ];
    }

    /**
     * @param array<string, mixed> $org
     * @return array{primary:?array{key:string,label:string,url:string,variant:string}, archive:?array{label:string,blocked:bool,url?:string,note?:string}}
     */
    private function buildHeroLifecycle(array $org, bool $canManage, int $id, int $branchCount): array
    {
        if (!$canManage || !empty($org['deleted_at'])) {
            return ['primary' => null, 'archive' => null];
        }

        $suspended = !empty($org['suspended_at']);
        if ($suspended) {
            $primary = [
                'key' => 'reactivate',
                'label' => 'Reactivate salon',
                'url' => '/platform-admin/salons/' . $id . '/reactivate-confirm',
                'variant' => 'primary',
            ];
        } else {
            $primary = [
                'key' => 'suspend',
                'label' => 'Suspend salon',
                'url' => '/platform-admin/salons/' . $id . '/suspend-confirm',
                'variant' => 'caution',
            ];
        }

        if ($branchCount > 0) {
            $archive = [
                'label' => 'Archive salon',
                'blocked' => true,
                'note' => 'Unavailable while branches exist.',
            ];
        } else {
            $archive = [
                'label' => 'Archive salon',
                'blocked' => false,
                'url' => '/platform-admin/salons/' . $id . '/archive-confirm',
            ];
        }

        return ['primary' => $primary, 'archive' => $archive];
    }

    /**
     * @param array<string, mixed>|null $primaryAdminRow from batch primary resolution (id, email, …)
     * @param array<string, mixed> $org
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   can_add: bool,
     *   add_blocked_hint: ?string
     * }
     */
    private function buildPeopleSection(int $organizationId, ?array $primaryAdminRow, array $org, int $branchCount, bool $canManage): array
    {
        $archived = !empty($org['deleted_at']);
        $suspended = !empty($org['suspended_at']);
        $membershipOk = $this->salonReads->membershipPivotExists();
        $primaryId = $primaryAdminRow !== null ? (int) ($primaryAdminRow['id'] ?? 0) : 0;

        $canAdd = $canManage && !$archived && !$suspended && $branchCount > 0 && $membershipOk;
        $hint = null;
        if (!$canManage) {
            $hint = null;
        } elseif ($archived) {
            $hint = 'Archived salon.';
            $canAdd = false;
        } elseif ($suspended) {
            $hint = 'Reactivate the salon to add people.';
            $canAdd = false;
        } elseif ($branchCount === 0) {
            $hint = 'Add a branch before adding people.';
            $canAdd = false;
        } elseif (!$membershipOk) {
            $hint = 'Membership data is not available on this database.';
            $canAdd = false;
        }

        $raw = $this->salonReads->listSalonLinkedPeople($organizationId);
        $rows = [];
        foreach ($raw as $r) {
            if (!is_array($r)) {
                continue;
            }
            $uid = (int) ($r['id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $codes = (string) ($r['role_codes'] ?? '');
            $rows[] = [
                'user_id' => $uid,
                'name' => (string) ($r['name'] ?? ''),
                'email' => (string) ($r['email'] ?? ''),
                'login_enabled' => empty($r['deleted_at']),
                'role_label' => $this->friendlyRoleLabel($codes),
                'is_primary_admin' => $primaryId > 0 && $uid === $primaryId,
                'access_url' => '/platform-admin/access/' . $uid,
            ];
        }

        return [
            'rows' => $rows,
            'can_add' => $canAdd,
            'add_blocked_hint' => $hint,
        ];
    }

    private function friendlyRoleLabel(string $roleCodesCsv): string
    {
        $codes = array_filter(array_map('trim', explode(',', strtolower($roleCodesCsv))));
        if ($codes === []) {
            return '—';
        }
        foreach (['owner', 'admin', 'reception', 'staff'] as $pref) {
            if (in_array($pref, $codes, true)) {
                return match ($pref) {
                    'owner' => 'Owner',
                    'admin' => 'Admin',
                    'reception' => 'Reception',
                    default => 'Staff',
                };
            }
        }

        $first = reset($codes);

        return $first !== false ? ucfirst((string) $first) : '—';
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed>|null $shape
     * @param array<string, mixed> $org
     * @return array<string, mixed>|null
     */
    private function formatPrimaryAdmin(?array $user, ?array $shape, int $salonId, bool $canManage, array $org): ?array
    {
        if ($user === null) {
            return null;
        }
        $uid = (int) ($user['id'] ?? 0);
        $deleted = !empty($user['deleted_at']);
        $loginActive = !$deleted;
        $archived = !empty($org['deleted_at']);
        $isPlatform = $uid > 0 && $this->principalAccess->isPlatformPrincipal($uid);

        return [
            'user_id' => $uid,
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role_code'] ?? ''),
            'login_enabled' => $loginActive,
            'password_changed_at' => $user['password_changed_at'] ?? null,
            'access_shape' => $shape,
            'admin_access_note' => $isPlatform ? 'Platform account — use Access for identity changes.' : null,
            'actions' => $this->adminAccessActions($salonId, $canManage, $archived, $isPlatform, $loginActive),
        ];
    }

    /**
     * @return list<array{key:string, label:string, url:string}>
     */
    private function adminAccessActions(int $salonId, bool $canManage, bool $salonArchived, bool $isPlatform, bool $loginEnabled): array
    {
        if (!$canManage || $salonArchived || $isPlatform) {
            return [];
        }
        $base = '/platform-admin/salons/' . $salonId . '/admin-access/';
        $out = [
            ['key' => 'email', 'label' => 'Change login email', 'url' => $base . 'email'],
            ['key' => 'password', 'label' => 'Set new password', 'url' => $base . 'password'],
        ];
        if ($loginEnabled) {
            $out[] = ['key' => 'disable_login', 'label' => 'Disable login', 'url' => $base . 'disable-login-confirm'];
        } else {
            $out[] = ['key' => 'enable_login', 'label' => 'Enable login', 'url' => $base . 'enable-login-confirm'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $org
     * @return list<array{key:string, label:string, url:string, tier?:string}>
     */
    private function managementActions(array $org, bool $canManage): array
    {
        if (!$canManage) {
            return [];
        }
        $id = (int) ($org['id'] ?? 0);
        $deleted = !empty($org['deleted_at']);
        if ($deleted) {
            return [];
        }
        $suspended = !empty($org['suspended_at']);
        $edit = ['key' => 'edit_salon', 'label' => 'Edit salon', 'url' => '/platform-admin/salons/' . $id . '/edit', 'tier' => 'secondary'];
        $branch = ['key' => 'add_branch', 'label' => 'Add branch', 'url' => '/platform-admin/salons/' . $id . '/branches/create', 'tier' => 'secondary'];
        if ($suspended) {
            return [
                ['key' => 'edit_salon', 'label' => 'Edit salon', 'url' => '/platform-admin/salons/' . $id . '/edit', 'tier' => 'primary'],
                $branch,
            ];
        }

        return [
            ['key' => 'edit_salon', 'label' => 'Edit salon', 'url' => '/platform-admin/salons/' . $id . '/edit', 'tier' => 'primary'],
            $branch,
        ];
    }

    /**
     * @param array<string, mixed> $org
     */
    private function lifecycleLabel(array $org): string
    {
        if (!empty($org['deleted_at'])) {
            return 'archived';
        }
        if (!empty($org['suspended_at'])) {
            return 'suspended';
        }

        return 'active';
    }
}
