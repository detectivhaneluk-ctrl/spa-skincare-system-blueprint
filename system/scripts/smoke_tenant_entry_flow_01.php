<?php

declare(strict_types=1);

/**
 * TENANT-ENTRY-FLOW-01 runtime smoke.
 *
 * Required env:
 * - SMOKE_BASE_URL
 * - SMOKE_FOUNDER_EMAIL / SMOKE_FOUNDER_PASSWORD
 * - SMOKE_BRANCH_A_EMAIL / SMOKE_BRANCH_A_PASSWORD
 * - SMOKE_BRANCH_B_EMAIL / SMOKE_BRANCH_B_PASSWORD
 * - SMOKE_MULTI_EMAIL / SMOKE_MULTI_PASSWORD
 * - SMOKE_ORPHAN_EMAIL / SMOKE_ORPHAN_PASSWORD
 * - SMOKE_FOREIGN_BRANCH_ID
 */

if (!extension_loaded('curl')) {
    fwrite(STDERR, "smoke_tenant_entry_flow_01: PHP curl extension is required.\n");
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
$founderEmail = $d('SMOKE_FOUNDER_EMAIL', 'founder-smoke@example.test');
$founderPassword = $d('SMOKE_FOUNDER_PASSWORD', 'FounderSmoke##2026');
$branchAEmail = $d('SMOKE_BRANCH_A_EMAIL', 'tenant-admin-a@example.test');
$branchAPassword = $d('SMOKE_BRANCH_A_PASSWORD', 'TenantAdminA##2026');
$branchBEmail = $d('SMOKE_BRANCH_B_EMAIL', 'tenant-reception-b@example.test');
$branchBPassword = $d('SMOKE_BRANCH_B_PASSWORD', 'TenantReceptionB##2026');
$multiEmail = $d('SMOKE_MULTI_EMAIL', 'tenant-multi-choice@example.test');
$multiPassword = $d('SMOKE_MULTI_PASSWORD', 'TenantMultiChoice##2026');
$orphanEmail = $d('SMOKE_ORPHAN_EMAIL', 'negative-orphan-access@example.test');
$orphanPassword = $d('SMOKE_ORPHAN_PASSWORD', 'NegativeOrphan##2026');
$foreignBranchId = (int) (getenv('SMOKE_FOREIGN_BRANCH_ID') ?: '0');

if ($foreignBranchId <= 0) {
    fwrite(STDERR, "Set SMOKE_FOREIGN_BRANCH_ID (positive branch id not granted to smoke users).\n");
    exit(2);
}

$passed = 0;
$failed = 0;
function te01Pass(string $name): void { global $passed; $passed++; fwrite(STDOUT, "PASS  {$name}\n"); }
function te01Fail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }

/**
 * @return array{code:int, body:string, location:?string}
 */
function te01Http(string $method, string $url, ?string $jar, ?array $post, bool $skipTls, array $headers = []): array
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

/**
 * @return array{jar:string, login_ok:bool}
 */
function te01Login(string $baseUrl, string $email, string $password, string $csrfField, bool $skipTls): array
{
    $jar = tempnam(sys_get_temp_dir(), 'spa_te01_');
    if ($jar === false) {
        return ['jar' => '', 'login_ok' => false];
    }
    $loginGet = te01Http('GET', $baseUrl . '/login', $jar, null, $skipTls);
    if ($loginGet['code'] !== 200 || !preg_match('/name="' . preg_quote($csrfField, '/') . '"\s+value="([^"]+)"/', $loginGet['body'], $cm)) {
        return ['jar' => $jar, 'login_ok' => false];
    }
    $loginPost = te01Http('POST', $baseUrl . '/login', $jar, [
        $csrfField => $cm[1],
        'email' => $email,
        'password' => $password,
    ], $skipTls);

    return ['jar' => $jar, 'login_ok' => $loginPost['code'] === 302];
}

function te01ExtractCsrf(string $html, string $csrfField): ?string
{
    if (!preg_match('/name="' . preg_quote($csrfField, '/') . '"\s+value="([^"]+)"/', $html, $m)) {
        return null;
    }

    return $m[1];
}

function te01ExtractFirstBranchId(string $html): ?int
{
    if (!preg_match('/<option\s+value="(\d+)"/i', $html, $m)) {
        return null;
    }
    $id = (int) $m[1];

    return $id > 0 ? $id : null;
}

$founder = te01Login($baseUrl, $founderEmail, $founderPassword, $csrfField, $skipTlsVerify);
$branchA = te01Login($baseUrl, $branchAEmail, $branchAPassword, $csrfField, $skipTlsVerify);
$branchB = te01Login($baseUrl, $branchBEmail, $branchBPassword, $csrfField, $skipTlsVerify);
$multi = te01Login($baseUrl, $multiEmail, $multiPassword, $csrfField, $skipTlsVerify);
$orphan = te01Login($baseUrl, $orphanEmail, $orphanPassword, $csrfField, $skipTlsVerify);

