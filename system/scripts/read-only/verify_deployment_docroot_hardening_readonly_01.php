<?php

declare(strict_types=1);

/**
 * DEPLOYMENT-DOCROOT-EXPOSURE-HARDENING-01 — read-only structural + surface checks for safe HTTP docroot.
 *
 * Confirms:
 * - Intended production docroot path (system/public)
 * - Canonical entry file wires bootstrap
 * - system/public does not contain high-risk artifact extensions
 * - system/storage and system/config have Apache deny .htaccess
 * - Root .htaccess includes defense-in-depth marker when present
 *
 * From repository root:
 *   php system/scripts/read-only/verify_deployment_docroot_hardening_readonly_01.php
 *
 * Exit: 0 = pass (warnings may be printed), 1 = fail.
 */

$repoRoot = realpath(dirname(__DIR__, 3));
if ($repoRoot === false) {
    fwrite(STDERR, "FAIL: could not resolve repository root.\n");
    exit(1);
}

$failures = [];
$warnings = [];

$intendedDocroot = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'public';
$publicIndex = $intendedDocroot . DIRECTORY_SEPARATOR . 'index.php';
$bootstrap = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'bootstrap.php';
$storageHtaccess = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . '.htaccess';
$configHtaccess = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.htaccess';
$rootHtaccess = $repoRoot . DIRECTORY_SEPARATOR . '.htaccess';
$rootIndex = $repoRoot . DIRECTORY_SEPARATOR . 'index.php';

echo "=== DEPLOYMENT-DOCROOT-EXPOSURE-HARDENING-01 ===\n\n";
echo 'INTENDED PRODUCTION DOCUMENT ROOT (absolute): ' . $intendedDocroot . "\n";
echo 'CANONICAL ENTRY SCRIPT: system/public/index.php' . "\n\n";

echo "FORBIDDEN WEB-ACCESSIBLE TREE (must not be production DocumentRoot):\n";
$forbidden = [
    'system/ (except as parent of public/ — never use system/ as docroot)',
    'archive/',
    'handoff/',
    'workers/',
    'distribution/',
    'logs/ (repo-top or operational; never web-rooted)',
    'backups/',
    'secrets/',
    'scripts/ (repo-top tooling; system/scripts/ already under blocked system/)',
    'system/data/ (migrations, schema SQL)',
    'system/scripts/',
    'system/docs/',
    'system/config/',
    'system/storage/ (files; not a public document root)',
];
foreach ($forbidden as $line) {
    echo '  - ' . $line . "\n";
}
echo "\nDO NOT SERVE: .env*, *.sql dumps, *.zip handoffs, private keys, logs under a public URL.\n\n";

$publicHtaccess = $intendedDocroot . DIRECTORY_SEPARATOR . '.htaccess';

if (!is_file($publicIndex)) {
    $failures[] = 'Missing system/public/index.php';
} else {
    $idx = (string) file_get_contents($publicIndex);
    if (!str_contains($idx, 'bootstrap.php') || !str_contains($idx, 'modules/bootstrap.php')) {
        $failures[] = 'system/public/index.php must require system bootstrap and modules/bootstrap.php';
    }
    if (!str_contains($idx, 'DEPLOYMENT-DOCROOT-CANONICAL-PUBLIC-ENTRY-MARKER-01')) {
        $failures[] = 'system/public/index.php must retain DEPLOYMENT-DOCROOT-CANONICAL-PUBLIC-ENTRY-MARKER-01';
    }
}

if (!is_file($publicHtaccess)) {
    $failures[] = 'Missing system/public/.htaccess';
} else {
    $pubHt = (string) file_get_contents($publicHtaccess);
    if (!str_contains($pubHt, 'DEPLOYMENT-DOCROOT-PUBLIC-HTACCESS-MARKER-01')) {
        $failures[] = 'system/public/.htaccess must retain DEPLOYMENT-DOCROOT-PUBLIC-HTACCESS-MARKER-01 (production docroot anchor)';
    }
}

if (!is_file($bootstrap)) {
    $failures[] = 'Missing system/bootstrap.php';
}

if (!is_file($storageHtaccess)) {
    $failures[] = 'Missing system/storage/.htaccess (expected deny-all for Apache)';
} else {
    $st = (string) file_get_contents($storageHtaccess);
    if (!str_contains($st, 'denied') && !str_contains($st, 'Deny from all')) {
        $failures[] = 'system/storage/.htaccess should deny HTTP access';
    }
}

if (!is_file($configHtaccess)) {
    $failures[] = 'Missing system/config/.htaccess (expected deny-all for Apache)';
} else {
    $cfgHt = (string) file_get_contents($configHtaccess);
    if (!str_contains($cfgHt, 'denied') && !str_contains($cfgHt, 'Deny from all')) {
        $failures[] = 'system/config/.htaccess should deny HTTP access';
    }
    if (!str_contains($cfgHt, 'DEPLOYMENT-DOCROOT-SYSTEM-CONFIG-HTACCESS-MARKER-01')) {
        $failures[] = 'system/config/.htaccess must retain DEPLOYMENT-DOCROOT-SYSTEM-CONFIG-HTACCESS-MARKER-01';
    }
}

