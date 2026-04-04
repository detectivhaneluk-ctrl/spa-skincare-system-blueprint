<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

/**
 * Single source of truth for calendar appointment badge codes, labels, icon ids, and dominant priority.
 * Legend must only list entries with implemented=true (real activation path exists).
 */
final class CalendarBadgeRegistry
{
    /** @var list<string> */
    public const BOOKING_SOURCE_VALUES = [
        'phone', 'walk_in', 'website', 'facebook', 'instagram', 'twitter', 'referral', 'other',
    ];

    /** @var list<string> */
    public const STAFF_GENDER_PREFERENCE_VALUES = ['male', 'female', 'any'];

    /**
     * @return array<string, array{
     *   label: string,
     *   icon_id: string,
     *   color_token: string,
     *   priority: int,
     *   strip_order: int,
     *   source: 'computed'|'stored',
     *   implemented: bool
     * }>
     */
    public static function definitions(): array
    {
        return [
            'appt_cancelled' => [
                'label' => 'Cancelled',
                'icon_id' => 'cal-icon-cancelled',
                'color_token' => 'cal-badge-cancelled',
                'priority' => 100,
                'strip_order' => 5,
                'source' => 'computed',
                'implemented' => true,
            ],
            'appt_no_show' => [
                'label' => 'No show',
                'icon_id' => 'cal-icon-no-show',
                'color_token' => 'cal-badge-no-show',
                'priority' => 95,
                'strip_order' => 6,
                'source' => 'computed',
                'implemented' => true,
            ],
            'appt_in_progress' => [
                'label' => 'In progress',
                'icon_id' => 'cal-icon-in-progress',
                'color_token' => 'cal-badge-in-progress',
                'priority' => 88,
                'strip_order' => 8,
                'source' => 'computed',
                'implemented' => true,
            ],
            'invoice_open_balance' => [
                'label' => 'Invoice — balance due',
                'icon_id' => 'cal-icon-invoice-open',
                'color_token' => 'cal-badge-invoice-open',
                'priority' => 85,
                'strip_order' => 12,
                'source' => 'computed',
                'implemented' => true,
            ],
            'invoice_partial' => [
                'label' => 'Invoice — partial payment',
                'icon_id' => 'cal-icon-invoice-partial',
                'color_token' => 'cal-badge-invoice-partial',
                'priority' => 82,
                'strip_order' => 13,
                'source' => 'computed',
                'implemented' => true,
            ],
            'checked_in' => [
                'label' => 'Checked in',
                'icon_id' => 'cal-icon-checked-in',
                'color_token' => 'cal-badge-checked-in',
                'priority' => 78,
                'strip_order' => 10,
                'source' => 'computed',
                'implemented' => true,
            ],
            'appt_completed' => [
                'label' => 'Completed',
                'icon_id' => 'cal-icon-completed',
                'color_token' => 'cal-badge-completed',
                'priority' => 75,
                'strip_order' => 9,
                'source' => 'computed',
                'implemented' => true,
            ],
            'new_client' => [
                'label' => 'New client (first visit)',
                'icon_id' => 'cal-icon-new-client',
                'color_token' => 'cal-badge-new-client',
                'priority' => 70,
                'strip_order' => 20,
                'source' => 'computed',
                'implemented' => true,
            ],
            'same_day_booking' => [
                'label' => 'Same-day booking',
                'icon_id' => 'cal-icon-same-day',
                'color_token' => 'cal-badge-same-day',
                'priority' => 55,
                'strip_order' => 22,
                'source' => 'computed',
                'implemented' => true,
            ],
            'prebooked' => [
                'label' => 'Advance booking',
                'icon_id' => 'cal-icon-prebooked',
                'color_token' => 'cal-badge-prebooked',
                'priority' => 52,
                'strip_order' => 24,
                'source' => 'computed',
                'implemented' => true,
            ],
            'staff_locked' => [
                'label' => 'Staff assignment locked',
                'icon_id' => 'cal-icon-lock',
                'color_token' => 'cal-badge-locked',
                'priority' => 48,
                'strip_order' => 30,
                'source' => 'computed',
                'implemented' => true,
            ],
            'repeat_series' => [
                'label' => 'Repeating series',
                'icon_id' => 'cal-icon-series',
                'color_token' => 'cal-badge-series',
                'priority' => 45,
                'strip_order' => 32,
                'source' => 'computed',
                'implemented' => true,
            ],
            'booking_group' => [
                'label' => 'Group booking',
                'icon_id' => 'cal-icon-group',
                'color_token' => 'cal-badge-booking-group',
                'priority' => 42,
                'strip_order' => 34,
                'source' => 'stored',
                'implemented' => true,
            ],
            'booking_couple' => [
                'label' => 'Couple / pair',
                'icon_id' => 'cal-icon-couple',
                'color_token' => 'cal-badge-booking-couple',
                'priority' => 41,
                'strip_order' => 35,
                'source' => 'stored',
                'implemented' => true,
            ],
            'booking_src_phone' => [
                'label' => 'Source: Phone',
                'icon_id' => 'cal-icon-phone',
                'color_token' => 'cal-badge-src-phone',
                'priority' => 38,
                'strip_order' => 40,
                'source' => 'stored',
                'implemented' => true,
            ],
            'booking_src_walk_in' => [
                'label' => 'Source: Walk-in',
                'icon_id' => 'cal-icon-walk-in',
                'color_token' => 'cal-badge-src-walk-in',
                'priority' => 38,
                'strip_order' => 41,
                'source' => 'stored',
                'implemented' => true,
            ],
            'booking_src_website' => [
                'label' => 'Source: Website',
                'icon_id' => 'cal-icon-website',
                'color_token' => 'cal-badge-src-website',
                'priority' => 38,
                'strip_order' => 42,
                'source' => 'stored',
                'implemented' => true,
            ],
            'booking_src_facebook' => [
                'label' => 'Source: Facebook',
                'icon_id' => 'cal-icon-facebook',
                'color_token' => 'cal-badge-src-facebook',
                'priority' => 38,
                'strip_order' => 43,
                'source' => 'stored',
                'implemented' => true,
            ],
            'booking_src_instagram' => [
                'label' => 'Source: Instagram',
                'icon_id' => 'cal-icon-instagram',
                'color_token' => 'cal-badge-src-instagram',
                'priority' => 38,
                'strip_order' => 44,
                'source' => 'stored',
                'implemented' => true,
            ],
            'booking_src_twitter' => [
                'label' => 'Source: Twitter / X',
                'icon_id' => 'cal-icon-twitter',
                'color_token' => 'cal-badge-src-twitter',
                'priority' => 38,
                'strip_order' => 45,
                'source' => 'stored',
                'implemented' => true,
            ],
            'booking_src_referral' => [
                'label' => 'Source: Referral',
                'icon_id' => 'cal-icon-referral',
                'color_token' => 'cal-badge-src-referral',
                'priority' => 38,
                'strip_order' => 46,
                'source' => 'stored',
                'implemented' => true,
            ],
            'booking_src_other' => [
                'label' => 'Source: Other',
                'icon_id' => 'cal-icon-other',
                'color_token' => 'cal-badge-src-other',
                'priority' => 38,
                'strip_order' => 47,
                'source' => 'stored',
                'implemented' => true,
            ],
            'staff_pref_gender' => [
                'label' => 'Staff gender preference set',
                'icon_id' => 'cal-icon-pref',
                'color_token' => 'cal-badge-pref',
                'priority' => 28,
                'strip_order' => 50,
                'source' => 'stored',
                'implemented' => true,
            ],
            'has_notes' => [
                'label' => 'Has notes',
                'icon_id' => 'cal-icon-notes',
                'color_token' => 'cal-badge-notes',
                'priority' => 35,
                'strip_order' => 52,
                'source' => 'computed',
                'implemented' => true,
            ],
            'cleanup_buffer' => [
                'label' => 'Prep / turnover buffer',
                'icon_id' => 'cal-icon-buffer',
                'color_token' => 'cal-badge-buffer',
                'priority' => 30,
                'strip_order' => 54,
                'source' => 'computed',
                'implemented' => true,
            ],
            'invoice_paid' => [
                'label' => 'Invoice — paid',
                'icon_id' => 'cal-icon-invoice-paid',
                'color_token' => 'cal-badge-invoice-paid',
                'priority' => 25,
                'strip_order' => 14,
                'source' => 'computed',
                'implemented' => true,
            ],
        ];
    }

