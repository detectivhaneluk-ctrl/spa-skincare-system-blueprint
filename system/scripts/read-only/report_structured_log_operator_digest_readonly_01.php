<?php

declare(strict_types=1);

/**
 * Operator digest: severity/category aggregates plus highlights for security- and runtime-sensitive categories.
 *
 * From repository root:
 *   php system/scripts/read-only/report_structured_log_operator_digest_readonly_01.php --file=PATH [--highlights=25]
 *
 * Exit: 0 (report only).
 */

$repoRoot = realpath(dirname(__DIR__, 3));
if ($repoRoot === false) {
    fwrite(STDERR, "Could not resolve repository root.\n");
    exit(0);
}

$file = null;
$highlightLimit = 25;
foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    } elseif (str_starts_with($arg, '--highlights=')) {
        $highlightLimit = max(1, (int) substr($arg, 13));
    }
}

if ($file === null || $file === '' || !is_readable($file)) {
    echo "FINAL-ELITE-BACKEND-MATURITY-WAVE-01 — structured log operator digest\n";
    echo "Usage: php system/scripts/read-only/report_structured_log_operator_digest_readonly_01.php --file=/path/to/log [--highlights=25]\n";
    exit(0);
}

/**
 * @param array<string, mixed> $row
 */
function isStructuredLine(array $row): bool
{
    if (isset($row['log_schema']) && is_string($row['log_schema']) && str_starts_with($row['log_schema'], 'spa_structured')) {
        return true;
    }

    return isset($row['category']) && (isset($row['level']) || isset($row['severity']));
}

function severityOf(array $row): string
{
    return strtolower((string) ($row['severity'] ?? $row['level'] ?? 'unknown'));
}

function isHighlightCategory(string $cat): bool
{
    return (bool) preg_match(
        '/auth\.|session\.|security\.|password|csrf|login|public-commerce|sales\.(payment|invoice)|http\.|tenant\./i',
        $cat
    );
}

$bySeverity = [];
$byCategory = [];
$highlights = [];
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
    if (!is_array($row) || !isStructuredLine($row)) {
        continue;
    }
    ++$total;
    $sev = severityOf($row);
    $cat = (string) ($row['category'] ?? 'unknown');
    $bySeverity[$sev] = ($bySeverity[$sev] ?? 0) + 1;
    $byCategory[$cat] = ($byCategory[$cat] ?? 0) + 1;

    $hl = $sev === 'critical' || $sev === 'error' || $sev === 'warning';
    $hl = $hl || isHighlightCategory($cat);
    if ($hl && count($highlights) < $highlightLimit) {
        $highlights[] = [
            'ts' => (string) ($row['ts'] ?? $row['@timestamp'] ?? ''),
            'severity' => $sev,
            'category' => $cat,
            'message' => strlen((string) ($row['message'] ?? '')) > 200
                ? substr((string) $row['message'], 0, 200) . '…'
                : (string) ($row['message'] ?? ''),
            'request_id' => (string) ($row['request_id'] ?? $row['correlation_id'] ?? ''),
        ];
    }
}
fclose($fh);

arsort($bySeverity);
arsort($byCategory);

echo "FINAL-ELITE-BACKEND-MATURITY-WAVE-01 — structured log operator digest\n";
echo 'Source: ' . $file . "\n";
echo 'Structured lines (spa_structured* or category+level): ' . $total . "\n\n";

echo "By severity:\n";
foreach ($bySeverity as $k => $n) {
    echo '  ' . $n . "\t" . $k . "\n";
}
echo "\nTop categories (up to 30):\n";
$i = 0;
foreach ($byCategory as $k => $n) {
    echo '  ' . $n . "\t" . $k . "\n";
    if (++$i >= 30) {
        break;
    }
}

echo "\n--- Highlights (errors/warnings + security/commerce/auth-related categories; file order = typical append order, cap {$highlightLimit}) ---\n";
foreach ($highlights as $h) {
    echo json_encode($h, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

exit(0);
