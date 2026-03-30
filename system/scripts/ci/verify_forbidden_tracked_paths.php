<?php

declare(strict_types=1);

/**
 * Fails if git tracks paths that must never be canonical (secrets, env, logs, sessions, zips, IDE noise).
 * Risk removed: accidental commit of credentials or runtime junk.
 * Proof: run from repo root; optional --staged for index-only check (pre-commit).
 * @root-family NONE
 */

$repoRoot = dirname(__DIR__, 3);
$stagedOnly = in_array('--staged', $argv ?? [], true);

chdir($repoRoot);

$cmd = $stagedOnly
    ? 'git diff --cached --name-only --diff-filter=ACMR'
    : 'git ls-files';

$output = shell_exec($cmd . ' 2>&1');
if ($output === null) {
    fwrite(STDERR, "verify_forbidden_tracked_paths: git command failed\n");
    exit(1);
}

$paths = array_filter(array_map('trim', explode("\n", $output)));

$denyRes = [
    '#(^|/)\\.env$#i',
    '#(^|/)\\.env\\.[^/]+$#',
    '#(^|/)\\.cursor(/|$)#',
    '#(^|/)\\.idea(/|$)#',
    '#\\.log$#i',
    '#\\.zip$#i',
    '#(^|/)vendor(/|$)#',
    '#(^|/)node_modules(/|$)#',
    '#(^|/)system/storage/sessions/[^/]+$#',
    '#(^|/)id_rsa$#',
    '#\\.pem$#i',
    '#\\.p12$#i',
    '#(^|/)\\.ssh(/|$)#',
    '#(^|/)auth\\.json$#i',
    '#(^|/)\\.phpcs-cache$#',
];

$errors = [];
foreach ($paths as $p) {
    $n = str_replace('\\', '/', $p);
    if ($n === 'system/.env.example' || str_ends_with($n, '.env.example')) {
        continue;
    }
    foreach ($denyRes as $re) {
        if (preg_match($re, $n) === 1) {
            $errors[] = $n;
            break;
        }
    }
}

// Explicit allow: only .gitkeep under sessions (tracked) is OK
$errors = array_values(array_filter($errors, static function (string $p): bool {
    return $p !== 'system/storage/sessions/.gitkeep';
}));

if ($errors !== []) {
    fwrite(STDERR, "verify_forbidden_tracked_paths: FAIL — forbidden path(s):\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "verify_forbidden_tracked_paths: PASS\n";
exit(0);
