<?php

declare(strict_types=1);

/**
 * DEV-ONLY: dump throttle DB state for an email + IP (matches LoginThrottleService queries).
 *
 * Usage (from system/):
 *   php scripts/dev-only/login_throttle_runtime_state_proof.php --email=tenant-admin-a@example.test
 *   php scripts/dev-only/login_throttle_runtime_state_proof.php --email=... --ip=127.0.0.1
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

use Core\Auth\LoginThrottleService;

$email = '';
$ip = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--email=')) {
        $email = strtolower(trim(substr($arg, 8)));
    }
    if (str_starts_with($arg, '--ip=')) {
        $ip = trim(substr($arg, 5));
    }
}

if ($email === '') {
    fwrite(STDERR, "Usage: php login_throttle_runtime_state_proof.php --email=... [--ip=...]\n");
    exit(1);
}

$appEnv = strtolower((string) env('APP_ENV', 'production'));
if ($appEnv !== 'local') {
    fwrite(STDERR, "Refusing: APP_ENV must be local (got {$appEnv}).\n");
    exit(2);
}

if ($ip === '') {
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';
}
if ($ip === '') {
    $ip = '127.0.0.1';
}
$_SERVER['REMOTE_ADDR'] = $ip;

$aKey = LoginThrottleService::canonicalLayerAKey($email, $ip);
$bKey = LoginThrottleService::canonicalLayerBKey($email);

$db = app(\Core\App\Database::class);
$throttle = app(LoginThrottleService::class);

$skew = 120;
$now = time();

$countWindow = static function (\Core\App\Database $db, string $identifier, int $windowSec, int $skewSec): int {
    $row = $db->fetchOne(
        'SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = ? AND success = 0
         AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
         AND created_at <= DATE_ADD(NOW(), INTERVAL ? SECOND)',
        [$identifier, $windowSec, $skewSec]
    );

    return (int) ($row['c'] ?? 0);
};

$lastFail = static function (\Core\App\Database $db, string $identifier, int $windowSec, int $skewSec): ?string {
    $row = $db->fetchOne(
        'SELECT created_at FROM login_attempts WHERE identifier = ? AND success = 0
         AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
         AND created_at <= DATE_ADD(NOW(), INTERVAL ? SECOND)
         ORDER BY created_at DESC LIMIT 1',
        [$identifier, $windowSec, $skewSec]
    );
    if ($row === null || empty($row['created_at'])) {
        return null;
    }

    return (string) $row['created_at'];
};

$futureA = $db->fetchOne(
    'SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = ? AND success = 0 AND created_at > DATE_ADD(NOW(), INTERVAL ? SECOND)',
    [$aKey, $skew]
);
$futureB = $db->fetchOne(
    'SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = ? AND success = 0 AND created_at > DATE_ADD(NOW(), INTERVAL ? SECOND)',
    [$bKey, $skew]
);
$futureLegacy = $db->fetchOne(
    'SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = ? AND success = 0 AND created_at > DATE_ADD(NOW(), INTERVAL ? SECOND)',
    [$email, $skew]
);

$legacyFail = $db->fetchOne(
    'SELECT COUNT(*) AS c FROM login_attempts WHERE identifier = ? AND success = 0',
    [$email]
);

$cA5 = $countWindow($db, $aKey, 300, $skew);
$cA10 = $countWindow($db, $aKey, 600, $skew);
$cA15 = $countWindow($db, $aKey, 900, $skew);
$cB30 = $countWindow($db, $bKey, 1800, $skew);

$remaining = $throttle->remainingLockoutSeconds($email);

echo 'queried_email=' . $email . PHP_EOL;
echo 'effective_ip=' . $ip . PHP_EOL;
echo 'php_time_unix=' . $now . PHP_EOL;
echo 'policy_max_remaining_sec=' . LoginThrottleService::MAX_REMAINING_LOCKOUT_SEC . PHP_EOL;
echo 'sql_window_note=DATE_SUB_DATE_ADD_NOW_matches_LoginThrottleService' . PHP_EOL;
echo 'layer_a_key=' . $aKey . PHP_EOL;
echo 'layer_b_key=' . $bKey . PHP_EOL;
echo 'layer_a_fail_count_300s_window=' . $cA5 . PHP_EOL;
echo 'layer_a_fail_count_600s_window=' . $cA10 . PHP_EOL;
echo 'layer_a_fail_count_900s_window=' . $cA15 . PHP_EOL;
echo 'layer_b_fail_count_1800s_window=' . $cB30 . PHP_EOL;
echo 'layer_a_newest_failure_created_at_in_900s_window=' . ($lastFail($db, $aKey, 900, $skew) ?? 'none') . PHP_EOL;
echo 'layer_b_newest_failure_created_at_in_1800s_window=' . ($lastFail($db, $bKey, 1800, $skew) ?? 'none') . PHP_EOL;
echo 'future_fail_rows_layer_a_beyond_skew=' . (int) ($futureA['c'] ?? 0) . PHP_EOL;
echo 'future_fail_rows_layer_b_beyond_skew=' . (int) ($futureB['c'] ?? 0) . PHP_EOL;
echo 'future_fail_rows_legacy_email_beyond_skew=' . (int) ($futureLegacy['c'] ?? 0) . PHP_EOL;
echo 'raw_legacy_identifier_fail_rows_total=' . (int) ($legacyFail['c'] ?? 0) . PHP_EOL;
echo 'effective_remaining_lockout_seconds=' . $remaining . PHP_EOL;

$conclusion = 'idle';
if ((int) ($futureA['c'] ?? 0) + (int) ($futureB['c'] ?? 0) + (int) ($futureLegacy['c'] ?? 0) > 0) {
    $conclusion = 'future_dated_rows_present_can_inflate_pre_repair_math_service_now_filters_and_caps';
} elseif ($remaining > 0) {
    $conclusion = 'active_lockout_within_policy_cap';
} elseif ((int) ($legacyFail['c'] ?? 0) > 0) {
    $conclusion = 'legacy_raw_email_rows_present_not_used_for_canonical_lockout_but_cleared_by_clear_script';
} else {
    $conclusion = 'no_lockout_no_future_rows';
}
echo 'conclusion=' . $conclusion . PHP_EOL;
