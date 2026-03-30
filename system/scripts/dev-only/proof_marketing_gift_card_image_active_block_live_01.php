<?php

declare(strict_types=1);

/**
 * MARKETING-GIFT-CARD-IMAGE-ACTIVE-REFERENCE-BLOCK-LIVE-PROOF-01
 *
 * HTTP proof: active template image_id blocks library delete; DB/FS unchanged; error flash.
 * Optional: archived template image_id cleared after allowed delete on disposable image.
 *
 * From system/:
 *   set MEDIA_SMOKE_PASSWORD=...
 *   php scripts/dev-only/proof_marketing_gift_card_image_active_block_live_01.php --base-url=http://127.0.0.1:8899 --email=tenant-admin-a@example.test
 */

if (!function_exists('curl_init')) {
    fwrite(STDERR, "ext-curl required\n");
    exit(2);
}

$baseUrl = 'http://127.0.0.1:8899';
$email = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = rtrim(substr($arg, 11), '/');
    }
    if (str_starts_with($arg, '--email=')) {
        $email = substr($arg, 8);
    }
}
$password = getenv('MEDIA_SMOKE_PASSWORD') ?: '';
if ($email === '' || $password === '') {
    fwrite(STDERR, "Set MEDIA_SMOKE_PASSWORD and --email=...\n");
    exit(2);
}

$systemRoot = dirname(__DIR__, 2);
$csrfName = 'csrf_token';

function httpRequest(string $method, string $url, string $cookieFile, ?string $postFields = null, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    }
    if ($extraHeaders !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $extraHeaders);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => is_string($body) ? $body : ''];
}

function extractCsrf(string $html, string $name): ?string
{
    if (preg_match('/name="' . preg_quote($name, '/') . '"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/name=\'' . preg_quote($name, '/') . '\'\s+value=\'([^\']+)\'/', $html, $m)) {
        return $m[1];
    }

    return null;
}

function countFilesRecursive(string $dir): int
{
    if (!is_dir($dir)) {
        return -1;
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            ++$n;
        }
    }

    return $n;
}

require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';
$db = app(\Core\App\Database::class);

$cookieFile = sys_get_temp_dir() . '/spa_gc_active_block_' . bin2hex(random_bytes(4)) . '.txt';

// --- Part A setup: active template -> library image ---
$pair = $db->fetchOne(
    "SELECT t.id AS template_id, t.branch_id, t.image_id AS image_id, t.name AS template_name
     FROM marketing_gift_card_templates t
     WHERE t.deleted_at IS NULL
       AND t.image_id IS NOT NULL
       AND EXISTS (
         SELECT 1 FROM marketing_gift_card_images i
         WHERE i.id = t.image_id AND i.deleted_at IS NULL AND i.media_asset_id IS NOT NULL
       )
     ORDER BY t.id ASC
     LIMIT 1"
);

$restoredTemplateImageId = null;
$templateIdForRestore = 0;

if ($pair === null) {
    $img = $db->fetchOne(
        "SELECT i.id AS image_id, i.branch_id, i.media_asset_id
         FROM marketing_gift_card_images i
         WHERE i.deleted_at IS NULL AND i.media_asset_id IS NOT NULL
         ORDER BY i.id DESC
         LIMIT 1"
    );
    $tpl = $img !== null
        ? $db->fetchOne(
            "SELECT id, branch_id, image_id FROM marketing_gift_card_templates
             WHERE deleted_at IS NULL AND branch_id = ?
             ORDER BY id ASC
             LIMIT 1",
            [(int) $img['branch_id']]
        )
        : null;
    if ($img === null || $tpl === null) {
        fwrite(STDERR, "No suitable active image + template in same branch for proof.\n");
        exit(3);
    }
    $templateIdForRestore = (int) $tpl['id'];
    $restoredTemplateImageId = $tpl['image_id'] !== null ? (int) $tpl['image_id'] : null;
    $db->query(
        'UPDATE marketing_gift_card_templates SET image_id = ? WHERE id = ? AND branch_id = ?',
        [(int) $img['image_id'], $templateIdForRestore, (int) $img['branch_id']]
    );
    $pair = [
        'template_id' => $templateIdForRestore,
        'branch_id' => (int) $img['branch_id'],
        'image_id' => (int) $img['image_id'],
        'template_name' => '(assigned for proof)',
    ];
}

