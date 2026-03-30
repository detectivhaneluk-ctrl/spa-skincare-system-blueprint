<?php

declare(strict_types=1);

/**
 * MARKETING-GIFT-CARD-IMAGE-PIPELINE-RUNTIME-PROOF-02
 *
 * Real multipart POST /marketing/gift-card-templates/images (session + CSRF), then DB + filesystem truth.
 *
 * Usage (from system/):
 *   set MEDIA_SMOKE_PASSWORD=...
 *   php scripts/dev-only/proof_marketing_gift_card_image_runtime_02.php --base-url=http://127.0.0.1:8899 --email=tenant-admin-a@example.test
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
    fwrite(STDERR, "Usage: ... --base-url=URL --email=...\nSet MEDIA_SMOKE_PASSWORD (e.g. TenantAdminA##2026 for smoke user).\n");
    exit(2);
}

$systemRoot = dirname(__DIR__, 2);
$marketingStorage = $systemRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'marketing' . DIRECTORY_SEPARATOR . 'gift-card-images';

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

function countFilesRecursive(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            ++$n;
        }
    }

    return $n;
}

function newestMtimeRecursive(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $max = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $max = max($max, $f->getMTime());
        }
    }

    return $max;
}

require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';
$db = app(\Core\App\Database::class);

$maxImgBefore = (int) ($db->fetchOne('SELECT COALESCE(MAX(id), 0) AS m FROM marketing_gift_card_images')['m'] ?? 0);
$filesBefore = countFilesRecursive($marketingStorage);
$mtimeBefore = newestMtimeRecursive($marketingStorage);

$cookieFile = sys_get_temp_dir() . '/spa_proof_mkt_gc_' . bin2hex(random_bytes(4)) . '.txt';

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
if ($r['code'] !== 200) {
    fwrite(STDERR, "GET /marketing/gift-card-templates/images failed {$r['code']}\n");
    @unlink($cookieFile);
    exit(5);
}
$csrfImg = extractCsrf($r['body'], $csrfName);
if ($csrfImg === null || $csrfImg === '') {
    fwrite(STDERR, "CSRF missing on images page\n");
    @unlink($cookieFile);
    exit(5);
}

$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true
);
$tmp = tempnam(sys_get_temp_dir(), 'mktgcproof');
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
    'title' => 'RUNTIME-PROOF-02 ' . date('Y-m-d H:i:s'),
    'image' => new CURLFile($tmp, 'image/png', 'mktgc-proof-02.png'),
]);
$uploadBody = curl_exec($ch);
$uploadCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($tmp);

echo "marketing_upload_http_code={$uploadCode}\n";
if ($uploadCode !== 302 && $uploadCode !== 303) {
    echo "marketing_upload_body_snip=" . substr(is_string($uploadBody) ? $uploadBody : '', 0, 500) . "\n";
    @unlink($cookieFile);
    exit(7);
}

$maxImgAfter = (int) ($db->fetchOne('SELECT COALESCE(MAX(id), 0) AS m FROM marketing_gift_card_images')['m'] ?? 0);
$newLibraryId = $maxImgAfter > $maxImgBefore ? $maxImgAfter : 0;
echo "marketing_gift_card_images_max_id_before={$maxImgBefore}\n";
echo "marketing_gift_card_images_new_row_id={$newLibraryId}\n";

$filesAfter = countFilesRecursive($marketingStorage);
$mtimeAfter = newestMtimeRecursive($marketingStorage);
echo "filesystem_marketing_gift_card_images_file_count_before={$filesBefore}\n";
echo "filesystem_marketing_gift_card_images_file_count_after={$filesAfter}\n";
echo "filesystem_marketing_gift_card_images_newest_mtime_before={$mtimeBefore}\n";
echo "filesystem_marketing_gift_card_images_newest_mtime_after={$mtimeAfter}\n";
echo "filesystem_no_new_direct_marketing_files=" . (($filesAfter === $filesBefore && $mtimeAfter <= $mtimeBefore) ? 'yes' : 'CHECK_MANUALLY') . "\n";

if ($newLibraryId <= 0) {
    fwrite(STDERR, "No new marketing_gift_card_images row detected.\n");
    @unlink($cookieFile);
    exit(8);
}
@unlink($cookieFile);

$row = $db->fetchOne(
    'SELECT i.*, ma.id AS ma_id, ma.status AS ma_status, ma.organization_id, ma.branch_id AS ma_branch, ma.stored_basename
     FROM marketing_gift_card_images i
     LEFT JOIN media_assets ma ON ma.id = i.media_asset_id
     WHERE i.id = ?',
    [$newLibraryId]
);
$maid = (int) ($row['media_asset_id'] ?? 0);
echo 'library_row=' . json_encode([
    'id' => $newLibraryId,
    'media_asset_id' => $maid,
    'storage_path' => $row['storage_path'] ?? null,
], JSON_UNESCAPED_UNICODE) . "\n";

$job = $db->fetchOne(
    'SELECT id, status, job_type FROM media_jobs WHERE media_asset_id = ? ORDER BY id DESC LIMIT 1',
    [$maid]
);
echo 'media_job_before_worker=' . json_encode($job ?: [], JSON_UNESCAPED_UNICODE) . "\n";

$quarantinePath = $systemRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'quarantine'
    . DIRECTORY_SEPARATOR . (int) ($row['organization_id'] ?? 0)
    . DIRECTORY_SEPARATOR . (int) ($row['ma_branch'] ?? 0)
    . DIRECTORY_SEPARATOR . (string) ($row['stored_basename'] ?? '');
echo 'quarantine_expected_path=' . str_replace('\\', '/', $quarantinePath) . "\n";
echo 'quarantine_file_exists_before_worker=' . (is_file($quarantinePath) ? 'yes' : 'no') . "\n";

$legacy = $db->fetchOne(
    'SELECT id, filename, media_asset_id FROM marketing_gift_card_images WHERE deleted_at IS NULL AND media_asset_id IS NULL ORDER BY id ASC LIMIT 1'
);
echo 'legacy_sample_row=' . json_encode($legacy ?: (object) [], JSON_UNESCAPED_UNICODE) . "\n";

$libBranchId = (int) ($row['branch_id'] ?? 0);
$tpl = $libBranchId > 0
    ? $db->fetchOne(
        'SELECT id, name FROM marketing_gift_card_templates WHERE branch_id = ? AND deleted_at IS NULL ORDER BY id ASC LIMIT 1',
        [$libBranchId]
    )
    : null;
$templateId = (int) ($tpl['id'] ?? 0);
echo 'template_for_update_id=' . $templateId . "\n";

// Worker passes — repeat until THIS asset is ready or failed (queue may contain older pending jobs).
$workerDir = realpath($systemRoot . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'workers' . DIRECTORY_SEPARATOR . 'image-pipeline');
$node = getenv('NODE_BINARY') ?: 'node';
$wcode = 99;
$passes = 0;
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
    for ($i = 0; $i < 25; $i++) {
        $stNow = $db->fetchOne('SELECT status FROM media_assets WHERE id = ?', [$maid]);
        $s = (string) ($stNow['status'] ?? '');
        if ($s === 'ready' || $s === 'failed') {
            break;
        }
        passthru(escapeshellarg($node) . ' src/worker.mjs', $wcode);
        ++$passes;
        if ($wcode !== 0) {
            break;
        }
    }
    chdir($prev);
    echo "worker_passes_run={$passes}\n";
    echo "worker_last_exit_code={$wcode}\n";
} else {
    echo "worker_skipped=worker_dir_missing\n";
}

$row2 = $db->fetchOne('SELECT status FROM media_assets WHERE id = ?', [$maid]);
$var = $db->fetchOne(
    'SELECT relative_path FROM media_asset_variants WHERE media_asset_id = ? AND is_primary = 1 LIMIT 1',
    [$maid]
);
$jobAfter = $db->fetchOne(
    'SELECT id, status, job_type FROM media_jobs WHERE media_asset_id = ? ORDER BY id DESC LIMIT 1',
    [$maid]
);
echo 'media_job_after_worker=' . json_encode($jobAfter ?: [], JSON_UNESCAPED_UNICODE) . "\n";
echo 'media_asset_after_worker=' . json_encode(['status' => $row2['status'] ?? null, 'primary_relative_path' => $var['relative_path'] ?? null], JSON_UNESCAPED_UNICODE) . "\n";

$rel = (string) ($var['relative_path'] ?? '');
$pubPath = $systemRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
echo 'variant_public_fs_path=' . str_replace('\\', '/', $pubPath) . "\n";
echo 'variant_file_exists=' . (is_file($pubPath) ? 'yes' : 'no') . "\n";

$emptyCookie = sys_get_temp_dir() . '/spa_proof_empty_' . bin2hex(random_bytes(3)) . '.txt';
file_put_contents($emptyCookie, '');

if ($rel !== '') {
    $previewUrl = $baseUrl . '/' . ltrim($rel, '/');
    $pr = httpRequest('GET', $previewUrl, $emptyCookie);
    echo "preview_http_get_url={$previewUrl}\n";
    echo "preview_http_code={$pr['code']}\n";
}

@unlink($emptyCookie);

$quarantineAfter = is_file($quarantinePath) ? 'yes' : 'no';
echo 'quarantine_file_exists_after_worker=' . $quarantineAfter . "\n";

// Template save requires media-ready image (business rule from bridge wave).
if ($templateId > 0 && ($row2['status'] ?? '') === 'ready') {
    $cookieFile2 = sys_get_temp_dir() . '/spa_proof_mkt_gc2_' . bin2hex(random_bytes(4)) . '.txt';
    $r = httpRequest('GET', $baseUrl . '/login', $cookieFile2);
    $csrfLogin = extractCsrf($r['body'], $csrfName);
    $postLogin = http_build_query([
        'email' => $email,
        'password' => $password,
        $csrfName => $csrfLogin,
    ], '', '&');
    httpRequest('POST', $baseUrl . '/login', $cookieFile2, $postLogin, ['Content-Type: application/x-www-form-urlencoded']);
    $r = httpRequest('GET', $baseUrl . '/marketing/gift-card-templates/' . $templateId . '/edit', $cookieFile2);
    $csrfEdit = $r['code'] === 200 ? extractCsrf($r['body'], $csrfName) : null;
    if ($csrfEdit) {
        $postTpl = http_build_query([
            $csrfName => $csrfEdit,
            'name' => (string) ($tpl['name'] ?? 'Proof Template'),
            'sell_in_store_enabled' => '1',
            'sell_online_enabled' => '1',
            'image_id' => (string) $newLibraryId,
        ], '', '&');
        $r2 = httpRequest('POST', $baseUrl . '/marketing/gift-card-templates/' . $templateId, $cookieFile2, $postTpl, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        echo "template_update_http_code={$r2['code']}\n";
    } else {
        echo "template_update_skipped=no_csrf_http_{$r['code']}\n";
    }
    @unlink($cookieFile2);
    $chk = $db->fetchOne('SELECT image_id FROM marketing_gift_card_templates WHERE id = ?', [$templateId]);
    echo 'template_image_id_after_post=' . json_encode($chk['image_id'] ?? null) . "\n";
} else {
    echo "template_update_skipped=need_ready_asset_and_template_row\n";
    echo "template_image_id_after_post=null\n";
}

exit(0);
