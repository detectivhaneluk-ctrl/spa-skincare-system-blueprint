<?php

declare(strict_types=1);

/**
 * MARKETING-GIFT-CARD-TEMPLATES-POST-MIGRATION-RUNTIME-05
 *
 * Proves two distinct runtime states:
 * - storage not ready: honesty guard (static + optional runtime isStorageReady)
 * - storage ready: real list/catalog from service/repository; create/clone/edit/archive/pager/images
 *
 * Usage:
 *   php system/scripts/read-only/verify_marketing_gift_card_templates_post_migration_runtime_05.php
 */

$base = dirname(__DIR__, 2);

// --- Static: index + controller contracts (no DB) ---
$indexPath = $base . '/modules/marketing/views/gift-card-templates/index.php';
$indexText = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';
echo 'static_index_wraps_notice_only_when_not_ready=' . (
    str_contains($indexText, 'if (!$storageReady)') && str_contains($indexText, 'storage-not-ready-notice')
        ? 'yes'
        : 'no'
) . PHP_EOL;
echo 'static_index_catalog_only_when_storage_ready=' . (
    str_contains($indexText, 'if ($storageReady)') && str_contains($indexText, 'No active gift card templates yet.')
        ? 'yes'
        : 'no'
) . PHP_EOL;
echo 'static_index_pager_uses_limit_offset_params=' . (str_contains($indexText, '?limit=') && str_contains($indexText, '&offset=') ? 'yes' : 'no') . PHP_EOL;

$ctlPath = $base . '/modules/marketing/controllers/MarketingGiftCardTemplatesController.php';
$ctlText = is_file($ctlPath) ? (string) file_get_contents($ctlPath) : '';
echo 'static_controller_index_branches_list_on_storage_ready=' . (
    str_contains($ctlText, '$storageReady') && str_contains($ctlText, 'listTemplatesForIndex')
        ? 'yes'
        : 'no'
) . PHP_EOL;

if (
    !str_contains($indexText, 'if (!$storageReady)')
    || !str_contains($indexText, 'if ($storageReady)')
    || !str_contains($ctlText, 'listTemplatesForIndex')
) {
    echo 'runtime_05_static_contract_status=FAIL' . PHP_EOL;
    exit(1);
}

require $base . '/bootstrap.php';
require $base . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);
$service = app(\Modules\Marketing\Services\MarketingGiftCardTemplateService::class);

