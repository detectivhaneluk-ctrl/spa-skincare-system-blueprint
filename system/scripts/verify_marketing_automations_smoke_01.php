<?php

declare(strict_types=1);

/**
 * MARKETING-AUTOMATIONS-MIGRATION-APPLY-AND-SMOKE-01
 *
 * Applies service-level smoke checks on a real branch context:
 * - reads effective defaults before manual rows
 * - toggles one automation
 * - upserts settings for starter automations
 * - re-reads effective state and prints persisted rows
 *
 * Usage:
 *   php system/scripts/verify_marketing_automations_smoke_01.php
 */

$base = dirname(__DIR__);
require $base . '/bootstrap.php';
require $base . '/modules/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();
$branch = $pdo->query(
    'SELECT id, organization_id FROM branches WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1'
)->fetch(\PDO::FETCH_ASSOC);

if (!$branch) {
    fwrite(STDERR, "No active branch found; cannot run smoke.\n");
    exit(1);
}

$branchId = (int) ($branch['id'] ?? 0);
$orgId = (int) ($branch['organization_id'] ?? 0);
if ($branchId <= 0 || $orgId <= 0) {
    fwrite(STDERR, "Invalid branch or organization context.\n");
    exit(1);
}

app(\Core\Branch\BranchContext::class)->setCurrentBranchId($branchId);
app(\Core\Organization\OrganizationContext::class)->setFromResolution(
    $orgId,
    \Core\Organization\OrganizationContext::MODE_BRANCH_DERIVED
);

/** @var \Modules\Marketing\Services\MarketingAutomationService $service */
$service = app(\Modules\Marketing\Services\MarketingAutomationService::class);

echo 'storage_ready=' . ($service->isStorageReady() ? 'yes' : 'no') . PHP_EOL;
echo 'branch_id=' . $branchId . PHP_EOL;

$before = $service->effectiveByBranch($branchId);
echo 'before_count=' . count($before) . PHP_EOL;
foreach ($before as $row) {
    echo 'before:' . $row['automation_key'] . ':enabled=' . (!empty($row['enabled']) ? '1' : '0')
        . ':persisted=' . (!empty($row['has_persisted_override']) ? '1' : '0')
        . ':config=' . json_encode($row['config']) . PHP_EOL;
}

$service->toggle($branchId, 'reengagement_45_day');
$service->upsertSettings($branchId, 'birthday_special', ['lookahead_days' => 9], true);
$service->upsertSettings($branchId, 'first_time_visitor_welcome', ['delay_hours' => 48], false);

$after = $service->effectiveByBranch($branchId);
echo 'after_count=' . count($after) . PHP_EOL;
foreach ($after as $row) {
    echo 'after:' . $row['automation_key'] . ':enabled=' . (!empty($row['enabled']) ? '1' : '0')
        . ':persisted=' . (!empty($row['has_persisted_override']) ? '1' : '0')
        . ':config=' . json_encode($row['config']) . PHP_EOL;
}

$stmt = $pdo->prepare(
    'SELECT automation_key, enabled, config_json
     FROM marketing_automations
     WHERE branch_id = ?
     ORDER BY automation_key ASC'
);
$stmt->execute([$branchId]);
$persisted = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
echo 'persisted_rows=' . count($persisted) . PHP_EOL;
foreach ($persisted as $p) {
    echo 'db:' . $p['automation_key'] . ':enabled=' . ((int) $p['enabled']) . ':config=' . (string) ($p['config_json'] ?? '') . PHP_EOL;
}

