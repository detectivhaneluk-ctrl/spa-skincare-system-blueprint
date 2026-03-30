<?php

declare(strict_types=1);

/**
 * PRINCIPAL-PLANE-CANONICALIZATION smoke.
 *
 * Required env: SMOKE_BASE_URL
 * Optional (defaults match scripts/dev-only/seed_branch_smoke_data.php *.example.test fixtures):
 * SMOKE_FOUNDER_*, SMOKE_BRANCH_A_*, SMOKE_MULTI_*, SMOKE_ORPHAN_*
 */

if (!extension_loaded('curl')) {
    fwrite(STDERR, "smoke_principal_plane_canonicalization_01: PHP curl extension is required.\n");
    exit(2);
}

$baseUrl = rtrim((string) (getenv('SMOKE_BASE_URL') ?: ''), '/');
$csrfField = (string) (getenv('SMOKE_CSRF_FIELD') ?: 'csrf_token');
$skipTls = filter_var((string) (getenv('SMOKE_SKIP_TLS_VERIFY') ?: ''), FILTER_VALIDATE_BOOLEAN);

if ($baseUrl === '') {
    fwrite(STDERR, "Set SMOKE_BASE_URL.\n");
    exit(2);
}

$smokeD = static fn (string $name, string $default): string => (string) (getenv($name) ?: $default);

$passed = 0;
$failed = 0;
function ppcPass(string $name): void { global $passed; $passed++; fwrite(STDOUT, "PASS  {$name}\n"); }
function ppcFail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }

/**
 * @return array{code:int, body:string, location:?string}
 */
function ppcHttp(string $method, string $url, ?string $jar, ?array $post, bool $skipTls, array $headers = []): array
{
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
    if (preg_match('/^Location:\s*(\S+)/mi', $head, $m)) {
        $location = trim($m[1]);
    }

    return ['code' => $code, 'body' => $body, 'location' => $location];
}

/**
 * @return array{jar:string, ok:bool}
 */
function ppcLogin(string $baseUrl, string $email, string $password, string $csrfField, bool $skipTls): array
{
    $jar = tempnam(sys_get_temp_dir(), 'spa_ppc_');
    if ($jar === false) {
        return ['jar' => '', 'ok' => false];
    }
    $get = ppcHttp('GET', $baseUrl . '/login', $jar, null, $skipTls);
    if ($get['code'] !== 200 || !preg_match('/name="' . preg_quote($csrfField, '/') . '"\s+value="([^"]+)"/', $get['body'], $m)) {
        return ['jar' => $jar, 'ok' => false];
    }
    $post = ppcHttp('POST', $baseUrl . '/login', $jar, [
        $csrfField => $m[1],
        'email' => $email,
        'password' => $password,
    ], $skipTls);

    return ['jar' => $jar, 'ok' => $post['code'] === 302];
}

function ppcExtractCsrf(string $html, string $csrfField): ?string
{
    if (!preg_match('/name="' . preg_quote($csrfField, '/') . '"\s+value="([^"]+)"/', $html, $m)) {
        return null;
    }

    return $m[1];
}

$founder = ppcLogin($baseUrl, $smokeD('SMOKE_FOUNDER_EMAIL', 'founder-smoke@example.test'), $smokeD('SMOKE_FOUNDER_PASSWORD', 'FounderSmoke##2026'), $csrfField, $skipTls);
$tenantSingle = ppcLogin($baseUrl, $smokeD('SMOKE_BRANCH_A_EMAIL', 'tenant-admin-a@example.test'), $smokeD('SMOKE_BRANCH_A_PASSWORD', 'TenantAdminA##2026'), $csrfField, $skipTls);
$tenantMulti = ppcLogin($baseUrl, $smokeD('SMOKE_MULTI_EMAIL', 'tenant-multi-choice@example.test'), $smokeD('SMOKE_MULTI_PASSWORD', 'TenantMultiChoice##2026'), $csrfField, $skipTls);
$orphan = ppcLogin($baseUrl, $smokeD('SMOKE_ORPHAN_EMAIL', 'negative-orphan-access@example.test'), $smokeD('SMOKE_ORPHAN_PASSWORD', 'NegativeOrphan##2026'), $csrfField, $skipTls);

foreach ([
    'founder_login' => $founder,
    'tenant_single_login' => $tenantSingle,
    'tenant_multi_login' => $tenantMulti,
    'orphan_login' => $orphan,
] as $name => $auth) {
    $auth['ok'] ? ppcPass($name) : ppcFail($name, 'expected login success');
}

