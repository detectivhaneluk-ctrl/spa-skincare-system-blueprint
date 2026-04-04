<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

/**
 * Merges computed (SQL-backed) and stored (appointment_calendar_meta) badge codes.
 * Only emits codes that exist in {@see CalendarBadgeRegistry} with implemented=true.
 */
final class CalendarAppointmentBadgeResolver
{
    private const MONEY_EPS = 0.005;

    /**
     * @param array<string, mixed> $row Day-calendar appointment row (plus optional display_flags from controller)
     * @return array{
     *   calendar_badges: list<array{code: string, icon_id: string, color_token: string, label: string}>,
     *   calendar_dominant_badge: array{code: string, label: string, color_token: string}|null
     * }
     */
    public function resolve(array $row): array
    {
        $defs = CalendarBadgeRegistry::definitions();
        $candidates = [];

        $status = strtolower(trim((string) ($row['status'] ?? 'scheduled')));
        if ($status === 'cancelled') {
            $this->addCandidate($candidates, 'appt_cancelled', $defs);
        } elseif ($status === 'no_show') {
            $this->addCandidate($candidates, 'appt_no_show', $defs);
        } elseif ($status === 'in_progress') {
            $this->addCandidate($candidates, 'appt_in_progress', $defs);
        } elseif ($status === 'completed') {
            $this->addCandidate($candidates, 'appt_completed', $defs);
        }

        $checkedRaw = $row['checked_in_at'] ?? null;
        if ($checkedRaw !== null && trim((string) $checkedRaw) !== '') {
            $this->addCandidate($candidates, 'checked_in', $defs);
        }

        if (!empty($row['has_notes_flag']) || !empty($row['has_notes'])) {
            $this->addCandidate($candidates, 'has_notes', $defs);
        }

        if (!empty($row['staff_assignment_locked'])) {
            $this->addCandidate($candidates, 'staff_locked', $defs);
        }

        $seriesRaw = $row['series_id'] ?? null;
        if ($seriesRaw !== null && $seriesRaw !== '' && (int) $seriesRaw > 0) {
            $this->addCandidate($candidates, 'repeat_series', $defs);
        }

        $bb = (int) ($row['buffer_before_effective'] ?? 0);
        $ba = (int) ($row['buffer_after_effective'] ?? 0);
        if ($bb > 0 || $ba > 0) {
            $this->addCandidate($candidates, 'cleanup_buffer', $defs);
        }

        $prior = isset($row['client_prior_appts_before']) ? (int) $row['client_prior_appts_before'] : -1;
        $clientId = isset($row['client_id']) ? (int) $row['client_id'] : 0;
        if ($clientId > 0 && $prior === 0) {
            $this->addCandidate($candidates, 'new_client', $defs);
        }

        if (!empty($row['same_day_booking'])) {
            $this->addCandidate($candidates, 'same_day_booking', $defs);
        }

        $flags = $row['display_flags'] ?? null;
        if (is_array($flags) && !empty($flags['prebooked'])) {
            $this->addCandidate($candidates, 'prebooked', $defs);
        }

        if (!in_array($status, ['cancelled', 'no_show'], true)) {
            $this->addInvoiceCandidates($candidates, $row, $defs);
        }
        $this->addStoredMetaCandidates($candidates, $row, $defs);

        if ($candidates === []) {
            return [
                'calendar_badges' => [],
                'calendar_dominant_badge' => null,
            ];
        }

        uasort($candidates, static fn (array $a, array $b): int => $a['strip_order'] <=> $b['strip_order']);
        $orderedCodes = array_keys($candidates);

        $badgeRows = [];
        foreach ($orderedCodes as $code) {
            if (!isset($defs[$code])) {
                continue;
            }
            $d = $defs[$code];
            $badgeRows[] = [
                'code' => $code,
                'icon_id' => (string) $d['icon_id'],
                'color_token' => (string) $d['color_token'],
                'label' => (string) $d['label'],
            ];
        }

        $dominantCode = null;
        $dominantPri = PHP_INT_MIN;
        foreach ($candidates as $code => $meta) {
            if ($meta['priority'] >= $dominantPri) {
                $dominantPri = $meta['priority'];
                $dominantCode = $code;
            }
        }

        $dominant = null;
        if ($dominantCode !== null && isset($defs[$dominantCode])) {
            $dominant = [
                'code' => $dominantCode,
                'label' => $defs[$dominantCode]['label'],
                'color_token' => $defs[$dominantCode]['color_token'],
            ];
        }

        return [
            'calendar_badges' => $badgeRows,
            'calendar_dominant_badge' => $dominant,
        ];
    }

    /**
     * @param array<string, array{strip_order: int, priority: int}> $candidates
     * @param array<string, array<string, mixed>> $defs
     */
    private function addCandidate(array &$candidates, string $code, array $defs): void
    {
        if (!isset($defs[$code]) || empty($defs[$code]['implemented'])) {
            return;
        }
        $candidates[$code] = [
            'strip_order' => (int) $defs[$code]['strip_order'],
            'priority' => (int) $defs[$code]['priority'],
        ];
    }

    /**
     * @param array<string, array{strip_order: int, priority: int}> $candidates
     * @param array<string, array<string, mixed>> $defs
     */
    private function addInvoiceCandidates(array &$candidates, array $row, array $defs): void
    {
        $inv = $row['linked_invoice'] ?? null;
        if (!is_array($inv) || empty($inv['id'])) {
            return;
        }
        $st = strtolower(trim((string) ($inv['status'] ?? '')));
        if (in_array($st, ['cancelled', 'refunded'], true)) {
            return;
        }
        $total = (float) ($inv['total_amount'] ?? 0);
        $paid = (float) ($inv['paid_amount'] ?? 0);
        $fullyPaid = $st === 'paid' || ($total > 0 && $paid + self::MONEY_EPS >= $total);
        $partial = $st === 'partial' || ($paid > self::MONEY_EPS && $paid + self::MONEY_EPS < $total);
        $openLike = in_array($st, ['draft', 'open'], true);

        if ($fullyPaid && !$partial) {
            $this->addCandidate($candidates, 'invoice_paid', $defs);

            return;
        }
        if ($partial) {
            $this->addCandidate($candidates, 'invoice_partial', $defs);

            return;
        }
        if ($openLike && $paid + self::MONEY_EPS < $total) {
            $this->addCandidate($candidates, 'invoice_open_balance', $defs);
        }
    }

    /**
     * @param array<string, array{strip_order: int, priority: int}> $candidates
     * @param array<string, array<string, mixed>> $defs
     */
    private function addStoredMetaCandidates(array &$candidates, array $row, array $defs): void
    {
        $meta = $row['appointment_calendar_meta'] ?? null;
        if (!is_array($meta)) {
            return;
        }
        if (!empty($meta['group_booking'])) {
            $this->addCandidate($candidates, 'booking_group', $defs);
        }
        if (!empty($meta['couple_booking'])) {
            $this->addCandidate($candidates, 'booking_couple', $defs);
        }
        $src = isset($meta['booking_source']) ? trim((string) $meta['booking_source']) : '';
        if ($src !== '') {
            $code = 'booking_src_' . $src;
            if (isset($defs[$code]) && !empty($defs[$code]['implemented'])) {
                $this->addCandidate($candidates, $code, $defs);
            }
        }
        $pref = isset($meta['staff_gender_preference']) ? trim((string) $meta['staff_gender_preference']) : '';
        if ($pref === 'male' || $pref === 'female') {
            $this->addCandidate($candidates, 'staff_pref_gender', $defs);
        }
    }
}
