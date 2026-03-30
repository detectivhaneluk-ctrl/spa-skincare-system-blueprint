<?php

declare(strict_types=1);

/**
 * Read-only verifier: MARKETING-GIFT-CARD-IMAGE-DELETE-HARDENING-IMPLEMENTATION-01
 *
 * Static checks only (no DB). Run from repo root:
 *   php system/scripts/read-only/verify_marketing_gift_card_image_delete_hardening_01.php
 */

$base = dirname(__DIR__, 2);
$repo = $base;
$checks = [];

$repoFile = $repo . '/modules/marketing/repositories/MarketingGiftCardTemplateRepository.php';
$repoText = is_file($repoFile) ? (string) file_get_contents($repoFile) : '';

$checks['repo_no_db_rowcount'] = !str_contains($repoText, '$this->db->rowCount()');
$checks['repo_uses_delete_stmt_rowcount'] = str_contains($repoText, '$deleteStmt = $this->db->query(\'DELETE FROM media_assets')
    && str_contains($repoText, '$deleteStmt->rowCount()');
$checks['repo_clear_archived_method'] = str_contains($repoText, 'function clearArchivedTemplateImageIdForLibraryImage');
$checks['repo_soft_delete_returns_int'] = str_contains($repoText, 'function softDeleteImageInBranch(int $imageId')
    && str_contains($repoText, 'return $stmt->rowCount()');

$svcFile = $repo . '/modules/marketing/services/MarketingGiftCardTemplateService.php';
$svcText = is_file($svcFile) ? (string) file_get_contents($svcFile) : '';

$checks['svc_cleanup_processed_dir'] = str_contains($svcText, 'public/media/processed/')
    && str_contains($svcText, 'cleanupDeletedMediaAssetFiles');
$checks['svc_staging_pattern'] = str_contains($svcText, '__stg_')
    && str_contains($svcText, 'removeWorkerStagingDirsForAsset');
$checks['svc_legacy_resolver'] = str_contains($svcText, 'resolveLegacyLibraryAbsoluteFile');
$checks['svc_log_prefix'] = str_contains($svcText, '[gc-image-delete]');

$ctlFile = $repo . '/modules/marketing/controllers/MarketingGiftCardTemplatesController.php';
$ctlText = is_file($ctlFile) ? (string) file_get_contents($ctlFile) : '';

$checks['ctl_uses_result_flash'] = str_contains($ctlText, '$result = $this->service->softDeleteImage')
    && str_contains($ctlText, 'flash_type');

foreach ($checks as $name => $ok) {
    echo $name . '=' . ($ok ? 'yes' : 'no') . PHP_EOL;
}

$pass = !in_array(false, $checks, true);
echo 'verify_marketing_gift_card_image_delete_hardening_01_status=' . ($pass ? 'PASS' : 'FAIL') . PHP_EOL;
exit($pass ? 0 : 1);
