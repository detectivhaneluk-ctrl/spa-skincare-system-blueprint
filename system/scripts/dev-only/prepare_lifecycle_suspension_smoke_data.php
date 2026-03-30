<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$db = app(\Core\App\Database::class);
$branchA = (int) (($db->fetchOne(
    'SELECT id FROM branches WHERE code = ? AND deleted_at IS NULL LIMIT 1',
    ['SMOKE_A']
)['id'] ?? 0));
$branchC = (int) (($db->fetchOne(
    'SELECT id FROM branches WHERE code = ? AND deleted_at IS NULL LIMIT 1',
    ['SMOKE_C']
)['id'] ?? 0));

if ($branchA <= 0 || $branchC <= 0) {
    fwrite(STDERR, "SMOKE_A/SMOKE_C branches are required. Run seed_branch_smoke_data.php first.\n");
    exit(1);
}

$orgA = (int) (($db->fetchOne('SELECT organization_id FROM branches WHERE id = ? LIMIT 1', [$branchA])['organization_id'] ?? 0));
$orgC = (int) (($db->fetchOne('SELECT organization_id FROM branches WHERE id = ? LIMIT 1', [$branchC])['organization_id'] ?? 0));
if ($orgA <= 0 || $orgC <= 0) {
    fwrite(STDERR, "Unable to resolve organizations for SMOKE_A/SMOKE_C.\n");
    exit(1);
}

$db->query('UPDATE organizations SET suspended_at = NULL WHERE id = ?', [$orgA]);
$db->query('UPDATE organizations SET suspended_at = CURRENT_TIMESTAMP WHERE id = ?', [$orgC]);

echo json_encode([
    'active_branch_id' => $branchA,
    'suspended_branch_id' => $branchC,
    'active_org_id' => $orgA,
    'suspended_org_id' => $orgC,
    'active_admin_email' => 'tenant-admin-a@example.test',
    'suspended_admin_email' => 'tenant-multi-choice@example.test',
    'platform_founder_email' => 'founder-smoke@example.test',
], JSON_PRETTY_PRINT) . PHP_EOL;