if ($failed === 0) {
    $r = ppcHttp('GET', $baseUrl . '/', $founder['jar'], null, $skipTls);
    ($r['code'] === 302 && str_contains((string) $r['location'], '/platform-admin'))
        ? ppcPass('platform_principal_home_path_platform_admin')
        : ppcFail('platform_principal_home_path_platform_admin', "expected 302 /platform-admin, got {$r['code']} " . (string) $r['location']);

    $r = ppcHttp('GET', $baseUrl . '/dashboard', $founder['jar'], null, $skipTls);
    ($r['code'] === 302 && str_contains((string) $r['location'], '/platform-admin'))
        ? ppcPass('platform_principal_tenant_route_denied')
        : ppcFail('platform_principal_tenant_route_denied', "expected redirect to /platform-admin, got {$r['code']} " . (string) $r['location']);

    $r = ppcHttp('GET', $baseUrl . '/', $tenantSingle['jar'], null, $skipTls);
    ($r['code'] === 302 && str_contains((string) $r['location'], '/dashboard'))
        ? ppcPass('tenant_single_home_path_dashboard')
        : ppcFail('tenant_single_home_path_dashboard', "expected 302 /dashboard, got {$r['code']} " . (string) $r['location']);

    $r = ppcHttp('GET', $baseUrl . '/tenant-entry', $tenantSingle['jar'], null, $skipTls);
    ($r['code'] === 302 && str_contains((string) $r['location'], '/dashboard'))
        ? ppcPass('tenant_single_tenant_entry_to_dashboard')
        : ppcFail('tenant_single_tenant_entry_to_dashboard', "expected 302 /dashboard, got {$r['code']} " . (string) $r['location']);

    $r = ppcHttp('GET', $baseUrl . '/platform-admin', $tenantSingle['jar'], null, $skipTls, ['Accept: application/json']);
    ($r['code'] === 403 && str_contains($r['body'], 'FORBIDDEN'))
        ? ppcPass('tenant_single_platform_route_denied')
        : ppcFail('tenant_single_platform_route_denied', "expected 403 FORBIDDEN, got {$r['code']}");

    $r = ppcHttp('GET', $baseUrl . '/tenant-entry', $tenantMulti['jar'], null, $skipTls);
    ($r['code'] === 200 && str_contains($r['body'], '/account/branch-context'))
        ? ppcPass('tenant_multi_chooser_state')
        : ppcFail('tenant_multi_chooser_state', "expected chooser 200, got {$r['code']}");
    $csrf = ppcExtractCsrf($r['body'], $csrfField);
    if ($csrf === null || !preg_match('/<option\s+value="(\d+)"/i', $r['body'], $m)) {
        ppcFail('tenant_multi_branch_selection_to_dashboard', 'could not extract chooser csrf/branch');
    } else {
        $selectedBranchId = (int) $m[1];
        $post = ppcHttp('POST', $baseUrl . '/account/branch-context', $tenantMulti['jar'], [
            $csrfField => $csrf,
            'branch_id' => (string) $selectedBranchId,
            'redirect_to' => '/dashboard',
        ], $skipTls);
        ($post['code'] === 302 && str_contains((string) $post['location'], '/dashboard'))
            ? ppcPass('tenant_multi_branch_selection_to_dashboard')
            : ppcFail('tenant_multi_branch_selection_to_dashboard', "expected 302 /dashboard, got {$post['code']}");
    }

    $r = ppcHttp('GET', $baseUrl . '/platform-admin', $tenantMulti['jar'], null, $skipTls, ['Accept: application/json']);
    ($r['code'] === 403 && str_contains($r['body'], 'FORBIDDEN'))
        ? ppcPass('tenant_multi_platform_route_denied')
        : ppcFail('tenant_multi_platform_route_denied', "expected 403 FORBIDDEN, got {$r['code']}");

    $r = ppcHttp('GET', $baseUrl . '/tenant-entry', $orphan['jar'], null, $skipTls);
    ($r['code'] === 200 && str_contains($r['body'], 'No active salon branch is available'))
        ? ppcPass('orphan_blocked_surface')
        : ppcFail('orphan_blocked_surface', "expected blocked tenant-entry surface, got {$r['code']}");

    $r = ppcHttp('GET', $baseUrl . '/dashboard', $orphan['jar'], null, $skipTls);
    ($r['code'] === 302 && str_contains((string) $r['location'], '/tenant-entry'))
        ? ppcPass('orphan_cannot_enter_tenant_protected_route')
        : ppcFail('orphan_cannot_enter_tenant_protected_route', "expected 302 /tenant-entry, got {$r['code']} " . (string) $r['location']);

    $r = ppcHttp('GET', $baseUrl . '/platform-admin', $orphan['jar'], null, $skipTls, ['Accept: application/json']);
    ($r['code'] === 403 && str_contains($r['body'], 'FORBIDDEN'))
        ? ppcPass('orphan_cannot_enter_control_plane')
        : ppcFail('orphan_cannot_enter_control_plane', "expected 403 FORBIDDEN, got {$r['code']}");

    $r = ppcHttp('GET', $baseUrl . '/platform-admin', $founder['jar'], null, $skipTls);
    if ($r['code'] === 200 && str_contains($r['body'], 'href="/platform-admin"') && !str_contains($r['body'], 'href="/dashboard"')) {
        ppcPass('control_plane_shell_nav_only');
    } else {
        ppcFail('control_plane_shell_nav_only', 'expected platform shell without tenant nav links');
    }

    $r = ppcHttp('GET', $baseUrl . '/dashboard', $tenantSingle['jar'], null, $skipTls);
    if ($r['code'] === 200 && str_contains($r['body'], 'href="/dashboard"') && !str_contains($r['body'], 'href="/platform-admin"')) {
        ppcPass('tenant_plane_shell_nav_only');
    } else {
        ppcFail('tenant_plane_shell_nav_only', 'expected tenant shell without platform nav links');
    }

    $r = ppcHttp('GET', $baseUrl . '/tenant-entry', $orphan['jar'], null, $skipTls);
    if ($r['code'] === 200 && !str_contains($r['body'], 'app-shell__header') && !str_contains($r['body'], 'href="/dashboard"') && !str_contains($r['body'], 'href="/platform-admin"')) {
        ppcPass('blocked_surface_has_no_plane_shell');
    } else {
        ppcFail('blocked_surface_has_no_plane_shell', 'expected blocked surface without tenant/platform shell headers');
    }
}

foreach ([$founder['jar'], $tenantSingle['jar'], $tenantMulti['jar'], $orphan['jar']] as $jar) {
    if (is_string($jar) && $jar !== '') {
        @unlink($jar);
    }
}

fwrite(STDOUT, "\nSummary: {$passed} passed, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);
