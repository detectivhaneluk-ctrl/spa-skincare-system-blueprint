<?php

declare(strict_types=1);

/**
 * Read-only: bootstraps the app, touches {@see \Core\Auth\SessionAuth} once (starts session), then prints
 * masked {@code session.save_handler} / {@code session.save_path} for ops proof.
 *
 * From repository root:
 *   php system/scripts/read-only/verify_session_ini_after_bootstrap_readonly_01.php
 *
 * Exit 0 always unless bootstrap throws.
 */

$systemPath = realpath(dirname(__DIR__, 2));
if ($systemPath === false) {
    fwrite(STDERR, "Could not resolve system path.\n");
    exit(1);
}

require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$cfg = \Core\App\Application::config('session', []);
if (!is_array($cfg)) {
    $cfg = [];
}

\Core\App\Application::container()->get(\Core\Auth\SessionAuth::class);

$snap = \Core\Runtime\Session\SessionBackendConfigurator::describeRuntime($cfg);
$snap['ini_save_handler'] = (string) ini_get('session.save_handler');
$snap['ini_save_path_masked'] = \Core\Runtime\Session\SessionBackendConfigurator::maskSavePath((string) ini_get('session.save_path'));
$snap['session_status'] = session_status();

echo json_encode($snap, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
echo "verify_session_ini_after_bootstrap_readonly_01: OK\n";
exit(0);
