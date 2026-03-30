<?php

declare(strict_types=1);

require __DIR__ . '/release_law_lib.php';

$repoRoot = dirname(__DIR__, 3);
$emitJson = false;
$selectedPaths = [];

foreach (array_slice($_SERVER['argv'] ?? [], 1) as $argument) {
    if ($argument === '--json') {
        $emitJson = true;
        continue;
    }
    if (str_starts_with($argument, '--repo-root=')) {
        $repoRoot = rtrim(substr($argument, strlen('--repo-root=')), "/\\");
        continue;
    }
    if (str_starts_with($argument, '--path=')) {
        $selectedPaths[] = ReleaseLawPaths::normalizeRelative(substr($argument, strlen('--path=')));
        continue;
    }
    if (str_starts_with($argument, '--paths-file=')) {
        $file = substr($argument, strlen('--paths-file='));
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $selectedPaths[] = ReleaseLawPaths::normalizeRelative($line);
            }
        }
    }
}

$composerValidation = ReleaseLawComposer::validateSettings($repoRoot);
$audit = ReleaseLawCasePathAuditor::audit($repoRoot, array_values(array_unique($selectedPaths)));
$payload = [
    'composer_settings_ok' => $composerValidation['ok'],
    'composer_contradictions' => $composerValidation['contradictions'],
    'audit' => $audit,
];

$ok = $composerValidation['ok'] && $audit['ok'];

if ($emitJson) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'composer_settings=' . ($composerValidation['ok'] ? 'PASS' : 'FAIL') . PHP_EOL;
    echo 'checked_classes=' . $audit['checked_class_count'] . PHP_EOL;
    echo 'critical_prefixes=' . implode(',', $audit['critical_family_checked']) . PHP_EOL;
    echo 'verdict=' . ($ok ? 'ACCEPTED' : 'CONTRADICTED') . PHP_EOL;

    $contradictions = array_merge($composerValidation['contradictions'], $audit['contradictions']);
    foreach ($contradictions as $contradiction) {
        echo 'contradiction_type=' . ($contradiction['type'] ?? 'unknown') . PHP_EOL;
        echo 'offending_path=' . ($contradiction['offending_path'] ?? 'n/a') . PHP_EOL;
        echo 'expected_path=' . ($contradiction['expected_path'] ?? 'n/a') . PHP_EOL;
        echo 'real_path=' . ($contradiction['real_path'] ?? 'n/a') . PHP_EOL;
        echo 'namespace_prefix=' . ($contradiction['namespace_prefix'] ?? 'n/a') . PHP_EOL;
        echo 'message=' . ($contradiction['message'] ?? '') . PHP_EOL;
    }
}

exit($ok ? 0 : 1);
