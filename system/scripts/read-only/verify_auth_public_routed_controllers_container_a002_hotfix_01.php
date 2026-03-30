<?php

declare(strict_types=1);

/**
 * A-002 hotfix read-only: auth/public array-handled controllers in register_core_dashboard_auth_public.php
 * must be container-singletons in register_organizations.php (Dispatcher resolves controllers only via container).
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_auth_public_routed_controllers_container_a002_hotfix_01.php
 */
$systemRoot = dirname(__DIR__, 2);
$routes = (string) file_get_contents($systemRoot . '/routes/web/register_core_dashboard_auth_public.php');
$orgReg = (string) file_get_contents($systemRoot . '/modules/bootstrap/register_organizations.php');

$checks = [
    'routes: LoginController wired' => str_contains($routes, 'Modules\\Auth\\Controllers\\LoginController::class'),
    'bootstrap: A-002 hotfix marker comment' => str_contains($orgReg, 'A-002 hotfix'),
];

foreach (['LoginController', 'PasswordResetController', 'AccountPasswordController', 'TenantEntryController', 'BranchContextController'] as $short) {
    $needle = '\\Modules\\Auth\\Controllers\\' . $short . '::class, static fn ()';
    $checks['register_organizations: ' . $short . ' one-line singleton'] = str_contains($orgReg, $needle);
}

$checks['register_organizations: SupportEntryController (pre-existing)'] = str_contains(
    $orgReg,
    '\\Modules\\Auth\\Controllers\\SupportEntryController::class,'
) || str_contains($orgReg, '\Modules\Auth\Controllers\SupportEntryController::class,');

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
