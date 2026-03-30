<?php

declare(strict_types=1);

/**
 * Fails if a commit range adds or modifies paths that must never enter the repo.
 * Risk removed: secrets and runtime artifacts in PR diffs.
 * Proof: php system/scripts/ci/verify_changed_paths_policy.php --base=<sha> --head=<sha>
 * @root-family NONE
 */

$repoRoot = dirname(__DIR__, 3);
chdir($repoRoot);

$base = null;
$head = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = substr($arg, 7);
    } elseif (str_starts_with($arg, '--head=')) {
        $head = substr($arg, 7);
    }
}

if ($base === null || $head === null || $base === '' || $head === '') {
    fwrite(STDERR, "Usage: php verify_changed_paths_policy.php --base=<sha> --head=<sha>\n");
    exit(2);
}

$range = escapeshellarg($base) . '...' . escapeshellarg($head);
$output = shell_exec("git diff --name-only --diff-filter=ACDMRT {$range} 2>&1");
if ($output === null) {
    fwrite(STDERR, "verify_changed_paths_policy: git diff failed\n");
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
];

$errors = [];
foreach ($paths as $p) {
    $n = str_replace('\\', '/', $p);
    if ($n === 'system/storage/sessions/.gitkeep') {
        continue;
    }
    if (str_ends_with($n, '.env.example')) {
        continue;
    }
    foreach ($denyRes as $re) {
        if (preg_match($re, $n) === 1) {
            $errors[] = $n;
            break;
        }
    }
}

$errors = array_values(array_unique($errors));

if ($errors !== []) {
    fwrite(STDERR, "verify_changed_paths_policy: FAIL — forbidden path(s) in diff:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "verify_changed_paths_policy: PASS\n";
exit(0);