$imageId = (int) $pair['image_id'];
$templateId = (int) $pair['template_id'];
$branchId = (int) $pair['branch_id'];

echo "step1_template_id={$templateId}\n";
echo "step1_target_image_id={$imageId}\n";
echo "step1_branch_id={$branchId}\n";

$verify = $db->fetchOne(
    'SELECT t.id, t.deleted_at, t.image_id
     FROM marketing_gift_card_templates t
     WHERE t.id = ?',
    [$templateId]
);
echo 'step2_db_template=' . json_encode($verify, JSON_UNESCAPED_UNICODE) . "\n";
$step2Ok = $verify !== null
    && $verify['deleted_at'] === null
    && (int) ($verify['image_id'] ?? 0) === $imageId;
echo 'step2_active_template_has_image_id=' . ($step2Ok ? 'yes' : 'no') . "\n";

$imgRow = $db->fetchOne(
    'SELECT i.id, i.deleted_at, i.media_asset_id, ma.organization_id, ma.branch_id AS ma_branch
     FROM marketing_gift_card_images i
     INNER JOIN media_assets ma ON ma.id = i.media_asset_id
     WHERE i.id = ?',
    [$imageId]
);
if ($imgRow === null) {
    fwrite(STDERR, "Image row or media join missing.\n");
    exit(4);
}
$maid = (int) $imgRow['media_asset_id'];
$orgId = (int) $imgRow['organization_id'];
$maBranch = (int) $imgRow['ma_branch'];

$vCountBefore = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM media_asset_variants WHERE media_asset_id = ?', [$maid])['c'] ?? 0);
$jRow = $db->fetchOne(
    'SELECT id, status, job_type FROM media_jobs WHERE media_asset_id = ? ORDER BY id DESC LIMIT 1',
    [$maid]
);
$jCountBefore = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM media_jobs WHERE media_asset_id = ?', [$maid])['c'] ?? 0);
$processedDir = $systemRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'processed'
    . DIRECTORY_SEPARATOR . $orgId . DIRECTORY_SEPARATOR . $maBranch . DIRECTORY_SEPARATOR . $maid;
$fsFilesBefore = countFilesRecursive($processedDir);

echo "step2_db_media_asset_id={$maid}\n";
echo "step2_db_variant_count={$vCountBefore}\n";
echo "step2_db_job_count={$jCountBefore}\n";
echo 'step2_db_latest_job=' . json_encode($jRow ?: (object) [], JSON_UNESCAPED_UNICODE) . "\n";
echo "step6_fs_processed_dir=" . str_replace('\\', '/', $processedDir) . "\n";
echo "step6_fs_processed_file_count={$fsFilesBefore}\n";

$r = httpRequest('GET', $baseUrl . '/login', $cookieFile);
if ($r['code'] !== 200) {
    fwrite(STDERR, "GET /login failed {$r['code']}\n");
    exit(5);
}
$csrfLogin = extractCsrf($r['body'], $csrfName);
$postLogin = http_build_query([
    'email' => $email,
    'password' => $password,
    $csrfName => $csrfLogin,
], '', '&');
$r = httpRequest('POST', $baseUrl . '/login', $cookieFile, $postLogin, ['Content-Type: application/x-www-form-urlencoded']);
if ($r['code'] !== 302 && $r['code'] !== 303) {
    fwrite(STDERR, "POST /login failed {$r['code']}\n");
    @unlink($cookieFile);
    exit(6);
}

