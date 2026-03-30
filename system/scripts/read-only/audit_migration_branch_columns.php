<?php

/**
 * Read-only audit helper: scan migration SQL for CREATE TABLE blocks and flag presence of branch_id.
 * Usage: php system/scripts/read-only/audit_migration_branch_columns.php
 * Output: TSV to stdout (table_name, migration_file, has_branch_id_in_create_block).
 *
 * Heuristic: text between CREATE TABLE ... ( and closing ) ENGINE — may miss edge cases; for human triage only.
 */

declare(strict_types=1);

$migrationsDir = dirname(__DIR__, 2) . '/data/migrations';
if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations dir not found: {$migrationsDir}\n");
    exit(1);
}

$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files);

$rows = [];
foreach ($files as $path) {
    $sql = file_get_contents($path);
    if ($sql === false) {
        continue;
    }
    $base = basename($path);
    if (!preg_match_all('/CREATE\s+TABLE\s+(?:`?)(\w+)(?:`?)\s*\(/is', $sql, $matches, PREG_OFFSET_CAPTURE)) {
        continue;
    }
    foreach ($matches[0] as $idx => $full) {
        $table = $matches[1][$idx][0];
        $startPos = $matches[0][$idx][1] + strlen($full);
        $rest = substr($sql, $startPos);
        if (!preg_match('/\)\s*ENGINE/is', $rest, $endM, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        $block = substr($rest, 0, $endM[0][1]);
        $hasBranch = str_contains(strtolower($block), 'branch_id');
        $rows[] = [$table, $base, $hasBranch ? '1' : '0'];
    }
}

usort($rows, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

echo "table\tmigration\thas_branch_id_in_create_block\n";
foreach ($rows as $r) {
    echo implode("\t", $r) . "\n";
}
