<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\Auth\AuthenticatedHomePathResolver;
use Core\Branch\BranchContext;
use Core\Branch\TenantBranchAccessService;
use Core\Middleware\BranchContextMiddleware;
use Core\Middleware\OrganizationContextMiddleware;
use Core\Organization\OrganizationContext;
use Core\Permissions\PermissionService;
use Modules\Auth\Services\TenantEntryResolverService;

$db = app(\Core\App\Database::class);
$branchAccess = app(TenantBranchAccessService::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$permService = app(PermissionService::class);
$tenantEntryResolver = app(TenantEntryResolverService::class);
$homeResolver = app(AuthenticatedHomePathResolver::class);
$sessionAuth = app(\Core\Auth\SessionAuth::class);
$branchMw = new BranchContextMiddleware();
$orgMw = new OrganizationContextMiddleware();

$passed = 0;
$failed = 0;
function w05Pass(string $name): void { global $passed; $passed++; echo "PASS  {$name}\n"; }
function w05Fail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }

$suffix = 'W05_' . bin2hex(random_bytes(4));
$origServer = $_SERVER;
$origSession = $_SESSION ?? [];
$origBranch = $branchContext->getCurrentBranchId();
$origOrg = $orgContext->getCurrentOrganizationId();
$origMode = $orgContext->getResolutionMode();

$pdo = $db->connection();
$pdo->beginTransaction();

