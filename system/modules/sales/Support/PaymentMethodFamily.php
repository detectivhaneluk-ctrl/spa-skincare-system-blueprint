<?php

declare(strict_types=1);

namespace Modules\Sales\Support;

use Modules\Sales\Services\PaymentMethodService;

/**
 * Canonical operator-facing family for payment method rows (code + name heuristics).
 *
 * The database has no family column; classification is mapping-only and must stay
 * aligned with {@see PaymentSettingsMethodBuckets} for Payment Settings summaries.
 */
final class PaymentMethodFamily
{
    public const CHECK = 'check';

    public const CASH = 'cash';

    /** Redemption / system tender; excluded from manual payment form. */
    public const GIFT_CARD = 'gift_card';

    /** Card-like recorded tenders (brands / explicit card wording). */
    public const CARD_RECORDED = 'card_recorded';

    /** Other recorded non-cash (ACH, transfer, store credit, etc.). */
    public const OTHER_RECORDED = 'other_recorded';

    /**
     * Resolve family from persisted code and label. Not a gateway/processor signal.
     */
    public static function resolve(string $code, string $name): string
    {
        $codeL = strtolower(trim($code));
        $nameL = strtolower(trim($name));
        $combined = $codeL . "\n" . $nameL;

        if ($codeL === PaymentMethodService::CODE_GIFT_CARD || $codeL === 'giftcard') {
            return self::GIFT_CARD;
        }

        if (self::isCheckLike($combined)) {
            return self::CHECK;
        }

        if (self::isCashLike($codeL, $combined)) {
            return self::CASH;
        }

        if (self::isCardRecordedLike($combined)) {
            return self::CARD_RECORDED;
        }

        return self::OTHER_RECORDED;
    }

    /**
     * @param array{id?:int, code?:string, name?:string, ...} $row
     * @return array{family:string, family_label:string, family_usage_note:string, ...}
     */
    public static function annotate(array $row): array
    {
        $code = (string) ($row['code'] ?? '');
        $name = (string) ($row['name'] ?? '');
        $family = self::resolve($code, $name);

        return array_merge($row, [
            'family' => $family,
            'family_label' => self::label($family),
            'family_usage_note' => self::catalogUsageNote($family),
        ]);
    }

    public static function label(string $family): string
    {
        return match ($family) {
            self::CHECK => 'Checks',
            self::CASH => 'Cash',
            self::GIFT_CARD => 'Gift cards',
            self::CARD_RECORDED => 'Cards / recorded non-cash',
            self::OTHER_RECORDED => 'Other recorded',
            default => 'Other recorded',
        };
    }

    /**
     * Short catalog hint; not a runtime rule.
     */
    public static function catalogUsageNote(string $family): string
    {
        return match ($family) {
            self::CHECK => 'Contributes to effective Checks on Payment Settings when active.',
            self::CASH => 'Contributes to effective Cash on Payment Settings when active.',
            self::GIFT_CARD => 'Gift redemption tender; manual payment form hides this code. Public sale limits live under Payment Settings → Gift cards.',
            self::CARD_RECORDED => 'Grouped with other recorded non-cash on Payment Settings (not gateway settings).',
            self::OTHER_RECORDED => 'Grouped with recorded non-cash on Payment Settings.',
            default => 'Grouped with recorded non-cash on Payment Settings.',
        };
    }

    private static function isCheckLike(string $combined): bool
    {
        return (bool) preg_match('/check|cheque/i', $combined);
    }

    private static function isCashLike(string $codeL, string $combined): bool
    {
        if (self::isCheckLike($combined)) {
            return false;
        }
        if ($codeL === 'cash') {
            return true;
        }

        return (bool) preg_match('/\bcash\b/i', $combined);
    }

    private static function isCardRecordedLike(string $combined): bool
    {
        return (bool) preg_match(
            '/\b(visa|mastercard|maestro|amex|american\s*express|discover|diners|unionpay|jcb|interac|debit\s*card|credit\s*card|chip|contactless|tap\s*to\s*pay)\b|\bcard\s+payment\b/i',
            $combined
        );
    }
}
