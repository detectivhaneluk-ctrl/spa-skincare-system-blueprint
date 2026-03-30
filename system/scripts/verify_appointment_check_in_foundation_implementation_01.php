<?php

declare(strict_types=1);

/**
 * APPOINTMENT-CHECK-IN-FOUNDATION-IMPLEMENTATION-01 — static proof (no DB).
 *
 * From system/:
 *   php scripts/verify_appointment_check_in_foundation_implementation_01.php
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function vCiPass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function vCiFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

$mig = (string) file_get_contents($root . '/data/migrations/095_appointments_check_in_foundation.sql');
if (!str_contains($mig, 'checked_in_at') || !str_contains($mig, 'checked_in_by')) {
    vCiFail('migration', '095_appointments_check_in_foundation.sql must add checked_in_at and checked_in_by');
} else {
    vCiPass('migration_columns');
}

$repo = (string) file_get_contents($root . '/modules/appointments/repositories/AppointmentRepository.php');
if (!str_contains($repo, 'function markCheckedIn')) {
    vCiFail('repository', 'AppointmentRepository::markCheckedIn missing');
} else {
    vCiPass('repository_markCheckedIn');
}

$svc = (string) file_get_contents($root . '/modules/appointments/services/AppointmentService.php');
if (!str_contains($svc, 'function markCheckedIn')) {
    vCiFail('service', 'AppointmentService::markCheckedIn missing');
} else {
    vCiPass('service_markCheckedIn');
}
if (!str_contains($svc, 'appointment_checked_in')) {
    vCiFail('audit', 'Expected audit key appointment_checked_in');
} else {
    vCiPass('audit_event');
}

$routes = (string) file_get_contents($root . '/routes/web/register_appointments_calendar.php');
if (!str_contains($routes, '/check-in') || !str_contains($routes, 'checkInAction')) {
    vCiFail('route', 'POST /appointments/{id}/check-in → checkInAction');
} else {
    vCiPass('route_registered');
}

$ctl = (string) file_get_contents($root . '/modules/appointments/controllers/AppointmentController.php');
if (!str_contains($ctl, 'function checkInAction')) {
    vCiFail('controller', 'AppointmentController::checkInAction missing');
} else {
    vCiPass('controller_action');
}
if (!str_contains($ctl, 'can_mark_checked_in')) {
    vCiFail('controller_flag', 'can_mark_checked_in for show view');
} else {
    vCiPass('controller_show_flags');
}

$view = (string) file_get_contents($root . '/modules/appointments/views/show.php');
if (!str_contains($view, '/check-in') || !str_contains($view, 'Checked in')) {
    vCiFail('view', 'show.php must expose check-in form path and Checked in row');
} else {
    vCiPass('view_show');
}

echo "\nDone. Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