$r = httpRequest('GET', $baseUrl . '/marketing/gift-card-templates/images', $cookieFile);
if ($r['code'] !== 200) {
    fwrite(STDERR, "GET images failed {$r['code']}\n");
    @unlink($cookieFile);
    exit(7);
}
$csrfImg = extractCsrf($r['body'], $csrfName);
if ($csrfImg === null || $csrfImg === '') {
    fwrite(STDERR, "CSRF missing\n");
    @unlink($cookieFile);
    exit(7);
}

$delUrl = $baseUrl . '/marketing/gift-card-templates/images/' . $imageId . '/delete';
$postDel = http_build_query([$csrfName => $csrfImg], '', '&');
$rDel = httpRequest('POST', $delUrl, $cookieFile, $postDel, ['Content-Type: application/x-www-form-urlencoded']);
echo "step3_delete_post_http_code={$rDel['code']}\n";

$imgAfter = $db->fetchOne(
    'SELECT id, deleted_at, media_asset_id FROM marketing_gift_card_images WHERE id = ?',
    [$imageId]
);
$maAfter = $db->fetchOne('SELECT id FROM media_assets WHERE id = ?', [$maid]);
$vAfter = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM media_asset_variants WHERE media_asset_id = ?', [$maid])['c'] ?? 0);
$jAfter = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM media_jobs WHERE media_asset_id = ?', [$maid])['c'] ?? 0);
$jRowAfter = $db->fetchOne(
    'SELECT id, status, job_type FROM media_jobs WHERE media_asset_id = ? ORDER BY id DESC LIMIT 1',
    [$maid]
);
$fsFilesAfter = countFilesRecursive($processedDir);

echo 'step5_db_library_after=' . json_encode($imgAfter ?: [], JSON_UNESCAPED_UNICODE) . "\n";
echo 'step5_db_media_asset_exists=' . ($maAfter !== null ? 'yes' : 'no') . "\n";
echo "step5_db_variant_after={$vAfter}\n";
echo "step5_db_job_after={$jAfter}\n";
echo 'step5_db_latest_job_after=' . json_encode($jRowAfter ?: (object) [], JSON_UNESCAPED_UNICODE) . "\n";
echo "step6_fs_processed_file_count_after={$fsFilesAfter}\n";

$rFlash = httpRequest('GET', $baseUrl . '/marketing/gift-card-templates/images', $cookieFile);
$bodyFlash = $rFlash['body'];
$hasErrorFlash = str_contains($bodyFlash, 'flash-error')
    || str_contains($bodyFlash, 'flash flash-error');
$hasMsg = str_contains($bodyFlash, 'active templates');
echo "step7_get_images_after_block_http_code={$rFlash['code']}\n";
echo 'step7_error_flash_present=' . ($hasErrorFlash ? 'yes' : 'no') . "\n";
echo 'step7_message_contains_active_templates=' . ($hasMsg ? 'yes' : 'no') . "\n";

$jobSame = ($jCountBefore === $jAfter)
    && (($jRow === null && $jRowAfter === null)
        || (
            (int) ($jRow['id'] ?? 0) === (int) ($jRowAfter['id'] ?? 0)
            && (string) ($jRow['status'] ?? '') === (string) ($jRowAfter['status'] ?? '')
        ));
$dbUnchanged = $imgAfter !== null
    && $imgAfter['deleted_at'] === null
    && (int) ($imgAfter['media_asset_id'] ?? 0) === $maid
    && $maAfter !== null
    && $vAfter === $vCountBefore
    && $jAfter === $jCountBefore
    && $jobSame;
$fsUnchanged = $fsFilesBefore === $fsFilesAfter && $fsFilesBefore >= 0;

$step4 = $dbUnchanged && $fsUnchanged;
echo 'step4_delete_blocked_db_fs_unchanged=' . ($step4 ? 'yes' : 'no') . "\n";

