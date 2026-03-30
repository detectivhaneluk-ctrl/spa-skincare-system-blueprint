<?php

declare(strict_types=1);

/**
 * Read-only: founder vs tenant plane classification and contradictions.
 * Usage: php scripts/audit_founder_tenant_boundary_truth.php email:user@example.com
 *        php scripts/audit_founder_tenant_boundary_truth.php id:42
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

$arg = $argv[1] ?? '';
if ($arg === '' || !str_contains($arg, ':')) {
    fwrite(STDERR, "Usage: php scripts/audit_founder_tenant_boundary_truth.php email:addr or id:N\n");
    exit(2);
}

[$kind, $value] = explode(':', $arg, 2);
$db = app(\Core\App\Database::class);
$userId = 0;
if (strtolower(trim($kind)) === 'id') {
    $userId = (int) trim($value);
} else {
    $row = $db->fetchOne('SELECT id FROM users WHERE email = ? LIMIT 1', [strtolower(trim($value))]);
    $userId = $row !== null ? (int) $row['id'] : 0;
}

$shape = app(\Core\Auth\UserAccessShapeService::class)->evaluate($userId);
$out = [
    'user_id' => $shape['user_id'] ?? $userId,
    'principal_plane' => $shape['principal_plane'] ?? null,
    'is_platform_principal' => $shape['is_platform_principal'] ?? null,
    'usable_branch_count' => isset($shape['usable_branch_ids']) ? count((array) $shape['usable_branch_ids']) : null,
    'contradictions' => $shape['contradictions'] ?? [],
    'canonical_state' => $shape['canonical_state'] ?? null,
    'expected_home_path' => $shape['expected_home_path'] ?? null,
    'boundary_summary' => ($shape['is_platform_principal'] ?? false)
        ? 'Founder/platform principal: control plane only at tenant-route layer.'
        : (($shape['principal_plane'] ?? '') === \Core\Auth\PrincipalPlaneResolver::TENANT_PLANE
            ? 'Tenant plane: requires branch/org context for tenant modules.'
            : 'Blocked authenticated: no usable branches; use tenant-entry blocked/suspended surfaces.'),
];
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
