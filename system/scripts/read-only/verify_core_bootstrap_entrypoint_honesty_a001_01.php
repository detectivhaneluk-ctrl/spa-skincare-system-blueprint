<?php

declare(strict_types=1);

/**
 * A-001 read-only: core bootstrap must not register services whose factories need
 * OrganizationContextResolver before that binding exists; gate follows resolver in modules/bootstrap.
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_core_bootstrap_entrypoint_honesty_a001_01.php
 */
$systemRoot = dirname(__DIR__, 2);

function src(string $relativeFromSystem): string
{
    global $systemRoot;

    return (string) file_get_contents($systemRoot . '/' . $relativeFromSystem);
}

$core = src('bootstrap.php');
$modules = src('modules/bootstrap.php');

$checks = [
    'core bootstrap: no factory references OrganizationContextResolver' => !preg_match(
        '/->get\s*\(\s*\\\\Core\\\\Organization\\\\OrganizationContextResolver::class\s*\)/',
        $core
    ),
    'core bootstrap: documents modules/bootstrap requirement' => str_contains($core, 'modules/bootstrap.php'),
    'modules bootstrap: registers OrganizationContextResolver' => str_contains($modules, 'OrganizationContextResolver::class'),
    'modules bootstrap: StaffMultiOrgOrganizationResolutionGate after resolver block' => preg_match(
        '/OrganizationContextResolver::class[\s\S]*StaffMultiOrgOrganizationResolutionGate::class/s',
        $modules
    ) === 1,
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
