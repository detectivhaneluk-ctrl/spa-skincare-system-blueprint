<?php

declare(strict_types=1);

/**
 * FOUNDATION-DISTRIBUTED-RUNTIME-SESSIONS-QUEUE-STORAGE-02 — session backend config + session epoch wiring truth.
 *
 *   php system/scripts/read-only/verify_session_backend_and_session_epoch_readonly_02.php
 */

$root = dirname(__DIR__, 2);
$fail = [];

$sessionCfg = $root . '/config/session.php';
if (!is_readable($sessionCfg)) {
    $fail[] = 'Missing system/config/session.php';
} else {
    $c = (string) file_get_contents($sessionCfg);
    if (!str_contains($c, 'SESSION_DRIVER') || !str_contains($c, 'redis')) {
        $fail[] = 'session.php should document SESSION_DRIVER and redis';
    }
}

$m123 = $root . '/data/migrations/123_users_session_version_logout_all_foundation.sql';
if (!is_readable($m123) || !str_contains((string) file_get_contents($m123), 'session_version')) {
    $fail[] = 'Migration 123 must define users.session_version';
}

foreach (
    [
        '/core/auth/SessionAuth.php' => ['SESSION_EPOCH_KEY', 'UserSessionEpochRepository'],
        '/core/auth/SessionEpochCoordinator.php' => ['assertAuthenticatedSessionEpochValid'],
        '/core/auth/UserSessionEpochRepository.php' => ['session_version'],
        '/core/auth/AuthService.php' => ['SessionEpochCoordinator', 'incrementSessionVersion'],
    ] as $rel => $needles
) {
    $path = $root . $rel;
    if (!is_readable($path)) {
        $fail[] = "Missing {$rel}";
        continue;
    }
    $t = (string) file_get_contents($path);
    foreach ($needles as $n) {
        if (!str_contains($t, $n)) {
            $fail[] = "{$rel} must reference {$n}";
        }
    }
}

$boot = $root . '/bootstrap.php';
if (is_readable($boot) && !str_contains((string) file_get_contents($boot), 'UserSessionEpochRepository')) {
    $fail[] = 'bootstrap.php should register UserSessionEpochRepository / SessionEpochCoordinator';
}

if ($fail !== []) {
    fwrite(STDERR, "FAIL session epoch readonly 02:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "PASS verify_session_backend_and_session_epoch_readonly_02\n";