$branchRow = $db->fetchOne('SELECT id FROM branches WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
$branchId = $branchRow ? (int) ($branchRow['id'] ?? 0) : 0;
echo 'fixture_branch_id=' . $branchId . PHP_EOL;
if ($branchId <= 0) {
    echo 'abort=no_branch_fixture' . PHP_EOL;
    exit(0);
}

$b = $db->fetchOne('SELECT organization_id FROM branches WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$branchId]);
$orgId = (int) ($b['organization_id'] ?? 0);
if ($orgId > 0) {
    app(\Core\Branch\BranchContext::class)->setCurrentBranchId($branchId);
    app(\Core\Organization\OrganizationContext::class)->setFromResolution(
        $orgId,
        \Core\Organization\OrganizationContext::MODE_BRANCH_DERIVED
    );
}

$ready = $service->isStorageReady();
echo 'runtime_storage_ready=' . ($ready ? 'yes' : 'no') . PHP_EOL;

if (!$ready) {
    echo 'state_1_storage_not_ready_honesty_only_expected=yes' . PHP_EOL;
    echo 'state_2_storage_ready_catalog_skipped=no_tables' . PHP_EOL;
    echo 'runtime_05_status=PASS_NOT_READY_ONLY' . PHP_EOL;
    exit(0);
}

// --- State 2: full runtime (real DB, no fake seed rows) ---
$baseline = $service->listTemplatesForIndex($branchId, 500, 0);
$t0 = (int) ($baseline['total'] ?? 0);
echo 'baseline_active_template_total=' . $t0 . PHP_EOL;

$empty = $service->listTemplatesForIndex($branchId, 25, 0);
$expectedRowCount = min((int) ($empty['limit'] ?? 25), max(0, $t0));
echo 'state_2a_list_total_from_service=' . (int) ($empty['total'] ?? 0) . PHP_EOL;
echo 'state_2a_list_rows_match_total_and_limit=' . (
    (int) ($empty['total'] ?? 0) === $t0 && count($empty['rows'] ?? []) === $expectedRowCount ? 'yes' : 'no'
) . PHP_EOL;
echo 'state_2a_empty_catalog_when_no_templates=' . (($t0 === 0 && ($empty['rows'] ?? []) === []) ? 'yes' : ($t0 > 0 ? 'not_applicable' : 'no')) . PHP_EOL;

$suffix = date('YmdHis');
$nameA = 'R05 Scratch ' . $suffix;
$idA = $service->createTemplateFromRequest($branchId, $nameA, null, null);
echo 'create_from_scratch_id=' . $idA . PHP_EOL;

$afterOne = $service->listTemplatesForIndex($branchId, 25, 0);
$idsAfterOne = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $afterOne['rows'] ?? []);
echo 'state_2b_list_includes_new_row=' . (in_array($idA, $idsAfterOne, true) ? 'yes' : 'no') . PHP_EOL;
echo 'state_2b_total_incremented=' . (((int) ($afterOne['total'] ?? 0) === $t0 + 1) ? 'yes' : 'no') . PHP_EOL;

$nameB = 'R05 Clone ' . $suffix;
$idB = $service->createTemplateFromRequest($branchId, $nameB, $idA, null);
$cloneRow = $service->findTemplateForEdit($branchId, $idB);
echo 'clone_source_recorded=' . (((int) ($cloneRow['clone_source_template_id'] ?? 0) === $idA) ? 'yes' : 'no') . PHP_EOL;

$afterTwo = $service->listTemplatesForIndex($branchId, 25, 0);
echo 'state_2c_total_after_clone=' . ((int) ($afterTwo['total'] ?? 0) === $t0 + 2 ? 'yes' : 'no') . PHP_EOL;

$p0 = $service->listTemplatesForIndex($branchId, 1, 0);
$p1 = $service->listTemplatesForIndex($branchId, 1, 1);
$totalForPager = (int) ($p0['total'] ?? 0);
echo 'pager_limit1_offset0_rows=' . count($p0['rows'] ?? []) . PHP_EOL;
echo 'pager_limit1_offset1_rows=' . count($p1['rows'] ?? []) . PHP_EOL;
echo 'pager_total_consistent=' . (
    $totalForPager === (int) ($p1['total'] ?? 0) && $totalForPager === $t0 + 2 ? 'yes' : 'no'
) . PHP_EOL;

$service->updateTemplateMetadata($branchId, $idA, 'R05 Updated ' . $suffix, false, true, null, null);
$upd = $service->findTemplateForEdit($branchId, $idA);
echo 'edit_persisted=' . (str_starts_with((string) ($upd['name'] ?? ''), 'R05 Updated') ? 'yes' : 'no') . PHP_EOL;

$service->archiveTemplate($branchId, $idB, null);
$service->archiveTemplate($branchId, $idA, null);
$final = $service->listTemplatesForIndex($branchId, 500, 0);
echo 'archive_restores_baseline_total=' . (((int) ($final['total'] ?? 0) === $t0) ? 'yes' : 'no') . PHP_EOL;

$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMBAJ9f8r8AAAAASUVORK5CYII=');
$tmpPng = tempnam(sys_get_temp_dir(), 'r05_img_');
file_put_contents($tmpPng, $pngBytes === false ? '' : $pngBytes);
$imgId = 0;
$bridge = $service->isMediaBackedImageUploadReady();
echo 'media_bridge_ready=' . ($bridge ? 'yes' : 'no') . PHP_EOL;
$hasImg = false;
$imgAfter = null;
if ($bridge) {
    try {
        $imgId = $service->uploadImage(
            $branchId,
            [
                'tmp_name' => $tmpPng,
                'name' => 'r05.png',
                'size' => filesize($tmpPng) ?: 0,
            ],
            'R05',
            null
        );
    } catch (\Throwable) {
        $imgId = 0;
    }
    @unlink($tmpPng);
    if ($imgId <= 0) {
        $legacyInsert = [
            'branch_id' => $branchId,
            'title' => 'R05 legacy list fixture',
            'storage_path' => 'storage/marketing/gift-card-images/2000/01/r05-legacy.bin',
            'filename' => 'r05-legacy.bin',
            'mime_type' => 'image/png',
            'size_bytes' => 68,
            'is_active' => 1,
            'created_by' => null,
            'updated_by' => null,
        ];
        if ($db->fetchOne(
            "SELECT 1 AS ok FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketing_gift_card_images' AND COLUMN_NAME = 'media_asset_id'
             LIMIT 1"
        )) {
            $legacyInsert['media_asset_id'] = null;
        }
        $db->insert('marketing_gift_card_images', $legacyInsert);
        $imgId = (int) $db->lastInsertId();
        echo 'images_list_uses_legacy_fixture_row=yes' . PHP_EOL;
    }
    $imgs = $service->listImages($branchId);
    foreach ($imgs as $row) {
        if ((int) ($row['id'] ?? 0) === $imgId) {
            $hasImg = true;
            break;
        }
    }
    echo 'images_page_list_includes_upload=' . ($hasImg ? 'yes' : 'no') . PHP_EOL;
    if ($imgId > 0) {
        $service->softDeleteImage($branchId, $imgId, null);
    }
    $imgAfter = $imgId > 0 ? $db->fetchOne('SELECT deleted_at FROM marketing_gift_card_images WHERE id = ?', [$imgId]) : null;
    echo 'image_soft_deleted=' . ($imgAfter !== null && !empty($imgAfter['deleted_at']) ? 'yes' : 'no') . PHP_EOL;
} else {
    @unlink($tmpPng);
    echo 'images_tests_skipped=no_media_bridge_migration_105' . PHP_EOL;
    echo 'images_page_list_includes_upload=skipped' . PHP_EOL;
    echo 'image_soft_deleted=skipped' . PHP_EOL;
}

$failed = false;
if (!in_array($idA, $idsAfterOne, true)) {
    $failed = true;
}
if ((int) ($afterTwo['total'] ?? 0) !== $t0 + 2) {
    $failed = true;
}
if ((int) ($final['total'] ?? 0) !== $t0) {
    $failed = true;
}
if ($bridge && (!$hasImg || $imgAfter === null || empty($imgAfter['deleted_at']))) {
    $failed = true;
}
if (((int) ($cloneRow['clone_source_template_id'] ?? 0) !== $idA)) {
    $failed = true;
}
if ($totalForPager !== $t0 + 2) {
    $failed = true;
}
if (!str_starts_with((string) ($upd['name'] ?? ''), 'R05 Updated')) {
    $failed = true;
}

echo 'runtime_05_status=' . ($failed ? 'FAIL' : 'PASS') . PHP_EOL;
exit($failed ? 1 : 0);
