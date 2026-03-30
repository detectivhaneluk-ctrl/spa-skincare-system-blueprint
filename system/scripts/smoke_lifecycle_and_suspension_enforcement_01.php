<?php

declare(strict_types=1);

if (!extension_loaded('curl')) {
    fwrite(STDERR, "smoke_lifecycle_and_suspension_enforcement_01: PHP curl extension is required.\n");
    exit(2);
}

$baseUrl = rtrim((string) (getenv('SMOKE_BASE_URL') ?: ''), '/');
$csrfField = (string) (getenv('SMOKE_CSRF_FIELD') ?: 'csrf_token');
$skipTlsVerify = filter_var((string) (getenv('SMOKE_SKIP_TLS_VERIFY') ?: ''), FILTER_VALIDATE_BOOLEAN);
$d = static fn (string $n, string $def): string => (string) (getenv($n) ?: $def);
$founderEmail = $d('SMOKE_FOUNDER_EMAIL', 'founder-smoke@example.test');
$founderPassword = $d('SMOKE_FOUNDER_PASSWORD', 'FounderSmoke##2026');
$activeAdminEmail = $d('SMOKE_ACTIVE_ADMIN_EMAIL', 'tenant-admin-a@example.test');
$activeAdminPassword = $d('SMOKE_ACTIVE_ADMIN_PASSWORD', 'TenantAdminA##2026');
$suspendedAdminEmail = $d('SMOKE_SUSPENDED_ADMIN_EMAIL', 'tenant-multi-choice@example.test');
$suspendedAdminPassword = $d('SMOKE_SUSPENDED_ADMIN_PASSWORD', 'TenantMultiChoice##2026');
$suspendedBranchId = (int) (getenv('SMOKE_SUSPENDED_BRANCH_ID') ?: '0');
$activeBranchId = (int) (getenv('SMOKE_ACTIVE_BRANCH_ID') ?: '0');

if (
    $baseUrl === ''
    || $suspendedBranchId <= 0 || $activeBranchId <= 0
) {
    fwrite(STDERR, "Missing required env vars for lifecycle suspension smoke (need SMOKE_BASE_URL, SMOKE_ACTIVE_BRANCH_ID, SMOKE_SUSPENDED_BRANCH_ID).\n");
    exit(2);
}

$passed = 0;
$failed = 0;
function lse01Pass(string $name): void { global $passed; $passed++; fwrite(STDOUT, "PASS  {$name}\n"); }
function lse01Fail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }

