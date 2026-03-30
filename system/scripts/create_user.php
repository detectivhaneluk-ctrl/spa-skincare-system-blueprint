<?php

declare(strict_types=1);

/**
 * Create / upsert login users with canonical access shapes only.
 *
 * Usage:
 *   php scripts/create_user.php --platform-founder email@example.com "Password" "Display Name"
 *   php scripts/create_user.php --tenant-admin email@example.com "Password" "Display Name" --org-id=1 --branch-id=2
 *   php scripts/create_user.php --tenant-staff email@example.com "Password" "Display Name" --org-id=1 --branch-id=2
 *
 * Legacy (deprecated): php scripts/create_user.php email pass [role_code]
 *   Refuses tenant roles without --org-id/--branch-id when user_organization_memberships exists.
 *
 * @see TenantUserProvisioningService
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

$argvRest = array_slice($argv, 1);
if ($argvRest === []) {
    fwrite(STDERR, "Usage: php create_user.php --platform-founder|--tenant-admin|--tenant-staff email password \"Name\" [--org-id=N --branch-id=M]\n");
    fwrite(STDERR, "Legacy: php create_user.php email password [role_code] (tenant roles require org/branch when memberships table exists)\n");
    exit(1);
}

$mode = null;
$orgId = null;
$branchId = null;
$positional = [];
foreach ($argvRest as $arg) {
    if (str_starts_with($arg, '--org-id=')) {
        $orgId = (int) substr($arg, 9);
        continue;
    }
    if (str_starts_with($arg, '--branch-id=')) {
        $branchId = (int) substr($arg, 12);
        continue;
    }
    if ($arg === '--platform-founder') {
        $mode = 'platform-founder';
        continue;
    }
    if ($arg === '--tenant-admin') {
        $mode = 'tenant-admin';
        continue;
    }
    if ($arg === '--tenant-staff') {
        $mode = 'tenant-staff';
        continue;
    }
    $positional[] = $arg;
}

$provision = app(\Modules\Organizations\Services\TenantUserProvisioningService::class);

if ($mode !== null) {
    $email = $positional[0] ?? null;
    $password = $positional[1] ?? null;
    $name = $positional[2] ?? null;
    if (!$email || !$password || !$name) {
        fwrite(STDERR, "Missing email, password, or display name for --{$mode}.\n");
        exit(1);
    }
    try {
        if ($mode === 'platform-founder') {
            $id = $provision->provisionPlatformFounder($email, $password, $name);
            echo "Platform founder upserted: user_id={$id} email={$email}\n";
        } elseif ($mode === 'tenant-admin') {
            if ($orgId === null || $branchId === null || $orgId <= 0 || $branchId <= 0) {
                fwrite(STDERR, "--tenant-admin requires --org-id and --branch-id.\n");
                exit(1);
            }
            $id = $provision->provisionTenantAdmin($email, $password, $name, $orgId, $branchId);
            echo "Tenant admin provisioned: user_id={$id} email={$email}\n";
        } else {
            if ($orgId === null || $branchId === null || $orgId <= 0 || $branchId <= 0) {
                fwrite(STDERR, "--tenant-staff requires --org-id and --branch-id.\n");
                exit(1);
            }
            $id = $provision->provisionTenantStaff($email, $password, $name, $orgId, $branchId, 'reception');
            echo "Tenant staff provisioned: user_id={$id} email={$email}\n";
        }
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

// Legacy path
$email = $positional[0] ?? null;
$password = $positional[1] ?? null;
$roleCode = $positional[2] ?? 'admin';
if (!$email || !$password) {
    fwrite(STDERR, "Usage: php create_user.php email password [role_code]\n");
    exit(1);
}

if ($roleCode === 'platform_founder') {
    try {
        $id = $provision->provisionPlatformFounder($email, $password, 'Admin');
        echo "User upserted (platform founder): {$email} user_id={$id}\n";
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

if ($provision->membershipTableExists()) {
    fwrite(STDERR, "Refusing legacy tenant user create without access shape. Use:\n");
    fwrite(STDERR, "  php scripts/create_user.php --tenant-admin {$email} \"password\" \"Name\" --org-id=ORG --branch-id=BRANCH\n");
    exit(1);
}

$db = app(\Core\App\Database::class);
$role = $db->fetchOne('SELECT id FROM roles WHERE code = ? AND deleted_at IS NULL', [$roleCode]);
if (!$role) {
    fwrite(STDERR, "Unknown role code: {$roleCode}\n");
    exit(1);
}
$hash = password_hash($password, PASSWORD_DEFAULT);
$db->query(
    'INSERT INTO users (email, password_hash, name, password_changed_at) VALUES (?, ?, ?, NOW())',
    [$email, $hash, 'Admin']
);
$userId = (int) $db->lastInsertId();
$db->query('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $role['id']]);
echo "User created (legacy install without memberships): {$email} (role: {$roleCode}) id={$userId}\n";
echo "WARNING: Pin branch_id and add memberships when migration 087 is applied.\n";
