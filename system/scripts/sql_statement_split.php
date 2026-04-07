<?php

declare(strict_types=1);

/**
 * Split raw SQL (line -- comments stripped) on semicolons outside single-quoted literals.
 * Naive explode(';') breaks COMMENT '...;...' and similar (FND-MIG: release-law migrate gate).
 *
 * @return list<string>
 */
function spa_split_sql_statements(string $sql): array
{
    $sql = preg_replace('~/\*.*?\*/~s', '', $sql) ?? $sql;
    $lines = preg_split('/\R/', $sql) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*(--|#)/', $line)) {
            continue;
        }
        $clean[] = $line;
    }
    $sqlNoComments = implode("\n", $clean);

    $parts = [];
    $buf = '';
    $len = strlen($sqlNoComments);
    $inString = false;
    for ($i = 0; $i < $len; $i++) {
        $c = $sqlNoComments[$i];
        if ($inString) {
            if ($c === "'") {
                if ($i + 1 < $len && $sqlNoComments[$i + 1] === "'") {
                    $buf .= "''";
                    $i++;

                    continue;
                }
                $inString = false;
            }
            $buf .= $c;

            continue;
        }
        if ($c === "'") {
            $inString = true;
            $buf .= $c;

            continue;
        }
        if ($c === ';') {
            $t = trim($buf);
            if ($t !== '') {
                $parts[] = $t;
            }
            $buf = '';

            continue;
        }
        $buf .= $c;
    }
    $t = trim($buf);
    if ($t !== '') {
        $parts[] = $t;
    }

    return array_values(array_filter($parts, static fn ($s) => $s !== ''));
}
