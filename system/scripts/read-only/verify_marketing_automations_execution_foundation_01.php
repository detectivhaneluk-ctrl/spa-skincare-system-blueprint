<?php

declare(strict_types=1);

/**
 * Read-only execution foundation verifier for automated emails.
 * Dry-run only; no outbound writes.
 *
 * Usage:
 *   php system/scripts/read-only/verify_marketing_automations_execution_foundation_01.php
 */

$base = dirname(__DIR__, 2);
require $base . '/bootstrap.php';
require $base . '/modules/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();
$branch = $pdo->query(
    'SELECT id, organization_id FROM branches WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1'
)->fetch(\PDO::FETCH_ASSOC);
if (!$branch) {
    fwrite(STDERR, "No active branch found.\n");
    exit(1);
}
$branchId = (int) ($branch['id'] ?? 0);
$orgId = (int) ($branch['organization_id'] ?? 0);
app(\Core\Branch\BranchContext::class)->setCurrentBranchId($branchId);
app(\Core\Organization\OrganizationContext::class)->setFromResolution(
    $orgId,
    \Core\Organization\OrganizationContext::MODE_BRANCH_DERIVED
);

/** @var \Modules\Marketing\Services\MarketingAutomationService $settings */
$settings = app(\Modules\Marketing\Services\MarketingAutomationService::class);
/** @var \Modules\Marketing\Services\MarketingAutomationExecutionService $exec */
$exec = app(\Modules\Marketing\Services\MarketingAutomationExecutionService::class);

echo 'storage_ready=' . ($settings->isStorageReady() ? 'yes' : 'no') . PHP_EOL;
echo 'branch_id=' . $branchId . PHP_EOL;

foreach (array_keys(\Modules\Marketing\Services\MarketingAutomationService::catalog()) as $key) {
    try {
        $summary = $exec->executeAutomationForBranch($branchId, $key, true);
        echo 'key=' . $key
            . ' enabled=' . ($summary['enabled'] ? 'yes' : 'no')
            . ' eligible=' . (int) $summary['eligible']
            . ' skipped_duplicate=' . (int) $summary['skipped_duplicate']
            . ' invalid=' . (int) $summary['invalid_recipient_data']
            . PHP_EOL;
    } catch (\Throwable $e) {
        echo 'key=' . $key . ' error=' . $e->getMessage() . PHP_EOL;
    }
}

