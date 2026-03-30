<?php

declare(strict_types=1);

namespace Modules\Clients\Support;

/**
 * Single place for public-flow email/phone normalization used in anonymous resolution.
 */
final class PublicContactNormalizer
{
    /**
     * @throws \InvalidArgumentException when empty or not a syntactically valid email
     */
    public static function normalizeEmail(string $email): string
    {
        $t = trim($email);
        if ($t === '') {
            throw new \InvalidArgumentException('Email is required.');
        }
        if (filter_var($t, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Email is invalid.');
        }

        return strtolower($t);
    }

    /**
     * Digits-only key for deterministic phone matching against stored free-form phone strings
     * (see {@see self::sqlExprNormalizedPhoneDigits()} for SQL-side matching where stored digit columns are not used).
     *
     * @return non-empty-string|null null when absent or not usable for strict matching
     */
    public static function normalizePhoneDigitsForMatch(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $raw = trim($phone);
        if ($raw === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null || $digits === '') {
            return null;
        }
        if (strlen($digits) < 7 || strlen($digits) > 20) {
            return null;
        }

        return $digits;
    }

    /**
     * SQL expression: strip common separators from a phone column for digit-only comparison.
     * Must stay aligned with {@see normalizePhoneDigitsForMatch()} (PHP) and {@see ClientRepository::phoneDigitsNormalizedSqlExpr()}.
     *
     * @param non-empty-string $qualifiedColumn e.g. {@code c.phone} or {@code phone}
     */
    public static function sqlExprNormalizedPhoneDigits(string $qualifiedColumn): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE({$qualifiedColumn}, '')), ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";
    }
}
