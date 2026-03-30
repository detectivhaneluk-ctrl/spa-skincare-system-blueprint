<?php

declare(strict_types=1);

namespace Modules\Marketing\Support;

final class MarketingContactEligibilityPolicy
{
    /**
     * Conservative canonical SQL contract for email eligibility in audience queries.
     */
    public static function sqlEmailEligible(string $alias = 'c'): string
    {
        return "{$alias}.marketing_opt_in = 1
            AND TRIM(COALESCE({$alias}.email, '')) <> ''
            AND INSTR(TRIM(COALESCE({$alias}.email, '')), '@') > 1";
    }

    /**
     * Conservative canonical SQL contract for SMS eligibility in audience queries.
     */
    public static function sqlSmsEligible(string $alias = 'c'): string
    {
        return "{$alias}.marketing_opt_in = 1
            AND TRIM(COALESCE({$alias}.phone, '')) <> ''";
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function emailEligible(array $row): bool
    {
        $marketingOptIn = (int) ($row['marketing_opt_in'] ?? 0) === 1;
        $email = trim((string) ($row['email'] ?? ''));
        return $marketingOptIn && $email !== '' && strpos($email, '@') !== false && strpos($email, '@') > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function smsEligible(array $row): bool
    {
        $marketingOptIn = (int) ($row['marketing_opt_in'] ?? 0) === 1;
        $phone = trim((string) ($row['mobile_phone'] ?? ($row['phone'] ?? '')));
        return $marketingOptIn && $phone !== '';
    }
}

