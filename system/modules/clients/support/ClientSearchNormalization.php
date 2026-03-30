<?php

declare(strict_types=1);

namespace Modules\Clients\Support;

/**
 * Stored searchable columns for clients: must match {@see PublicContactNormalizer} rules and migration backfill SQL.
 */
final class ClientSearchNormalization
{
    /**
     * Lowercase trimmed email for {@code clients.email_lc}; null when empty / whitespace-only.
     */
    public static function emailLcForStorage(mixed $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $t = trim((string) $email);
        if ($t === '') {
            return null;
        }

        return strtolower($t);
    }

    /**
     * Digits-only key for stored columns; null unless 7–20 digits (same as {@see PublicContactNormalizer::normalizePhoneDigitsForMatch()}).
     */
    public static function phoneDigitsForStorage(?string $phone): ?string
    {
        return PublicContactNormalizer::normalizePhoneDigitsForMatch($phone);
    }
}
