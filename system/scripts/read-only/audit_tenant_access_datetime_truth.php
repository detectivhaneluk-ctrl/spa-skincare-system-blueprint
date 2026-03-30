<?php

declare(strict_types=1);

/**
 * TENANT-ACCESS-DATETIME-DATA-TRUTH-AUDIT-AND-REPAIR-01 — read-only data truth audit.
 *
 * Scope: users, user_organization_memberships, organizations, branches as used for founder
 * tenant-access / access-shape evaluation (no writes).
 *
 * Usage (from repo root or system/):
 *   php system/scripts/read-only/audit_tenant_access_datetime_truth.php
 *   php system/scripts/read-only/audit_tenant_access_datetime_truth.php --json
 *
 * Exit: 0 always (audit tool). Inspect findings for actionable rows.
 */

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';

$json = in_array('--json', array_slice($argv, 1), true);

/** @var \Core\App\Database $db */
$db = app(\Core\App\Database::class);
$pdo = $db->connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!is_string($dbName) || $dbName === '') {
    fwrite(STDERR, "audit_tenant_access_datetime_truth: no database selected.\n");
    exit(1);
}

/**
 * Values that break strict TIMESTAMP semantics or PHP/SQL comparisons (legacy drift).
 */
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

$findings = [];
$summary = [
    'database' => $dbName,
    'membership_table_present' => tableExists($pdo, $dbName, 'user_organization_memberships'),
    'counts' => [],
];

// --- A) Dirty datetimes (tenant-access–relevant columns only) ---
$dtSpecs = [
    ['table' => 'users', 'column' => 'deleted_at', 'key' => 'dirty_datetime_users_deleted_at'],
    ['table' => 'organizations', 'column' => 'deleted_at', 'key' => 'dirty_datetime_organizations_deleted_at'],
    ['table' => 'organizations', 'column' => 'suspended_at', 'key' => 'dirty_datetime_organizations_suspended_at'],
    ['table' => 'branches', 'column' => 'deleted_at', 'key' => 'dirty_datetime_branches_deleted_at'],
];

foreach ($dtSpecs as $spec) {
    if (!tableExists($pdo, $dbName, $spec['table'])) {
        continue;
    }
    $col = $spec['column'];
    $rows = $db->fetchAll("SELECT id, `{$col}` AS v FROM `{$spec['table']}` WHERE `{$col}` IS NOT NULL");
    $bad = [];
    foreach ($rows as $row) {
        if (isDirtyDatetime($row['v'] ?? null)) {
            $bad[] = ['id' => (int) $row['id'], 'value' => (string) ($row['v'] ?? '')];
        }
    }
    if ($bad !== []) {
        $findings[] = ['code' => $spec['key'], 'rows' => $bad];
    }
    $summary['counts'][$spec['key']] = count($bad);
}

// --- B) Membership vs organization lifecycle (membership table required) ---
if ($summary['membership_table_present']) {
    $allowedStatus = ['active', 'suspended'];

    $activeDeletedOrg = $db->fetchAll(
        'SELECT m.user_id, m.organization_id, m.status, m.default_branch_id
         FROM user_organization_memberships m
         INNER JOIN organizations o ON o.id = m.organization_id
         WHERE m.status = ? AND o.deleted_at IS NOT NULL',
        ['active']
    );
    if ($activeDeletedOrg !== []) {
        $findings[] = ['code' => 'membership_active_organization_deleted', 'rows' => $activeDeletedOrg];
    }
    $summary['counts']['membership_active_organization_deleted'] = count($activeDeletedOrg);

    $activeSuspendedOrg = $db->fetchAll(
        'SELECT m.user_id, m.organization_id, m.status, m.default_branch_id, o.suspended_at AS org_suspended_at
         FROM user_organization_memberships m
         INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
         WHERE m.status = ? AND o.suspended_at IS NOT NULL',
        ['active']
    );
    if ($activeSuspendedOrg !== []) {
        $findings[] = ['code' => 'membership_active_organization_suspended', 'rows' => $activeSuspendedOrg];
    }
    $summary['counts']['membership_active_organization_suspended'] = count($activeSuspendedOrg);

    $invalidDefault = $db->fetchAll(
        'SELECT m.user_id, m.organization_id, m.status, m.default_branch_id
         FROM user_organization_memberships m
         LEFT JOIN branches b ON b.id = m.default_branch_id
         WHERE m.default_branch_id IS NOT NULL
           AND (
             b.id IS NULL
             OR b.deleted_at IS NOT NULL
             OR b.organization_id <> m.organization_id
           )'
    );
    if ($invalidDefault !== []) {
        $findings[] = ['code' => 'membership_default_branch_invalid', 'rows' => $invalidDefault];
    }
    $summary['counts']['membership_default_branch_invalid'] = count($invalidDefault);

    $unknown = $db->fetchAll(
        'SELECT m.user_id, m.organization_id, m.status
         FROM user_organization_memberships m
         WHERE m.status NOT IN (?, ?)',
        ['active', 'suspended']
    );
    if ($unknown !== []) {
        $findings[] = [
            'code' => 'membership_status_unknown',
            'note' => 'No automatic repair; map statuses manually if introduced by legacy data.',
            'rows' => $unknown,
        ];
    }
    $summary['counts']['membership_status_unknown'] = count($unknown);
}

