<?php

declare(strict_types=1);

/**
 * STRUCTURED-ERROR-LOGGING-FOUNDATION-01 — read-only inventory of remaining `error_log(` call sites.
 *
 * Prefer {@see \Core\App\StructuredLogger} / {@see slog()} for security- and ops-relevant events.
 *
 * From repository root:
 *   php system/scripts/read-only/report_structured_logging_hotspots_readonly_01.php
 *
 * Exit: always 0 (report only).
 */

$systemRoot = realpath(dirname(__DIR__, 2));
if ($systemRoot === false) {
    fwrite(STDERR, "Could not resolve system/ root.\n");
    exit(0);
}

$counts = [];
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
    $content = (string) file_get_contents($path);
    $n = preg_match_all('/\berror_log\s*\(/', $content) ?: 0;
    if ($n > 0) {
        $rel = str_replace('\\', '/', substr($path, strlen($systemRoot)));
        $counts[$rel] = $n;
    }
}

arsort($counts);

echo "STRUCTURED-ERROR-LOGGING-FOUNDATION-01 — remaining error_log( hotspots under system/ (excluding scripts/)\n";
echo 'Total files: ' . count($counts) . ', total calls: ' . array_sum($counts) . "\n\n";

$i = 0;
foreach ($counts as $rel => $n) {
    echo str_pad((string) $n, 4, ' ', STR_PAD_LEFT) . '  ' . $rel . "\n";
    if (++$i >= 60) {
        $rest = count($counts) - $i;
        if ($rest > 0) {
            echo "... ({$rest} more files)\n";
        }
        break;
    }
}

exit(0);
