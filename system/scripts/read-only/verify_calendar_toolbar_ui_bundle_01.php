<?php

declare(strict_types=1);

/**
 * Read-only: asserts calendar toolbar UI routes + migration file exist.
 */
$root = dirname(__DIR__, 3);
$fail = false;

$routes = @file_get_contents($root . '/routes/web/register_appointments_calendar.php') ?: '';
$mig = @file_get_contents($root . '/data/migrations/134_calendar_user_ui_foundation.sql') ?: '';
$ctl = @file_get_contents($root . '/modules/appointments/controllers/AppointmentController.php') ?: '';

$assert = static function (string $label, bool $ok) use (&$fail): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        $fail = true;
    }
};

$assert('migration 134 exists', str_contains($mig, 'calendar_user_preferences'));
$assert('migration saved views', str_contains($mig, 'calendar_saved_views'));
$assert('GET /calendar/ui-preferences', str_contains($routes, "'/calendar/ui-preferences'") && str_contains($routes, 'calendarUiPreferencesGet'));
$assert('POST /calendar/ui-preferences', str_contains($routes, 'calendarUiPreferencesSave'));
$assert('GET /calendar/saved-views/{id', str_contains($routes, 'calendarSavedViewDetail'));
$assert('POST /calendar/saved-views', str_contains($routes, 'calendarSavedViewsCreate'));
$assert('print day calendar route', str_contains($routes, '/appointments/calendar/day/print/calendar') && str_contains($routes, 'printDayCalendarPage'));
$assert('print itineraries route', str_contains($routes, 'printDayClientItinerariesPage'));
$assert('controller has resolveCalendarUiActor', str_contains($ctl, 'resolveCalendarUiActor'));

exit($fail ? 1 : 0);
