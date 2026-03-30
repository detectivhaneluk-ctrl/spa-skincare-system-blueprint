<?php

declare(strict_types=1);

/**
 * SENSITIVE-TOKEN-URL-LEAKAGE-REMOVAL-01 — static audit for secret-bearing query strings and unsafe redirects.
 *
 * Allowlisted (documented):
 * - Email / staff copy links may include `?token=` once: password reset ({@see PasswordResetService::absoluteResetUrl}),
 *   intake completion URL ({@see modules/intake/views/assignments/index.php}).
 * - `$_GET['token']` only for one-time exchange into session: {@see PasswordResetController}, {@see IntakePublicController}.
 *
 * From repository root:
 *   php system/scripts/read-only/verify_sensitive_token_url_leakage_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$systemRoot = realpath(dirname(__DIR__, 2));
if ($systemRoot === false) {
    fwrite(STDERR, "FAIL: could not resolve system/ root.\n");
    exit(1);
}

$registrar = $systemRoot . '/routes/web/register_core_dashboard_auth_public.php';
$reg = is_file($registrar) ? (string) file_get_contents($registrar) : '';
$failures = [];

if (str_contains($reg, "\$router->get('/api/public/booking/manage'")) {
    $failures[] = 'Routes: GET /api/public/booking/manage must not be registered (use POST + body token).';
}
if (str_contains($reg, "\$router->get('/api/public/booking/manage/slots'")) {
    $failures[] = 'Routes: GET /api/public/booking/manage/slots must not be registered (use POST + body).';
}
if (str_contains($reg, "\$router->get('/api/public/commerce/purchase/status'")) {
    $failures[] = 'Routes: GET /api/public/commerce/purchase/status must not be registered (use POST + body).';
}
if (!str_contains($reg, "\$router->post('/api/public/booking/manage'")) {
    $failures[] = 'Routes: missing POST /api/public/booking/manage';
}
if (!str_contains($reg, "\$router->post('/api/public/booking/manage/slots'")) {
    $failures[] = 'Routes: missing POST /api/public/booking/manage/slots';
}
if (!str_contains($reg, "\$router->post('/api/public/commerce/purchase/status'")) {
    $failures[] = 'Routes: missing POST /api/public/commerce/purchase/status';
}

$allowGetTokenBasenames = ['IntakePublicController.php', 'PasswordResetController.php'];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($systemRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($path, strlen($systemRoot)));
    $content = (string) file_get_contents($path);

    if (str_contains($content, "\$_GET['confirmation_token']") || str_contains($content, '$_GET["confirmation_token"]')) {
        $failures[] = "Forbidden \$_GET['confirmation_token'] in {$rel}";
    }

    if (preg_match('/\$_GET\[[\'"]token[\'"]\]/', $content) === 1) {
        $base = basename($path);
        if (!in_array($base, $allowGetTokenBasenames, true)) {
            $failures[] = "Forbidden \$_GET['token'] outside exchange controllers in {$rel}";
        }
    }

    // Narrow: literal `token` query param built via http_build_query (not e.g. csrf_token substrings).
    if (preg_match('/http_build_query\s*\(\s*(?:\[\s*|array\s*\(\s*)[\'"]token[\'"]\s*=>/s', $content) === 1) {
        $failures[] = "Forbidden http_build_query with token query param in {$rel}";
    }

    if (preg_match("/header\s*\(\s*['\"]Location:\s*[^'\"]*token=/i", $content) === 1) {
        $failures[] = "Forbidden redirect Location with token= query in {$rel}";
    }
}

foreach ($failures as $f) {
    fwrite(STDERR, 'FAIL: ' . $f . "\n");
}

if ($failures !== []) {
    exit(1);
}

echo "SENSITIVE-TOKEN-URL-LEAKAGE-01: static checks passed.\n";
echo "Allowlisted: email reset URL + intake staff completion link may contain ?token=; exchange controllers may read \$_GET['token'] once then redirect.\n";
exit(0);
