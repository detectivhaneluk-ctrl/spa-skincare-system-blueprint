<?php

declare(strict_types=1);

/**
 * Non-strict migration stamping: after tolerated legacy DDL conflicts, prove the migration file's
 * intended tables/columns/indexes/FKs exist before trusting {@code migrations} row insertion.
 *
 * Used only from {@see scripts/migrate.php} incremental non-strict mode.
 */

/**
 * @param list<string> $statements Parsed SQL statements (no trailing semicolons in each element).
 */
function migration_nonstrict_end_state_proof_passes(array $statements, PDO $pdo): bool
{
    $req = extract_schema_requirements_from_migration_statements($statements);
    if (
        $req['tables'] === []
        && $req['columns'] === []
        && $req['indexes'] === []
        && $req['foreign_keys'] === []
    ) {
        return false;
    }

    return prove_schema_requirements($pdo, $req);
}

/**
 * @param list<string> $statements
 * @return array{
 *   tables: list<string>,
 *   columns: list<array{0: string, 1: string}>,
 *   indexes: list<array{0: string, 1: string}>,
 *   foreign_keys: list<array{0: string, 1: string}>
 * }
 */
function extract_schema_requirements_from_migration_statements(array $statements): array
{
    $tables = [];
    $columns = [];
    $indexes = [];
    $foreignKeys = [];

    foreach ($statements as $stmt) {
        $s = trim($stmt);
        if ($s === '') {
            continue;
        }
        $upper = strtoupper($s);
        if (str_contains($upper, 'CREATE TABLE')) {
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([a-zA-Z0-9_]+)[`"]?\s*\(/is', $s, $cm)) {
                $tables[] = strtolower($cm[1]);
            }
        }

        if (str_contains($upper, 'ALTER TABLE')) {
            if (!preg_match('/ALTER\s+TABLE\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $s, $tm)) {
                continue;
            }
            $table = strtolower($tm[1]);

            if (preg_match_all('/\bADD\s+COLUMN\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $s, $colm)) {
                foreach ($colm[1] as $c) {
                    $columns[] = [$table, strtolower((string) $c)];
                }
            }
            if (preg_match_all('/\bADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $s, $im)) {
                foreach ($im[1] as $ix) {
                    $indexes[] = [$table, strtolower((string) $ix)];
                }
            }
            if (preg_match_all('/\bADD\s+CONSTRAINT\s+[`"]?([a-zA-Z0-9_]+)[`"]?\s+FOREIGN\s+KEY/is', $s, $fm)) {
                foreach ($fm[1] as $fk) {
                    $foreignKeys[] = [$table, strtolower((string) $fk)];
                }
            }
        }
    }

    return [
        'tables' => array_values(array_unique($tables)),
        'columns' => $columns,
        'indexes' => $indexes,
        'foreign_keys' => $foreignKeys,
    ];
}

/**
 * @param array{
 *   tables: list<string>,
 *   columns: list<array{0: string, 1: string}>,
 *   indexes: list<array{0: string, 1: string}>,
 *   foreign_keys: list<array{0: string, 1: string}>
 * } $req
 */
function prove_schema_requirements(PDO $pdo, array $req): bool
{
    foreach ($req['tables'] as $t) {
        $q = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?)
             LIMIT 1'
        );
        $q->execute([$t]);
        if (!$q->fetchColumn()) {
            return false;
        }
    }

    foreach ($req['columns'] as [$table, $column]) {
        $q = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND LOWER(TABLE_NAME) = LOWER(?)
               AND LOWER(COLUMN_NAME) = LOWER(?)
             LIMIT 1'
        );
        $q->execute([$table, $column]);
        if (!$q->fetchColumn()) {
            return false;
        }
    }

    foreach ($req['indexes'] as [$table, $indexName]) {
        $q = $pdo->prepare(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND LOWER(TABLE_NAME) = LOWER(?)
               AND LOWER(INDEX_NAME) = LOWER(?)
             LIMIT 1'
        );
        $q->execute([$table, $indexName]);
        if (!$q->fetchColumn()) {
            return false;
        }
    }

    foreach ($req['foreign_keys'] as [$table, $constraint]) {
        $q = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND LOWER(TABLE_NAME) = LOWER(?)
               AND LOWER(CONSTRAINT_NAME) = LOWER(?)
               AND CONSTRAINT_TYPE = \'FOREIGN KEY\'
             LIMIT 1'
        );
        $q->execute([$table, $constraint]);
        if (!$q->fetchColumn()) {
            return false;
        }
    }

    return true;
}
