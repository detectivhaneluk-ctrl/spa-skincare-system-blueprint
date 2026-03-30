<?php

declare(strict_types=1);

/**
 * Read-only proof: LIFECYCLE-SUSPENSION-HARDENING-WAVE-01 wiring and branch-access filters.
 *
 * Run: php system/scripts/read-only/verify_lifecycle_suspension_hardening_wave_01_readonly.php
 */

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';

$ok = true;
$fail = static function (string $m) use (&$ok): void {
    fwrite(STDERR, "FAIL: {$m}\n");
    $ok = false;
};

$t = static function (string $path): string {
    $full = dirname(__DIR__, 2) . '/' . ltrim($path, '/');

    return is_readable($full) ? (string) file_get_contents($full) : '';
};

$enf = $t('core/tenant/TenantRuntimeContextEnforcer.php');
if ($enf === '') {
    $fail('missing TenantRuntimeContextEnforcer.php');
} else {
    foreach ([
        'isOrganizationActive($orgId)',
        'isTenantUserInactiveStaffAtBranch',
        'denyInactiveActor',
        'TENANT_ACTOR_INACTIVE',
        'TENANT_ORGANIZATION_SUSPENDED',
    ] as $needle) {
        if (!str_contains($enf, $needle)) {
            $fail('TenantRuntimeContextEnforcer missing: ' . $needle);
        }
    }
}

$gate = $t('core/Organization/OrganizationLifecycleGate.php');
if ($gate === '') {
    $fail('missing OrganizationLifecycleGate.php');
} elseif (!str_contains($gate, 'function isTenantUserInactiveStaffAtBranch')) {
    $fail('OrganizationLifecycleGate missing isTenantUserInactiveStaffAtBranch');
}

$authMw = $t('core/middleware/AuthMiddleware.php');
if ($authMw === '' || !str_contains($authMw, 'enforceForAuthenticatedUser((int) ($user[\'id\'] ?? 0))')) {
    $fail('AuthMiddleware must call TenantRuntimeContextEnforcer::enforceForAuthenticatedUser');
}

$bcc = $t('modules/auth/controllers/BranchContextController.php');
if ($bcc === '' || !str_contains($bcc, 'isBranchLinkedToSuspendedOrganization')) {
    $fail('BranchContextController must fail-closed on suspended org for POST /account/branch-context');
}

$tba = $t('core/Branch/TenantBranchAccessService.php');
if ($tba === '') {
    $fail('missing TenantBranchAccessService.php');
} else {
    $c = substr_count($tba, 'o.suspended_at IS NULL');
    if ($c < 4) {
        $fail('TenantBranchAccessService expected at least 4 org suspended_at IS NULL filters, got ' . (string) $c);
    }
}

$routesDir = $systemPath . '/routes';
$routeFiles = glob($routesDir . '/web/*.php') ?: [];
$tenantProtected = 0;
foreach ($routeFiles as $rf) {
    $c = (string) file_get_contents($rf);
    if (str_contains($c, 'TenantProtectedRouteMiddleware::class') && str_contains($c, 'AuthMiddleware::class')) {
        $tenantProtected++;
    }
}
if ($tenantProtected < 1) {
    $fail('expected at least one route registrar referencing AuthMiddleware + TenantProtectedRouteMiddleware');
}

$map = $t('docs/LIFECYCLE-SUSPENSION-HARDENING-WAVE-01-COVERAGE-MAP.md');
if ($map === '' || !str_contains($map, 'LIFECYCLE-SUSPENSION-HARDENING-WAVE-01')) {
    $fail('coverage map doc missing or empty');
}

if (!$ok) {
    exit(1);
}

echo "PASS: lifecycle_suspension_hardening_wave_01_readonly\n";
exit(0);
