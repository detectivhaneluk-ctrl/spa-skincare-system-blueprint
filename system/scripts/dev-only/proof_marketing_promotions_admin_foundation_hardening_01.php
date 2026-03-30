<?php

declare(strict_types=1);

/**
 * MARKETING-PROMOTIONS-ADMIN-FOUNDATION-HARDENING-01 proof script.
 *
 * Usage:
 *   php system/scripts/dev-only/proof_marketing_promotions_admin_foundation_hardening_01.php
 */

$base = dirname(__DIR__, 2);
require $base . '/bootstrap.php';
require $base . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);
$branchContext = app(\Core\Branch\BranchContext::class);
$orgContext = app(\Core\Organization\OrganizationContext::class);
$service = app(\Modules\Marketing\Services\MarketingSpecialOfferService::class);

$migrationRow = $db->fetchOne(
    'SELECT migration, run_at FROM migrations WHERE migration = ? LIMIT 1',
    ['108_marketing_special_offers_admin_foundation_hardening.sql']
);
echo 'migration_108_recorded=' . ($migrationRow ? 'yes' : 'no') . PHP_EOL;
echo 'migration_108_run_at=' . (string) ($migrationRow['run_at'] ?? '') . PHP_EOL;

$routeFile = $base . '/routes/web/register_marketing.php';
$routeText = is_file($routeFile) ? (string) file_get_contents($routeFile) : '';
echo 'route_edit_exists=' . (str_contains($routeText, "/marketing/promotions/special-offers/{id:\\d+}/edit") ? 'yes' : 'no') . PHP_EOL;
echo 'route_update_exists=' . (str_contains($routeText, "/marketing/promotions/special-offers/{id:\\d+}'") ? 'yes' : 'no') . PHP_EOL;
echo 'route_toggle_active_exists=' . (str_contains($routeText, "/marketing/promotions/special-offers/{id:\\d+}/toggle-active") ? 'yes' : 'no') . PHP_EOL;

$viewFile = $base . '/modules/marketing/views/promotions/special-offers.php';
$viewText = is_file($viewFile) ? (string) file_get_contents($viewFile) : '';
echo 'empty_result_text_truth_present=' . (str_contains($viewText, 'Results 0 of 0') ? 'yes' : 'no') . PHP_EOL;

$columns = ['offer_option', 'start_date', 'end_date', 'is_active'];
foreach ($columns as $column) {
    $col = $db->fetchOne(
        "SELECT COUNT(*) AS c
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'marketing_special_offers'
           AND COLUMN_NAME = ?",
        [$column]
    );
    echo 'column_' . $column . '_exists=' . (((int) ($col['c'] ?? 0) > 0) ? 'yes' : 'no') . PHP_EOL;
}

$branches = $db->fetchAll(
    "SELECT id, organization_id
     FROM branches
     WHERE deleted_at IS NULL
     ORDER BY id ASC
     LIMIT 2"
);

if (count($branches) < 1) {
    echo 'abort=no_branch_fixture' . PHP_EOL;
    exit(0);
}

$setScope = static function (int $branchId) use ($db, $branchContext, $orgContext): void {
    $branch = $db->fetchOne(
        'SELECT id, organization_id FROM branches WHERE id = ? AND deleted_at IS NULL LIMIT 1',
        [$branchId]
    );
    if (!$branch) {
        throw new \RuntimeException('Scope branch not found.');
    }
    $branchContext->setCurrentBranchId((int) $branch['id']);
    $orgContext->setFromResolution((int) $branch['organization_id'], \Core\Organization\OrganizationContext::MODE_BRANCH_DERIVED);
};

$branchA = (int) ($branches[0]['id'] ?? 0);
$branchB = isset($branches[1]['id']) ? (int) $branches[1]['id'] : 0;
echo 'fixture_branch_a=' . $branchA . PHP_EOL;
echo 'fixture_branch_b=' . $branchB . PHP_EOL;

$suffix = date('YmdHis');
$codeA = 'PROMO-' . $suffix . '-A';
$codeB = 'PROMO-' . $suffix . '-B';
$createdId = 0;
$createdInB = 0;