function lse01Http(string $method, string $url, ?string $jar, ?array $post, bool $skipTls, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['code' => 0, 'body' => '', 'location' => null];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($jar !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($skipTls) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($raw === false || $err !== 0) {
        return ['code' => 0, 'body' => '', 'location' => null];
    }
    $split = strpos($raw, "\r\n\r\n");
    if ($split === false) {
        return ['code' => 0, 'body' => (string) $raw, 'location' => null];
    }
    $head = substr($raw, 0, $split);
    $body = substr($raw, $split + 4);
    $code = 0;
    if (preg_match('#^HTTP/\S+\s+(\d{3})#m', $head, $m)) {
        $code = (int) $m[1];
    }
    $location = null;
    if (preg_match('/^Location:\s*(\S+)/mi', $head, $lm)) {
        $location = trim($lm[1]);
    }

    return ['code' => $code, 'body' => $body, 'location' => $location];
}

function lse01Login(string $baseUrl, string $email, string $password, string $csrfField, bool $skipTls): array
{
    $jar = tempnam(sys_get_temp_dir(), 'spa_lse01_');
    if ($jar === false) {
        return ['jar' => '', 'login_ok' => false, 'location' => null];
    }
    $loginGet = lse01Http('GET', $baseUrl . '/login', $jar, null, $skipTls);
    if ($loginGet['code'] !== 200 || !preg_match('/name="' . preg_quote($csrfField, '/') . '"\s+value="([^"]+)"/', $loginGet['body'], $cm)) {
        return ['jar' => $jar, 'login_ok' => false, 'location' => null];
    }
    $loginPost = lse01Http('POST', $baseUrl . '/login', $jar, [
        $csrfField => $cm[1],
        'email' => $email,
        'password' => $password,
    ], $skipTls);

    return ['jar' => $jar, 'login_ok' => $loginPost['code'] === 302, 'location' => $loginPost['location']];
}

$founder = lse01Login($baseUrl, $founderEmail, $founderPassword, $csrfField, $skipTlsVerify);
$activeAdmin = lse01Login($baseUrl, $activeAdminEmail, $activeAdminPassword, $csrfField, $skipTlsVerify);
$suspendedAdmin = lse01Login($baseUrl, $suspendedAdminEmail, $suspendedAdminPassword, $csrfField, $skipTlsVerify);

if ($activeAdmin['login_ok']) {
    lse01Pass('active_admin_login_allowed');
} else {
    lse01Fail('active_admin_login_allowed', 'expected login redirect');
}
if ($suspendedAdmin['location'] !== null && str_contains($suspendedAdmin['location'], '/login')) {
    lse01Pass('suspended_admin_login_denied');
} else {
    lse01Fail('suspended_admin_login_denied', 'expected redirect to /login for suspended tenant user');
}

$activeDashboard = lse01Http('GET', $baseUrl . '/dashboard', $activeAdmin['jar'], null, $skipTlsVerify);
if ($activeDashboard['code'] === 200) {
    lse01Pass('active_tenant_runtime_allowed');
} else {
    lse01Fail('active_tenant_runtime_allowed', "expected 200, got {$activeDashboard['code']}");
}

$suspendedTenantEntry = lse01Http('GET', $baseUrl . '/tenant-entry', $suspendedAdmin['jar'], null, $skipTlsVerify);
if ($suspendedTenantEntry['code'] === 302 && $suspendedTenantEntry['location'] !== null && str_contains($suspendedTenantEntry['location'], '/login')) {
    lse01Pass('suspended_tenant_session_cannot_continue');
} else {
    lse01Fail('suspended_tenant_session_cannot_continue', 'expected redirected login barrier');
}

$bookingSlots = lse01Http(
    'GET',
    $baseUrl . '/api/public/booking/slots?branch_id=' . $suspendedBranchId . '&service_id=1&date=' . date('Y-m-d', strtotime('+1 day')),
    null,
    null,
    $skipTlsVerify,
    ['Accept: application/json']
);
if ($bookingSlots['code'] === 403 && str_contains($bookingSlots['body'], 'ORGANIZATION_SUSPENDED')) {
    lse01Pass('public_booking_denied_on_suspended_org_branch');
} else {
    lse01Fail('public_booking_denied_on_suspended_org_branch', "expected 403 ORGANIZATION_SUSPENDED, got {$bookingSlots['code']}");
}

$commerceCatalog = lse01Http(
    'GET',
    $baseUrl . '/api/public/commerce/catalog?branch_id=' . $suspendedBranchId,
    null,
    null,
    $skipTlsVerify,
    ['Accept: application/json']
);
if ($commerceCatalog['code'] === 403 && str_contains($commerceCatalog['body'], 'ORGANIZATION_SUSPENDED')) {
    lse01Pass('public_commerce_denied_on_suspended_org_branch');
} else {
    lse01Fail('public_commerce_denied_on_suspended_org_branch', "expected 403 ORGANIZATION_SUSPENDED, got {$commerceCatalog['code']}");
}

if ($founder['login_ok']) {
    $platform = lse01Http('GET', $baseUrl . '/platform-admin', $founder['jar'], null, $skipTlsVerify);
    if ($platform['code'] === 200) {
        lse01Pass('platform_founder_control_plane_access_unchanged');
    } else {
        lse01Fail('platform_founder_control_plane_access_unchanged', "expected 200, got {$platform['code']}");
    }
} else {
    lse01Fail('platform_founder_control_plane_access_unchanged', 'founder login failed');
}

$activeCatalog = lse01Http(
    'GET',
    $baseUrl . '/api/public/commerce/catalog?branch_id=' . $activeBranchId,
    null,
    null,
    $skipTlsVerify,
    ['Accept: application/json']
);
if ($activeCatalog['code'] !== 403) {
    lse01Pass('active_branch_public_surface_not_globally_blocked');
} else {
    lse01Fail('active_branch_public_surface_not_globally_blocked', 'unexpected 403 for active branch');
}

foreach ([$founder['jar'], $activeAdmin['jar'], $suspendedAdmin['jar']] as $jar) {
    if (is_string($jar) && $jar !== '') {
        @unlink($jar);
    }
}

fwrite(STDOUT, "\nSummary: {$passed} passed, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);
