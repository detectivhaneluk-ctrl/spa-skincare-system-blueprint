<?php

declare(strict_types=1);

/**
 * Bumps users.session_version for logout-all / revoke semantics (distributed-safe with Redis or file sessions).
 *
 *   php system/scripts/invalidate_user_sessions_cli_02.php --user-id=123
 */

$systemRoot = dirname(__DIR__);
require $systemRoot . '/bootstrap.php';

use Core\App\Application;
use Core\Auth\UserSessionEpochRepository;

$userId = 0;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--user-id=')) {
        $userId = (int) trim(substr($arg, strlen('--user-id=')));
    }
}
if ($userId <= 0) {
    fwrite(STDERR, "Usage: --user-id=POSITIVE_INT\n");
    exit(1);
}

$repo = Application::container()->get(UserSessionEpochRepository::class);
$before = $repo->getSessionVersion($userId);
$repo->incrementSessionVersion($userId);
$after = $repo->getSessionVersion($userId);
fwrite(STDOUT, "user {$userId} session_version {$before} -> {$after}\n");
