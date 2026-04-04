<?php

declare(strict_types=1);

/**
 * APPOINTMENT-CONFLICT-BUFFER-SARGABILITY-HARDENING-01 — static contract for buffered staff overlap SQL.
 *
 * Semantics (unchanged vs legacy AvailabilityService query):
 * - Same staff_id, deleted_at IS NULL, BLOCKING_STATUSES set, optional exclude id.
 * - For each existing row, blocking interval is [start_at - buffer_before, end_at + buffer_after] with buffers from
 *   appointment overrides when set, else LEFT JOIN services (COALESCE to 0 when both missing).
 * - Overlap with candidate [windowStartAt, windowEndAt] iff blocked_start < windowEnd AND blocked_end > windowStart
 *   (strict inequalities, same as before).
 *
 * Index-friendly shape: compare bare appointments.start_at / appointments.end_at to expressions that only wrap the
 * bound window endpoints plus joined buffer columns (no DATE_SUB/DATE_ADD on a.start_at or a.end_at).
 *
 * Limitation: COALESCE(s.buffer_*_minutes, 0) still varies per row, so the RHS of each range predicate is not a single
 * scalar constant across the join; indexes on (staff_id, deleted_at, start_at) / end_at still help filter early.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_appointment_conflict_buffer_sargability_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

$system = dirname(__DIR__, 2);
$path = $system . '/modules/appointments/services/AvailabilityService.php';
$src = is_file($path) ? (string) file_get_contents($path) : '';

$checks = [];

$checks['AvailabilityService: hasBufferedAppointmentConflict does not wrap a.start_at in DATE_SUB'] =
    !preg_match('/DATE_SUB\s*\(\s*a\.start_at/si', $src);

$checks['AvailabilityService: hasBufferedAppointmentConflict does not wrap a.end_at in DATE_ADD'] =
    !preg_match('/DATE_ADD\s*\(\s*a\.end_at/si', $src);

$checks['AvailabilityService: uses bare a.start_at < DATE_ADD on window end parameter'] =
    (bool) preg_match('/a\.start_at\s*<\s*DATE_ADD\s*\(\s*\?/s', $src);

$checks['AvailabilityService: uses bare a.end_at > DATE_SUB on window start parameter'] =
    (bool) preg_match('/a\.end_at\s*>\s*DATE_SUB\s*\(\s*\?/s', $src);

$checks['AvailabilityService: preserves buffer minutes from overrides or joined services'] =
    str_contains($src, 'a.buffer_before_override_minutes')
    && str_contains($src, 'a.buffer_after_override_minutes')
    && str_contains($src, 's.buffer_before_minutes')
    && str_contains($src, 's.buffer_after_minutes');

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'Appointment buffered conflict sargability static checks passed.' . PHP_EOL;
exit(0);
