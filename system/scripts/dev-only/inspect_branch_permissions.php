<?php

declare(strict_types=1);

/**
 * Runtime/data truth for branches.view (nav + GET /branches).
 *
 * Usage (from system/):
 *   php scripts/dev-only/inspect_branch_permissions.php
 *   php scripts/dev-only/inspect_branch_permissions.php --email=demo@example.com
 *   php scripts/dev-only/inspect_branch_permissions.php --user-id=1
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

use Core\App\Application;
use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Permissions\PermissionService;

$pdo = app(Database::class)->connection();
$dbName = (string) (app(\Core\App\Config::class)->get('database')['database'] ?? '');

$args = array_slice($argv, 1);
$emailFilter = null;
$userIdFilter = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--email=')) {
        $emailFilter = strtolower(trim(substr($a, strlen('--email='))));
    }
    if (str_starts_with($a, '--user-id=')) {
        $userIdFilter = (int) substr($a, strlen('--user-id='));
    }
}

echo "=== Branch permission / RBAC inspect ===\n";
echo "Database: {$dbName}\n\n";

$m083 = '083_branch_admin_permissions.sql';
$m084 = '084_branch_permissions_settings_zone_backfill.sql';
$st = $pdo->prepare('SELECT 1 FROM migrations WHERE migration = ? LIMIT 1');
foreach ([$m083, $m084] as $m) {
    $st->execute([$m]);
    $ok = (bool) $st->fetchColumn();
    echo "Migration stamped: {$m} => " . ($ok ? 'YES' : 'NO') . "\n";
}
echo "\n";

$permRows = $pdo->query(
    "SELECT id, code, name FROM permissions WHERE code IN ('branches.view', 'branches.manage') ORDER BY code"
)->fetchAll(PDO::FETCH_ASSOC);
echo "permissions rows (branches.*): " . (count($permRows) === 0 ? 'NONE (missing — run migrate or backfill)' : '') . "\n";
foreach ($permRows as $r) {
    echo "  id={$r['id']} code={$r['code']}\n";
}
echo "\n";

$roleBranchView = $pdo->query(
    "SELECT r.id AS role_id, r.code AS role_code, p.code AS perm
     FROM roles r
     INNER JOIN role_permissions rp ON rp.role_id = r.id
     INNER JOIN permissions p ON p.id = rp.permission_id
     WHERE p.code IN ('branches.view', 'branches.manage')
     ORDER BY r.code, p.code"
)->fetchAll(PDO::FETCH_ASSOC);
echo "role_permissions for branches.* (by role):\n";
if ($roleBranchView === []) {
    echo "  NONE — no role has branches.view/manage in role_permissions\n";
} else {
    foreach ($roleBranchView as $r) {
        echo "  role {$r['role_code']} (id {$r['role_id']}): {$r['perm']}\n";
    }
}
echo "\n";

$sqlUsers = 'SELECT u.id, u.email, u.name FROM users u WHERE u.deleted_at IS NULL';
$params = [];
if ($emailFilter !== null && $emailFilter !== '') {
    $sqlUsers .= ' AND LOWER(u.email) = ?';
    $params[] = $emailFilter;
} elseif ($userIdFilter !== null && $userIdFilter > 0) {
    $sqlUsers .= ' AND u.id = ?';
    $params[] = $userIdFilter;
}
$sqlUsers .= ' ORDER BY u.id LIMIT 50';

$stmt = $pdo->prepare($sqlUsers);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($users === []) {
    echo "No users matched filter.\n";
    exit(0);
}

$perms = Application::container()->get(PermissionService::class);
$branchContext = Application::container()->get(BranchContext::class);
$branchContext->setCurrentBranchId(null);

foreach ($users as $u) {
    $uid = (int) $u['id'];
    $effective = $perms->getForUser($uid);
    $hasView = in_array('branches.view', $effective, true);
    $hasManage = in_array('branches.manage', $effective, true);

    $roles = $pdo->prepare(
        'SELECT r.code FROM roles r
         INNER JOIN user_roles ur ON ur.role_id = r.id
         WHERE ur.user_id = ? ORDER BY r.code'
    );
    $roles->execute([$uid]);
    $roleCodes = array_column($roles->fetchAll(PDO::FETCH_ASSOC), 'code');

    echo "--- User id={$uid} email={$u['email']} ---\n";
    echo '  roles: ' . ($roleCodes === [] ? '(none)' : implode(', ', $roleCodes)) . "\n";
    echo '  PermissionService::getForUser has branches.view: ' . ($hasView ? 'YES' : 'NO') . "\n";
    echo '  PermissionService::getForUser has branches.manage: ' . ($hasManage ? 'YES' : 'NO') . "\n";
    echo '  PermissionService::has(branches.view): ' . ($perms->has($uid, 'branches.view') ? 'YES' : 'NO') . "\n";

    $sg = $pdo->prepare(
        'SELECT DISTINCT p.code
         FROM staff st
         INNER JOIN staff_group_members sgm ON sgm.staff_id = st.id
         INNER JOIN staff_groups sg ON sg.id = sgm.staff_group_id
            AND sg.deleted_at IS NULL AND sg.is_active = 1
         INNER JOIN staff_group_permissions sgp ON sgp.staff_group_id = sg.id
         INNER JOIN permissions p ON p.id = sgp.permission_id
         WHERE st.user_id = ? AND st.deleted_at IS NULL AND st.is_active = 1 AND sg.branch_id IS NULL
         ORDER BY p.code'
    );
    try {
        $sg->execute([$uid]);
        $sgCodes = array_column($sg->fetchAll(PDO::FETCH_ASSOC), 'code');
    } catch (Throwable) {
        $sgCodes = [];
    }
    $branchSg = array_values(array_filter($sgCodes, static fn (string $c): bool => str_starts_with($c, 'branches.')));
    echo '  staff-group (branch_id IS NULL) branch.* codes: ' . ($branchSg === [] ? '(none)' : implode(', ', $branchSg)) . "\n";
    echo "\n";
}

echo "Hint: if migrations 083/084 are NO, run: php scripts/migrate.php\n";
echo "Hint: idempotent SQL repair: php scripts/dev-only/apply_branch_permission_backfill.php\n";