foreach (['founder' => $founder, 'branchA' => $branchA, 'branchB' => $branchB, 'multi' => $multi, 'orphan' => $orphan] as $label => $auth) {
    if ($auth['login_ok']) {
        te01Pass("{$label}_login");
    } else {
        te01Fail("{$label}_login", 'expected login success');
    }
}

if ($failed === 0) {
    $branchAEntry = te01Http('GET', $baseUrl . '/tenant-entry', $branchA['jar'], null, $skipTlsVerify);
    if ($branchAEntry['code'] === 302 && $branchAEntry['location'] !== null && str_contains($branchAEntry['location'], '/dashboard')) {
        te01Pass('branchA_single_branch_auto_resolve_to_dashboard');
    } else {
        te01Fail('branchA_single_branch_auto_resolve_to_dashboard', "expected 302 /dashboard, got code={$branchAEntry['code']} location=" . ($branchAEntry['location'] ?? 'null'));
    }

    $branchBEntry = te01Http('GET', $baseUrl . '/tenant-entry', $branchB['jar'], null, $skipTlsVerify);
    if ($branchBEntry['code'] === 302 && $branchBEntry['location'] !== null && str_contains($branchBEntry['location'], '/dashboard')) {
        te01Pass('branchB_single_branch_auto_resolve_to_dashboard');
    } else {
        te01Fail('branchB_single_branch_auto_resolve_to_dashboard', "expected 302 /dashboard, got code={$branchBEntry['code']} location=" . ($branchBEntry['location'] ?? 'null'));
    }

    $multiEntry = te01Http('GET', $baseUrl . '/tenant-entry', $multi['jar'], null, $skipTlsVerify);
    if ($multiEntry['code'] === 200 && str_contains($multiEntry['body'], '/account/branch-context')) {
        te01Pass('multi_branch_lands_on_chooser');
    } else {
        te01Fail('multi_branch_lands_on_chooser', "expected chooser 200, got code={$multiEntry['code']}");
    }

    $chooserCsrf = te01ExtractCsrf($multiEntry['body'], $csrfField);
    $chooserAllowedBranch = te01ExtractFirstBranchId($multiEntry['body']);
    if ($chooserCsrf === null || $chooserAllowedBranch === null) {
        te01Fail('chooser_post_with_allowed_branch_enters_dashboard', 'could not resolve chooser csrf/branch option');
        te01Fail('chooser_post_with_foreign_branch_denied', 'could not resolve chooser csrf');
    } else {
        $chooserPostAllowed = te01Http('POST', $baseUrl . '/account/branch-context', $multi['jar'], [
            $csrfField => $chooserCsrf,
            'branch_id' => (string) $chooserAllowedBranch,
            'redirect_to' => '/dashboard',
        ], $skipTlsVerify);
        if ($chooserPostAllowed['code'] === 302 && $chooserPostAllowed['location'] !== null && str_contains($chooserPostAllowed['location'], '/dashboard')) {
            te01Pass('chooser_post_with_allowed_branch_enters_dashboard');
        } else {
            te01Fail('chooser_post_with_allowed_branch_enters_dashboard', "expected 302 /dashboard, got code={$chooserPostAllowed['code']}");
        }

        $chooserPostForeign = te01Http('POST', $baseUrl . '/account/branch-context', $multi['jar'], [
            $csrfField => $chooserCsrf,
            'branch_id' => (string) $foreignBranchId,
            'redirect_to' => '/dashboard',
        ], $skipTlsVerify, ['Accept: application/json']);
        if ($chooserPostForeign['code'] === 403 && str_contains($chooserPostForeign['body'], 'FORBIDDEN')) {
            te01Pass('chooser_post_with_foreign_branch_denied');
        } else {
            te01Fail('chooser_post_with_foreign_branch_denied', "expected 403 FORBIDDEN, got code={$chooserPostForeign['code']}");
        }
    }

    $orphanEntry = te01Http('GET', $baseUrl . '/tenant-entry', $orphan['jar'], null, $skipTlsVerify);
    if ($orphanEntry['code'] === 200 && str_contains($orphanEntry['body'], 'No active salon branch is available')) {
        te01Pass('zero_context_gets_blocked_help_screen');
    } else {
        te01Fail('zero_context_gets_blocked_help_screen', "expected 200 blocked screen, got code={$orphanEntry['code']}");
    }

    $founderTenantEntry = te01Http('GET', $baseUrl . '/tenant-entry', $founder['jar'], null, $skipTlsVerify);
    if ($founderTenantEntry['code'] === 302 && $founderTenantEntry['location'] !== null && str_contains($founderTenantEntry['location'], '/platform-admin')) {
        te01Pass('founder_behavior_unchanged');
    } else {
        te01Fail('founder_behavior_unchanged', "expected founder redirect to /platform-admin, got code={$founderTenantEntry['code']}");
    }
}

foreach ([$founder['jar'], $branchA['jar'], $branchB['jar'], $multi['jar'], $orphan['jar']] as $jar) {
    if (is_string($jar) && $jar !== '') {
        @unlink($jar);
    }
}

fwrite(STDOUT, "\nSummary: {$passed} passed, {$failed} failed.\n");
exit($failed > 0 ? 1 : 0);
