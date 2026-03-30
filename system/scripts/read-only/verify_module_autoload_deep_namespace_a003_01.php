<?php

declare(strict_types=1);

/**
 * A-003 read-only: module autoload maps arbitrary depth under Modules\Module\...\Class.
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_module_autoload_deep_namespace_a003_01.php
 */
$systemRoot = dirname(__DIR__, 2);

require $systemRoot . '/core/app/autoload.php';

if (!function_exists('moduleClassFileCandidates')) {
    fwrite(STDERR, "moduleClassFileCandidates missing\n");
    exit(1);
}

$checks = [];

$deep = moduleClassFileCandidates($systemRoot, ['Marketing', 'Services', 'Automation', 'DeepTestClass']);
$checks['deep_first_try_is_all_lower_dirs'] = isset($deep[0])
    && str_ends_with($deep[0], '/modules/marketing/services/automation/DeepTestClass.php');

$httpAdmin = moduleClassFileCandidates($systemRoot, ['Sales', 'Http', 'Controllers', 'Admin', 'ThingController']);
$checks['http_nested_first_segment_lower'] = isset($httpAdmin[0])
    && str_contains($httpAdmin[0], '/modules/sales/http/controllers/admin/ThingController.php');

$flat = moduleClassFileCandidates($systemRoot, ['Marketing', 'Services', 'MarketingSegmentEvaluator']);
$checks['three_segment_still_lower_first'] = isset($flat[0])
    && str_ends_with($flat[0], '/modules/marketing/services/MarketingSegmentEvaluator.php');

$checks['three_segment_real_file_resolves'] = is_file($flat[0]);

$twoSeg = moduleClassFileCandidates($systemRoot, ['Sales', 'SalesController']);
$checks['two_segment_maps_to_module_root_path'] = isset($twoSeg[0])
    && str_ends_with($twoSeg[0], '/modules/sales/SalesController.php');

$membership = moduleClassFileCandidates($systemRoot, ['Memberships', 'Services', 'MembershipService']);
$checks['membership_capital_Services_fallback_exists'] = count(array_filter($membership, 'is_file')) >= 1;

$inventoryProv = moduleClassFileCandidates($systemRoot, ['Inventory', 'Providers', 'InvoiceStockSettlementProviderImpl']);
$checks['inventory_Providers_dir_resolves'] = count(array_filter($inventoryProv, 'is_file')) >= 1;

$autoloadSrc = (string) file_get_contents($systemRoot . '/core/app/autoload.php');
$checks['autoload_uses_moduleClassFileCandidates'] = str_contains($autoloadSrc, 'moduleClassFileCandidates(');
$checks['autoload_allows_two_segment_modules_namespace'] = str_contains($autoloadSrc, 'count($parts) >= 2');

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
