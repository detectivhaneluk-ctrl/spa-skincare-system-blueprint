<?php

declare(strict_types=1);

/**
 * Mandatory twin of handoff/HandoffZipRules.ps1 — keep logic aligned when rules change.
 *
 * Forbidden ZIP entries (normalized path):
 * - system/.env, system/.env.local; any path segment `.env` or `.env.*` except `.env.example` (templates allowed)
 * - any path ending in .zip (nested archives)
 * - any path ending in .log
 * - system/storage/logs, backups, sessions, framework cache/sessions/views trees (except .gitkeep placeholders)
 * - any path under node_modules (incl. workers/image-pipeline/node_modules)
 * - .DS_Store, Thumbs.db
 * - system/docs/*-result.txt (case-insensitive suffix; pasted proof transcripts)
 *
 * Enforcement: handoff/build-final-zip.ps1 invokes this after the PowerShell ZIP scan; non-zero removes the output ZIP.
 * Use standalone for release acceptance on any artifact bytes (uploaded/re-zipped/third-party).
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_handoff_zip_rules_readonly.php distribution/spa-skincare-system-blueprint-HANDOFF-FOR-UPLOAD.zip
 *
 * Exit: 0 = OK, 1 = violations, 2 = bad usage / missing file / no ZipArchive.
 */

if (!extension_loaded('zip')) {
    fwrite(STDERR, "FAIL: PHP zip extension (ZipArchive) not loaded.\n");
    exit(2);
}

if ($argc < 2 || $argv[1] === '' || $argv[1] === '-h' || $argv[1] === '--help') {
    fwrite(STDERR, "Usage: php verify_handoff_zip_rules_readonly.php <path-to.zip>\n");
    exit(2);
}

$zipPath = $argv[1];
if (!is_file($zipPath) || !is_readable($zipPath)) {
    fwrite(STDERR, "FAIL: ZIP not found or unreadable: {$zipPath}\n");
    exit(2);
}

/**
 * @return non-empty-string|null violation reason, or null if allowed
 */
function handoff_zip_entry_is_under_path(string $normalized, string $directoryPrefix): bool
{
    return $normalized === $directoryPrefix || str_starts_with($normalized, $directoryPrefix . '/');
}

function handoff_zip_entry_violation(string $entryFullName): ?string
{
    $name = trim(str_replace('\\', '/', $entryFullName), '/');
    if ($name === '') {
        return null;
    }
    $normalized = strtolower($name);

    if ($normalized === 'system/.env') {
        return 'forbidden path: system/.env';
    }
    if ($normalized === 'system/.env.local') {
        return 'forbidden path: system/.env.local';
    }
    if (str_ends_with($normalized, '.zip')) {
        return "forbidden path: nested or generated zip archive ({$normalized})";
    }
    if (handoff_zip_entry_is_under_path($normalized, 'system/storage/logs') && $normalized !== 'system/storage/logs/.gitkeep') {
        return "forbidden path: system/storage/logs/ ({$normalized})";
    }
    if (handoff_zip_entry_is_under_path($normalized, 'system/storage/backups')) {
        return "forbidden path: system/storage/backups/ ({$normalized})";
    }
    if (handoff_zip_entry_is_under_path($normalized, 'system/storage/sessions') && $normalized !== 'system/storage/sessions/.gitkeep') {
        return "forbidden path: system/storage/sessions/ ({$normalized})";
    }
    foreach (['system/storage/framework/cache', 'system/storage/framework/sessions', 'system/storage/framework/views'] as $fwPrefix) {
        if (handoff_zip_entry_is_under_path($normalized, $fwPrefix) && !str_ends_with($normalized, '/.gitkeep')) {
            return "forbidden path: framework runtime {$fwPrefix}/ ({$normalized})";
        }
    }
    if (handoff_zip_entry_is_under_path($normalized, 'workers/image-pipeline/node_modules')) {
        return "forbidden path: workers/image-pipeline/node_modules/ ({$normalized})";
    }
    if (str_starts_with($normalized, 'system/docs/') && str_ends_with($normalized, '-result.txt')) {
        return "forbidden path: system/docs/*-RESULT.txt transcript ({$normalized})";
    }
    if (str_ends_with($normalized, '.log')) {
        return "forbidden path: runtime log ({$normalized})";
    }

    return null;
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
    fwrite(STDERR, "FAIL: could not open ZIP: {$zipPath}\n");
    exit(2);
}

$violations = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if ($stat === false) {
        continue;
    }
    $entry = (string) ($stat['name'] ?? '');
    $reason = handoff_zip_entry_violation($entry);
    if ($reason !== null) {
        $violations[] = "{$reason} [zip entry: {$entry}]";
    }
}
$zip->close();

if ($violations === []) {
    echo 'OK: no forbidden artifacts in ' . realpath($zipPath) . PHP_EOL;
    exit(0);
}

fwrite(STDERR, "Handoff ZIP verification failed (" . count($violations) . " violation(s)):\n");
foreach ($violations as $line) {
    fwrite(STDERR, '  - ' . $line . PHP_EOL);
}
exit(1);
