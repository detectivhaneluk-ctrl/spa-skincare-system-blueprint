<?php

declare(strict_types=1);

/**
 * DEV-ONLY: remove stuck login throttle rows for one email (+ optional IP for Layer A).
 *
 * Usage (from system/):
 *   php scripts/dev-only/clear_login_throttle_for_email.php --email=user@example.test
 *   php scripts/dev-only/clear_login_throttle_for_email.php --email=user@example.test --ip=192.168.1.10
 *
 * Default --ip=127.0.0.1 when omitted (typical local browser to Laragon).
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

use Core\Auth\LoginThrottleService;

$email = '';
$ip = '127.0.0.1';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--email=')) {
        $email = strtolower(trim(substr($arg, 8)));
    }
    if (str_starts_with($arg, '--ip=')) {
        $ip = trim(substr($arg, 5));
    }
}

if ($email === '') {
    fwrite(STDERR, "Usage: php clear_login_throttle_for_email.php --email=... [--ip=127.0.0.1]\n");
    exit(1);
}

$appEnv = strtolower((string) env('APP_ENV', 'production'));
if ($appEnv !== 'local') {
    fwrite(STDERR, "Refusing: APP_ENV must be local (got {$appEnv}).\n");
    exit(2);
}

$aKey = LoginThrottleService::canonicalLayerAKey($email, $ip);
$bKey = LoginThrottleService::canonicalLayerBKey($email);

$db = app(\Core\App\Database::class);

$countFor = static function (string $identifier) use ($db): int {
    $row = $db->fetchOne(
        'SELECT COUNT(*) AS c FROM login_attempts WHERE success = 0 AND identifier = ?',
        [$identifier]
    );

    return (int) ($row['c'] ?? 0);
};

$cA = $countFor($aKey);
$cB = $countFor($bKey);
$cLegacy = $countFor($email);

echo 'queried_email=' . $email . PHP_EOL;
echo 'effective_ip=' . $ip . PHP_EOL;
echo 'layer_a_key=' . $aKey . PHP_EOL;
echo 'layer_b_key=' . $bKey . PHP_EOL;
echo 'before_layer_a_fail_rows=' . $cA . PHP_EOL;
echo 'before_layer_b_fail_rows=' . $cB . PHP_EOL;
echo 'before_legacy_raw_email_fail_rows=' . $cLegacy . PHP_EOL;

if ($cA + $cB + $cLegacy === 0) {
    echo 'removed_total=0' . PHP_EOL;
    exit(0);
}

$db->query(
    'DELETE FROM login_attempts WHERE success = 0 AND (identifier = ? OR identifier = ? OR identifier = ?)',
    [$aKey, $bKey, $email]
);

$total = $cA + $cB + $cLegacy;
echo 'removed_layer_a_fail_rows=' . $cA . PHP_EOL;
echo 'removed_layer_b_fail_rows=' . $cB . PHP_EOL;
echo 'removed_legacy_raw_email_fail_rows=' . $cLegacy . PHP_EOL;
echo 'removed_total=' . $total . PHP_EOL;
