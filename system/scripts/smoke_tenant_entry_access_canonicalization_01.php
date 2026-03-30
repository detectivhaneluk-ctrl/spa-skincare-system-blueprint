<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\Auth\AuthenticatedHomePathResolver;
use Core\Auth\PrincipalAccessService;
use Core\Branch\TenantBranchAccessService;
use Modules\Auth\Services\TenantEntryResolverService;

$db = app(\Core\App\Database::class);
$branchAccess = app(TenantBranchAccessService::class);
$tenantEntryResolver = app(TenantEntryResolverService::class);
$homeResolver = app(AuthenticatedHomePathResolver::class);
$principalAccess = app(PrincipalAccessService::class);

$passed = 0;
$failed = 0;
function teacPass(string $name): void { global $passed; $passed++; echo "PASS  {$name}\n"; }
function teacFail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }

$pdo = $db->connection();
$pdo->beginTransaction();

try {
    $suffix = 'TEAC_' . bin2hex(random_bytes(4));

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

    $platformRoleRow = $db->fetchOne('SELECT id FROM roles WHERE code = ? AND deleted_at IS NULL LIMIT 1', ['platform_founder']);
    $adminRoleRow = $db->fetchOne('SELECT id FROM roles WHERE code = ? AND deleted_at IS NULL LIMIT 1', ['admin']);
    if ($platformRoleRow === null || $adminRoleRow === null) {
        throw new RuntimeException('Missing required roles: platform_founder and admin');
    }
    $platformRoleId = (int) $platformRoleRow['id'];
    $adminRoleId = (int) $adminRoleRow['id'];

    $mkUser = static function (string $email, ?int $branchId) use ($db, $suffix): int {
        $db->insert('users', [
            'email' => $email,
            'password_hash' => password_hash($suffix . '_pass_123', PASSWORD_DEFAULT),
            'name' => $suffix . ' User',
            'branch_id' => $branchId,
        ]);

        return (int) $db->lastInsertId();
    };

    $platformUser = $mkUser(strtolower($suffix . '_platform@example.test'), null);
    $branchAUser = $mkUser(strtolower($suffix . '_brancha@example.test'), $branchA);
    $branchBUser = $mkUser(strtolower($suffix . '_branchb@example.test'), $branchB);
    $multiUser = $mkUser(strtolower($suffix . '_multi@example.test'), null);
    $orphanUser = $mkUser(strtolower($suffix . '_orphan@example.test'), null);
    $legacySafeUser = $mkUser(strtolower($suffix . '_legacy_safe@example.test'), $branchA);
    $legacyAmbiguousUser = $mkUser(strtolower($suffix . '_legacy_ambiguous@example.test'), $branchA);

    $db->insert('user_roles', ['user_id' => $platformUser, 'role_id' => $platformRoleId]);
    foreach ([$branchAUser, $branchBUser, $multiUser, $orphanUser, $legacySafeUser, $legacyAmbiguousUser] as $uid) {
        $db->insert('user_roles', ['user_id' => $uid, 'role_id' => $adminRoleId]);
    }

    $db->insert('user_organization_memberships', [
        'user_id' => $branchAUser,
        'organization_id' => $orgA,
        'status' => 'active',
        'default_branch_id' => $branchA,
    ]);
    $db->insert('user_organization_memberships', [
        'user_id' => $branchBUser,
        'organization_id' => $orgA,
        'status' => 'active',
        'default_branch_id' => $branchB,
    ]);
    $db->insert('user_organization_memberships', [
        'user_id' => $multiUser,
        'organization_id' => $orgA,
        'status' => 'active',
        'default_branch_id' => $branchA,
    ]);
    $db->insert('user_organization_memberships', [
        'user_id' => $multiUser,
        'organization_id' => $orgB,
        'status' => 'active',
        'default_branch_id' => $branchC,
    ]);
    $db->insert('user_organization_memberships', [
        'user_id' => $legacyAmbiguousUser,
        'organization_id' => $orgB,
        'status' => 'active',
        'default_branch_id' => $branchC,
    ]);

    // 1) platform-smoke login shape equivalent: platform principal and explicit home path.
    ($principalAccess->isPlatformPrincipal($platformUser) === true)
        ? teacPass('platform_principal_classified')
        : teacFail('platform_principal_classified', 'expected true');
    ($homeResolver->homePathForUserId($platformUser) === AuthenticatedHomePathResolver::PATH_PLATFORM)
        ? teacPass('platform_home_path_platform_admin')
        : teacFail('platform_home_path_platform_admin', 'expected /platform-admin');

    // 2) branchA single-branch tenant access.
    $branchAAllowed = $branchAccess->allowedBranchIdsForUser($branchAUser);
    ($branchAAllowed === [$branchA])
        ? teacPass('branchA_exactly_one_allowed_branch')
        : teacFail('branchA_exactly_one_allowed_branch', json_encode($branchAAllowed));
    $branchAEntry = $tenantEntryResolver->resolveForUser($branchAUser);
    (($branchAEntry['state'] ?? null) === 'single' && (int) ($branchAEntry['branch_id'] ?? 0) === $branchA)
        ? teacPass('branchA_tenant_entry_single')
        : teacFail('branchA_tenant_entry_single', json_encode($branchAEntry));
    ($branchAccess->defaultAllowedBranchIdForUser($branchAUser) === $branchA)
        ? teacPass('branchA_default_branch_stable')
        : teacFail('branchA_default_branch_stable', 'default mismatch');

    // 3) branchB single-branch tenant access.
    $branchBAllowed = $branchAccess->allowedBranchIdsForUser($branchBUser);
    ($branchBAllowed === [$branchB])
        ? teacPass('branchB_exactly_one_allowed_branch')
        : teacFail('branchB_exactly_one_allowed_branch', json_encode($branchBAllowed));
    $branchBEntry = $tenantEntryResolver->resolveForUser($branchBUser);
    (($branchBEntry['state'] ?? null) === 'single' && (int) ($branchBEntry['branch_id'] ?? 0) === $branchB)
        ? teacPass('branchB_tenant_entry_single')
        : teacFail('branchB_tenant_entry_single', json_encode($branchBEntry));

    // 4) tenant-multi chooser access.
    $multiEntry = $tenantEntryResolver->resolveForUser($multiUser);
    $multiAllowed = $branchAccess->allowedBranchIdsForUser($multiUser);
    sort($multiAllowed);
    $expectedMulti = [$branchA, $branchB, $branchC];
    sort($expectedMulti);
    (($multiEntry['state'] ?? null) === 'multiple')
        ? teacPass('tenant_multi_returns_multiple_state')
        : teacFail('tenant_multi_returns_multiple_state', json_encode($multiEntry));
    ($multiAllowed === $expectedMulti)
        ? teacPass('tenant_multi_allowed_branch_ids_match_memberships')
        : teacFail('tenant_multi_allowed_branch_ids_match_memberships', json_encode($multiAllowed));

    // 5) tenant-orphan blocked.
    $orphanEntry = $tenantEntryResolver->resolveForUser($orphanUser);
    (($orphanEntry['state'] ?? null) === 'none')
        ? teacPass('tenant_orphan_blocked_none')
        : teacFail('tenant_orphan_blocked_none', json_encode($orphanEntry));

    // 6) legacy drift repair preflight classification + safe/ambiguous split.
    $legacySafeAllowed = $branchAccess->allowedBranchIdsForUser($legacySafeUser);
    ($legacySafeAllowed === [])
        ? teacPass('legacy_safe_preflight_currently_blocked')
        : teacFail('legacy_safe_preflight_currently_blocked', json_encode($legacySafeAllowed));
    $legacyAmbiguousAllowed = $branchAccess->allowedBranchIdsForUser($legacyAmbiguousUser);
    ($legacyAmbiguousAllowed === [])
        ? teacPass('legacy_ambiguous_preflight_blocked')
        : teacFail('legacy_ambiguous_preflight_blocked', json_encode($legacyAmbiguousAllowed));

    // Safe deterministic backfill simulation.
    $db->insert('user_organization_memberships', [
        'user_id' => $legacySafeUser,
        'organization_id' => $orgA,
        'status' => 'active',
        'default_branch_id' => $branchA,
    ]);
    $legacySafeAfter = $branchAccess->allowedBranchIdsForUser($legacySafeUser);
    ($legacySafeAfter === [$branchA])
        ? teacPass('legacy_safe_case_backfillable')
        : teacFail('legacy_safe_case_backfillable', json_encode($legacySafeAfter));
    $legacyAmbiguousAfter = $branchAccess->allowedBranchIdsForUser($legacyAmbiguousUser);
    ($legacyAmbiguousAfter === [])
        ? teacPass('legacy_ambiguous_case_manual_review')
        : teacFail('legacy_ambiguous_case_manual_review', json_encode($legacyAmbiguousAfter));

    // 7) single-branch tenant home path resolves to dashboard (canonical post-login).
    ($homeResolver->homePathForUserId($branchAUser) === AuthenticatedHomePathResolver::PATH_DASHBOARD)
        ? teacPass('tenant_home_path_dashboard_for_single_branch')
        : teacFail('tenant_home_path_dashboard_for_single_branch', 'expected /dashboard for single-branch tenant');
} catch (\Throwable $e) {
    teacFail('script_runtime', $e->getMessage());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