    /**
     * @return list<array{code: string, label: string, icon_id: string, color_token: string}>
     */
    public static function legendItemsImplemented(): array
    {
        $out = [];
        foreach (self::definitions() as $code => $def) {
            if (empty($def['implemented'])) {
                continue;
            }
            $out[] = [
                'code' => $code,
                'label' => $def['label'],
                'icon_id' => $def['icon_id'],
                'color_token' => $def['color_token'],
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $out;
    }

    public static function definitionFor(string $code): ?array
    {
        return self::definitions()[$code] ?? null;
    }

    /**
     * Merge user-editable calendar meta (whitelist only). Null patch values mean "leave unchanged".
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public static function mergeCalendarMetaPatch(array $current, array $patch): array
    {
        $out = $current;
        if (array_key_exists('booking_source', $patch)) {
            $v = $patch['booking_source'];
            if ($v === null) {
                // leave unchanged
            } elseif ($v === '') {
                unset($out['booking_source']);
            } elseif (is_string($v) && in_array($v, self::BOOKING_SOURCE_VALUES, true)) {
                $out['booking_source'] = $v;
            }
        }
        if (array_key_exists('group_booking', $patch) && $patch['group_booking'] !== null) {
            if ($patch['group_booking']) {
                $out['group_booking'] = true;
            } else {
                unset($out['group_booking']);
            }
        }
        if (array_key_exists('couple_booking', $patch) && $patch['couple_booking'] !== null) {
            if ($patch['couple_booking']) {
                $out['couple_booking'] = true;
            } else {
                unset($out['couple_booking']);
            }
        }
        if (array_key_exists('staff_gender_preference', $patch)) {
            $v = $patch['staff_gender_preference'];
            if ($v === null) {
                // unchanged
            } elseif ($v === '') {
                unset($out['staff_gender_preference']);
            } elseif (is_string($v) && in_array($v, self::STAFF_GENDER_PREFERENCE_VALUES, true)) {
                $out['staff_gender_preference'] = $v;
            }
        }

        return $out;
    }
}
