<?php

declare(strict_types=1);

/**
 * HTTP smoke checks for auth / session / permission boundaries (MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-03).
 *
 * No DB writes. No production code loaded — hits a running app instance via HTTP only.
 *
 * @see system/docs/AUTH-PERMISSIONS-SMOKE-OPS.md
 */

if (!extension_loaded('curl')) {
    fwrite(STDERR, "smoke_auth_permissions: PHP curl extension is required.\n");
    exit(2);
}

$baseUrl = rtrim((string) (getenv('SMOKE_BASE_URL') ?: ''), '/');
if ($baseUrl === '') {
    fwrite(STDERR, "smoke_auth_permissions: Set SMOKE_BASE_URL to the running app origin (e.g. http://spa.test or http://127.0.0.1:8080).\n");
    exit(2);
}

$staffEmail = (string) (getenv('SMOKE_STAFF_EMAIL') ?: '');
$staffPassword = (string) (getenv('SMOKE_STAFF_PASSWORD') ?: '');
$runAuthTier = $staffEmail !== '' && $staffPassword !== '';

$deniedEmail = (string) (getenv('SMOKE_DENIED_EMAIL') ?: '');
$deniedPassword = (string) (getenv('SMOKE_DENIED_PASSWORD') ?: '');
$runDeniedTier = $deniedEmail !== '' && $deniedPassword !== '';

$sessionCookie = (string) (getenv('SMOKE_SESSION_COOKIE') ?: getenv('SESSION_COOKIE') ?: 'spa_session');
$csrfField = (string) (getenv('SMOKE_CSRF_FIELD') ?: 'csrf_token');
$skipTlsVerify = filter_var((string) (getenv('SMOKE_SKIP_TLS_VERIFY') ?: ''), FILTER_VALIDATE_BOOLEAN);

$passed = 0;
$failed = 0;

function smoke_fail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

function smoke_pass(string $name): void
{
    global $passed;
    $passed++;
    fwrite(STDOUT, "PASS  {$name}\n");
}

/**
 * @return array{code:int, body:string, location:?string}
 */
function smoke_http(
    string $method,
    string $url,
    array $headers,
    ?string $cookieJarPath,
    ?array $postFields,
    bool $skipTlsVerify
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        return ['code' => 0, 'body' => '', 'location' => null];
    }
    $hdr = $headers;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $hdr,
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
        return ['code' => 0, 'body' => $raw, 'location' => null];
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

// --- Tier A: anonymous / public boundaries (no credentials) ---

$r = smoke_http('GET', $baseUrl . '/login', [], null, null, $skipTlsVerify);
if ($r['code'] !== 200 || stripos($r['body'], 'Login') === false) {
    smoke_fail('public_guest_login_page', "expected HTTP 200 with login markup, got {$r['code']}");
} else {
    smoke_pass('public_guest_login_page');
}

$r = smoke_http('GET', $baseUrl . '/api/public/booking/slots', [], null, null, $skipTlsVerify);
if ($r['code'] === 401 || $r['code'] === 302) {
    smoke_fail('public_booking_slots_not_auth_gated', "expected public endpoint (not 401/302), got {$r['code']}");
} else {
    smoke_pass('public_booking_slots_not_auth_gated');
}

$r = smoke_http('GET', $baseUrl . '/notifications', ['Accept: application/json'], null, null, $skipTlsVerify);
if ($r['code'] !== 401) {
    smoke_fail('protected_notifications_json_unauthenticated', "expected 401 JSON gate, got {$r['code']}");
} elseif (!str_contains($r['body'], 'UNAUTHORIZED')) {
    smoke_fail('protected_notifications_json_unauthenticated', 'body missing UNAUTHORIZED');
} else {
    smoke_pass('protected_notifications_json_unauthenticated');
}

$r = smoke_http('GET', $baseUrl . '/dashboard', [], null, null, $skipTlsVerify);
if ($r['code'] !== 302 || $r['location'] === null || !str_contains($r['location'], '/login')) {
    smoke_fail('protected_dashboard_redirect_unauthenticated', "expected 302 to /login, got code={$r['code']} location=" . ($r['location'] ?? 'null'));
} else {
    smoke_pass('protected_dashboard_redirect_unauthenticated');
}

$r = smoke_http(
    'GET',
    $baseUrl . '/notifications',
    ['Accept: application/json', 'Cookie: ' . $sessionCookie . '=invalidsmokesessionid'],
    null,
    null,
    $skipTlsVerify
);
if ($r['code'] !== 401) {
    smoke_fail('invalid_session_cookie_unauthenticated', "expected 401 with bogus session cookie, got {$r['code']}");
} else {
    smoke_pass('invalid_session_cookie_unauthenticated');
}

// --- Tier B: authenticated session (optional credentials) ---

