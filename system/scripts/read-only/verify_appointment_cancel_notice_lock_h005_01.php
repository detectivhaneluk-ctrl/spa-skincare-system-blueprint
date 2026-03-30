<?php

declare(strict_types=1);

/**
 * H-005-CANCELLATION-NOTICE-TOCTOU-FIX-01: static proof that {@see \Modules\Appointments\Services\AppointmentService::cancel}
 * acquires a row lock including `start_at` before computing the min-notice window. No database.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_appointment_cancel_notice_lock_h005_01.php
 */

$base = dirname(__DIR__, 2);
$path = $base . '/modules/appointments/services/AppointmentService.php';
if (!is_file($path)) {
    fwrite(STDERR, "FAIL: missing {$path}\n");
    exit(1);
}

$src = (string) file_get_contents($path);
$start = strpos($src, 'public function cancel(');
if ($start === false) {
    fwrite(STDERR, "FAIL: cancel() not found\n");
    exit(1);
}
$end = strpos($src, "\n    public function reschedule(", $start);
$chunk = $end !== false ? substr($src, $start, $end - $start) : '';

$lockSql = 'SELECT * FROM appointments WHERE id = ? AND deleted_at IS NULL FOR UPDATE';
$posLock = strpos($chunk, $lockSql);
$posStartAtLocked = strpos($chunk, '$startAt = $locked[\'start_at\']');
$posNotice = strpos($chunk, '$insideNoticeWindow = true');

$checks = [
    'cancel() body extracted' => $chunk !== '',
    'lock uses SELECT * including full row (FOR UPDATE)' => $posLock !== false,
    'notice window reads start_at from $locked, not pre-lock $current' => $posStartAtLocked !== false
        && !str_contains($chunk, '$startAt = $current[\'start_at\']'),
    'FOR UPDATE appears before min-notice assignment from locked row' => $posLock !== false && $posStartAtLocked !== false && $posLock < $posStartAtLocked,
    'no second pre-update lock fetch omitting start_at' => !str_contains($chunk, 'SELECT id, status, notes, branch_id FROM appointments WHERE id = ? AND deleted_at IS NULL FOR UPDATE'),
];

if ($posNotice !== false && $posStartAtLocked !== false) {
    $checks['insideNoticeWindow flip occurs after locked start_at read'] = $posStartAtLocked < $posNotice;
} else {
    $checks['insideNoticeWindow flip occurs after locked start_at read'] = false;
}

$failed = false;
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'MISSING') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
