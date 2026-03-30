<?php

declare(strict_types=1);

/**
 * TENANT-ACCESS-DATETIME-DATA-TRUTH-AUDIT-AND-REPAIR-01 — minimal repairs for audited contradictions.
 *
 * Rules are explicit and narrow; see system/docs/TENANT-ACCESS-DATETIME-DATA-TRUTH-REPAIR-RULES.md
 *
 * Default: dry-run (no writes). Mutations only with --apply.
 *
 * Usage (from repo root):
 *   php system/scripts/repair_tenant_access_datetime_truth.php
 *   php system/scripts/repair_tenant_access_datetime_truth.php --apply
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';

$apply = in_array('--apply', array_slice($argv, 1), true);

/** @var \Core\App\Database $db */
$db = app(\Core\App\Database::class);
$pdo = $db->connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($dbName) || $dbName === '') {
    fwrite(STDERR, "repair_tenant_access_datetime_truth: no database selected.\n");
    exit(1);
}

function isDirtyDatetime(mixed $v): bool
{
    if ($v === null) {
        return false;
    }
    $s = trim((string) $v);
    if ($s === '') {
        return true;
    }
    if (str_starts_with($s, '0000-00-00')) {
        return true;
    }

    return false;
}

function tableExists(\PDO $pdo, string $schema, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $st->execute([$schema, $table]);

    return $st->fetchColumn() !== false;
}