if (!$runAuthTier) {
    fwrite(STDOUT, "SKIP  auth_session_tier (set SMOKE_STAFF_EMAIL and SMOKE_STAFF_PASSWORD)\n");
    fwrite(STDOUT, "SKIP  guest_login_redirect_when_authenticated (requires auth tier)\n");
} else {
    $jar = tempnam(sys_get_temp_dir(), 'spa_smoke_');
    if ($jar === false) {
        fwrite(STDERR, "smoke_auth_permissions: could not create temp cookie jar.\n");
        exit(2);
    }
    try {
        $r = smoke_http('GET', $baseUrl . '/login', [], $jar, null, $skipTlsVerify);
        if ($r['code'] !== 200) {
            smoke_fail('auth_login_page_for_csrf', "GET /login failed: {$r['code']}");
        } elseif (!preg_match('/name="' . preg_quote($csrfField, '/') . '"\s+value="([^"]+)"/', $r['body'], $cm)) {
            smoke_fail('auth_login_page_for_csrf', 'could not parse CSRF hidden field');
        } else {
            $post = [
                $csrfField => $cm[1],
                'email' => $staffEmail,
                'password' => $staffPassword,
            ];
            $r2 = smoke_http('POST', $baseUrl . '/login', [], $jar, $post, $skipTlsVerify);
            if ($r2['code'] !== 302) {
                smoke_fail('auth_staff_login_post', "expected 302 after login, got {$r2['code']} body_head=" . substr($r2['body'], 0, 200));
            } else {
                smoke_pass('auth_staff_login_post');
            }
        }

        if ($failed === 0) {
            $r3 = smoke_http('GET', $baseUrl . '/notifications', ['Accept: application/json'], $jar, null, $skipTlsVerify);
            if ($r3['code'] !== 200) {
                smoke_fail('authenticated_notifications_json_200', "expected 200, got {$r3['code']} (user needs notifications.view?) body_head=" . substr($r3['body'], 0, 200));
            } else {
                $j = json_decode($r3['body'], true);
                if (!is_array($j) || !array_key_exists('notifications', $j)) {
                    smoke_fail('authenticated_notifications_json_200', 'response is not notifications JSON');
                } else {
                    smoke_pass('authenticated_notifications_json_200');
                }
            }
        }

        if ($failed === 0) {
            $r4 = smoke_http('GET', $baseUrl . '/login', [], $jar, null, $skipTlsVerify);
            if ($r4['code'] !== 302 || $r4['location'] === null) {
                smoke_fail('guest_login_redirect_when_authenticated', "expected 302 from GuestMiddleware, got {$r4['code']}");
            } else {
                $loc = $r4['location'];
                if (str_starts_with($loc, 'http')) {
                    $pathOnly = (string) (parse_url($loc, PHP_URL_PATH) ?: '/');
                } else {
                    $pathOnly = explode('?', $loc, 2)[0];
                    if ($pathOnly === '') {
                        $pathOnly = '/';
                    }
                }
                if ($pathOnly === '/login') {
                    smoke_fail('guest_login_redirect_when_authenticated', "expected redirect away from /login, got {$loc}");
                } else {
                    smoke_pass('guest_login_redirect_when_authenticated');
                }
            }
        }
    } finally {
        @unlink($jar);
    }
}

// --- Tier C: permission denial (optional second account without notifications.view) ---

if (!$runDeniedTier) {
    fwrite(STDOUT, "SKIP  permission_denied_notifications_403 (set SMOKE_DENIED_EMAIL and SMOKE_DENIED_PASSWORD for a user lacking notifications.view)\n");
} else {
    $jarD = tempnam(sys_get_temp_dir(), 'spa_smoke_denied_');
    if ($jarD === false) {
        fwrite(STDERR, "smoke_auth_permissions: could not create temp cookie jar for denied tier.\n");
        exit(2);
    }
    try {
        $r = smoke_http('GET', $baseUrl . '/login', [], $jarD, null, $skipTlsVerify);
        if ($r['code'] !== 200 || !preg_match('/name="' . preg_quote($csrfField, '/') . '"\s+value="([^"]+)"/', $r['body'], $cm)) {
            smoke_fail('denied_login_csrf', 'could not load login / CSRF');
        } else {
            $post = [
                $csrfField => $cm[1],
                'email' => $deniedEmail,
                'password' => $deniedPassword,
            ];
            $r2 = smoke_http('POST', $baseUrl . '/login', [], $jarD, $post, $skipTlsVerify);
            if ($r2['code'] !== 302) {
                smoke_fail('denied_user_login', "login failed code={$r2['code']}");
            } else {
                $r3 = smoke_http('GET', $baseUrl . '/notifications', ['Accept: application/json'], $jarD, null, $skipTlsVerify);
                if ($r3['code'] !== 403 || !str_contains($r3['body'], 'FORBIDDEN')) {
                    smoke_fail('permission_denied_notifications_403', "expected 403 FORBIDDEN, got {$r3['code']} body_head=" . substr($r3['body'], 0, 200));
                } else {
                    smoke_pass('permission_denied_notifications_403');
                }
            }
        }
    } finally {
        @unlink($jarD);
    }
}

fwrite(STDOUT, "\nSummary: {$passed} passed, {$failed} failed.\n");

exit($failed > 0 ? 1 : 0);
