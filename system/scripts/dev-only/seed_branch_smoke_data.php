<?php

declare(strict_types=1);

/**
 * Dev-only: minimal branches, branch-scoped users, staff + categories for branch-isolation smoke.
 * Idempotent: safe to re-run; uses fixed branch codes and emails.
 *
 * SUPER-ADMIN-LOGIN-CONTROL-PLANE-CANONICALIZATION-01:
 * Deterministic fixtures on *.example.test with distinct passwords (no shared smoke password).
 * platform_founder, tenant admin (branch A), reception (branch B), multi-branch chooser, negative orphan.
 *
 * Usage (from system/): php scripts/dev-only/seed_branch_smoke_data.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

$db = app(\Core\App\Database::class);

$obsoleteSmokeEmails = [
    'platform-smoke@example.com',
    'branchA@example.com',
    'branchB@example.com',
    'tenant-orphan@example.com',
    'tenant-multi@example.com',
];
$memTable = $db->fetchOne(
    'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
    ['user_organization_memberships']
);
foreach ($obsoleteSmokeEmails as $oldEmail) {
    $oldRow = $db->fetchOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$oldEmail]);
    if ($oldRow === null) {
        continue;
    }
    $oid = (int) $oldRow['id'];
    if ($memTable !== null) {
        $db->query('DELETE FROM user_organization_memberships WHERE user_id = ?', [$oid]);
    }
    $db->query('UPDATE users SET deleted_at = NOW() WHERE id = ?', [$oid]);
}

$defaultOrgRow = $db->fetchOne('SELECT MIN(id) AS id FROM organizations WHERE deleted_at IS NULL');
if ($defaultOrgRow === null || $defaultOrgRow['id'] === null || (int) $defaultOrgRow['id'] <= 0) {
    fwrite(STDERR, "No active organization (migration 086 / FOUNDATION-08). Run php scripts/migrate.php first.\n");
    exit(1);
}
$defaultOrgId = (int) $defaultOrgRow['id'];

$ensureBranch = static function (string $name, string $code) use ($db, $defaultOrgId): int {
    $row = $db->fetchOne('SELECT id FROM branches WHERE code = ? AND deleted_at IS NULL', [$code]);
    if ($row) {
        $id = (int) $row['id'];
        $db->query('UPDATE branches SET organization_id = ? WHERE id = ?', [$defaultOrgId, $id]);
        return $id;
    }
    $db->query(
        'INSERT INTO branches (name, code, organization_id) VALUES (?, ?, ?)',
        [$name, $code, $defaultOrgId]
    );
    return (int) $db->lastInsertId();
};

$branchAId = $ensureBranch('Smoke Branch A', 'SMOKE_A');
$branchBId = $ensureBranch('Smoke Branch B', 'SMOKE_B');
$secondOrgRow = $db->fetchOne(
    'SELECT id FROM organizations WHERE deleted_at IS NULL AND id <> ? ORDER BY id ASC LIMIT 1',
    [$defaultOrgId]
);
if ($secondOrgRow === null) {
    $db->query(
        'INSERT INTO organizations (name, code, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
        ['Smoke Organization B', 'SMOKE_ORG_B']
    );
    $secondOrgId = (int) $db->lastInsertId();
} else {
    $secondOrgId = (int) $secondOrgRow['id'];
}
if ($secondOrgId <= 0) {
    fwrite(STDERR, "Unable to resolve/create second organization.\n");
    exit(1);
}
$branchCRow = $db->fetchOne('SELECT id FROM branches WHERE code = ? AND deleted_at IS NULL', ['SMOKE_C']);
if ($branchCRow) {
    $branchCId = (int) $branchCRow['id'];
    $db->query('UPDATE branches SET organization_id = ? WHERE id = ?', [$secondOrgId, $branchCId]);
} else {
    $db->query(
        'INSERT INTO branches (name, code, organization_id) VALUES (?, ?, ?)',
        ['Smoke Branch C', 'SMOKE_C', $secondOrgId]
    );
    $branchCId = (int) $db->lastInsertId();
}

$roleRow = static function (string $code) use ($db): array {
    $row = $db->fetchOne('SELECT id FROM roles WHERE code = ? AND deleted_at IS NULL', [$code]);
    if (!$row) {
        fwrite(STDERR, "Missing role '{$code}'. Run data/seeders/001 and 014 (php scripts/seed.php) first.\n");
        exit(1);
    }

    return $row;
};

$platformFounderRoleId = (int) $roleRow('platform_founder')['id'];
$adminRoleId = (int) $roleRow('admin')['id'];
$receptionRoleId = (int) $roleRow('reception')['id'];

$setSingleRole = static function (int $userId, int $roleId) use ($db): void {
    $db->query('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
    $db->query('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $roleId]);
};

$upsertUser = static function (string $email, string $name, ?int $branchId, int $roleId, string $plainPassword) use ($db, $setSingleRole): int {
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $row = $db->fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
    if ($row) {
        $uid = (int) $row['id'];
        try {
            $db->query(
                'UPDATE users SET password_hash = ?, name = ?, branch_id = ?, deleted_at = NULL, password_changed_at = NOW() WHERE id = ?',
                [$hash, $name, $branchId, $uid]
            );
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'password_changed_at')) {
                throw $e;
            }
            $db->query(
                'UPDATE users SET password_hash = ?, name = ?, branch_id = ?, deleted_at = NULL WHERE id = ?',
                [$hash, $name, $branchId, $uid]
            );
        }
    } else {
        try {
            $db->query(
                'INSERT INTO users (email, password_hash, name, branch_id, password_changed_at) VALUES (?, ?, ?, ?, NOW())',
                [$email, $hash, $name, $branchId]
            );
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'password_changed_at')) {
                throw $e;
            }
            $db->query(
                'INSERT INTO users (email, password_hash, name, branch_id) VALUES (?, ?, ?, ?)',
                [$email, $hash, $name, $branchId]
            );
        }
        $uid = (int) $db->lastInsertId();
    }
    $setSingleRole($uid, $roleId);

    return $uid;
};

$userPlatformId = $upsertUser('founder-smoke@example.test', 'Founder Smoke', null, $platformFounderRoleId, 'FounderSmoke##2026');
$userAId = $upsertUser('tenant-admin-a@example.test', 'Tenant Admin A Smoke', $branchAId, $adminRoleId, 'TenantAdminA##2026');
$userBId = $upsertUser('tenant-reception-b@example.test', 'Tenant Reception B Smoke', $branchBId, $receptionRoleId, 'TenantReceptionB##2026');
$userOrphanRow = $db->fetchOne('SELECT id FROM users WHERE email = ?', ['negative-orphan-access@example.test']);
if ($userOrphanRow) {
    $userOrphanId = (int) $userOrphanRow['id'];
    $hashO = password_hash('NegativeOrphan##2026', PASSWORD_DEFAULT);
    try {
        $db->query(
            'UPDATE users SET password_hash = ?, name = ?, branch_id = NULL, deleted_at = NULL, password_changed_at = NOW() WHERE id = ?',
            [$hashO, 'Negative Orphan Access Fixture', $userOrphanId]
        );
    } catch (\Throwable $e) {
        if (!str_contains($e->getMessage(), 'password_changed_at')) {
            throw $e;
        }
        $db->query(
            'UPDATE users SET password_hash = ?, name = ?, branch_id = NULL, deleted_at = NULL WHERE id = ?',
            [$hashO, 'Negative Orphan Access Fixture', $userOrphanId]
        );
    }
} else {
    $userOrphanId = $upsertUser('negative-orphan-access@example.test', 'Negative Orphan Access Fixture', null, $adminRoleId, 'NegativeOrphan##2026');
}
$setSingleRole($userOrphanId, $adminRoleId);
$userMultiRow = $db->fetchOne('SELECT id FROM users WHERE email = ?', ['tenant-multi-choice@example.test']);
if ($userMultiRow) {
    $userMultiId = (int) $userMultiRow['id'];
    $hashM = password_hash('TenantMultiChoice##2026', PASSWORD_DEFAULT);
    try {
        $db->query(
            'UPDATE users SET password_hash = ?, name = ?, branch_id = NULL, deleted_at = NULL, password_changed_at = NOW() WHERE id = ?',
            [$hashM, 'Tenant Multi Choice Smoke', $userMultiId]
        );
    } catch (\Throwable $e) {
        if (!str_contains($e->getMessage(), 'password_changed_at')) {
            throw $e;
        }
        $db->query(
            'UPDATE users SET password_hash = ?, name = ?, branch_id = NULL, deleted_at = NULL WHERE id = ?',
            [$hashM, 'Tenant Multi Choice Smoke', $userMultiId]
        );
    }
} else {
    $userMultiId = $upsertUser('tenant-multi-choice@example.test', 'Tenant Multi Choice Smoke', null, $adminRoleId, 'TenantMultiChoice##2026');
}
$setSingleRole($userMultiId, $adminRoleId);

$membershipTable = $db->fetchOne(
    'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
    ['user_organization_memberships']
);
if ($membershipTable !== null) {
    $db->query(
        'DELETE FROM user_organization_memberships WHERE user_id IN (?, ?, ?, ?, ?)',
        [$userPlatformId, $userAId, $userBId, $userOrphanId, $userMultiId]
    );
    $db->query(
        'INSERT INTO user_organization_memberships (user_id, organization_id, status, default_branch_id)
         VALUES (?, ?, ?, ?)',
        [$userAId, $defaultOrgId, 'active', $branchAId]
    );
    $db->query(
        'INSERT INTO user_organization_memberships (user_id, organization_id, status, default_branch_id)
         VALUES (?, ?, ?, ?)',
        [$userBId, $defaultOrgId, 'active', $branchBId]
    );
    $db->query(
        'INSERT INTO user_organization_memberships (user_id, organization_id, status, default_branch_id)
         VALUES (?, ?, ?, ?)',
        [$userMultiId, $defaultOrgId, 'active', $branchAId]
    );
    $db->query(
        'INSERT INTO user_organization_memberships (user_id, organization_id, status, default_branch_id)
         VALUES (?, ?, ?, ?)',
        [$userMultiId, $secondOrgId, 'active', $branchCId]
    );
}

$ensureStaff = static function (int $branchId, string $first, string $last) use ($db): int {
    $row = $db->fetchOne(
        'SELECT id FROM staff WHERE branch_id = ? AND last_name = ? AND deleted_at IS NULL',
        [$branchId, $last]
    );
    if ($row) {
        return (int) $row['id'];
    }
    $db->query(
        'INSERT INTO staff (user_id, first_name, last_name, is_active, branch_id, created_by) VALUES (NULL, ?, ?, 1, ?, NULL)',
        [$first, $last, $branchId]
    );
    return (int) $db->lastInsertId();
};

$staffAId = $ensureStaff($branchAId, 'Proof', 'StaffA-Smoke');
$staffBId = $ensureStaff($branchBId, 'Proof', 'StaffB-Smoke');

$ensureCategory = static function (int $branchId, string $name) use ($db): int {
    $row = $db->fetchOne(
        'SELECT id FROM service_categories WHERE branch_id = ? AND name = ? AND deleted_at IS NULL',
        [$branchId, $name]
    );
    if ($row) {
        return (int) $row['id'];
    }
    $db->query(
        'INSERT INTO service_categories (name, sort_order, branch_id) VALUES (?, 0, ?)',
        [$name, $branchId]
    );
    return (int) $db->lastInsertId();
};

$catAId = $ensureCategory($branchAId, 'Smoke Cat A');
$catBId = $ensureCategory($branchBId, 'Smoke Cat B');

echo json_encode([
    'platform_smoke_user_id' => $userPlatformId,
    'founder_smoke_email' => 'founder-smoke@example.test',
    'tenant_admin_a_email' => 'tenant-admin-a@example.test',
    'tenant_reception_b_email' => 'tenant-reception-b@example.test',
    'tenant_multi_choice_email' => 'tenant-multi-choice@example.test',
    'negative_orphan_access_email' => 'negative-orphan-access@example.test',
    'branch_a_id' => $branchAId,
    'branch_b_id' => $branchBId,
    'branch_c_id' => $branchCId,
    'user_a_id' => $userAId,
    'user_b_id' => $userBId,
    'user_orphan_id' => $userOrphanId,
    'user_multi_id' => $userMultiId,
    'staff_a_id' => $staffAId,
    'staff_b_id' => $staffBId,
    'category_a_id' => $catAId,
    'category_b_id' => $catBId,
], JSON_PRETTY_PRINT) . PHP_EOL;
