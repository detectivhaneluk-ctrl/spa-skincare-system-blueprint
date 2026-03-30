<?php

declare(strict_types=1);

/**
 * CANONICAL-CLIENT-IP-SECURITY-UNIFICATION-01 — forbid raw superglobal REMOTE_ADDR reads outside the canonical resolver.
 *
 * Approved implementation: {@see \Core\App\ClientIp::forRequest()} (trusted-proxy aware via `app.trusted_proxies`).
 *
 * Allowlisted:
 * - `system/core/app/ClientIp.php` (reads `REMOTE_ADDR` / forwarded headers only here)
 * - `system/scripts/**` (dev/ops proofs may mutate `$_SERVER` for CLI harnesses)
 *
 * From repository root:
 *   php system/scripts/read-only/verify_canonical_client_ip_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$systemRoot = realpath(dirname(__DIR__, 2));
if ($systemRoot === false) {
    fwrite(STDERR, "FAIL: could not resolve system/ root.\n");
    exit(1);
}

$allowedRealPath = realpath($systemRoot . '/core/app/ClientIp.php');

$patterns = [
    '/\$_SERVER\s*\[\s*[\'"]REMOTE_ADDR[\'"]\s*\]/',
    '/isset\s*\(\s*\$_SERVER\s*\[\s*[\'"]REMOTE_ADDR[\'"]\s*\]\s*\)/',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($systemRoot, FilesystemIterator::SKIP_DOTS)
);

$failures = [];

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $real = realpath($path);
    if ($real === false || ($allowedRealPath !== false && $real === $allowedRealPath)) {
        continue;
    }
    $content = (string) file_get_contents($path);
    $rel = str_replace('\\', '/', substr($path, strlen($systemRoot)));
    foreach ($patterns as $re) {
        if (preg_match($re, $content) === 1) {
            $failures[] = "Raw REMOTE_ADDR access in {$rel} (use Core\\App\\ClientIp::forRequest())";
            break;
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $f) {
        fwrite(STDERR, 'FAIL: ' . $f . "\n");
    }
    exit(1);
}

echo "CANONICAL-CLIENT-IP-01: pass — no raw REMOTE_ADDR via superglobal outside ClientIp.php; scripts/ skipped.\n";
exit(0);
