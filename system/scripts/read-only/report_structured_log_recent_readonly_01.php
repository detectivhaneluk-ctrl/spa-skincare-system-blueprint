<?php

declare(strict_types=1);

/**
 * Prints the last N JSON structured log lines from a file (spa_structured_* or lines with category + level/severity).
 *
 * From repository root:
 *   php system/scripts/read-only/report_structured_log_recent_readonly_01.php [--file=path] [--limit=50]
 *
 * Exit: 0 (report only).
 */

$repoRoot = realpath(dirname(__DIR__, 3));
if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(0);
}

$file = null;
$limit = 50;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    }
}

if ($file === null || $file === '') {
    $candidates = [
        $repoRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs',
    ];
    $iniLog = ini_get('error_log');
    if (is_string($iniLog) && $iniLog !== '' && !str_starts_with($iniLog, 'syslog')) {
        $candidates[] = dirname($iniLog);
        array_unshift($candidates, $iniLog);
    }
    foreach ($candidates as $c) {
        if (is_file($c)) {
            $file = $c;
            break;
        }
        if (is_dir($c)) {
            $g = glob($c . DIRECTORY_SEPARATOR . '*.log') ?: [];
            if ($g !== []) {
                $file = $g[0];
                break;
            }
        }
    }
}

if ($file === null || !is_readable($file)) {
    echo "FINAL-OPERATIONAL-EXCELLENCE-WAVE-01 — structured log tail\n";
    echo "No readable log file. Pass --file=/path/to/php-error.log\n";
    exit(0);
}

/**
 * @param array<string, mixed> $row
 */
function isStructuredAppLine(array $row): bool
{
    if (isset($row['log_schema']) && is_string($row['log_schema']) && str_starts_with($row['log_schema'], 'spa_structured')) {
        return true;
    }

    return isset($row['category']) && (isset($row['level']) || isset($row['severity']));
}

$lines = @file($file, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Could not read file: {$file}\n");
    exit(0);
}

$matched = [];
for ($i = count($lines) - 1; $i >= 0 && count($matched) < $limit; --$i) {
    $line = trim($lines[$i]);
    if ($line === '' || $line[0] !== '{') {
        continue;
    }
    try {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        continue;
    }
    if (!is_array($row) || !isStructuredAppLine($row)) {
        continue;
    }
    $matched[] = $line;
}

$matched = array_reverse($matched);

echo "FINAL-OPERATIONAL-EXCELLENCE-WAVE-01 — structured log recent (newest last in file; showing last " . count($matched) . " matches)\n";
echo 'Source: ' . $file . "\n\n";
foreach ($matched as $m) {
    echo $m . "\n";
}

exit(0);
