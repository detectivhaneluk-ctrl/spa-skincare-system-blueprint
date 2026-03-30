<?php

declare(strict_types=1);

/**
 * DEV-ONLY: prove runtime env file, DB user row shape, password_verify, throttle (no raw password/hash output).
 *
 * Usage (from system/):
 *   php scripts/dev-only/login_failure_runtime_truth_proof.php --email=user@example.com
 *   php scripts/dev-only/login_failure_runtime_truth_proof.php --email=user@example.com --password=SECRET
 *
 * --password: optional; if omitted, password_verify line is skipped (prints password_verify=skipped).
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

$email = '';
$testPassword = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--email=')) {
        $email = strtolower(trim(substr($arg, 8)));
    }
    if (str_starts_with($arg, '--password=')) {
        $testPassword = substr($arg, 11);
    }
}

if ($email === '') {
    fwrite(STDERR, "Usage: php login_failure_runtime_truth_proof.php --email=... [--password=...]\n");
    exit(1);
}

$appEnv = strtolower((string) env('APP_ENV', 'production'));
if ($appEnv !== 'local') {
    fwrite(STDERR, "Refusing to run: APP_ENV must be local for this dev-only script (current APP_ENV={$appEnv}).\n");
    exit(2);
}

$loaded = \Core\App\Env::loadedEnvFilePaths();
echo 'env_files_loaded=' . ($loaded === [] ? 'none' : implode('|', $loaded)) . PHP_EOL;
echo 'APP_ENV=' . (string) env('APP_ENV', '') . PHP_EOL;
echo 'DB_HOST=' . (string) env('DB_HOST', '') . PHP_EOL;
echo 'DB_PORT=' . (string) env('DB_PORT', '') . PHP_EOL;
echo 'DB_DATABASE=' . (string) env('DB_DATABASE', '') . PHP_EOL;
echo 'DB_USERNAME=' . (string) env('DB_USERNAME', '') . PHP_EOL;
echo 'queried_email=' . $email . PHP_EOL;

$db = app(\Core\App\Database::class);
$row = $db->fetchOne(
    'SELECT id, email, branch_id, deleted_at, password_hash FROM users WHERE email = ?',
    [$email]
);
/** Same row set {@see \Core\Auth\AuthService::attempt()} uses for login. */
$authRow = $db->fetchOne(
    'SELECT id, password_hash FROM users WHERE email = ? AND deleted_at IS NULL',
    [$email]
);

if ($row === null) {
    echo 'user_row_exists=no' . PHP_EOL;
    echo 'user_id=n/a' . PHP_EOL;
    echo 'deleted_at=NULL' . PHP_EOL;
    echo 'deleted=n/a' . PHP_EOL;
    echo 'auth_lookup_row_exists=no' . PHP_EOL;
    echo 'hash_present=no' . PHP_EOL;
    echo 'hash_prefix=unknown' . PHP_EOL;
    echo 'password_verify=n/a' . PHP_EOL;
    $hash = '';
} else {
    echo 'user_row_exists=yes' . PHP_EOL;
    echo 'user_id=' . (int) $row['id'] . PHP_EOL;
    $delRaw = $row['deleted_at'];
    $delStr = $delRaw !== null && $delRaw !== '' ? (string) $delRaw : 'NULL';
    echo 'deleted_at=' . $delStr . PHP_EOL;
    echo 'deleted=' . ($delRaw !== null && $delRaw !== '' ? 'yes' : 'no') . PHP_EOL;
    echo 'auth_lookup_row_exists=' . ($authRow !== null ? 'yes' : 'no') . PHP_EOL;
    $hash = (string) ($row['password_hash'] ?? '');
    $hashLen = strlen($hash);
    echo 'hash_present=' . ($hashLen > 0 ? 'yes' : 'no') . PHP_EOL;
    if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$')) {
        echo 'hash_prefix=bcrypt' . PHP_EOL;
    } elseif (str_starts_with($hash, '$argon2')) {
        echo 'hash_prefix=argon2' . PHP_EOL;
    } elseif ($hashLen === 0) {
        echo 'hash_prefix=empty' . PHP_EOL;
    } else {
        echo 'hash_prefix=unknown' . PHP_EOL;
    }
    echo 'hash_length=' . $hashLen . PHP_EOL;

    if ($delRaw !== null && $delRaw !== '') {
        echo 'password_verify=skipped_deleted' . PHP_EOL;
    } elseif ($testPassword === null) {
        echo 'password_verify=skipped_no_password_arg' . PHP_EOL;
    } else {
        echo 'password_verify=' . (password_verify($testPassword, $hash) ? 'yes' : 'no') . PHP_EOL;
    }
}

$throttle = app(\Core\Auth\LoginThrottleService::class);
$secs = $throttle->remainingLockoutSeconds($email);
echo 'throttle_policy_max_remaining_sec=' . \Core\Auth\LoginThrottleService::MAX_REMAINING_LOCKOUT_SEC . PHP_EOL;
echo 'throttle_remaining_lockout_seconds=' . $secs . PHP_EOL;
echo 'throttle_note=CLI_REMOTE_ADDR_may_differ_from_browser_so_lockout_may_not_match_http' . PHP_EOL;

$throttleBlocks = $secs > 0;
$authWouldVerify = false;
if ($row !== null && ($row['deleted_at'] === null || $row['deleted_at'] === '') && $testPassword !== null) {
    $authWouldVerify = password_verify($testPassword, (string) ($row['password_hash'] ?? ''));
}

$sessionLoginWouldRun = !$throttleBlocks && $authWouldVerify;
echo 'session_creation_reached_per_auth_service=' . ($sessionLoginWouldRun ? 'yes' : 'no') . PHP_EOL;
echo 'session_note=HTTP_may_still_fail_if_cookie_params_mismatch;_CLI_only_models_Core\\Auth\\AuthService_after_throttle' . PHP_EOL;

$conclusion = 'unknown';
if ($throttleBlocks) {
    $conclusion = 'throttle_lockout_(check_message_differs_in_LoginController_vs_invalid_credentials)';
} elseif ($row === null) {
    $conclusion = 'user_row_missing_wrong_db_or_never_seeded';
} elseif ($row['deleted_at'] !== null && $row['deleted_at'] !== '') {
    $conclusion = 'user_soft_deleted_auth_query_returns_no_row';
} elseif ($testPassword === null) {
    $conclusion = 'pass_--password_to_test_hash_mismatch';
} elseif (!$authWouldVerify) {
    $conclusion = 'password_hash_mismatch_or_empty_hash';
} else {
    $conclusion = 'credentials_ok_if_login_still_fails_investigate_http_session_cookie_or_post_login_gates';
}
echo 'conclusion=' . $conclusion . PHP_EOL;
