<?php

declare(strict_types=1);

/**
 * DEV-ONLY: LOGIN-THROTTLE-CANONICALIZATION-16 behavioral checks (Layer A email+IP, Layer B email).
 *
 * Usage (from system/):
 *   php scripts/dev-only/login_throttle_canonicalization_proof.php
 */

use Core\App\Application;
use Core\Auth\LoginThrottleService;

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

/**
 * @return array{0:bool,1:string}
 */
function assertRange(int $value, int $min, int $max, string $label): array
{
    if ($value < $min || $value > $max) {
        return [false, "FAIL {$label}: got {$value}, expected {$min}..{$max}"];
    }

    return [true, "OK {$label}: {$value}"];
}

function aKey(string $emailNorm, string $ip): string
{
    return LoginThrottleService::canonicalLayerAKey($emailNorm, $ip);
}

function bKey(string $emailNorm): string
{
    return LoginThrottleService::canonicalLayerBKey($emailNorm);
}

$email = 'login-throttle-canonicalization-16-proof@example.test';
$e = strtolower(trim($email));
$ip1 = '10.66.16.1';
$ip2 = '10.66.16.2';

$db = Application::container()->get(\Core\App\Database::class);
$throttle = Application::container()->get(LoginThrottleService::class);

$clean = static function () use ($db, $e, $ip1, $ip2): void {
    $keys = [aKey($e, $ip1), aKey($e, $ip2), bKey($e)];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $db->query(
        "DELETE FROM login_attempts WHERE identifier IN ($placeholders) OR identifier = ?",
        [...$keys, $e]
    );
};

$clean();

echo "=== LOGIN-THROTTLE-CANONICALIZATION-16 proof ===\n";

$allOk = true;

// 1) Two wrong attempts do not cause a cooldown
$_SERVER['REMOTE_ADDR'] = $ip1;
for ($i = 0; $i < 2; $i++) {
    $throttle->recordAttempt($e, false);
}
$r2 = $throttle->remainingLockoutSeconds($e);
[$ok, $msg] = assertRange($r2, 0, 0, 'after_2_fails_remaining_sec');
echo $msg . "\n";
$allOk = $allOk && $ok;

// 2) Five failures from same email+IP → tier-1 cooldown (~30s from last failure)
for ($i = 0; $i < 3; $i++) {
    $throttle->recordAttempt($e, false);
}
$r5 = $throttle->remainingLockoutSeconds($e);
[$ok5, $msg5] = assertRange($r5, 1, 30, 'after_5_fails_tier1_remaining_sec');
echo $msg5 . "\n";
$allOk = $allOk && $ok5;

// 3) Another IP is not blocked by the first IP’s low-tier cooldown
$_SERVER['REMOTE_ADDR'] = $ip2;
$rOtherIp = $throttle->remainingLockoutSeconds($e);
[$okIp, $msgIp] = assertRange($rOtherIp, 0, 0, 'different_ip_while_ip1_tier1_remaining_sec');
echo $msgIp . "\n";
$allOk = $allOk && $okIp;

$_SERVER['REMOTE_ADDR'] = $ip1;
$rBack = $throttle->remainingLockoutSeconds($e);
[$okBack, $msgBack] = assertRange($rBack, 1, 30, 'back_to_ip1_still_tier1_remaining_sec');
echo $msgBack . "\n";
$allOk = $allOk && $okBack;

// 4) clearFailures clears active Layer A lock for this client (same as post-success path)
$throttle->clearFailures($e);
$rClear = $throttle->remainingLockoutSeconds($e);
[$okCl, $msgCl] = assertRange($rClear, 0, 0, 'after_clearFailures_remaining_sec');
echo $msgCl . "\n";
$allOk = $allOk && $okCl;

// 5) Success path: failures then success row + clearFailures → no stuck cooldown
for ($i = 0; $i < 5; $i++) {
    $throttle->recordAttempt($e, false);
}
$lockedBefore = $throttle->remainingLockoutSeconds($e);
$throttle->recordAttempt($e, true);
$throttle->clearFailures($e);
$rAfterSuccess = $throttle->remainingLockoutSeconds($e);
[$okS, $msgS] = assertRange($rAfterSuccess, 0, 0, 'after_success_and_clear_remaining_sec');
echo $msgS . " (locked_before_success_sec={$lockedBefore})\n";
$allOk = $allOk && $okS;

// 6) Progressive tier 2: seven failures → ~120s cooldown from last failure
$clean();
$_SERVER['REMOTE_ADDR'] = $ip1;
for ($i = 0; $i < 7; $i++) {
    $throttle->recordAttempt($e, false);
}
$r7 = $throttle->remainingLockoutSeconds($e);
[$ok7, $msg7] = assertRange($r7, 90, 120, 'after_7_fails_tier2_remaining_sec');
echo $msg7 . "\n";
$allOk = $allOk && $ok7;

$clean();

// 7) Heavy failure volume: remaining seconds never exceed canonical policy max (900)
$_SERVER['REMOTE_ADDR'] = $ip1;
for ($i = 0; $i < 20; $i++) {
    $throttle->recordAttempt($e, false);
}
$rHeavy = $throttle->remainingLockoutSeconds($e);
[$okHeavy, $msgHeavy] = assertRange($rHeavy, 0, LoginThrottleService::MAX_REMAINING_LOCKOUT_SEC, 'after_20_fails_remaining_sec_policy_capped');
echo $msgHeavy . "\n";
$allOk = $allOk && $okHeavy;

$clean();

echo 'policy_max_remaining_sec=' . LoginThrottleService::MAX_REMAINING_LOCKOUT_SEC . "\n";
echo 'login_controller_invalid_credentials_flash=Invalid email or password. (unchanged; see LoginController::attempt)' . "\n";
echo 'verdict=' . ($allOk ? 'ALL_CHECKS_PASSED' : 'SOME_CHECKS_FAILED') . "\n";

exit($allOk ? 0 : 1);