try {
    $db->insert('organizations', ['name' => $suffix . '_Org_A', 'code' => strtolower($suffix . '_A')]);
    $orgA = (int) $db->lastInsertId();
    $db->insert('organizations', ['name' => $suffix . '_Org_B', 'code' => strtolower($suffix . '_B')]);
    $orgB = (int) $db->lastInsertId();

    $db->insert('branches', ['name' => $suffix . '_Branch_A', 'code' => strtolower($suffix . '_BA'), 'organization_id' => $orgA]);
    $branchA = (int) $db->lastInsertId();
    $db->insert('branches', ['name' => $suffix . '_Branch_B', 'code' => strtolower($suffix . '_BB'), 'organization_id' => $orgB]);
    $branchB = (int) $db->lastInsertId();

    $platformFounderRow = $db->fetchOne(
        'SELECT id FROM roles WHERE code = ? AND deleted_at IS NULL LIMIT 1',
        ['platform_founder']
    );
    $platformFounderRoleId = $platformFounderRow !== null ? (int) $platformFounderRow['id'] : 0;
    if ($platformFounderRoleId <= 0) {
        throw new RuntimeException('Missing live platform_founder role required for smoke verification.');
    }

    $db->insert('permissions', ['code' => strtolower($suffix . '.perm'), 'name' => $suffix . ' Permission']);
    $permId = (int) $db->lastInsertId();
    $permCode = strtolower($suffix . '.perm');

    $db->insert('roles', ['code' => strtolower($suffix . '_live_role'), 'name' => $suffix . ' Live Role']);
    $liveRoleId = (int) $db->lastInsertId();
    $db->insert('roles', [
        'code' => strtolower($suffix . '_deleted_role'),
        'name' => $suffix . ' Deleted Role',
        'deleted_at' => date('Y-m-d H:i:s'),
    ]);
    $deletedRoleId = (int) $db->lastInsertId();
    $db->insert('role_permissions', ['role_id' => $liveRoleId, 'permission_id' => $permId]);
    $db->insert('role_permissions', ['role_id' => $deletedRoleId, 'permission_id' => $permId]);

    $mkUser = static function (string $email, ?int $branchId) use ($db, $suffix): int {
        $db->insert('users', [
            'email' => $email,
            'password_hash' => password_hash($suffix . '_pass_123', PASSWORD_DEFAULT),
            'name' => $suffix . ' User',
            'branch_id' => $branchId,
        ]);

        return (int) $db->lastInsertId();
    };

    $uPinnedValid = $mkUser(strtolower($suffix . '_pinned_valid@example.test'), $branchA);
    $uPinnedNoMembership = $mkUser(strtolower($suffix . '_pinned_none@example.test'), $branchA);
    $uPinnedMismatch = $mkUser(strtolower($suffix . '_pinned_mismatch@example.test'), $branchA);
    $uPlatform = $mkUser(strtolower($suffix . '_platform@example.test'), $branchA);
    $uDeletedRoleOnly = $mkUser(strtolower($suffix . '_deleted_role@example.test'), null);
    $uLiveRoleOnly = $mkUser(strtolower($suffix . '_live_role@example.test'), null);
    $uMembershipOnly = $mkUser(strtolower($suffix . '_membership_only@example.test'), null);

    $db->insert('user_organization_memberships', [
        'user_id' => $uPinnedValid,
        'organization_id' => $orgA,
        'status' => 'active',
        'default_branch_id' => $branchA,
    ]);
    $db->insert('user_organization_memberships', [
        'user_id' => $uPinnedMismatch,
        'organization_id' => $orgB,
        'status' => 'active',
        'default_branch_id' => $branchB,
    ]);
    $db->insert('user_organization_memberships', [
        'user_id' => $uMembershipOnly,
        'organization_id' => $orgA,
        'status' => 'active',
        'default_branch_id' => $branchA,
    ]);

    $db->insert('user_roles', ['user_id' => $uPlatform, 'role_id' => $platformFounderRoleId]);
    $db->insert('user_roles', ['user_id' => $uDeletedRoleOnly, 'role_id' => $deletedRoleId]);
    $db->insert('user_roles', ['user_id' => $uLiveRoleOnly, 'role_id' => $liveRoleId]);

    $allowValid = $branchAccess->allowedBranchIdsForUser($uPinnedValid);
    ($allowValid === [$branchA])
        ? w05Pass('pinned_with_valid_membership_allowed')
        : w05Fail('pinned_with_valid_membership_allowed', 'expected [' . $branchA . '], got ' . json_encode($allowValid));

    $allowNone = $branchAccess->allowedBranchIdsForUser($uPinnedNoMembership);
    ($allowNone === [])
        ? w05Pass('pinned_with_zero_memberships_denied')
        : w05Fail('pinned_with_zero_memberships_denied', 'expected [], got ' . json_encode($allowNone));

    $allowMismatch = $branchAccess->allowedBranchIdsForUser($uPinnedMismatch);
    ($allowMismatch === [])
        ? w05Pass('pinned_with_mismatched_membership_denied')
        : w05Fail('pinned_with_mismatched_membership_denied', 'expected [], got ' . json_encode($allowMismatch));

    $allowMembershipOnly = $branchAccess->allowedBranchIdsForUser($uMembershipOnly);
    (in_array($branchA, $allowMembershipOnly, true))
        ? w05Pass('membership_only_user_still_allowed_by_membership')
        : w05Fail('membership_only_user_still_allowed_by_membership', 'expected branch ' . $branchA . ' in ' . json_encode($allowMembershipOnly));

    $simulateContext = static function (int $userId, ?int $sessionBranchId) use ($branchMw, $orgMw, $branchContext, $orgContext): array {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/dashboard';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SESSION['user_id'] = $userId;
        if ($sessionBranchId !== null) {
            $_SESSION['branch_id'] = $sessionBranchId;
        } else {
            unset($_SESSION['branch_id']);
        }

        $branchMw->handle(static fn (): null => null);
        $orgMw->handle(static fn (): null => null);

        return [
            'branch_id' => $branchContext->getCurrentBranchId(),
            'org_id' => $orgContext->getCurrentOrganizationId(),
            'mode' => $orgContext->getResolutionMode(),
            'session_branch_set' => array_key_exists('branch_id', $_SESSION),
        ];
    };

    $ctxValid = $simulateContext($uPinnedValid, $branchA);
    (
        $ctxValid['branch_id'] === $branchA
        && $ctxValid['org_id'] === $orgA
        && $ctxValid['mode'] === OrganizationContext::MODE_BRANCH_DERIVED
    )
        ? w05Pass('tenant_runtime_context_valid_pinned_membership')
        : w05Fail('tenant_runtime_context_valid_pinned_membership', json_encode($ctxValid));

    $ctxNone = $simulateContext($uPinnedNoMembership, $branchA);
    (
        $ctxNone['branch_id'] === null
        && ($ctxNone['mode'] === null || $ctxNone['mode'] !== OrganizationContext::MODE_BRANCH_DERIVED)
    )
        ? w05Pass('tenant_runtime_context_denied_zero_membership')
        : w05Fail('tenant_runtime_context_denied_zero_membership', json_encode($ctxNone));

    $ctxMismatch = $simulateContext($uPinnedMismatch, $branchA);
    (
        $ctxMismatch['branch_id'] === null
        && ($ctxMismatch['mode'] === null || $ctxMismatch['mode'] !== OrganizationContext::MODE_BRANCH_DERIVED)
    )
        ? w05Pass('tenant_runtime_context_denied_mismatched_membership')
        : w05Fail('tenant_runtime_context_denied_mismatched_membership', json_encode($ctxMismatch));

    $ctxPlatform = $simulateContext($uPlatform, $branchA);
    (
        $ctxPlatform['branch_id'] === null
        && $ctxPlatform['session_branch_set'] === false
        && $homeResolver->homePathForUserId($uPlatform) === AuthenticatedHomePathResolver::PATH_PLATFORM
    )
        ? w05Pass('platform_principal_behavior_unchanged')
        : w05Fail('platform_principal_behavior_unchanged', json_encode($ctxPlatform));

    $permService->clearCache();
    $permsDeletedOnly = $permService->getForUser($uDeletedRoleOnly);
    (!in_array($permCode, $permsDeletedOnly, true))
        ? w05Pass('soft_deleted_role_permissions_excluded')
        : w05Fail('soft_deleted_role_permissions_excluded', 'unexpected permission code present: ' . $permCode);

    $permService->clearCache();
    $permsLive = $permService->getForUser($uLiveRoleOnly);
    (in_array($permCode, $permsLive, true))
        ? w05Pass('live_role_permissions_still_granted')
        : w05Fail('live_role_permissions_still_granted', 'expected permission code missing: ' . $permCode);

    $tenantEntryValid = $tenantEntryResolver->resolveForUser($uPinnedValid);
    (($tenantEntryValid['state'] ?? '') === 'single')
        ? w05Pass('tenant_entry_single_branch_regression_green')
        : w05Fail('tenant_entry_single_branch_regression_green', json_encode($tenantEntryValid));

    $tenantEntryNone = $tenantEntryResolver->resolveForUser($uPinnedNoMembership);
    (($tenantEntryNone['state'] ?? '') === 'none')
        ? w05Pass('tenant_entry_none_branch_regression_green')
        : w05Fail('tenant_entry_none_branch_regression_green', json_encode($tenantEntryNone));
} catch (\Throwable $e) {
    w05Fail('script_runtime', $e->getMessage());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SERVER = $origServer;
    $_SESSION = $origSession;
    $branchContext->setCurrentBranchId($origBranch);
    if ($origMode === null) {
        $orgContext->reset();
    } else {
        $orgContext->setFromResolution($origOrg, $origMode);
    }
}

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);

