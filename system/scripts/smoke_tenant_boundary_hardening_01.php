<?php

declare(strict_types=1);

/**
 * TENANT-BOUNDARY-HARDENING-01 runtime smoke verifier.
 *
 * Required env:
 * - SMOKE_BASE_URL
 * - SMOKE_ADMIN_EMAIL / SMOKE_ADMIN_PASSWORD
 * - SMOKE_RECEPTION_EMAIL / SMOKE_RECEPTION_PASSWORD
 * - SMOKE_ORPHAN_EMAIL / SMOKE_ORPHAN_PASSWORD
 * - SMOKE_FOREIGN_BRANCH_ID (branch not allowed for tenant admin principal)
 */

if (!extension_loaded('curl')) {
    fwrite(STDERR, "smoke_tenant_boundary_hardening_01: PHP curl extension is required.\n");
    exit(2);
}

$baseUrl = rtrim((string) (getenv('SMOKE_BASE_URL') ?: ''), '/');
if ($baseUrl === '') {
    fwrite(STDERR, "Set SMOKE_BASE_URL.\n");
    exit(2);
}
$csrfField = (string) (getenv('SMOKE_CSRF_FIELD') ?: 'csrf_token');
$skipTlsVerify = filter_var((string) (getenv('SMOKE_SKIP_TLS_VERIFY') ?: ''), FILTER_VALIDATE_BOOLEAN);

$d = static fn (string $n, string $def): string => (string) (getenv($n) ?: $def);
$adminEmail = $d('SMOKE_ADMIN_EMAIL', 'tenant-admin-a@example.test');
$adminPassword = $d('SMOKE_ADMIN_PASSWORD', 'TenantAdminA##2026');
$receptionEmail = $d('SMOKE_RECEPTION_EMAIL', 'tenant-reception-b@example.test');
$receptionPassword = $d('SMOKE_RECEPTION_PASSWORD', 'TenantReceptionB##2026');
$orphanEmail = $d('SMOKE_ORPHAN_EMAIL', 'negative-orphan-access@example.test');
$orphanPassword = $d('SMOKE_ORPHAN_PASSWORD', 'NegativeOrphan##2026');
$foreignBranchId = (int) (getenv('SMOKE_FOREIGN_BRANCH_ID') ?: '0');

if ($foreignBranchId <= 0) {
    fwrite(STDERR, "Set SMOKE_FOREIGN_BRANCH_ID (positive branch id not granted to smoke users).\n");
    exit(2);
}

$passed = 0;
$failed = 0;

function tb01Pass(string $name): void
{
    global $passed;
    $passed++;
    fwrite(STDOUT, "PASS  {$name}\n");
}

function tb01Fail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{code:int, body:string, location:?string}
 */
