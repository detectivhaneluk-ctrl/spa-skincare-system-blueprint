<?php

declare(strict_types=1);

/**
 * H-007 read-only proof: scripts under system/scripts/dev-only/ must not resolve bootstrap via
 * dirname(__DIR__) alone (that points at system/scripts/, not system/).
 *
 * Usage (from `system/`): php scripts/read-only/prove_script_bootstrap_path_h007.php
 */

$systemPath = dirname(__DIR__, 2);
$bootstrap = $systemPath . DIRECTORY_SEPARATOR . 'bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "H-007 proof FAILED: canonical bootstrap not found at {$bootstrap}\n");
    exit(1);
}

$devOnly = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dev-only';
if (!is_dir($devOnly)) {
    fwrite(STDERR, "H-007 proof FAILED: dev-only directory missing\n");
    exit(1);
}

$fragile = '/dirname\s*\(\s*__DIR__\s*\)\s*\.\s*[\'"]\//';
$failures = [];

foreach (glob($devOnly . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
    $base = basename($file);
    $src = (string) file_get_contents($file);
    if ($src === '') {
        continue;
    }
    if (preg_match($fragile, $src) === 1) {
        $failures[] = $base;
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'H-007 proof FAILED: fragile dirname(__DIR__) path in: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'H-007 proof OK: no dev-only script uses dirname(__DIR__) string concat for system paths; canonical bootstrap exists.' . PHP_EOL;
exit(0);
