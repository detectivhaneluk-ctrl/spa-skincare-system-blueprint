<?php

declare(strict_types=1);

/**
 * Requires @release-proof metadata on changed hotspot files (PR range).
 * Risk removed: backbone edits without task / risk / proof / ROOT linkage.
 * Proof: php system/scripts/ci/verify_hotspot_proof_metadata.php --base=<sha> --head=<sha>
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
    fwrite(STDERR, "Usage: php verify_hotspot_proof_metadata.php --base=<sha> --head=<sha>\n");
    exit(2);
}

$configPath = $repoRoot . '/system/docs/contracts/hotspot-path-patterns.json';
$raw = file_get_contents($configPath);
if ($raw === false) {
    fwrite(STDERR, "Missing {$configPath}\n");
    exit(1);
}
/** @var array{path_regexes?: list<string>} $cfg */
$cfg = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$regexes = $cfg['path_regexes'] ?? [];

$range = escapeshellarg($base) . '...' . escapeshellarg($head);
$output = shell_exec("git diff --name-only --diff-filter=ACDMRT {$range} 2>&1");
if ($output === null) {
    fwrite(STDERR, "verify_hotspot_proof_metadata: git diff failed\n");
    exit(1);
}

$paths = array_values(array_filter(array_map('trim', explode("\n", $output))));
$hotspotChanged = [];
foreach ($paths as $rel) {
    $n = str_replace('\\', '/', $rel);
    foreach ($regexes as $re) {
        $delim = '~';
        if (preg_match($delim . str_replace($delim, '\\' . $delim, $re) . $delim, $n) === 1) {
            $hotspotChanged[] = $n;
            break;
        }
    }
}

$hotspotChanged = array_values(array_unique($hotspotChanged));

$errors = [];
foreach ($hotspotChanged as $rel) {
    $full = $repoRoot . '/' . $rel;
    if (!is_file($full)) {
        continue;
    }
    $snippet = readFirstBytes($full, 16384);
    if (!proofMetadataComplete($snippet)) {
        $errors[] = $rel;
    }
}

if ($errors !== []) {
    fwrite(STDERR, "verify_hotspot_proof_metadata: FAIL — missing @release-proof block in:\n"
        . implode("\n", $errors)
        . "\nSee system/docs/DONE-MEANS-PROVED.md\n");
    exit(1);
}

echo "verify_hotspot_proof_metadata: PASS";
if ($hotspotChanged !== []) {
    echo ' (checked ' . count($hotspotChanged) . ' hotspot file(s))';
}
echo "\n";
exit(0);

function readFirstBytes(string $path, int $max): string
{
    $h = fopen($path, 'rb');
    if ($h === false) {
        return '';
    }
    $data = fread($h, $max);
    fclose($h);
    return $data === false ? '' : $data;
}

function proofMetadataComplete(string $snippet): bool
{
    if (!preg_match('/@release-proof\b/', $snippet)) {
        return false;
    }
    if (!preg_match('/@task-id\s+\S+/', $snippet)) {
        return false;
    }
    if (!preg_match('/@risk-removed\s+\S/', $snippet)) {
        return false;
    }
    if (!preg_match('/@proof-command\s+\S/', $snippet)) {
        return false;
    }
    if (!preg_match('/@root-family\s+(ROOT-[0-9]+|NONE)\b/', $snippet)) {
        return false;
    }
    return true;
}
