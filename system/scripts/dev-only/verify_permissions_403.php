<?php

declare(strict_types=1);

/**
 * Verify permissions and role_permissions for vat_rates / payment_methods 403.
 * Usage (from `system/`): php scripts/dev-only/verify_permissions_403.php
 */

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';

$db = app(\Core\App\Database::class);

echo "=== DB connection ===\n";
echo "DB_DATABASE = " . (getenv('DB_DATABASE') ?: env('DB_DATABASE', '') ?: '') . "\n\n";

$codes = ['payment_methods.view', 'payment_methods.manage', 'vat_rates.view', 'vat_rates.manage'];

echo "=== 1. Permission rows ===\n";
foreach ($codes as $code) {
    $row = $db->fetchOne('SELECT id, code, name FROM permissions WHERE code = ?', [$code]);
    if ($row) {
        echo "  OK  {$code} (id={$row['id']})\n";
    } else {
        echo "  MISSING  {$code}\n";
    }
}

echo "\n=== 2. Roles (owner, admin) ===\n";
$owner = $db->fetchOne('SELECT id, code FROM roles WHERE code = ?', ['owner']);
$admin = $db->fetchOne('SELECT id, code FROM roles WHERE code = ?', ['admin']);
echo "  owner: " . ($owner ? "id={$owner['id']}" : "MISSING") . "\n";
echo "  admin: " . ($admin ? "id={$admin['id']}" : "MISSING") . "\n";

echo "\n=== 3. role_permissions for these 4 permissions ===\n";
foreach ($codes as $code) {
    $perm = $db->fetchOne('SELECT id FROM permissions WHERE code = ?', [$code]);
    if (!$perm) {
        echo "  {$code}: permission missing, skip\n";
        continue;
    }
    $pid = (int) $perm['id'];
    $links = $db->fetchAll(
        'SELECT rp.role_id, r.code FROM role_permissions rp INNER JOIN roles r ON r.id = rp.role_id WHERE rp.permission_id = ?',
        [$pid]
    );
    $roles = array_map(fn($r) => $r['code'], $links);
    echo "  {$code} (pid={$pid}): " . (count($links) ? implode(', ', $roles) : "NO ROLE LINKS") . "\n";
}

echo "\n=== 4. All users with roles (to see who can log in) ===\n";
$users = $db->fetchAll(
    'SELECT u.id, u.email, u.name, r.code AS role_code FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.id
     INNER JOIN roles r ON r.id = ur.role_id
     WHERE u.deleted_at IS NULL'
);
foreach ($users as $u) {
    echo "  user_id={$u['id']} {$u['email']} role={$u['role_code']}\n";
}

echo "\n=== 5. Does owner have vat_rates.view? ===\n";
if (!$owner) {
    echo "  owner role missing\n";
} else {
    $has = $db->fetchOne(
        'SELECT 1 FROM role_permissions rp INNER JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? AND p.code = ?',
        [$owner['id'], 'vat_rates.view']
    );
    echo "  owner (id={$owner['id']}): " . ($has ? "YES" : "NO") . "\n";
}

echo "\n=== 6. Does admin have vat_rates.view? ===\n";
if (!$admin) {
    echo "  admin role missing\n";
} else {
    $has = $db->fetchOne(
        'SELECT 1 FROM role_permissions rp INNER JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? AND p.code = ?',
        [$admin['id'], 'vat_rates.view']
    );
    echo "  admin (id={$admin['id']}): " . ($has ? "YES" : "NO") . "\n";
}

echo "\nDone.\n";
