<?php

declare(strict_types=1);

/**
 * MARKETING-GIFT-CARD-IMAGE-PIPELINE-CANONICALIZATION-01 — read-only proof.
 *
 * Verifies:
 * - marketing_gift_card_images.media_asset_id exists (migration 105)
 * - MarketingGiftCardTemplateService uses MediaAssetUploadService (no direct storeUploadedImage path)
 * - Rows with media_asset_id join to media_assets; jobs exist for those assets
 * - Legacy rows (media_asset_id IS NULL) remain listable
 * - template.image_id FK unchanged (schema / code contract)
 *
 * Usage (from repo root or system/):
 *   php system/scripts/read-only/verify_marketing_gift_card_images_canonical_media_bridge_01.php
 *
 * Full upload pipeline (is_uploaded_file + org context) is exercised via HTTP:
 *   php system/scripts/dev-only/proof_media_post_assets_http.php
 *   (and marketing form POST /marketing/gift-card-templates/images in browser)
 */

$base = dirname(__DIR__, 2);
require $base . '/bootstrap.php';
require $base . '/modules/bootstrap.php';

$pdo = app(\Core\App\Database::class)->connection();

$svcPath = $base . '/modules/marketing/services/MarketingGiftCardTemplateService.php';
$svcText = is_file($svcPath) ? (string) file_get_contents($svcPath) : '';
echo 'static_service_uses_media_upload_service=' . (str_contains($svcText, 'MediaAssetUploadService') && str_contains($svcText, 'acceptUpload') ? 'yes' : 'no') . PHP_EOL;
echo 'static_service_no_store_uploaded_image=' . (!str_contains($svcText, 'storeUploadedImage') ? 'yes' : 'no') . PHP_EOL;
echo 'static_service_no_gift_card_direct_storage_root=' . (!str_contains($svcText, 'storage/marketing/gift-card-images') ? 'yes' : 'no') . PHP_EOL;

$col = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketing_gift_card_images' AND COLUMN_NAME = 'media_asset_id'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
echo 'schema_marketing_gift_card_images_has_media_asset_id=' . ($col ? 'yes' : 'no') . PHP_EOL;

$fk = $pdo->query(
    "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketing_gift_card_images'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = 'fk_mkt_gc_images_media_asset'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
echo 'schema_fk_mkt_gc_images_media_asset=' . ($fk ? 'yes' : 'no') . PHP_EOL;

$tplFk = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketing_gift_card_templates'
       AND COLUMN_NAME = 'image_id' AND REFERENCED_TABLE_NAME = 'marketing_gift_card_images'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
echo 'schema_template_image_id_fk_to_library=' . ($tplFk ? 'yes' : 'no') . PHP_EOL;

$legacyCount = $pdo->query(
    "SELECT COUNT(*) AS c FROM marketing_gift_card_images WHERE deleted_at IS NULL AND media_asset_id IS NULL"
)->fetch(PDO::FETCH_ASSOC);
echo 'runtime_legacy_library_rows=' . (int) ($legacyCount['c'] ?? 0) . PHP_EOL;

$linked = $pdo->query(
    'SELECT i.id AS library_id, i.media_asset_id, a.id AS asset_id, a.status AS asset_status
     FROM marketing_gift_card_images i
     INNER JOIN media_assets a ON a.id = i.media_asset_id
     WHERE i.deleted_at IS NULL
     ORDER BY i.id DESC
     LIMIT 5'
)->fetchAll(PDO::FETCH_ASSOC);
echo 'runtime_media_linked_library_samples=' . count($linked) . PHP_EOL;
foreach ($linked as $i => $row) {
    echo 'sample_' . $i . '=' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

if ($linked !== []) {
    $aid = (int) ($linked[0]['media_asset_id'] ?? 0);
    if ($aid > 0) {
        $job = $pdo->prepare('SELECT id, status, job_type FROM media_jobs WHERE media_asset_id = ? ORDER BY id ASC LIMIT 1');
        $job->execute([$aid]);
        $jr = $job->fetch(PDO::FETCH_ASSOC);
        echo 'sample_has_media_job_row=' . ($jr ? 'yes' : 'no') . PHP_EOL;
        if ($jr) {
            echo 'sample_media_job=' . json_encode($jr, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    }
}

$dbCli = app(\Core\App\Database::class);
$branchRow = $dbCli->fetchOne('SELECT id FROM branches WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
$branchId = $branchRow ? (int) ($branchRow['id'] ?? 0) : 0;
if ($branchId > 0 && $col) {
    $b = $dbCli->fetchOne('SELECT organization_id FROM branches WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$branchId]);
    $orgId = (int) ($b['organization_id'] ?? 0);
    if ($orgId > 0) {
        app(\Core\Branch\BranchContext::class)->setCurrentBranchId($branchId);
        app(\Core\Organization\OrganizationContext::class)->setFromResolution(
            $orgId,
            \Core\Organization\OrganizationContext::MODE_BRANCH_DERIVED
        );
    }
    $svc = app(\Modules\Marketing\Services\MarketingGiftCardTemplateService::class);
    $listed = $svc->listImages($branchId);
    $legacyListed = 0;
    $mediaListed = 0;
    foreach ($listed as $r) {
        if ((string) ($r['library_status'] ?? '') === 'legacy') {
            ++$legacyListed;
        }
        if (!empty($r['media_asset_id'])) {
            ++$mediaListed;
        }
    }
    echo 'runtime_list_images_branch_' . $branchId . '_total=' . count($listed) . PHP_EOL;
    echo 'runtime_list_images_legacy_status_rows=' . $legacyListed . PHP_EOL;
    echo 'runtime_list_images_with_media_asset_id=' . $mediaListed . PHP_EOL;
}

$ok = str_contains($svcText, 'MediaAssetUploadService')
    && str_contains($svcText, 'acceptUpload')
    && !str_contains($svcText, 'storeUploadedImage')
    && !str_contains($svcText, 'storage/marketing/gift-card-images')
    && (bool) $col
    && (bool) $tplFk;

echo 'verify_marketing_gift_card_images_canonical_media_bridge_01_status=' . ($ok ? 'PASS' : 'FAIL') . PHP_EOL;
exit($ok ? 0 : 1);
