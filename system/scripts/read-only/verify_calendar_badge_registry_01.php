<?php

declare(strict_types=1);

/**
 * Read-only: calendar badge registry + day-cache prefix wiring (no DB).
 * For DB column readiness (white-screen fix): {@see verify_calendar_day_db_schema_133_01.php}
 */
$root = dirname(__DIR__, 3);
$system = $root . '/system';

$ok = true;
$checks = [];

$avail = @file_get_contents($system . '/modules/appointments/services/AvailabilityService.php') ?: '';
$checks[] = [
    'AvailabilityService cal_v4 day cache prefix',
    str_contains($avail, "'cal_v4:day_apts'"),
];
$checks[] = [
    'AvailabilityService selects appointment_calendar_meta',
    str_contains($avail, 'a.appointment_calendar_meta'),
];
$checks[] = [
    'AvailabilityService linked_invoice_snapshot JSON',
    str_contains($avail, 'linked_invoice_snapshot'),
];

$ctl = @file_get_contents($system . '/modules/appointments/controllers/AppointmentController.php') ?: '';
$checks[] = [
    'AppointmentController uses CalendarAppointmentBadgeResolver',
    str_contains($ctl, 'CalendarAppointmentBadgeResolver'),
];
$checks[] = [
    'applyDayCalendarAppointmentDisplay sets calendar_badges',
    str_contains($ctl, "'calendar_badges'"),
];

$reg = $system . '/modules/appointments/services/CalendarBadgeRegistry.php';
$checks[] = ['CalendarBadgeRegistry.php exists', is_file($reg)];
$res = $system . '/modules/appointments/services/CalendarAppointmentBadgeResolver.php';
$checks[] = ['CalendarAppointmentBadgeResolver.php exists', is_file($res)];
$mig = $system . '/data/migrations/133_appointments_calendar_meta.sql';
$checks[] = ['Migration 133_appointments_calendar_meta.sql exists', is_file($mig)];

foreach ($checks as [$label, $pass]) {
    if (!$pass) {
        $ok = false;
        fwrite(STDERR, "FAIL  {$label}\n");
    } else {
        echo "OK    {$label}\n";
    }
}

exit($ok ? 0 : 1);
