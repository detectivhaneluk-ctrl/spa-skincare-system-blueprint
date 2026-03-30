<?php

declare(strict_types=1);

/**
 * Counts structured log lines by severity (or level) and category from a log file.
 *
 * From repository root:
 *   php system/scripts/read-only/report_structured_log_severity_category_counts_readonly_01.php [--file=path]
 *
 * Exit: 0 (report only).
 */

$repoRoot = realpath(dirname(__DIR__, 3));
if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(0);
}

$file = null;
foreach ($argv as $i => $arg) {
    if ($i > 0 && str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    }
}

if ($file === null || $file === '' || !is_readable($file)) {
    echo "FINAL-OPERATIONAL-EXCELLENCE-WAVE-01 — structured log aggregates\n";
    echo "Usage: php system/scripts/read-only/report_structured_log_severity_category_counts_readonly_01.php --file=/path/to/log\n";
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

$bySeverity = [];
$byCategory = [];
$total = 0;

$fh = fopen($file, 'rb');
if ($fh === false) {
    fwrite(STDERR, "Could not open: {$file}\n");
    exit(0);
}
while (($line = fgets($fh)) !== false) {
    $line = trim($line);
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
    ++$total;
    $sev = (string) ($row['severity'] ?? $row['level'] ?? 'unknown');
    $cat = (string) ($row['category'] ?? 'unknown');
    $bySeverity[$sev] = ($bySeverity[$sev] ?? 0) + 1;
    $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;
}
fclose($fh);

arsort($bySeverity);
arsort($byCategory);

echo "FINAL-OPERATIONAL-EXCELLENCE-WAVE-01 — structured log severity/category counts\n";
echo 'Source: ' . $file . "\n";
echo 'Structured lines matched: ' . $total . "\n\n";
echo "By severity/level:\n";
foreach ($bySeverity as $k => $n) {
    echo '  ' . $n . "\t" . $k . "\n";
}
echo "\nBy category (top 40):\n";
$i = 0;
foreach ($byCategory as $k => $n) {
    echo '  ' . $n . "\t" . $k . "\n";
    if (++$i >= 40) {
        $rest = count($byCategory) - 40;
        if ($rest > 0) {
            echo "  ... ({$rest} more categories)\n";
        }
        break;
    }
}

exit(0);
