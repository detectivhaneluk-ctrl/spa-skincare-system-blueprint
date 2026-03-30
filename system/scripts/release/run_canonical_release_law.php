<?php

declare(strict_types=1);

require __DIR__ . '/release_law_lib.php';

$repoRoot = dirname(__DIR__, 3);
$reportDir = $repoRoot . '/distribution/release-law';
$zipPath = $repoRoot . '/distribution/spa-skincare-system-blueprint-canonical-release.zip';

foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
    if (str_starts_with($argument, '--report-dir=')) {
        $value = substr($argument, strlen('--report-dir='));
        $reportDir = str_starts_with($value, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $value) === 1
            ? rtrim($value, "/\\")
            : $repoRoot . '/' . ReleaseLawPaths::normalizeRelative($value);
        continue;
    }
    if (str_starts_with($argument, '--output-zip=')) {
        $value = substr($argument, strlen('--output-zip='));
        $zipPath = str_starts_with($value, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $value) === 1
            ? $value
            : $repoRoot . '/' . ReleaseLawPaths::normalizeRelative($value);
    }
}

$result = ReleaseLawGate::run($repoRoot, $reportDir, $zipPath);

echo 'Release Law Verdict: ' . $result['verdict'] . PHP_EOL;
echo 'JSON Report: ' . $result['reports']['json_path'] . PHP_EOL;
echo 'Text Report: ' . $result['reports']['text_path'] . PHP_EOL;
echo 'Text Report SHA256: ' . $result['reports']['text_sha256'] . PHP_EOL;
echo 'Artifact Path: ' . ($result['artifact_path'] ?? 'n/a') . PHP_EOL;

exit($result['verdict'] === 'ACCEPTED' ? 0 : 1);
