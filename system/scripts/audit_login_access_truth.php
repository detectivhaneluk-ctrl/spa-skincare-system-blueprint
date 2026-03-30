<?php

declare(strict_types=1);

/**
 * Read-only: print canonical login / home-path truth for one user.
 * Usage: php scripts/audit_login_access_truth.php email:user@example.com
 *        php scripts/audit_login_access_truth.php id:42
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

$arg = $argv[1] ?? '';
if ($arg === '' || !str_contains($arg, ':')) {
    fwrite(STDERR, "Usage: php scripts/audit_login_access_truth.php email:addr or id:N\n");
    exit(2);
}

[$kind, $value] = explode(':', $arg, 2);
$kind = strtolower(trim($kind));
$value = trim($value);

$db = app(\Core\App\Database::class);
$userId = 0;
if ($kind === 'id') {
    $userId = (int) $value;
} elseif ($kind === 'email') {
    $row = $db->fetchOne('SELECT id FROM users WHERE email = ? LIMIT 1', [strtolower($value)]);
    $userId = $row !== null ? (int) $row['id'] : 0;
} else {
    fwrite(STDERR, "Unknown kind (use email or id).\n");
    exit(2);
}

$shape = app(\Core\Auth\UserAccessShapeService::class)->evaluate($userId);
echo json_encode($shape, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
