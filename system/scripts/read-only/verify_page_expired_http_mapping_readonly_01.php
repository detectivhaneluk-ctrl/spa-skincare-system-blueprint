<?php

declare(strict_types=1);

/**
 * API-419 — static check: PAGE_EXPIRED maps to HTTP 419 in {@see \Core\App\Response::codeToHttp}.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_page_expired_http_mapping_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$responsePath = $system . '/core/app/Response.php';
$src = is_file($responsePath) ? (string) file_get_contents($responsePath) : '';

$ok = $src !== '' && str_contains($src, "'PAGE_EXPIRED' => 419");

echo 'Response::codeToHttp includes PAGE_EXPIRED => 419: ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;

if (!$ok) {
    fwrite(STDERR, 'FAILED: PAGE_EXPIRED must map to HTTP 419 in system/core/app/Response.php' . PHP_EOL);
    exit(1);
}

exit(0);
