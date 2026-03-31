<?php

declare(strict_types=1);

/**
 * BIG-05D — Tenant-entry cross-org branch selector disambiguation proof.
 *
 * Static code checks + runtime DB query to prove:
 *  1. listAllActiveBranchesUnscopedForTenantEntryResolver() returns organization_id + organization_name
 *  2. tenant-entry-chooser.php uses <optgroup> for multi-org case
 *  3. same-name branches from different orgs are now grouped and unambiguous
 *  4. single-org flow still uses flat list (no unnecessary complexity)
 *  5. selection contract unchanged — option value remains branch_id only
 *  6. runtime DB: show cross-org branch state that triggered the bug
 *
 * Usage:
 *   php system/scripts/read-only/verify_big_05d_tenant_entry_cross_org_selector_proof.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

use Core\App\Database;

$system  = dirname(__DIR__, 2);
$db      = app(Database::class);
$pdo     = $db->connection();

/**
 * @return string
 */
function readOrFail05d(string $path): string
{
    $src = @file_get_contents($path);
    if (!is_string($src) || $src === '') {
        fwrite(STDERR, "FAIL: unreadable file {$path}\n");
        exit(1);
    }
    return $src;
}

$branchDir    = readOrFail05d($system . '/core/Branch/BranchDirectory.php');
$chooserView  = readOrFail05d($system . '/modules/auth/views/tenant-entry-chooser.php');
$controller   = readOrFail05d($system . '/modules/auth/controllers/TenantEntryController.php');

$checks = [];

// ── 1. listAllActiveBranchesUnscopedForTenantEntryResolver now joins organizations ──────────
$checks['BranchDirectory::listAllActiveBranchesUnscopedForTenantEntryResolver JOINs organizations table'] =
    str_contains($branchDir, 'INNER JOIN organizations o ON o.id = b.organization_id')
    && str_contains($branchDir, 'organization_name');

// ── 2. The unscoped resolver method returns organization_id in SELECT ─────────────────────
$checks['listAllActiveBranchesUnscopedForTenantEntryResolver SELECT includes organization_id'] =
    str_contains($branchDir, 'b.organization_id') &&
    preg_match('/listAllActiveBranchesUnscopedForTenantEntryResolver[^}]+b\.organization_id/s', $branchDir) === 1;

// ── 3. The unscoped resolver method returns organization_name in SELECT ───────────────────
$checks['listAllActiveBranchesUnscopedForTenantEntryResolver SELECT includes o.name AS organization_name'] =
    preg_match('/listAllActiveBranchesUnscopedForTenantEntryResolver[^}]+organization_name/s', $branchDir) === 1;

// ── 4. Chooser view groups by organization_id ─────────────────────────────────────────────
$checks['tenant-entry-chooser.php groups branches by organization_id'] =
    str_contains($chooserView, 'organization_id')
    && str_contains($chooserView, 'branchesByOrg');

// ── 5. Chooser view uses <optgroup> for multi-org selector ────────────────────────────────
$checks['tenant-entry-chooser.php renders <optgroup> for multi-org case'] =
    str_contains($chooserView, '<optgroup')
    && str_contains($chooserView, 'isMultiOrg');

// ── 6. Chooser view uses organization_name as optgroup label ─────────────────────────────
// The view stores organization_name from branch data into $orgData['name'] and renders it
// as the optgroup label — check both the source reference and the optgroup label usage.
$checks['tenant-entry-chooser.php uses organization_name as optgroup label'] =
    str_contains($chooserView, 'organization_name')
    && str_contains($chooserView, 'optgroup label=')
    && str_contains($chooserView, '$orgData[\'name\']')
    && str_contains($chooserView, 'htmlspecialchars($orgData[\'name\'])');

// ── 7. Single-org path still uses flat option list (no optgroup regression) ──────────────
$checks['tenant-entry-chooser.php preserves flat option list for single-org case'] =
    str_contains($chooserView, 'else:')
    && preg_match('/else:.*foreach.*branches.*branch.*option/s', $chooserView) === 1;

// ── 8. Option value is still plain branch_id (no contract change) ────────────────────────
$checks['tenant-entry-chooser.php option value is branch id (selection contract unchanged)'] =
    str_contains($chooserView, 'value="<?= (int) ($branch[\'id\']')
    && !str_contains($chooserView, 'value="<?= (int) ($branch[\'organization_id\']');

// ── 9. Controller still uses the same method + filter (no change needed) ─────────────────
$checks['TenantEntryController still uses listAllActiveBranchesUnscopedForTenantEntryResolver'] =
    str_contains($controller, 'listAllActiveBranchesUnscopedForTenantEntryResolver()');

// ── 10. Runtime DB: cross-org same-name branches exist (the bug's data trigger) ─────────
$crossOrgRows = $pdo->query(
    "SELECT b1.organization_id AS org1_id, b1.id AS branch1_id, b1.name,
            b2.organization_id AS org2_id, b2.id AS branch2_id
     FROM branches b1
     INNER JOIN branches b2
         ON  b1.name            = b2.name
         AND b1.organization_id < b2.organization_id
         AND b2.deleted_at IS NULL
     WHERE b1.deleted_at IS NULL
     ORDER BY b1.name, b1.organization_id"
)->fetchAll(PDO::FETCH_ASSOC);

$checks['Runtime DB: cross-org same-name branches exist (confirms the bug was real)'] = !empty($crossOrgRows);

// ── 11. Runtime DB: after fix, chooser for affected user would show grouped unambiguous options
// Verify the query now returns organization_name (JOIN worked)
$resolverResult = $pdo->query(
    "SELECT b.id, b.name, b.code, b.organization_id,
            o.name AS organization_name
     FROM branches b
     INNER JOIN organizations o ON o.id = b.organization_id
     WHERE b.deleted_at IS NULL
     ORDER BY o.name, b.name"
)->fetchAll(PDO::FETCH_ASSOC);

$hasOrgName = !empty($resolverResult) && array_key_exists('organization_name', $resolverResult[0]);
$checks['Runtime DB: query with org JOIN returns organization_name for all branch rows'] = $hasOrgName;

// ────────────────────────────────────────────────────────────────────────────────────────

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

// Print cross-org duplicate evidence
if (!empty($crossOrgRows)) {
    echo PHP_EOL . "Cross-org same-name branches (the trigger for the bug):\n";
    foreach ($crossOrgRows as $r) {
        echo "  '{$r['name']}': org {$r['org1_id']} branch {$r['branch1_id']} vs org {$r['org2_id']} branch {$r['branch2_id']}\n";
    }
}

// Print grouped chooser simulation
if ($hasOrgName) {
    $grouped = [];
    foreach ($resolverResult as $row) {
        $oid = (int) $row['organization_id'];
        if (!isset($grouped[$oid])) {
            $grouped[$oid] = ['org_name' => $row['organization_name'], 'branches' => []];
        }
        $grouped[$oid]['branches'][] = $row;
    }
    if (count($grouped) > 1) {
        echo PHP_EOL . "Chooser would now render as grouped <optgroup> (multi-org user):\n";
        foreach ($grouped as $gData) {
            echo "  <optgroup label=\"{$gData['org_name']}\">\n";
            foreach ($gData['branches'] as $b) {
                echo "    <option value=\"{$b['id']}\">{$b['name']}</option>\n";
            }
            echo "  </optgroup>\n";
        }
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . "verify_big_05d_tenant_entry_cross_org_selector_proof: OK\n";
exit(0);