// --- C) User branch pin vs branch/org truth ---
if (tableExists($pdo, $dbName, 'users') && tableExists($pdo, $dbName, 'branches')) {
    $pinDeletedBranch = $db->fetchAll(
        'SELECT u.id AS user_id, u.branch_id, u.deleted_at AS user_deleted_at
         FROM users u
         INNER JOIN branches b ON b.id = u.branch_id
         WHERE u.branch_id IS NOT NULL AND b.deleted_at IS NOT NULL'
    );
    if ($pinDeletedBranch !== []) {
        $findings[] = ['code' => 'user_branch_pin_points_to_deleted_branch', 'rows' => $pinDeletedBranch];
    }
    $summary['counts']['user_branch_pin_points_to_deleted_branch'] = count($pinDeletedBranch);

    $pinDeletedOrg = $db->fetchAll(
        'SELECT u.id AS user_id, u.branch_id, u.deleted_at AS user_deleted_at
         FROM users u
         INNER JOIN branches b ON b.id = u.branch_id
         INNER JOIN organizations o ON o.id = b.organization_id
         WHERE u.branch_id IS NOT NULL AND o.deleted_at IS NOT NULL'
    );
    if ($pinDeletedOrg !== []) {
        $findings[] = ['code' => 'user_branch_pin_organization_deleted', 'rows' => $pinDeletedOrg];
    }
    $summary['counts']['user_branch_pin_organization_deleted'] = count($pinDeletedOrg);
}

// --- D) Informational: tenant-shaped users with no usable membership path (not auto-repaired) ---
if ($summary['membership_table_present'] && tableExists($pdo, $dbName, 'users')) {
    $platformIds = platformPrincipalUserIds($db);
    $platformPh = $platformIds === [] ? '0' : implode(', ', array_fill(0, count($platformIds), '?'));
    $sql = "SELECT u.id AS user_id, u.email, u.branch_id, u.deleted_at
            FROM users u
            WHERE u.deleted_at IS NULL
              AND u.id NOT IN ({$platformPh})
              AND NOT EXISTS (
                SELECT 1 FROM user_organization_memberships m
                INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
                WHERE m.user_id = u.id AND m.status = 'active'
              )";
    $params = $platformIds;
    $orphans = $platformIds === [] ? $db->fetchAll($sql) : $db->fetchAll($sql, $params);
    if ($orphans !== []) {
        $findings[] = [
            'code' => 'login_capable_tenant_user_no_active_membership',
            'note' => 'Informational only. Access-shape may be tenant_orphan_blocked; repair via founder tooling, not this script.',
            'rows' => $orphans,
        ];
    }
    $summary['counts']['login_capable_tenant_user_no_active_membership'] = count($orphans);
}

$summary['total_finding_groups'] = count($findings);
$summary['schema_note'] = 'user_organization_memberships has no revoked_at column; revoked/suspended_at pairing is N/A.';

$out = [
    'task' => 'TENANT-ACCESS-DATETIME-DATA-TRUTH-AUDIT-01',
    'summary' => $summary,
    'findings' => $findings,
];

if ($json) {
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "TENANT-ACCESS-DATETIME-DATA-TRUTH-AUDIT-01\n";
    echo 'Database: ' . $dbName . "\n";
    echo 'Membership table: ' . ($summary['membership_table_present'] ? 'yes' : 'no') . "\n";
    echo $summary['schema_note'] . "\n\n";
    foreach ($findings as $f) {
        echo '--- ' . $f['code'] . ' (' . count($f['rows']) . " rows) ---\n";
        if (isset($f['note'])) {
            echo $f['note'] . "\n";
        }
        $slice = array_slice($f['rows'], 0, 50);
        echo json_encode($slice, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        if (count($f['rows']) > 50) {
            echo '... truncated, use --json for full export or query DB.' . "\n";
        }
        echo "\n";
    }
    if ($findings === []) {
        echo "No contradictions detected in scoped checks.\n";
    }
}

exit(0);