try {
    $setScope($branchA);
    $createdId = $service->createForCurrentBranch([
        'name' => 'Proof Offer ' . $suffix,
        'code' => $codeA,
        'origin' => 'manual',
        'adjustment_type' => 'percent',
        'adjustment_value' => '15',
        'offer_option' => 'internal_only',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);
    echo 'create_offer_id=' . $createdId . PHP_EOL;
    echo 'create_proof=yes' . PHP_EOL;

    $service->updateForCurrentBranch($createdId, [
        'name' => 'Proof Offer Updated ' . $suffix,
        'code' => $codeA,
        'origin' => 'auto',
        'adjustment_type' => 'fixed',
        'adjustment_value' => '99.50',
        'offer_option' => 'hide_from_customer',
        'start_date' => '2026-02-01',
        'end_date' => '2026-11-30',
    ]);
    $row = $db->fetchOne('SELECT * FROM marketing_special_offers WHERE id = ? LIMIT 1', [$createdId]);
    echo 'update_proof=' . ((($row['origin'] ?? '') === 'auto' && ($row['adjustment_type'] ?? '') === 'fixed') ? 'yes' : 'no') . PHP_EOL;
    echo 'options_persistence_truth=' . ((($row['offer_option'] ?? '') === 'hide_from_customer') ? 'yes' : 'no') . PHP_EOL;

    // H-006: new rows start inactive; simulate legacy is_active=1 to prove "clear flag" path only.
    $db->query('UPDATE marketing_special_offers SET is_active = 1 WHERE id = ?', [$createdId]);
    $service->toggleActiveForCurrentBranch($createdId);
    $toggleRow = $db->fetchOne('SELECT is_active FROM marketing_special_offers WHERE id = ? LIMIT 1', [$createdId]);
    echo 'active_toggle_proof=' . (((int) ($toggleRow['is_active'] ?? 1) === 0) ? 'yes' : 'no') . PHP_EOL;

    $badDateBlocked = false;
    try {
        $service->updateForCurrentBranch($createdId, [
            'name' => 'Invalid Date Attempt',
            'code' => $codeA,
            'origin' => 'manual',
            'adjustment_type' => 'percent',
            'adjustment_value' => '5',
            'offer_option' => 'all',
            'start_date' => '2026-12-01',
            'end_date' => '2026-01-01',
        ]);
    } catch (\InvalidArgumentException) {
        $badDateBlocked = true;
    }
    echo 'date_window_validation_proof=' . ($badDateBlocked ? 'yes' : 'no') . PHP_EOL;

    $duplicateBlocked = false;
    try {
        $service->createForCurrentBranch([
            'name' => 'Duplicate Same Branch',
            'code' => strtolower($codeA),
            'origin' => 'manual',
            'adjustment_type' => 'percent',
            'adjustment_value' => '10',
            'offer_option' => 'all',
            'start_date' => '',
            'end_date' => '',
        ]);
    } catch (\InvalidArgumentException) {
        $duplicateBlocked = true;
    }
    echo 'code_uniqueness_same_branch_proof=' . ($duplicateBlocked ? 'yes' : 'no') . PHP_EOL;

    if ($branchB > 0) {
        $setScope($branchB);
        $createdInB = $service->createForCurrentBranch([
            'name' => 'Cross Branch Same Code',
            'code' => $codeA,
            'origin' => 'manual',
            'adjustment_type' => 'percent',
            'adjustment_value' => '7',
            'offer_option' => 'all',
            'start_date' => '',
            'end_date' => '',
        ]);
        echo 'code_uniqueness_cross_branch_allows_same_code=yes' . PHP_EOL;

        $crossBranchBlocked = false;
        try {
            $service->updateForCurrentBranch($createdId, [
                'name' => 'Cross Branch Should Fail',
                'code' => $codeB,
                'origin' => 'manual',
                'adjustment_type' => 'percent',
                'adjustment_value' => '5',
                'offer_option' => 'all',
                'start_date' => '',
                'end_date' => '',
            ]);
        } catch (\InvalidArgumentException) {
            $crossBranchBlocked = true;
        }
        echo 'branch_scoping_proof=' . ($crossBranchBlocked ? 'yes' : 'no') . PHP_EOL;
    } else {
        echo 'code_uniqueness_cross_branch_allows_same_code=not_tested_single_branch_fixture' . PHP_EOL;
        echo 'branch_scoping_proof=not_tested_single_branch_fixture' . PHP_EOL;
    }

    $setScope($branchA);
    $service->softDeleteForCurrentBranch($createdId);
    $deletedRow = $db->fetchOne('SELECT deleted_at, is_active FROM marketing_special_offers WHERE id = ? LIMIT 1', [$createdId]);
    $deletedOk = !empty($deletedRow['deleted_at']) && (int) ($deletedRow['is_active'] ?? 1) === 0;
    echo 'delete_soft_delete_proof=' . ($deletedOk ? 'yes' : 'no') . PHP_EOL;
} finally {
    if ($createdInB > 0 && $branchB > 0) {
        try {
            $setScope($branchB);
            $service->softDeleteForCurrentBranch($createdInB);
        } catch (\Throwable) {
        }
    }
}
