<?php

declare(strict_types=1);

/**
 * DEPLOYMENT-DOCROOT-DEV-ENTRY-MARKER-01 — Local / dev convenience only when the vhost DocumentRoot is this repository folder.
 * Production MUST set DocumentRoot to `system/public` (see system/docs/DEPLOYMENT-DOCROOT-EXPOSURE-HARDENING-01.md).
 * This file does not prevent static file exposure of sibling paths if the server is misconfigured.
 * Root `.htaccess` adds defense-in-depth 403s for sensitive top-level paths when mod_rewrite is enabled; Nginx operators must deny those paths explicitly.
 *
 * DEPLOYMENT-DOCROOT-ROOT-INDEX-PRODUCTION-BLOCK-MARKER-01 — when APP_ENV is production|prod, refuse HTTP via this entry so operators must use system/public as DocumentRoot.
 */
$systemPath = __DIR__ . '/system';
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $env = strtolower(trim((string) env('APP_ENV', '')));
    if ($env === 'production' || $env === 'prod') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "503 Service Unavailable: repository root must not be the production web entry. Set DocumentRoot (or Nginx root) to system/public only. Current APP_ENV disallows this bootstrap path.\n";
        exit(1);
    }
}

$app = new \Core\App\Application($systemPath);
$app->run();
