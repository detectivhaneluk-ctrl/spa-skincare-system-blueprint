<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

/**
 * Canonical cashier / invoice line classification for staff checkout.
 *
 * Persisted on {@see invoice_items.item_type}. Legacy rows may still use historical spellings;
 * new writes must use these constants only.
 *
 * @see CashierLineItemParser
 * @see CashierLineItemValidator
 */
final class CashierInvoiceLineType
{
    public const PRODUCT = 'product';
    public const SERVICE = 'service';
    public const MANUAL = 'manual';
    public const GIFT_VOUCHER = 'gift_voucher';
    public const GIFT_CARD = 'gift_card';
    public const SERIES = 'series';
    public const CLIENT_ACCOUNT = 'client_account';
    public const MEMBERSHIP = 'membership';
    public const TIP = 'tip';

    /** @var list<string> */
    public const ALL = [
        self::PRODUCT,
        self::SERVICE,
        self::MANUAL,
        self::GIFT_VOUCHER,
        self::GIFT_CARD,
        self::SERIES,
        self::CLIENT_ACCOUNT,
        self::MEMBERSHIP,
        self::TIP,
    ];

    /**
     * Contract summary (documentation + runtime checks). Keys:
     * - requires_client: invoice.client_id must be set
     * - requires_employee: items[][employee_user_id] or line_meta.employee_user_id recommended (tips)
     * - quantity_allowed: false => forced to 1 in parser
     * - domain_effect: none | invoice_line_only | gift_card_issue | package_assign | membership_sale
     *
     * @return array<string, array{requires_client: bool, requires_employee: bool, quantity_allowed: bool, domain_effect: string}>
     */
    public static function contractByType(): array
    {
        return [
            self::PRODUCT => [
                'requires_client' => false,
                'requires_employee' => false,
                'quantity_allowed' => true,
                'domain_effect' => 'invoice_line_only',
            ],
            self::SERVICE => [
                'requires_client' => false,
                'requires_employee' => false,
                'quantity_allowed' => true,
                'domain_effect' => 'invoice_line_only',
            ],
            self::MANUAL => [
                'requires_client' => false,
                'requires_employee' => false,
                'quantity_allowed' => true,
                'domain_effect' => 'invoice_line_only',
            ],
            self::GIFT_VOUCHER => [
                'requires_client' => false,
                'requires_employee' => false,
                'quantity_allowed' => true,
                'domain_effect' => 'invoice_line_only',
            ],
            self::GIFT_CARD => [
                'requires_client' => false,
                'requires_employee' => false,
                'quantity_allowed' => true,
                'domain_effect' => 'gift_card_issue',
            ],
            self::SERIES => [
                'requires_client' => true,
                'requires_employee' => false,
                'quantity_allowed' => true,
                'domain_effect' => 'package_assign',
            ],
            self::CLIENT_ACCOUNT => [
                'requires_client' => true,
                'requires_employee' => false,
                'quantity_allowed' => true,
                'domain_effect' => 'invoice_line_only',
            ],
            self::MEMBERSHIP => [
                'requires_client' => true,
                'requires_employee' => false,
                'quantity_allowed' => false,
                'domain_effect' => 'membership_sale',
            ],
            self::TIP => [
                'requires_client' => false,
                'requires_employee' => false,
                'quantity_allowed' => false,
                'domain_effect' => 'invoice_line_only',
            ],
        ];
    }

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