if (is_file($rootIndex)) {
    $warnings[] = 'Repository root index.php exists: dev convenience only. Production DocumentRoot must be system/public (see system/docs/DEPLOYMENT-DOCROOT-EXPOSURE-HARDENING-01.md).';
    $rootIdx = (string) file_get_contents($rootIndex);
    if (!str_contains($rootIdx, 'DEPLOYMENT-DOCROOT-DEV-ENTRY-MARKER-01')) {
        $failures[] = 'Repository root index.php must retain DEPLOYMENT-DOCROOT-DEV-ENTRY-MARKER-01 (operator/verifier anchor)';
    }
    if (!str_contains($rootIdx, 'DEPLOYMENT-DOCROOT-ROOT-INDEX-PRODUCTION-BLOCK-MARKER-01')) {
        $failures[] = 'Repository root index.php must retain DEPLOYMENT-DOCROOT-ROOT-INDEX-PRODUCTION-BLOCK-MARKER-01 (production root-entry refusal anchor)';
    }
}

$apacheRef = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'deployment' . DIRECTORY_SEPARATOR . 'apache-vhost.production-snippet.conf';
$nginxRef = $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'deployment' . DIRECTORY_SEPARATOR . 'nginx-server.production-snippet.conf';
if (!is_file($apacheRef)) {
    $failures[] = 'Missing system/docs/deployment/apache-vhost.production-snippet.conf (reference vhost fragment)';
} else {
    $a = (string) file_get_contents($apacheRef);
    if (!str_contains($a, 'DEPLOYMENT-REFERENCE-APACHE-MARKER-01')) {
        $failures[] = 'Apache reference snippet must retain DEPLOYMENT-REFERENCE-APACHE-MARKER-01';
    }
    if (!str_contains($a, 'DEPLOYMENT-REFERENCE-APACHE-ELITE-MARKER-01')) {
        $failures[] = 'Apache reference snippet must retain DEPLOYMENT-REFERENCE-APACHE-ELITE-MARKER-01';
    }
    if (substr_count($a, 'system/public') < 2) {
        $failures[] = 'Apache reference snippet must show system/public at least twice (DocumentRoot + Directory examples)';
    }
}
if (!is_file($nginxRef)) {
    $failures[] = 'Missing system/docs/deployment/nginx-server.production-snippet.conf (reference server fragment)';
} else {
    $n = (string) file_get_contents($nginxRef);
    if (!str_contains($n, 'DEPLOYMENT-REFERENCE-NGINX-MARKER-01')) {
        $failures[] = 'Nginx reference snippet must retain DEPLOYMENT-REFERENCE-NGINX-MARKER-01';
    }
    if (!str_contains($n, 'DEPLOYMENT-REFERENCE-NGINX-ELITE-MARKER-01')) {
        $failures[] = 'Nginx reference snippet must retain DEPLOYMENT-REFERENCE-NGINX-ELITE-MARKER-01';
    }
    if (!str_contains($n, 'root /var/www/spa/system/public')) {
        $failures[] = 'Nginx reference snippet must include concrete root /var/www/spa/system/public example line';
    }
}

if (is_file($rootHtaccess)) {
    $ht = (string) file_get_contents($rootHtaccess);
    if (!str_contains($ht, 'DEPLOYMENT-DOCROOT-EXPOSURE-HARDENING-01')) {
        $warnings[] = 'Root .htaccess missing DEPLOYMENT-DOCROOT-EXPOSURE-HARDENING-01 marker (deny rules may be absent).';
    }
    if (!str_contains($ht, 'DEPLOYMENT-DOCROOT-ROOT-DENY-DEPTH-MARKER-02')) {
        $failures[] = 'Root .htaccess must retain DEPLOYMENT-DOCROOT-ROOT-DENY-DEPTH-MARKER-02 (extended deny-depth anchor).';
    }
    if (!preg_match(
        '/RewriteRule\s+\^\(archive\|distribution\|handoff\|system\|workers\|logs\|backups\|secrets\|scripts\)/',
        $ht
    )) {
        $failures[] = 'Root .htaccess RewriteRule deny alternation must include archive|distribution|handoff|system|workers|logs|backups|secrets|scripts.';
    }
} else {
    $warnings[] = 'No repository root .htaccess (optional Apache defense in depth for mistaken docroot).';
}

$riskExt = ['env', 'sql', 'zip', 'log', 'bak', 'pem', 'key', 'p12', 'yaml', 'yml'];
if (is_dir($intendedDocroot)) {
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($intendedDocroot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $base = $file->getBasename();
        if ($base === '.env' || str_starts_with($base, '.env.')) {
            $failures[] = 'Forbidden env file under system/public: ' . $file->getPathname();
            continue;
        }
        $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, $riskExt, true)) {
            $failures[] = 'Forbidden extension in system/public (.' . $ext . '): ' . $file->getPathname();
        }
    }
} else {
    $failures[] = 'system/public is not a directory';
}

foreach ($warnings as $w) {
    echo 'WARNING: ' . $w . "\n";
}
if ($warnings !== []) {
    echo "\n";
}

foreach ($failures as $f) {
    fwrite(STDERR, 'FAIL: ' . $f . "\n");
}

if ($failures !== []) {
    exit(1);
}

echo "CHECK: All structural docroot hardening checks passed.\n";
exit(0);