function platformPrincipalUserIds(\Core\App\Database $db): array
{
    $rows = $db->fetchAll(
        'SELECT DISTINCT ur.user_id AS id
         FROM user_roles ur
         INNER JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
         WHERE r.code = ?',
        ['platform_founder']
    );
    $ids = [];
    foreach ($rows as $r) {
        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    return array_map('intval', array_keys($ids));
}

$actions = [];

// Rule R4 — normalize dirty TIMESTAMP-like values to NULL (tenant-access columns only)
$dtTargets = [
    ['table' => 'users', 'column' => 'deleted_at'],
    ['table' => 'organizations', 'column' => 'deleted_at'],
    ['table' => 'organizations', 'column' => 'suspended_at'],
    ['table' => 'branches', 'column' => 'deleted_at'],
];
foreach ($dtTargets as $t) {
    if (!tableExists($pdo, $dbName, $t['table'])) {
        continue;
    }
    $col = $t['column'];
    $rows = $db->fetchAll("SELECT id, `{$col}` AS v FROM `{$t['table']}` WHERE `{$col}` IS NOT NULL");
    foreach ($rows as $row) {
        if (!isDirtyDatetime($row['v'] ?? null)) {
            continue;
        }
        $id = (int) $row['id'];
        $actions[] = [
            'rule' => 'R4_dirty_datetime_to_null',
            'sql' => "UPDATE `{$t['table']}` SET `{$col}` = NULL WHERE id = ?",
            'params' => [$id],
            'context' => ['table' => $t['table'], 'column' => $col, 'id' => $id, 'raw' => (string) ($row['v'] ?? '')],
        ];
    }
}

// Rules R3, R1, R2 — membership (requires table)
if (tableExists($pdo, $dbName, 'user_organization_memberships')) {
    $r3 = $db->fetchAll(
        'SELECT m.user_id, m.organization_id
         FROM user_organization_memberships m
         INNER JOIN organizations o ON o.id = m.organization_id
         WHERE m.status = ? AND o.deleted_at IS NOT NULL',
        ['active']
    );
    foreach ($r3 as $r) {
        $actions[] = [
            'rule' => 'R3_membership_active_to_suspended_deleted_org',
            'sql' => 'UPDATE user_organization_memberships SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND organization_id = ?',
            'params' => ['suspended', (int) $r['user_id'], (int) $r['organization_id']],
            'context' => $r,
        ];
    }

    $r1 = $db->fetchAll(
        'SELECT m.user_id, m.organization_id
         FROM user_organization_memberships m
         INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
         WHERE m.status = ? AND o.suspended_at IS NOT NULL',
        ['active']
    );
    foreach ($r1 as $r) {
        $actions[] = [
            'rule' => 'R1_membership_active_to_suspended_suspended_org',
            'sql' => 'UPDATE user_organization_memberships SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND organization_id = ?',
            'params' => ['suspended', (int) $r['user_id'], (int) $r['organization_id']],
            'context' => $r,
        ];
    }

    $r2 = $db->fetchAll(
        'SELECT m.user_id, m.organization_id, m.default_branch_id
         FROM user_organization_memberships m
         LEFT JOIN branches b ON b.id = m.default_branch_id
         WHERE m.default_branch_id IS NOT NULL
           AND (
             b.id IS NULL
             OR b.deleted_at IS NOT NULL
             OR b.organization_id <> m.organization_id
           )'
    );
    foreach ($r2 as $r) {
        $actions[] = [
            'rule' => 'R2_membership_clear_invalid_default_branch',
            'sql' => 'UPDATE user_organization_memberships SET default_branch_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND organization_id = ?',
            'params' => [(int) $r['user_id'], (int) $r['organization_id']],
            'context' => $r,
        ];
    }
}

// Rules R5, R6 — user branch_id (skip platform principals: intentional control-plane accounts)
$platformSet = array_fill_keys(platformPrincipalUserIds($db), true);
if (tableExists($pdo, $dbName, 'users') && tableExists($pdo, $dbName, 'branches')) {
    $r5 = $db->fetchAll(
        'SELECT u.id AS user_id, u.branch_id
         FROM users u
         INNER JOIN branches b ON b.id = u.branch_id
         WHERE u.branch_id IS NOT NULL AND b.deleted_at IS NOT NULL'
    );
    foreach ($r5 as $r) {
        $uid = (int) $r['user_id'];
        if (isset($platformSet[$uid])) {
            continue;
        }
        $actions[] = [
            'rule' => 'R5_clear_user_branch_pin_deleted_branch',
            'sql' => 'UPDATE users SET branch_id = NULL WHERE id = ?',
            'params' => [$uid],
            'context' => $r,
        ];
    }

    $r6 = $db->fetchAll(
        'SELECT u.id AS user_id, u.branch_id
         FROM users u
         INNER JOIN branches b ON b.id = u.branch_id
         INNER JOIN organizations o ON o.id = b.organization_id
         WHERE u.branch_id IS NOT NULL AND o.deleted_at IS NOT NULL'
    );
    foreach ($r6 as $r) {
        $uid = (int) $r['user_id'];
        if (isset($platformSet[$uid])) {
            continue;
        }
        $actions[] = [
            'rule' => 'R6_clear_user_branch_pin_deleted_org',
            'sql' => 'UPDATE users SET branch_id = NULL WHERE id = ?',
            'params' => [$uid],
            'context' => $r,
        ];
    }
}

echo 'TENANT-ACCESS-DATETIME-DATA-TRUTH-REPAIR-01' . "\n";
echo 'Mode: ' . ($apply ? 'APPLY (writes enabled)' : 'DRY-RUN (no writes)') . "\n";
echo 'Planned statements: ' . count($actions) . "\n\n";

foreach ($actions as $i => $a) {
    echo ($i + 1) . '. [' . $a['rule'] . '] ' . $a['sql'] . ' | ' . json_encode($a['params']) . "\n";
}

if (!$apply) {
    echo "\nRe-run with --apply to execute inside a single transaction.\n";
    exit(0);
}

if ($actions === []) {
    echo "Nothing to apply.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    foreach ($actions as $a) {
        $db->query($a['sql'], $a['params']);
    }
    $pdo->commit();
    echo "\nApplied " . count($actions) . " statement(s) successfully.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Repair failed; transaction rolled back: ' . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