// Restore template if we assigned for proof
if ($templateIdForRestore > 0) {
    $db->query(
        'UPDATE marketing_gift_card_templates SET image_id = ? WHERE id = ? AND branch_id = ?',
        [$restoredTemplateImageId, $templateIdForRestore, $branchId]
    );
    echo "restored_template_{$templateIdForRestore}_image_id=" . ($restoredTemplateImageId === null ? 'null' : (string) $restoredTemplateImageId) . "\n";
}

// --- Part B: archived reference cleared after allowed delete ---
$archived = $db->fetchOne(
    'SELECT id, branch_id, image_id FROM marketing_gift_card_templates
     WHERE deleted_at IS NOT NULL
     ORDER BY id ASC
     LIMIT 1'
);
$disposable = $db->fetchOne(
    "SELECT i.id AS image_id, i.branch_id, i.media_asset_id, ma.organization_id, ma.branch_id AS ma_branch
     FROM marketing_gift_card_images i
     INNER JOIN media_assets ma ON ma.id = i.media_asset_id
     WHERE i.deleted_at IS NULL
       AND NOT EXISTS (
         SELECT 1 FROM marketing_gift_card_templates t
         WHERE t.deleted_at IS NULL AND t.image_id = i.id
       )
     ORDER BY i.id DESC
     LIMIT 1"
);

$step8Status = 'skipped';
if ($archived !== null && $disposable !== null
    && (int) $archived['branch_id'] === (int) $disposable['branch_id']) {
    $aid = (int) $disposable['image_id'];
    $atid = (int) $archived['id'];
    $prevArchImg = $archived['image_id'] !== null ? (int) $archived['image_id'] : null;
    $db->query('UPDATE marketing_gift_card_templates SET image_id = ? WHERE id = ?', [$aid, $atid]);

    $r = httpRequest('GET', $baseUrl . '/marketing/gift-card-templates/images', $cookieFile);
    $csrf8 = $r['code'] === 200 ? extractCsrf($r['body'], $csrfName) : null;
    if ($csrf8) {
        $url8 = $baseUrl . '/marketing/gift-card-templates/images/' . $aid . '/delete';
        httpRequest('POST', $url8, $cookieFile, http_build_query([$csrfName => $csrf8], '', '&'), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }
    $archAfter = $db->fetchOne('SELECT image_id FROM marketing_gift_card_templates WHERE id = ?', [$atid]);
    $imgSoft = $db->fetchOne('SELECT deleted_at FROM marketing_gift_card_images WHERE id = ?', [$aid]);
    $step8Ok = !empty($imgSoft['deleted_at']) && ($archAfter === null || $archAfter['image_id'] === null);
    echo 'step8_archived_template_id=' . $atid . "\n";
    echo 'step8_disposable_image_deleted=' . (!empty($imgSoft['deleted_at']) ? 'yes' : 'no') . "\n";
    echo 'step8_archived_image_id_after_delete=' . json_encode($archAfter['image_id'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
    echo 'step8_archived_reference_nulled=' . ($step8Ok ? 'yes' : 'no') . "\n";
    $step8Status = $step8Ok ? 'pass' : 'fail';

    $db->query('UPDATE marketing_gift_card_templates SET image_id = ? WHERE id = ?', [$prevArchImg, $atid]);
    echo 'step8_restored_archived_template_image_id=' . json_encode($prevArchImg, JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "step8_archived_behavior=skipped_no_matching_archived_and_disposable_same_branch\n";
}

@unlink($cookieFile);

$accept = $step2Ok
    && ($rDel['code'] === 302 || $rDel['code'] === 303)
    && $dbUnchanged
    && $fsUnchanged
    && $hasErrorFlash
    && $hasMsg
    && ($step8Status === 'skipped' || $step8Status === 'pass');

echo 'proof_active_block_aggregate=' . ($accept ? 'ACCEPT' : 'REVIEW') . "\n";

exit($accept ? 0 : 10);