function tb01Http(
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
function tb01Login(
    string $baseUrl,
    string $email,
    string $password,
    string $csrfField,
    bool $skipTlsVerify
): array {
    $jar = tempnam(sys_get_temp_dir(), 'spa_tb01_');
    if ($jar === false) {
        return ['jar' => '', 'login_ok' => false];
    }
    $getLogin = tb01Http('GET', $baseUrl . '/login', $jar, null, $skipTlsVerify);
    if ($getLogin['code'] !== 200 || !preg_match('/name="' . preg_quote($csrfField, '/') . '"\s+value="([^"]+)"/', $getLogin['body'], $cm)) {
        return ['jar' => $jar, 'login_ok' => false];
    }
    $postLogin = tb01Http('POST', $baseUrl . '/login', $jar, [
        $csrfField => $cm[1],
        'email' => $email,
        'password' => $password,
    ], $skipTlsVerify);

    return ['jar' => $jar, 'login_ok' => $postLogin['code'] === 302];
}

/**
 * @return string|null
 */
function tb01DashboardCsrf(string $baseUrl, string $jar, string $csrfField, bool $skipTlsVerify): ?string
{
    $dashboard = tb01Http('GET', $baseUrl . '/dashboard', $jar, null, $skipTlsVerify);
    if ($dashboard['code'] !== 200) {
        return null;
    }
    if (!preg_match('/name="' . preg_quote($csrfField, '/') . '"\s+value="([^"]+)"/', $dashboard['body'], $cm)) {
        return null;
    }

    return $cm[1];
}

$admin = tb01Login($baseUrl, $adminEmail, $adminPassword, $csrfField, $skipTlsVerify);
$reception = tb01Login($baseUrl, $receptionEmail, $receptionPassword, $csrfField, $skipTlsVerify);
$orphan = tb01Login($baseUrl, $orphanEmail, $orphanPassword, $csrfField, $skipTlsVerify);

foreach (['admin' => $admin, 'reception' => $reception, 'orphan' => $orphan] as $label => $auth) {
    if (!$auth['login_ok']) {
        tb01Fail("{$label}_login", 'expected 302 login success');
    } else {
        tb01Pass("{$label}_login");
    }
}

if ($failed === 0) {
    $adminDash = tb01Http('GET', $baseUrl . '/dashboard', $admin['jar'], null, $skipTlsVerify);
    if ($adminDash['code'] === 200) {
        tb01Pass('tenant_admin_dashboard_allowed');
    } else {
        tb01Fail('tenant_admin_dashboard_allowed', "expected 200, got {$adminDash['code']}");
    }

    $receptionDash = tb01Http('GET', $baseUrl . '/dashboard', $reception['jar'], null, $skipTlsVerify);
    if ($receptionDash['code'] === 200) {
        tb01Pass('tenant_reception_dashboard_allowed');
    } else {
        tb01Fail('tenant_reception_dashboard_allowed', "expected 200, got {$receptionDash['code']}");
    }

    $csrf = tb01DashboardCsrf($baseUrl, $admin['jar'], $csrfField, $skipTlsVerify);
    if ($csrf === null) {
        tb01Fail('tenant_foreign_branch_switch_denied', 'unable to resolve csrf token from tenant dashboard');
    } else {
        $switch = tb01Http(
            'POST',
            $baseUrl . '/account/branch-context',
            $admin['jar'],
            [$csrfField => $csrf, 'branch_id' => (string) $foreignBranchId, 'redirect_to' => '/dashboard'],
            $skipTlsVerify,
            ['Accept: application/json']
        );
        if ($switch['code'] === 403 && str_contains($switch['body'], 'FORBIDDEN')) {
            tb01Pass('tenant_foreign_branch_switch_denied');
        } else {
            tb01Fail('tenant_foreign_branch_switch_denied', "expected 403 FORBIDDEN, got code={$switch['code']} body_head=" . substr($switch['body'], 0, 180));
        }
    }

    $orphanDash = tb01Http('GET', $baseUrl . '/dashboard', $orphan['jar'], null, $skipTlsVerify, ['Accept: application/json']);
    if (
        $orphanDash['code'] === 403
        && (str_contains($orphanDash['body'], 'TENANT_CONTEXT_REQUIRED')
            || str_contains($orphanDash['body'], 'ORGANIZATION_CONTEXT_REQUIRED'))
    ) {
        tb01Pass('tenant_missing_context_denied');
        tb01Pass('tenant_unresolved_context_not_global_fallback');
    } else {
        tb01Fail('tenant_missing_context_denied', "expected 403 TENANT_CONTEXT_REQUIRED, got code={$orphanDash['code']} body_head=" . substr($orphanDash['body'], 0, 200));
        tb01Fail('tenant_unresolved_context_not_global_fallback', 'unresolved tenant context was not fail-closed');
    }
}

foreach ([$admin['jar'], $reception['jar'], $orphan['jar']] as $jar) {
    if (is_string($jar) && $jar !== '') {
        @unlink($jar);
    }
}

fwrite(STDOUT, "\nSummary: {$passed} passed, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);
