<?php

declare(strict_types=1);

namespace Core\App;

use InvalidArgumentException;

/**
 * MySQL identifier guard (SQL-IDENTIFIER-SAFETY-GUARD-01).
 *
 * Use for **identifiers** (table/column names), never for values — values stay in prepared placeholders.
 * Segments are restricted to lowercase `[a-z][a-z0-9_]{0,63}` (unquoted-safe subset + typical repo naming).
 * Output is **backtick-quoted** so reserved words (`key`, `order`, …) remain valid.
 */
final class SqlIdentifier
{
    private const SEGMENT = '/^[a-z][a-z0-9_]{0,63}$/D';

    public static function assertSegment(string $name): string
    {
        if (preg_match(self::SEGMENT, $name) !== 1) {
            throw new InvalidArgumentException('Invalid SQL identifier segment.');
        }

        return $name;
    }

    public static function quoteTable(string $table): string
    {
        return '`' . self::assertSegment($table) . '`';
    }

    public static function quoteColumn(string $column): string
    {
        return '`' . self::assertSegment($column) . '`';
    }

    /**
     * @param list<string> $columns
     * @return list<string> quoted segments
     */
    public static function quoteColumns(array $columns): array
    {
        $out = [];
        foreach ($columns as $c) {
            if (!is_string($c)) {
                throw new InvalidArgumentException('Column names must be strings.');
            }
            $out[] = self::quoteColumn($c);
        }

        return $out;
    }
}
