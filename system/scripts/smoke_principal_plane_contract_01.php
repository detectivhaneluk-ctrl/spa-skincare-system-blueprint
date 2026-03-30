<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\Auth\AuthenticatedHomePathResolver;
use Core\Auth\PrincipalPlaneResolver;
use Core\Branch\TenantBranchAccessService;
use Modules\Auth\Services\TenantEntryResolverService;

$db = app(\Core\App\Database::class);
$planeResolver = app(PrincipalPlaneResolver::class);
$homeResolver = app(AuthenticatedHomePathResolver::class);
$tenantEntry = app(TenantEntryResolverService::class);
$branchAccess = app(TenantBranchAccessService::class);

$passed = 0;
$failed = 0;
function ppc01Pass(string $name): void { global $passed; $passed++; echo "PASS  {$name}\n"; }
function ppc01Fail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }

$pdo = $db->connection();
$pdo->beginTransaction();

try {
    $suffix = 'PPC01_' . bin2hex(random_bytes(4));

    $db->insert('organizations', ['name' => $suffix . '_Org_A', 'code' => strtolower($suffix . '_oa')]);
    $orgA = (int) $db->lastInsertId();
    $db->insert('organizations', ['name' => $suffix . '_Org_B', 'code' => strtolower($suffix . '_ob')]);
    $orgB = (int) $db->lastInsertId();

    $db->insert('branches', ['name' => $suffix . '_Branch_A', 'code' => strtolower($suffix . '_ba'), 'organization_id' => $orgA]);
    $branchA = (int) $db->lastInsertId();
    $db->insert('branches', ['name' => $suffix . '_Branch_B', 'code' => strtolower($suffix . '_bb'), 'organization_id' => $orgA]);
    $branchB = (int) $db->lastInsertId();
    $db->insert('branches', ['name' => $suffix . '_Branch_C', 'code' => strtolower($suffix . '_bc'), 'organization_id' => $orgB]);
    $branchC = (int) $db->lastInsertId();

    $platformRole = $db->fetchOne('SELECT id FROM roles WHERE code = ? AND deleted_at IS NULL LIMIT 1', ['platform_founder']);
    $adminRole = $db->fetchOne('SELECT id FROM roles WHERE code = ? AND deleted_at IS NULL LIMIT 1', ['admin']);
    if ($platformRole === null || $adminRole === null) {
        throw new RuntimeException('Missing required roles: platform_founder/admin');
    }

    $mkUser = static function (?int $branchId) use ($db, $suffix): int {
        $db->insert('users', [
            'email' => strtolower($suffix . '_' . bin2hex(random_bytes(2)) . '@example.test'),
            'password_hash' => password_hash($suffix . '_pass_123', PASSWORD_DEFAULT),
            'name' => $suffix . ' User',
            'branch_id' => $branchId,
        ]);
        return (int) $db->lastInsertId();
    };

    $platform = $mkUser(null);
    $single = $mkUser($branchA);
    $multi = $mkUser(null);
    $orphan = $mkUser(null);

    $db->insert('user_roles', ['user_id' => $platform, 'role_id' => (int) $platformRole['id']]);
    $db->insert('user_roles', ['user_id' => $single, 'role_id' => (int) $adminRole['id']]);
    $db->insert('user_roles', ['user_id' => $multi, 'role_id' => (int) $adminRole['id']]);
    $db->insert('user_roles', ['user_id' => $orphan, 'role_id' => (int) $adminRole['id']]);

    $db->insert('user_organization_memberships', [
        'user_id' => $single,
        'organization_id' => $orgA,
        'status' => 'active',
        'default_branch_id' => $branchA,
    ]);
    $db->insert('user_organization_memberships', [
        'user_id' => $multi,
        'organization_id' => $orgA,
        'status' => 'active',
        'default_branch_id' => $branchB,
    ]);
    $db->insert('user_organization_memberships', [
        'user_id' => $multi,
        'organization_id' => $orgB,
        'status' => 'active',
        'default_branch_id' => $branchC,
    ]);

    ($planeResolver->resolveForUserId($platform) === PrincipalPlaneResolver::CONTROL_PLANE)
        ? ppc01Pass('platform_classified_control_plane')
        : ppc01Fail('platform_classified_control_plane', 'unexpected classification');
    ($planeResolver->resolveForUserId($single) === PrincipalPlaneResolver::TENANT_PLANE)
        ? ppc01Pass('tenant_single_classified_tenant_plane')
        : ppc01Fail('tenant_single_classified_tenant_plane', 'unexpected classification');
    ($planeResolver->resolveForUserId($multi) === PrincipalPlaneResolver::TENANT_PLANE)
        ? ppc01Pass('tenant_multi_classified_tenant_plane')
        : ppc01Fail('tenant_multi_classified_tenant_plane', 'unexpected classification');
    ($planeResolver->resolveForUserId($orphan) === PrincipalPlaneResolver::BLOCKED_AUTHENTICATED)
        ? ppc01Pass('orphan_classified_blocked_authenticated')
        : ppc01Fail('orphan_classified_blocked_authenticated', 'unexpected classification');

    ($homeResolver->homePathForUserId($platform) === '/platform-admin')
        ? ppc01Pass('platform_home_path_platform_admin')
        : ppc01Fail('platform_home_path_platform_admin', 'expected /platform-admin');
    ($homeResolver->homePathForUserId($single) === '/dashboard')
        ? ppc01Pass('tenant_home_path_dashboard')
        : ppc01Fail('tenant_home_path_dashboard', 'expected /dashboard');
    ($homeResolver->homePathForUserId($orphan) === '/tenant-entry')
        ? ppc01Pass('blocked_home_path_tenant_entry')
        : ppc01Fail('blocked_home_path_tenant_entry', 'expected /tenant-entry');

    $singleEntry = $tenantEntry->resolveForUser($single);
    (($singleEntry['state'] ?? null) === 'single')
        ? ppc01Pass('tenant_single_entry_state_single')
        : ppc01Fail('tenant_single_entry_state_single', json_encode($singleEntry));
    $multiEntry = $tenantEntry->resolveForUser($multi);
    (($multiEntry['state'] ?? null) === 'multiple')
        ? ppc01Pass('tenant_multi_entry_state_multiple')
        : ppc01Fail('tenant_multi_entry_state_multiple', json_encode($multiEntry));
    $orphanEntry = $tenantEntry->resolveForUser($orphan);
    (($orphanEntry['state'] ?? null) === 'none')
        ? ppc01Pass('blocked_entry_state_none')
        : ppc01Fail('blocked_entry_state_none', json_encode($orphanEntry));

    $allowedSingle = $branchAccess->allowedBranchIdsForUser($single);
    ($allowedSingle === [$branchA])
        ? ppc01Pass('tenant_single_branch_access_stable')
        : ppc01Fail('tenant_single_branch_access_stable', json_encode($allowedSingle));

    $baseLayout = (string) file_get_contents(base_path('shared/layout/base.php'));
    (str_contains($baseLayout, 'PrincipalPlaneResolver::TENANT_PLANE') && str_contains($baseLayout, '$hideNav = true;'))
        ? ppc01Pass('tenant_shell_layout_is_plane_aware')
        : ppc01Fail('tenant_shell_layout_is_plane_aware', 'base layout missing plane-aware nav suppression');

    $platformLayout = (string) file_get_contents(base_path('shared/layout/platform_admin.php'));
    (str_contains($platformLayout, 'aria-label="Platform control plane"') && !str_contains($platformLayout, 'href="/dashboard"'))
        ? ppc01Pass('control_plane_shell_is_dedicated')
        : ppc01Fail('control_plane_shell_is_dedicated', 'platform shell contract not detected');

    $blockedView = (string) file_get_contents(base_path('modules/auth/views/tenant-entry-blocked.php'));
    (str_contains($blockedView, '$hideNav = true;'))
        ? ppc01Pass('blocked_surface_forces_no_shell')
        : ppc01Fail('blocked_surface_forces_no_shell', 'blocked view missing hideNav');
} catch (\Throwable $e) {
    ppc01Fail('script_runtime', $e->getMessage());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
