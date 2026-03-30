<?php

declare(strict_types=1);

/**
 * MARKETING-GIFT-CARD-IMAGE-DELETE-LIVE-PROOF-AND-REPAIR-01
 *
 * Live HTTP + DB + filesystem proof for gift-card image library delete (hardening implementation).
 *
 * From system/:
 *   set MEDIA_SMOKE_PASSWORD=your_password
 *   php scripts/dev-only/proof_marketing_gift_card_image_delete_live_01.php --base-url=http://127.0.0.1:8899 --email=tenant-admin-a@example.test
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
    $respHeaders = '';
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, $line) use (&$respHeaders): int {
        $respHeaders .= $line;

        return strlen($line);
    });
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

    return ['code' => $code, 'body' => is_string($body) ? $body : '', 'headers' => $respHeaders];
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

function dirEmptyOrGone(string $path): bool
{
    if (!is_dir($path)) {
        return true;
    }
    $items = scandir($path);

    return $items === false || count($items) <= 2;
}

function listStagingDirsForAsset(string $branchProcessedParent, int $assetId): array
{
    if (!is_dir($branchProcessedParent)) {
        return [];
    }
    $out = [];
    $pattern = '/^__stg_' . preg_quote((string) $assetId, '/') . '_\d+$/';
    foreach (scandir($branchProcessedParent) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (preg_match($pattern, $name) === 1 && is_dir($branchProcessedParent . DIRECTORY_SEPARATOR . $name)) {
            $out[] = $branchProcessedParent . DIRECTORY_SEPARATOR . $name;
        }
    }

    return $out;
}

require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';
$db = app(\Core\App\Database::class);

$cookieFile = sys_get_temp_dir() . '/spa_gc_del_proof_' . bin2hex(random_bytes(4)) . '.txt';

$r = httpRequest('GET', $baseUrl . '/login', $cookieFile);
if ($r['code'] !== 200) {
    fwrite(STDERR, "GET /login failed {$r['code']}\n");
    exit(3);
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
    exit(4);
}

$r = httpRequest('GET', $baseUrl . '/marketing/gift-card-templates/images', $cookieFile);
echo "step1_get_images_http_code={$r['code']}\n";
$fatalSnips = ['rowCount()', 'Call to undefined method', 'Fatal error'];
$body1 = $r['body'];
foreach ($fatalSnips as $s) {
    echo 'step1_body_contains_' . preg_replace('/[^a-z0-9]+/i', '_', $s) . '=' . (str_contains($body1, $s) ? 'YES_FAIL' : 'no') . "\n";
}

if ($r['code'] !== 200) {
    fwrite(STDERR, "GET images failed {$r['code']}\n");
    @unlink($cookieFile);
    exit(5);
}

$otherBefore = $db->fetchOne(
    'SELECT id FROM marketing_gift_card_images WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1 OFFSET 1'
);
$otherImageId = (int) ($otherBefore['id'] ?? 0);
echo 'control_other_active_image_id=' . ($otherImageId > 0 ? (string) $otherImageId : 'none') . "\n";

$csrfImg = extractCsrf($body1, $csrfName);
if ($csrfImg === null || $csrfImg === '') {
    fwrite(STDERR, "CSRF missing on images page\n");
    @unlink($cookieFile);
    exit(5);
}

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true
);
$tmp = tempnam(sys_get_temp_dir(), 'gcdelproof');
if ($tmp === false || $png === false) {
    exit(6);
}
file_put_contents($tmp, $png);

$ch = curl_init($baseUrl . '/marketing/gift-card-templates/images');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    $csrfName => $csrfImg,
    'title' => 'DELETE-LIVE-PROOF ' . date('Y-m-d H:i:s'),
    'image' => new CURLFile($tmp, 'image/png', 'gcdel-proof.png'),
]);
$uploadBody = curl_exec($ch);
$uploadCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($tmp);

echo "step2_upload_http_code={$uploadCode}\n";
if ($uploadCode !== 302 && $uploadCode !== 303) {
    echo 'step2_upload_snip=' . substr(is_string($uploadBody) ? $uploadBody : '', 0, 400) . "\n";
    @unlink($cookieFile);
    exit(7);
}

$maxIdRow = $db->fetchOne('SELECT id FROM marketing_gift_card_images ORDER BY id DESC LIMIT 1');
$newId = (int) ($maxIdRow['id'] ?? 0);
echo "step3_new_library_image_id={$newId}\n";

$row = $db->fetchOne(
    'SELECT i.*, ma.organization_id, ma.branch_id AS ma_branch, ma.stored_basename, ma.status AS ma_status
     FROM marketing_gift_card_images i
     LEFT JOIN media_assets ma ON ma.id = i.media_asset_id
     WHERE i.id = ?',
    [$newId]
);
$maid = (int) ($row['media_asset_id'] ?? 0);
$orgId = (int) ($row['organization_id'] ?? 0);
$branchId = (int) ($row['ma_branch'] ?? $row['branch_id'] ?? 0);
$basename = (string) ($row['stored_basename'] ?? '');

echo 'step3_library_row=' . json_encode([
    'id' => $newId,
    'deleted_at' => $row['deleted_at'] ?? null,
    'media_asset_id' => $maid,
    'ma_status' => $row['ma_status'] ?? null,
], JSON_UNESCAPED_UNICODE) . "\n";

if ($maid <= 0) {
    fwrite(STDERR, "Upload did not produce media_asset_id (migrations 103/105 required).\n");
    @unlink($cookieFile);
    exit(8);
}

$quarantinePath = $systemRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'quarantine'
    . DIRECTORY_SEPARATOR . $orgId . DIRECTORY_SEPARATOR . $branchId . DIRECTORY_SEPARATOR . $basename;
$processedDir = $systemRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'processed'
    . DIRECTORY_SEPARATOR . $orgId . DIRECTORY_SEPARATOR . $branchId . DIRECTORY_SEPARATOR . $maid;
$branchProcessedParent = $systemRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'processed'
    . DIRECTORY_SEPARATOR . $orgId . DIRECTORY_SEPARATOR . $branchId;

$workerDir = realpath($systemRoot . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'image-pipeline');
$node = getenv('NODE_BINARY') ?: 'node';
if ($workerDir && is_file($workerDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'worker.mjs')) {
    putenv('MEDIA_SYSTEM_ROOT=' . $systemRoot);
    putenv('WORKER_ONCE=1');
    putenv('WORKER_MAX_JOBS=1');
    foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $k) {
        $v = env($k, null);
        if ($v !== null && $v !== '') {
            putenv($k . '=' . (string) $v);
        }
    }
    $prev = getcwd();
    chdir($workerDir);
    $wcode = 0;
    for ($i = 0; $i < 30; $i++) {
        $stNow = $db->fetchOne('SELECT status FROM media_assets WHERE id = ?', [$maid]);
        if (in_array((string) ($stNow['status'] ?? ''), ['ready', 'failed'], true)) {
            break;
        }
        passthru(escapeshellarg($node) . ' src/worker.mjs', $wcode);
        if ($wcode !== 0) {
            break;
        }
    }
    chdir($prev);
}

$vCount = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM media_asset_variants WHERE media_asset_id = ?', [$maid])['c'] ?? 0);
$jCount = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM media_jobs WHERE media_asset_id = ?', [$maid])['c'] ?? 0);
$maStatus = (string) ($db->fetchOne('SELECT status FROM media_assets WHERE id = ?', [$maid])['status'] ?? '');
echo "before_delete_db_media_assets_status={$maStatus}\n";
echo "before_delete_db_variant_count={$vCount}\n";
echo "before_delete_db_job_count={$jCount}\n";
echo 'before_delete_fs_quarantine_exists=' . (is_file($quarantinePath) ? 'yes' : 'no') . "\n";
echo 'before_delete_fs_processed_dir_exists=' . (is_dir($processedDir) ? 'yes' : 'no') . "\n";
$stgBefore = listStagingDirsForAsset($branchProcessedParent, $maid);
echo 'before_delete_fs_staging_dirs=' . json_encode($stgBefore, JSON_UNESCAPED_UNICODE) . "\n";

$r = httpRequest('GET', $baseUrl . '/marketing/gift-card-templates/images', $cookieFile);
$csrfDel = $r['code'] === 200 ? extractCsrf($r['body'], $csrfName) : null;
if ($csrfDel === null || $csrfDel === '') {
    fwrite(STDERR, "No CSRF for delete\n");
    @unlink($cookieFile);
    exit(9);
}

$delUrl = $baseUrl . '/marketing/gift-card-templates/images/' . $newId . '/delete';
$postDel = http_build_query([$csrfName => $csrfDel], '', '&');
$rDel = httpRequest('POST', $delUrl, $cookieFile, $postDel, ['Content-Type: application/x-www-form-urlencoded']);
echo "step4_delete_post_http_code={$rDel['code']}\n";

$libAfter = $db->fetchOne('SELECT id, deleted_at, media_asset_id FROM marketing_gift_card_images WHERE id = ?', [$newId]);
echo 'after_delete_library_row=' . json_encode($libAfter ?: [], JSON_UNESCAPED_UNICODE) . "\n";
$maAfter = $db->fetchOne('SELECT id FROM media_assets WHERE id = ?', [$maid]);
echo 'after_delete_media_assets_row_exists=' . ($maAfter !== null ? 'yes' : 'no') . "\n";
$vAfter = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM media_asset_variants WHERE media_asset_id = ?', [$maid])['c'] ?? 0);
$jAfter = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM media_jobs WHERE media_asset_id = ?', [$maid])['c'] ?? 0);
echo "after_delete_db_variant_count={$vAfter}\n";
echo "after_delete_db_job_count={$jAfter}\n";

echo 'after_delete_fs_processed_dir_gone=' . (dirEmptyOrGone($processedDir) ? 'yes' : 'no') . "\n";
echo 'after_delete_fs_quarantine_gone=' . (!is_file($quarantinePath) && !is_file($quarantinePath . '.incoming') ? 'yes' : 'no') . "\n";
$stgAfter = listStagingDirsForAsset($branchProcessedParent, $maid);
echo 'after_delete_fs_staging_dirs=' . json_encode($stgAfter, JSON_UNESCAPED_UNICODE) . "\n";

$r2 = httpRequest('GET', $baseUrl . '/marketing/gift-card-templates/images', $cookieFile);
$csrf2 = $r2['code'] === 200 ? extractCsrf($r2['body'], $csrfName) : null;
if ($csrf2) {
    $rSecond = httpRequest('POST', $delUrl, $cookieFile, http_build_query([$csrfName => $csrf2], '', '&'), [
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    echo "step12_second_delete_http_code={$rSecond['code']}\n";
    $follow = httpRequest('GET', $baseUrl . '/marketing/gift-card-templates/images', $cookieFile);
    $hasErr = str_contains($follow['body'], 'already deleted')
        || str_contains($follow['body'], 'flash-error')
        || str_contains($follow['body'], 'flash flash-error');
    echo 'step12_followup_get_images_http_code=' . $follow['code'] . "\n";
    echo 'step12_second_delete_flash_error_visible=' . ($hasErr ? 'yes' : 'no') . "\n";
}

if ($otherImageId > 0 && $otherImageId !== $newId) {
    $o = $db->fetchOne('SELECT id, deleted_at FROM marketing_gift_card_images WHERE id = ?', [$otherImageId]);
    echo 'step13_control_image=' . json_encode($o ?: [], JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "step13_control_image=skipped_no_second_library_row\n";
}

@unlink($cookieFile);

$ok = str_contains($body1, 'rowCount()') === false
    && str_contains($body1, 'Call to undefined method') === false
    && !empty($libAfter['deleted_at'])
    && $maAfter === null
    && $vAfter === 0
    && $jAfter === 0
    && dirEmptyOrGone($processedDir);

echo 'proof_aggregate_delete_ok=' . ($ok ? 'ACCEPT' : 'REVIEW') . "\n";

exit($ok ? 0 : 10);
