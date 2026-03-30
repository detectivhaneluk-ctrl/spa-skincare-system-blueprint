<?php

declare(strict_types=1);

/**
 * PLATFORM-CONTROL-PLANE-SHELL-01 smoke verifier.
 *
 * Required env:
 * - SMOKE_BASE_URL
 * - SMOKE_FOUNDER_EMAIL / SMOKE_FOUNDER_PASSWORD
 * - SMOKE_ADMIN_EMAIL / SMOKE_ADMIN_PASSWORD
 */

if (!extension_loaded('curl')) {
    fwrite(STDERR, "smoke_platform_control_plane_shell_01: PHP curl extension is required.\n");
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

$passed = 0;
$failed = 0;

function cpShellPass(string $name): void
{
    global $passed;
    $passed++;
    fwrite(STDOUT, "PASS  {$name}\n");
}

function cpShellFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{code:int, body:string, location:?string}
 */
function cpShellHttp(
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

/**
 * @return array{jar:string, login_ok:bool}
 */
function cpShellLogin(
    string $baseUrl,
    string $email,
    string $password,
    string $csrfField,
    bool $skipTlsVerify
): array {
    $jar = tempnam(sys_get_temp_dir(), 'spa_cps01_');
    if ($jar === false) {
        return ['jar' => '', 'login_ok' => false];
    }
    $loginGet = cpShellHttp('GET', $baseUrl . '/login', $jar, null, $skipTlsVerify);
    if (
        $loginGet['code'] !== 200
        || !preg_match('/name="' . preg_quote($csrfField, '/') . '"\s+value="([^"]+)"/', $loginGet['body'], $cm)
    ) {
        return ['jar' => $jar, 'login_ok' => false];
    }
    $loginPost = cpShellHttp('POST', $baseUrl . '/login', $jar, [
        $csrfField => $cm[1],
        'email' => $email,
        'password' => $password,
    ], $skipTlsVerify);

    return ['jar' => $jar, 'login_ok' => $loginPost['code'] === 302];
}

$tenantMenuLabels = [
    'Appointments',
    'Clients',
    'Staff',
    'Sales',
    'Inventory',
    'Gift Cards',
    'Packages',
    'Memberships',
    'Marketing',
    'Payroll',
    'Services &amp; Resources',
    'Branches',
    'Settings',
];
$platformMenuLabels = ['Dashboard', 'Organizations'];

$founder = cpShellLogin($baseUrl, $founderEmail, $founderPassword, $csrfField, $skipTlsVerify);
$admin = cpShellLogin($baseUrl, $adminEmail, $adminPassword, $csrfField, $skipTlsVerify);

foreach (['founder' => $founder, 'admin' => $admin] as $label => $auth) {
    if ($auth['login_ok']) {
        cpShellPass("{$label}_login");
    } else {
        cpShellFail("{$label}_login", 'expected 302 login success');
    }
}

if ($failed === 0) {
    $r = cpShellHttp('GET', $baseUrl . '/', $founder['jar'], null, $skipTlsVerify);
    if ($r['code'] === 302 && $r['location'] !== null && str_contains($r['location'], '/platform-admin')) {
        cpShellPass('founder_home_redirects_platform_dashboard');
    } else {
        cpShellFail('founder_home_redirects_platform_dashboard', "expected 302 to /platform-admin, got code={$r['code']} location=" . ($r['location'] ?? 'null'));
    }

    $platformPage = cpShellHttp('GET', $baseUrl . '/platform-admin', $founder['jar'], null, $skipTlsVerify);
    if ($platformPage['code'] === 200) {
        cpShellPass('founder_platform_dashboard_renders');
    } else {
        cpShellFail('founder_platform_dashboard_renders', "expected 200, got code={$platformPage['code']}");
    }

    foreach ($platformMenuLabels as $label) {
        if (str_contains($platformPage['body'], $label)) {
            cpShellPass('founder_shell_has_platform_nav_' . strtolower(str_replace(' ', '_', $label)));
        } else {
            cpShellFail('founder_shell_has_platform_nav_' . strtolower(str_replace(' ', '_', $label)), "missing label {$label}");
        }
    }

    $tenantLeaks = [];
    foreach ($tenantMenuLabels as $tenantLabel) {
        if (str_contains($platformPage['body'], $tenantLabel)) {
            $tenantLeaks[] = $tenantLabel;
        }
    }
    if ($tenantLeaks === []) {
        cpShellPass('founder_shell_has_no_tenant_menu_items');
    } else {
        cpShellFail('founder_shell_has_no_tenant_menu_items', 'found: ' . implode(', ', $tenantLeaks));
    }

    $r = cpShellHttp('GET', $baseUrl . '/', $admin['jar'], null, $skipTlsVerify);
    if ($r['code'] === 302 && $r['location'] !== null && str_contains($r['location'], '/dashboard')) {
        cpShellPass('tenant_admin_home_redirects_tenant_dashboard');
    } else {
        cpShellFail('tenant_admin_home_redirects_tenant_dashboard', "expected 302 to /dashboard, got code={$r['code']} location=" . ($r['location'] ?? 'null'));
    }

    $tenantPage = cpShellHttp('GET', $baseUrl . '/dashboard', $admin['jar'], null, $skipTlsVerify);
    if ($tenantPage['code'] === 200) {
        cpShellPass('tenant_dashboard_renders_tenant_shell');
    } else {
        cpShellFail('tenant_dashboard_renders_tenant_shell', "expected 200, got code={$tenantPage['code']}");
    }
    if (!str_contains($tenantPage['body'], '/platform-admin') && !str_contains($tenantPage['body'], 'Platform')) {
        cpShellPass('tenant_shell_has_no_platform_nav');
    } else {
        cpShellFail('tenant_shell_has_no_platform_nav', 'tenant dashboard includes platform navigation markers');
    }

    $forbidden = cpShellHttp('GET', $baseUrl . '/platform-admin', $admin['jar'], null, $skipTlsVerify, ['Accept: application/json']);
    if ($forbidden['code'] === 403 && str_contains($forbidden['body'], 'FORBIDDEN')) {
        cpShellPass('tenant_admin_forbidden_platform_admin');
    } else {
        cpShellFail('tenant_admin_forbidden_platform_admin', "expected 403 FORBIDDEN, got code={$forbidden['code']}");
    }

    $founderDashboard = cpShellHttp('GET', $baseUrl . '/dashboard', $founder['jar'], null, $skipTlsVerify);
    if ($founderDashboard['code'] === 302 && $founderDashboard['location'] !== null && str_contains($founderDashboard['location'], '/platform-admin')) {
        cpShellPass('foundation_100_founder_dashboard_redirect_unchanged');
    } else {
        cpShellFail('foundation_100_founder_dashboard_redirect_unchanged', "expected 302 /platform-admin, got code={$founderDashboard['code']}");
    }
}

foreach ([$founder['jar'], $admin['jar']] as $jar) {
    if (is_string($jar) && $jar !== '') {
        @unlink($jar);
    }
}

fwrite(STDOUT, "\nSummary: {$passed} passed, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);
