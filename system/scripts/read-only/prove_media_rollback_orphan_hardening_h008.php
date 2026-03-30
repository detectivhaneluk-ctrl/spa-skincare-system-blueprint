<?php

declare(strict_types=1);

/**
 * H-008 read-only proof: media write flows align committed filesystem state with DB commit boundaries.
 *
 * Usage (from system/): php scripts/read-only/prove_media_rollback_orphan_hardening_h008.php
 */

$system = dirname(__DIR__, 2);
$repoRoot = dirname($system);
$uploadSvc = $system . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'MediaAssetUploadService.php';
$processor = $repoRoot . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'image-pipeline' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'processor.mjs';

$errors = [];

if (!is_readable($uploadSvc)) {
    $errors[] = 'missing_MediaAssetUploadService';
} else {
    $php = file_get_contents($uploadSvc);
    if ($php === false) {
        $errors[] = 'unreadable_MediaAssetUploadService';
    } else {
        if (!str_contains($php, '.incoming')) {
            $errors[] = 'upload_missing_incoming_staging';
        }
        if (!str_contains($php, 'inTransaction()')) {
            $errors[] = 'upload_missing_transaction_guard';
        }
        if (!str_contains($php, 'Failed to finalize quarantine file after database commit')) {
            $errors[] = 'upload_missing_finalize_error_message';
        }
        if (!str_contains($php, 'Rows are already committed')) {
            $errors[] = 'upload_missing_post_commit_finalize_comment';
        }
    }
}

if (!is_readable($processor)) {
    $errors[] = 'missing_processor_mjs';
} else {
    $js = file_get_contents($processor);
    if ($js === false) {
        $errors[] = 'unreadable_processor_mjs';
    } else {
        if (!str_contains($js, 'variantStagingDirAbsolute') && !str_contains($js, '__stg_')) {
            $errors[] = 'worker_missing_staging_dir';
        }
        if (!str_contains($js, 'promoteVariantStagingToFinal')) {
            $errors[] = 'worker_missing_promote';
        }
        if (!str_contains($js, 'path.join(stagingAbs')) {
            $errors[] = 'worker_variants_not_written_to_staging';
        }
        // Order: first processClaimedJob commit, then promote (avoid post-rollback promote in same try/catch as encode).
        $fnStart = strpos($js, 'export async function processClaimedJob');
        if ($fnStart === false) {
            $errors[] = 'worker_missing_processClaimedJob';
        } else {
            $slice = substr($js, $fnStart);
            $c = strpos($slice, 'await conn.commit()');
            $p = strpos($slice, 'promoteVariantStagingToFinal(stagingAbs, finalAbs)');
            if ($c === false || $p === false || $c >= $p) {
                $errors[] = 'worker_commit_before_promote_order';
            }
            // Rollback path must clear staging without relying on final dir alone.
            if (!str_contains($slice, 'await conn.rollback()') || !str_contains($slice, 'rimrafOutputDir(stagingAbs)')) {
                $errors[] = 'worker_missing_rollback_staging_cleanup';
            }
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "H-008 proof FAILED: " . implode(', ', $errors) . PHP_EOL);
    exit(1);
}

echo 'H-008 proof OK: upload quarantine staging + worker variant staging/promote after commit verified (read-only source checks).' . PHP_EOL;
exit(0);
