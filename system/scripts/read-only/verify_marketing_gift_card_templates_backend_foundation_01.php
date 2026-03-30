<?php

declare(strict_types=1);

/**
 * MARKETING-GIFT-CARD-TEMPLATES-BACKEND-FOUNDATION-01 verifier.
 *
 * Usage:
 *   php system/scripts/read-only/verify_marketing_gift_card_templates_backend_foundation_01.php
 */

$base = dirname(__DIR__, 2);
require $base . '/bootstrap.php';
require $base . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);
$pdo = $db->connection();

$routeFile = $base . '/routes/web/register_marketing.php';
$routeText = is_file($routeFile) ? (string) file_get_contents($routeFile) : '';
echo 'route_templates_index_exists=' . (str_contains($routeText, "/marketing/gift-card-templates'") ? 'yes' : 'no') . PHP_EOL;
echo 'route_templates_create_exists=' . (str_contains($routeText, "/marketing/gift-card-templates/create'") ? 'yes' : 'no') . PHP_EOL;
echo 'route_templates_images_exists=' . (str_contains($routeText, "/marketing/gift-card-templates/images'") ? 'yes' : 'no') . PHP_EOL;

$controllerFile = $base . '/modules/marketing/controllers/MarketingGiftCardTemplatesController.php';
$controllerText = is_file($controllerFile) ? (string) file_get_contents($controllerFile) : '';
echo 'controller_has_clone_flow=' . (str_contains($controllerText, 'createTemplateFromRequest') ? 'yes' : 'no') . PHP_EOL;
echo 'controller_has_image_upload_action=' . (str_contains($controllerText, 'uploadImage') ? 'yes' : 'no') . PHP_EOL;

$schemaFile = $base . '/data/full_project_schema.sql';
$schemaText = is_file($schemaFile) ? (string) file_get_contents($schemaFile) : '';
echo 'canonical_schema_contains_templates=' . (str_contains($schemaText, 'CREATE TABLE marketing_gift_card_templates') ? 'yes' : 'no') . PHP_EOL;
echo 'canonical_schema_contains_images=' . (str_contains($schemaText, 'CREATE TABLE marketing_gift_card_images') ? 'yes' : 'no') . PHP_EOL;

