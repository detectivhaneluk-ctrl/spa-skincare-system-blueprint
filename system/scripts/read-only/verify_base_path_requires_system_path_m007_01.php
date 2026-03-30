<?php

declare(strict_types=1);

/**
 * M-007: base_path() must not silently resolve relative to system/core when SYSTEM_PATH is missing.
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_base_path_requires_system_path_m007_01.php
 */
$systemRoot = dirname(__DIR__, 2);
$helpers = (string) file_get_contents($systemRoot . '/core/app/helpers.php');

$checks = [
    'helpers base_path guards SYSTEM_PATH' => str_contains($helpers, "defined('SYSTEM_PATH')")
        && str_contains($helpers, 'SYSTEM_PATH ===')
        && str_contains($helpers, 'RuntimeException'),
    'helpers documents M-007 and removed core fallback' => str_contains($helpers, 'M-007')
        && str_contains($helpers, 'fallback'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
