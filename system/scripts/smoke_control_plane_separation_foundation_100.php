<?php

declare(strict_types=1);

/**
 * FOUNDATION-100 smoke verifier for control-plane separation.
 *
 * Required env:
 * - SMOKE_BASE_URL
 * - SMOKE_FOUNDER_EMAIL / SMOKE_FOUNDER_PASSWORD
 * - SMOKE_ADMIN_EMAIL / SMOKE_ADMIN_PASSWORD
 * - SMOKE_RECEPTION_EMAIL / SMOKE_RECEPTION_PASSWORD
 */

if (!extension_loaded('curl')) {
    fwrite(STDERR, "smoke_control_plane_separation_foundation_100: PHP curl extension is required.\n");
    exit(2);
}

$baseUrl = rtrim((string) (getenv('SMOKE_BASE_URL') ?: ''), '/');
if ($baseUrl === '') {
    fwrite(STDERR, "Set SMOKE_BASE_URL (e.g. http://spa.test).\n");
    exit(2);
}

$csrfField = (string) (getenv('SMOKE_CSRF_FIELD') ?: 'csrf_token');
$skipTlsVerify = filter_var((string) (getenv('SMOKE_SKIP_TLS_VERIFY') ?: ''), FILTER_VALIDATE_BOOLEAN);

$d = static fn (string $n, string $def): string => (string) (getenv($n) ?: $def);
$founderEmail = $d('SMOKE_FOUNDER_EMAIL', 'founder-smoke@example.test');
$founderPassword = $d('SMOKE_FOUNDER_PASSWORD', 'FounderSmoke##2026');
$adminEmail = $d('SMOKE_ADMIN_EMAIL', 'tenant-admin-a@example.test');
$adminPassword = $d('SMOKE_ADMIN_PASSWORD', 'TenantAdminA##2026');
$receptionEmail = $d('SMOKE_RECEPTION_EMAIL', 'tenant-reception-b@example.test');
$receptionPassword = $d('SMOKE_RECEPTION_PASSWORD', 'TenantReceptionB##2026');

$passed = 0;
$failed = 0;

function smoke100Pass(string $name): void
{
    global $passed;
    $passed++;
    fwrite(STDOUT, "PASS  {$name}\n");
}

function smoke100Fail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{code:int, body:string, location:?string}
 */
function smoke100Http(
    string $method,
    string $url,
    ?string $cookieJarPath,
    ?array $postFields,
    bool $skipTlsVerify,
    array $headers = []
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        return ['code' => 0, 'body' => '', 'location' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($skipTlsVerify) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    if ($cookieJarPath !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarPath);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarPath);
    }
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        return ['code' => 0, 'body' => '', 'location' => null];
    }

    $headerSize = strpos($raw, "\r\n\r\n");
    if ($headerSize === false) {
        return ['code' => 0, 'body' => (string) $raw, 'location' => null];
    }
    $headerBlock = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize + 4);
    $code = 0;
    if (preg_match('#^HTTP/\S+\s+(\d{3})#m', $headerBlock, $m)) {
        $code = (int) $m[1];
    }
    $location = null;
    if (preg_match('/^Location:\s*(\S+)/mi', $headerBlock, $lm)) {
        $location = trim($lm[1]);
    }

    return ['code' => $code, 'body' => $body, 'location' => $location];
}

/**
 * @return array{jar:string, login_ok:bool}
 */
function smoke100Login(
    string $baseUrl,
    string $email,
    string $password,
    string $csrfField,
    bool $skipTlsVerify
): array {
    $jar = tempnam(sys_get_temp_dir(), 'spa_cp_');
    if ($jar === false) {
        return ['jar' => '', 'login_ok' => false];
    }

    $getLogin = smoke100Http('GET', $baseUrl . '/login', $jar, null, $skipTlsVerify);
    if ($getLogin['code'] !== 200 || !preg_match('/name="' . preg_quote($csrfField, '/') . '"\s+value="([^"]+)"/', $getLogin['body'], $cm)) {
        return ['jar' => $jar, 'login_ok' => false];
    }
    $post = [$csrfField => $cm[1], 'email' => $email, 'password' => $password];
    $postLogin = smoke100Http('POST', $baseUrl . '/login', $jar, $post, $skipTlsVerify);
    return ['jar' => $jar, 'login_ok' => $postLogin['code'] === 302];
}

