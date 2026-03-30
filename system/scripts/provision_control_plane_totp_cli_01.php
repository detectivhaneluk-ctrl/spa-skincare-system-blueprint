<?php

declare(strict_types=1);

/**
 * CLI-only enrollment for control-plane TOTP (no HTTP UI).
 *
 * Usage (from repository root, after bootstrap + migrations):
 *   php system/scripts/provision_control_plane_totp_cli_01.php <user_id> <BASE32_SECRET>
 *
 * Requires APP_KEY in environment for secret encryption.
 */

$system = dirname(__DIR__);
require $system . '/bootstrap.php';
require $system . '/modules/bootstrap.php';

$args = array_slice($argv, 1);
if (count($args) < 2) {
    fwrite(STDERR, "Usage: php provision_control_plane_totp_cli_01.php <user_id> <BASE32_SECRET>\n");
    exit(2);
}

$userId = (int) $args[0];
$secret = (string) $args[1];
if ($userId <= 0) {
    fwrite(STDERR, "Invalid user id.\n");
    exit(2);
}

$svc = \Core\App\Application::container()->get(\Modules\Organizations\Services\ControlPlaneTotpService::class);
try {
    $svc->enrollBase32Secret($userId, $secret);
} catch (Throwable $e) {
    fwrite(STDERR, 'Enrollment failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "control_plane_totp: enrolled user {$userId} (TOTP enabled).\n";
exit(0);
