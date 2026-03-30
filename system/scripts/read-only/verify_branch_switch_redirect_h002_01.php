<?php

declare(strict_types=1);

/**
 * H-002: branch-switch open redirect hardening — static + behavioral checks.
 */
$system = dirname(__DIR__, 2);
require_once $system . '/core/http/SafeInternalRedirectPath.php';

use Core\Http\SafeInternalRedirectPath;

$branchController = (string) file_get_contents($system . '/modules/auth/controllers/BranchContextController.php');
$checks = [
    'BranchContextController uses SafeInternalRedirectPath::normalize' => str_contains($branchController, 'SafeInternalRedirectPath::normalize'),
    'BranchContextController has no http/https-only redirect guard' => !str_contains($branchController, "str_starts_with(\$redirect, 'http://')"),
    'autoload maps Core\\Http\\' => str_contains(
        (string) file_get_contents($system . '/core/app/autoload.php'),
        "'Core\\\\Http\\\\'"
    ),
];

$behavior = [
    '//evil.example/path' => SafeInternalRedirectPath::DEFAULT_PATH,
    '////evil.example' => SafeInternalRedirectPath::DEFAULT_PATH,
    'http://evil.example/' => SafeInternalRedirectPath::DEFAULT_PATH,
    'https://evil.example/x' => SafeInternalRedirectPath::DEFAULT_PATH,
    'javascript:alert(1)' => SafeInternalRedirectPath::DEFAULT_PATH,
    '\\/\\/evil.example' => SafeInternalRedirectPath::DEFAULT_PATH,
    '/%2f%2fevil.example' => SafeInternalRedirectPath::DEFAULT_PATH,
    '/sales/invoices' => '/sales/invoices',
    '/appointments?branch_id=1' => '/appointments?branch_id=1',
    '/dashboard' => '/dashboard',
    '' => SafeInternalRedirectPath::DEFAULT_PATH,
];

foreach ($behavior as $in => $expected) {
    $got = SafeInternalRedirectPath::normalize($in);
    $label = 'normalize(' . json_encode($in, JSON_UNESCAPED_SLASHES) . ')';
    $checks[$label] = $got === $expected;
}

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
