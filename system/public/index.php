<?php

declare(strict_types=1);

/**
 * DEPLOYMENT-DOCROOT-CANONICAL-PUBLIC-ENTRY-MARKER-01 — canonical production HTTP entry; DocumentRoot must be this directory (`system/public`).
 * Do not expose the repository root or `system/` as the web root.
 * @see system/docs/DEPLOYMENT-DOCROOT-EXPOSURE-HARDENING-01.md
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$app = new \Core\App\Application($systemPath);
$app->run();