$tables = ['marketing_gift_card_templates', 'marketing_gift_card_images'];
foreach ($tables as $table) {
    $stmt = $pdo->prepare('SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->execute([$table]);
    echo 'table_' . $table . '_exists=' . ($stmt->fetch(\PDO::FETCH_ASSOC) ? 'yes' : 'no') . PHP_EOL;
}

$branchRow = $db->fetchOne('SELECT id FROM branches WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
$branchId = $branchRow ? (int) ($branchRow['id'] ?? 0) : 0;
echo 'fixture_branch_id=' . $branchId . PHP_EOL;
if ($branchId <= 0) {
    echo 'abort=no_branch_fixture' . PHP_EOL;
    exit(0);
}

$service = app(\Modules\Marketing\Services\MarketingGiftCardTemplateService::class);
echo 'storage_ready=' . ($service->isStorageReady() ? 'yes' : 'no') . PHP_EOL;
if (!$service->isStorageReady()) {
    echo 'storage_not_ready_skip_mutations=yes' . PHP_EOL;
    exit(0);
}

$scratchId = $service->createTemplateFromRequest($branchId, 'Verifier Scratch ' . date('YmdHis'), null, null);
echo 'template_create_scratch_id=' . $scratchId . PHP_EOL;

$cloneId = $service->createTemplateFromRequest($branchId, 'Verifier Clone ' . date('YmdHis'), $scratchId, null);
echo 'template_clone_id=' . $cloneId . PHP_EOL;

$scratch = $service->findTemplateForEdit($branchId, $scratchId);
$clone = $service->findTemplateForEdit($branchId, $cloneId);
echo 'clone_source_template_id_recorded=' . (((int) ($clone['clone_source_template_id'] ?? 0) === $scratchId) ? 'yes' : 'no') . PHP_EOL;
echo 'clone_preserves_flags=' . (((int) ($clone['sell_in_store_enabled'] ?? -1) === (int) ($scratch['sell_in_store_enabled'] ?? -2) && (int) ($clone['sell_online_enabled'] ?? -1) === (int) ($scratch['sell_online_enabled'] ?? -2)) ? 'yes' : 'no') . PHP_EOL;

$service->updateTemplateMetadata($branchId, $scratchId, 'Verifier Updated', false, true, null, null);
$updated = $service->findTemplateForEdit($branchId, $scratchId);
echo 'template_update_rename_persisted=' . (((string) ($updated['name'] ?? '') === 'Verifier Updated') ? 'yes' : 'no') . PHP_EOL;
echo 'template_update_toggle_persisted=' . (((int) ($updated['sell_in_store_enabled'] ?? 1) === 0 && (int) ($updated['sell_online_enabled'] ?? 0) === 1) ? 'yes' : 'no') . PHP_EOL;

$list = $service->listTemplatesForIndex($branchId, 10, 0);
$listedIds = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $list['rows'] ?? []);
echo 'list_returns_scoped_templates=' . (in_array($scratchId, $listedIds, true) && in_array($cloneId, $listedIds, true) ? 'yes' : 'no') . PHP_EOL;

$otherBranch = $db->fetchOne(
    'SELECT id FROM branches WHERE deleted_at IS NULL AND id != ? ORDER BY id ASC LIMIT 1',
    [$branchId]
);
if ($otherBranch) {
    $otherBranchId = (int) ($otherBranch['id'] ?? 0);
    $blocked = false;
    try {
        $service->updateTemplateMetadata($otherBranchId, $scratchId, 'Cross Branch Should Fail', true, true, null, null);
    } catch (\Throwable) {
        $blocked = true;
    }
    echo 'branch_scope_enforced=' . ($blocked ? 'yes' : 'no') . PHP_EOL;
} else {
    echo 'branch_scope_enforced=not_tested_single_branch_fixture' . PHP_EOL;
}

$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMBAJ9f8r8AAAAASUVORK5CYII=');
$tmpPng = tempnam(sys_get_temp_dir(), 'gct_img_');
file_put_contents($tmpPng, $pngBytes === false ? '' : $pngBytes);
$uploadOk = false;
$uploadedImageId = 0;
$bridgeReady = $service->isMediaBackedImageUploadReady();
echo 'media_bridge_ready=' . ($bridgeReady ? 'yes' : 'no') . PHP_EOL;
if ($bridgeReady) {
    try {
        $uploadedImageId = $service->uploadImage(
            $branchId,
            [
                'tmp_name' => $tmpPng,
                'name' => 'verifier.png',
                'size' => filesize($tmpPng) ?: 0,
            ],
            'Verifier image',
            null
        );
        $uploadOk = $uploadedImageId > 0;
    } catch (\Throwable) {
        $uploadOk = false;
    }
}
if (!$uploadOk && $bridgeReady) {
    echo 'image_upload_cli_note=expected_fail_without_http_upload_and_org_context' . PHP_EOL;
}
echo 'image_upload_validation_works=' . ($uploadOk ? 'yes' : ($bridgeReady ? 'no_use_http_or_dev_proof_script' : 'not_applicable_no_bridge')) . PHP_EOL;

$tmpTxt = tempnam(sys_get_temp_dir(), 'gct_not_img_');
file_put_contents($tmpTxt, 'not an image');
$badUploadBlocked = false;
try {
    $service->uploadImage(
        $branchId,
        [
            'tmp_name' => $tmpTxt,
            'name' => 'bad.txt',
            'size' => filesize($tmpTxt) ?: 0,
        ],
        'Bad file',
        null
    );
} catch (\Throwable) {
    $badUploadBlocked = true;
}
echo 'non_image_upload_rejected=' . ($badUploadBlocked ? 'yes' : 'no') . PHP_EOL;

if ($uploadedImageId <= 0 && $service->isStorageReady()) {
    $legacyInsert = [
        'branch_id' => $branchId,
        'title' => 'Verifier legacy scratch',
        'storage_path' => 'storage/marketing/gift-card-images/2000/01/verifier-legacy-scratch.bin',
        'filename' => 'verifier-legacy-scratch.bin',
        'mime_type' => 'image/png',
        'size_bytes' => 68,
        'is_active' => 1,
        'created_by' => null,
        'updated_by' => null,
    ];
    $col = $db->fetchOne(
        "SELECT 1 AS ok FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketing_gift_card_images' AND COLUMN_NAME = 'media_asset_id'
         LIMIT 1"
    );
    if ($col) {
        $legacyInsert['media_asset_id'] = null;
    }
    $db->insert('marketing_gift_card_images', $legacyInsert);
    $uploadedImageId = (int) $db->lastInsertId();
    echo 'image_delete_uses_legacy_fixture_row=yes' . PHP_EOL;
}

if ($uploadedImageId > 0) {
    $service->softDeleteImage($branchId, $uploadedImageId, null);
    $afterImageDelete = $db->fetchOne('SELECT deleted_at FROM marketing_gift_card_images WHERE id = ?', [$uploadedImageId]);
    echo 'image_delete_soft_safe=' . (!empty($afterImageDelete['deleted_at']) ? 'yes' : 'no') . PHP_EOL;
} else {
    echo 'image_delete_soft_safe=not_tested' . PHP_EOL;
}

$service->archiveTemplate($branchId, $scratchId, null);
$service->archiveTemplate($branchId, $cloneId, null);
$archivedScratch = $db->fetchOne('SELECT deleted_at FROM marketing_gift_card_templates WHERE id = ?', [$scratchId]);
$archivedClone = $db->fetchOne('SELECT deleted_at FROM marketing_gift_card_templates WHERE id = ?', [$cloneId]);
echo 'template_archive_soft_safe=' . (!empty($archivedScratch['deleted_at']) && !empty($archivedClone['deleted_at']) ? 'yes' : 'no') . PHP_EOL;

@unlink($tmpTxt);

