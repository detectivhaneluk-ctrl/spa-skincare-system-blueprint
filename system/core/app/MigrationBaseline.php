<?php

declare(strict_types=1);

namespace Core\App;

use PDO;

/**
 * Disk ↔ {@code migrations} table baseline truth (read-only DDL alignment, not semantic schema diff).
 * Used by {@see scripts/read-only/verify_migration_baseline_readonly.php}, optional HTTP enforcement,
 * and post-{@see scripts/migrate.php} sanity checks.
 */
final class MigrationBaseline
{
    /**
     * @return array{
     *   migrations_dir: string,
     *   files_on_disk: int,
     *   rows_in_migrations_table: int,
     *   pending: list<string>,
     *   pending_count: int,
     *   orphan_stamps: list<string>,
     *   orphan_stamp_count: int,
     *   migrations_table_missing: bool,
     *   latest_file: string|null,
     *   latest_applied: string|null,
     *   baseline_aligned: bool,
     *   strict_would_fail: bool,
     *   issues: list<string>
     * }
     */
    public static function collect(string $systemPath, PDO $pdo): array
    {
        $migrationsPath = $systemPath . '/data/migrations';
        $files = glob($migrationsPath . '/*.sql') ?: [];
        sort($files);
        $fileBasenames = array_map(static fn (string $p): string => basename($p), $files);

        $applied = [];
        $tableMissing = false;
        try {
            $stmt = $pdo->query('SELECT migration FROM migrations ORDER BY id');
            if ($stmt !== false) {
                $applied = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            }
        } catch (\Throwable) {
            $tableMissing = true;
            $applied = [];
        }

        $appliedMap = array_fill_keys(array_map('strval', $applied), true);
        $pending = array_values(array_filter($fileBasenames, static fn (string $f): bool => !isset($appliedMap[$f])));
        $orphanStamps = array_values(array_filter($applied, static function (string $m) use ($fileBasenames): bool {
            return !in_array($m, $fileBasenames, true);
        }));

        $issues = [];
        if ($tableMissing) {
            $issues[] = 'migrations_table_missing';
        }
        if ($pending !== []) {
            $issues[] = 'pending_migrations:' . (string) count($pending);
        }
        if ($orphanStamps !== []) {
            $issues[] = 'orphan_stamps:' . (string) count($orphanStamps);
        }

        $aligned = !$tableMissing && $pending === [] && $orphanStamps === [];

        return [
            'migrations_dir' => $migrationsPath,
            'files_on_disk' => count($fileBasenames),
            'rows_in_migrations_table' => count($applied),
            'pending' => $pending,
            'pending_count' => count($pending),
            'orphan_stamps' => $orphanStamps,
            'orphan_stamp_count' => count($orphanStamps),
            'migrations_table_missing' => $tableMissing,
            'latest_file' => $fileBasenames === [] ? null : $fileBasenames[array_key_last($fileBasenames)],
            'latest_applied' => $applied === [] ? null : $applied[array_key_last($applied)],
            'baseline_aligned' => $aligned,
            'strict_would_fail' => !$aligned,
            'issues' => $issues,
        ];
    }

    /**
     * @param array{issues: list<string>, pending: list<string>, orphan_stamps: list<string>, migrations_table_missing: bool} $report
     */
    public static function strictSummaryLine(array $report): string
    {
        if ($report['baseline_aligned']) {
            return '';
        }
        $parts = [];
        if ($report['migrations_table_missing']) {
            $parts[] = 'migrations_table_missing';
        }
        if ($report['pending'] !== []) {
            $parts[] = 'pending_migrations=' . count($report['pending']);
        }
        if ($report['orphan_stamps'] !== []) {
            $parts[] = 'orphan_stamps=' . count($report['orphan_stamps']);
        }

        return implode('; ', $parts);
    }

    /**
     * When baseline is broken: emit HTTP 503 plain text and exit (web SAPI only; caller should guard).
     */
    public static function respond503IfNotAligned(string $systemPath, PDO $pdo): void
    {
        $report = self::collect($systemPath, $pdo);
        if ($report['baseline_aligned']) {
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=UTF-8', true, 503);
        }
        $summary = self::strictSummaryLine($report);
        echo "Service unavailable: migration baseline is not aligned ({$summary}).\n"
            . "Run: php system/scripts/read-only/verify_migration_baseline_readonly.php --json\n"
            . "Then apply pending migrations or fix orphan stamps before serving traffic.\n";
        exit;
    }
}