$founder = smoke100Login($baseUrl, $founderEmail, $founderPassword, $csrfField, $skipTlsVerify);
$admin = smoke100Login($baseUrl, $adminEmail, $adminPassword, $csrfField, $skipTlsVerify);
$reception = smoke100Login($baseUrl, $receptionEmail, $receptionPassword, $csrfField, $skipTlsVerify);

foreach (['founder' => $founder, 'admin' => $admin, 'reception' => $reception] as $label => $auth) {
    if (!$auth['login_ok']) {
        smoke100Fail("{$label}_login", 'expected 302 login success');
    } else {
        smoke100Pass("{$label}_login");
    }
}

if ($failed === 0) {
    $r = smoke100Http('GET', $baseUrl . '/', $founder['jar'], null, $skipTlsVerify);
    if ($r['code'] === 302 && $r['location'] !== null && str_contains($r['location'], '/platform-admin')) {
        smoke100Pass('founder_home_redirects_platform_admin');
    } else {
        smoke100Fail('founder_home_redirects_platform_admin', "expected 302 to /platform-admin, got code={$r['code']} location=" . ($r['location'] ?? 'null'));
    }

    $r = smoke100Http('GET', $baseUrl . '/', $admin['jar'], null, $skipTlsVerify);
    if ($r['code'] === 302 && $r['location'] !== null && str_contains($r['location'], '/dashboard')) {
        smoke100Pass('admin_home_redirects_dashboard');
    } else {
        smoke100Fail('admin_home_redirects_dashboard', "expected 302 to /dashboard, got code={$r['code']} location=" . ($r['location'] ?? 'null'));
    }

    $r = smoke100Http('GET', $baseUrl . '/', $reception['jar'], null, $skipTlsVerify);
    if ($r['code'] === 302 && $r['location'] !== null && str_contains($r['location'], '/dashboard')) {
        smoke100Pass('reception_home_redirects_dashboard');
    } else {
        smoke100Fail('reception_home_redirects_dashboard', "expected 302 to /dashboard, got code={$r['code']} location=" . ($r['location'] ?? 'null'));
    }

    $r = smoke100Http('GET', $baseUrl . '/platform-admin', $admin['jar'], null, $skipTlsVerify, ['Accept: application/json']);
    if ($r['code'] === 403 && str_contains($r['body'], 'FORBIDDEN')) {
        smoke100Pass('admin_forbidden_platform_admin');
    } else {
        smoke100Fail('admin_forbidden_platform_admin', "expected 403 FORBIDDEN, got code={$r['code']} body_head=" . substr($r['body'], 0, 200));
    }

    $r = smoke100Http('GET', $baseUrl . '/platform-admin', $reception['jar'], null, $skipTlsVerify, ['Accept: application/json']);
    if ($r['code'] === 403 && str_contains($r['body'], 'FORBIDDEN')) {
        smoke100Pass('reception_forbidden_platform_admin');
    } else {
        smoke100Fail('reception_forbidden_platform_admin', "expected 403 FORBIDDEN, got code={$r['code']} body_head=" . substr($r['body'], 0, 200));
    }

    $r = smoke100Http('GET', $baseUrl . '/dashboard', $founder['jar'], null, $skipTlsVerify);
    if ($r['code'] === 302 && $r['location'] !== null && str_contains($r['location'], '/platform-admin')) {
        smoke100Pass('founder_dashboard_redirects_platform_admin');
    } else {
        smoke100Fail('founder_dashboard_redirects_platform_admin', "expected 302 to /platform-admin, got code={$r['code']} location=" . ($r['location'] ?? 'null'));
    }
}

if ($founder['jar'] !== '') {
    @unlink($founder['jar']);
}
if ($admin['jar'] !== '') {
    @unlink($admin['jar']);
}
if ($reception['jar'] !== '') {
    @unlink($reception['jar']);
}

fwrite(STDOUT, "\nSummary: {$passed} passed, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);
