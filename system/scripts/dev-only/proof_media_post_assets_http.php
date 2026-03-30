<?php

declare(strict_types=1);

/**
 * Dev-only: real multipart POST /media/assets after browser-like login (is_uploaded_file path exercised).
 *
 * Requires ext-curl. Start the app reachable at base URL (e.g. php -S 127.0.0.1:8899 from project root).
 *
 * Usage (from `system/`):
 *   php scripts/dev-only/proof_media_post_assets_http.php --base-url=http://127.0.0.1:8899 --email=a@b.c --password=secret
 *
 * Exit 0 on HTTP 201 + JSON success; non-zero otherwise.
 */

if (!function_exists('curl_init')) {
    fwrite(STDERR, "ext-curl required\n");
    exit(2);
}

$baseUrl = 'http://127.0.0.1:8899';
$email = '';
$password = '';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--base-url=')) {
        $baseUrl = rtrim(substr($arg, 11), '/');
        continue;
    }
    if (str_starts_with($arg, '--email=')) {
        $email = substr($arg, 8);
        continue;
    }
    if (str_starts_with($arg, '--password=')) {
        $password = substr($arg, 11);
        continue;
    }
}

if ($email === '') {
    fwrite(STDERR, "Usage: php proof_media_post_assets_http.php --base-url=URL --email=... --password=...\n");
    fwrite(STDERR, "On Windows shells, `#` in passwords is often stripped; set MEDIA_SMOKE_PASSWORD instead and omit --password.\n");
    exit(2);
}
if ($password === '') {
    $fromEnv = getenv('MEDIA_SMOKE_PASSWORD');
    if (is_string($fromEnv) && $fromEnv !== '') {
        $password = $fromEnv;
    }
}
if ($password === '') {
    fwrite(STDERR, "Missing password: pass --password=... or set MEDIA_SMOKE_PASSWORD (recommended when password contains #).\n");
    exit(2);
}

$cookieFile = sys_get_temp_dir() . '/spa_proof_media_cookies_' . bin2hex(random_bytes(4)) . '.txt';

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

// 1) GET /login
$r = httpRequest('GET', $baseUrl . '/login', $cookieFile);
if ($r['code'] !== 200) {
    fwrite(STDERR, "GET /login failed http={$r['code']}\n");
    @unlink($cookieFile);
    exit(3);
}
$csrfLogin = extractCsrf($r['body'], $csrfName);
if ($csrfLogin === null || $csrfLogin === '') {
    fwrite(STDERR, "Could not parse CSRF from /login\n");
    @unlink($cookieFile);
    exit(3);
}

// 2) POST /login
$postLogin = http_build_query([
    'email' => $email,
    'password' => $password,
    $csrfName => $csrfLogin,
], '', '&');
$r = httpRequest('POST', $baseUrl . '/login', $cookieFile, $postLogin, [
    'Content-Type: application/x-www-form-urlencoded',
]);
if ($r['code'] !== 302 && $r['code'] !== 303) {
    fwrite(STDERR, "POST /login expected redirect, got http={$r['code']}\n");
    @unlink($cookieFile);
    exit(4);
}

// 3) GET follow redirect target once for CSRF on layout
$loc = null;
if (preg_match('/^Location:\s*(.+)$/mi', $r['headers'], $m)) {
    $loc = trim($m[1]);
}
$nextPath = '/dashboard';
if ($loc !== null && $loc !== '') {
    $pu = parse_url($loc);
    if (!empty($pu['path'])) {
        $nextPath = ($pu['path'] ?? '/') . (isset($pu['query']) ? '?' . $pu['query'] : '');
    }
}
$r = httpRequest('GET', $baseUrl . $nextPath, $cookieFile);
if ($r['code'] !== 200) {
    $r2 = httpRequest('GET', $baseUrl . '/dashboard', $cookieFile);
    if ($r2['code'] !== 200) {
        fwrite(STDERR, "GET post-login page failed http={$r['code']} / dashboard={$r2['code']}\n");
        @unlink($cookieFile);
        exit(5);
    }
    $r = $r2;
}
$csrfPost = extractCsrf($r['body'], $csrfName);
if ($csrfPost === null || $csrfPost === '') {
    fwrite(STDERR, "Could not parse CSRF from authenticated page\n");
    @unlink($cookieFile);
    exit(5);
}

// 4) Minimal valid PNG (1x1)
$png = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    true
);
if ($png === false) {
    fwrite(STDERR, "PNG decode failed\n");
    @unlink($cookieFile);
    exit(6);
}
$tmp = tempnam(sys_get_temp_dir(), 'proofimg');
if ($tmp === false) {
    fwrite(STDERR, "tempnam failed\n");
    @unlink($cookieFile);
    exit(6);
}
file_put_contents($tmp, $png);

$ch = curl_init($baseUrl . '/media/assets');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    $csrfName => $csrfPost,
    'image' => new CURLFile($tmp, 'image/png', 'proof.png'),
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($tmp);
@unlink($cookieFile);

echo "http_code={$code}\n";
echo "body=" . (is_string($body) ? $body : '') . "\n";

if ($code !== 201) {
    exit(7);
}
$j = json_decode(is_string($body) ? $body : '', true);
if (!is_array($j) || empty($j['success'])) {
    exit(8);
}

exit(0);
