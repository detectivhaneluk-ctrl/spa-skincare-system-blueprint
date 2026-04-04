<?php

declare(strict_types=1);

/**
 * Calendar operational smoke — static/read-only (no DB, no subprocess).
 * Covers day JSON route, AvailabilityService day query + legacy fallback, JS fetch safety, context menu wiring.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_calendar_operational_readonly_bundle_01.php
 *
 * With DB (schema + seed): additionally run:
 *   php system/scripts/read-only/verify_calendar_day_db_schema_133_01.php
 *   php system/scripts/dev-only/verify_appointments_calendar_smoke_01.php   (from system/)
 */

$system = dirname(__DIR__, 2);
$fail = false;

function calAssert(string $label, bool $ok, bool &$fail): void
{
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $fail = true;
    }
}

echo "=== Calendar operational read-only bundle ===\n\n";

$routes = (string) file_get_contents($system . '/routes/web/register_appointments_calendar.php');
calAssert('GET /calendar/day route', str_contains($routes, "'/calendar/day'") && str_contains($routes, 'dayCalendar'), $fail);
calAssert('GET /calendar/week-summary route', str_contains($routes, "'/calendar/week-summary'"), $fail);
calAssert('GET /calendar/month-summary route', str_contains($routes, "'/calendar/month-summary'"), $fail);
calAssert('GET /calendar/side-panel route', str_contains($routes, "'/calendar/side-panel'"), $fail);
calAssert('GET /appointments/calendar/day page route', str_contains($routes, "'/appointments/calendar/day'") && str_contains($routes, 'dayCalendarPage'), $fail);

$avail = (string) file_get_contents($system . '/modules/appointments/services/AvailabilityService.php');
calAssert('AvailabilityService cal_v4 cache prefix', str_contains($avail, "'cal_v4:day_apts'"), $fail);
calAssert('listDayAppointmentsGroupedByStaff selects appointment_calendar_meta', str_contains($avail, 'a.appointment_calendar_meta'), $fail);
calAssert('Legacy SQL fallback (missing meta column)', str_contains($avail, 'isMissingAppointmentCalendarMetaColumn') && str_contains($avail, "str_replace(', a.appointment_calendar_meta', '', \$sql)"), $fail);
calAssert('linked_invoice_snapshot JSON_OBJECT in day query', str_contains($avail, 'linked_invoice_snapshot'), $fail);

$ctl = (string) file_get_contents($system . '/modules/appointments/controllers/AppointmentController.php');
calAssert('dayCalendar() merges contract + appointments_by_staff', str_contains($ctl, 'function dayCalendar') && str_contains($ctl, 'appointments_by_staff'), $fail);
calAssert('applyDayCalendarAppointmentDisplay sets calendar_badges', str_contains($ctl, "'calendar_badges'"), $fail);

$js = (string) file_get_contents($system . '/public/assets/js/app-calendar-day.js');
calAssert('JS fetch /calendar/day', str_contains($js, "fetch('/calendar/day?'") || str_contains($js, 'fetch("/calendar/day?'), $fail);
calAssert('JS load() uses res.text + JSON.parse (not raw res.json)', str_contains($js, 'await res.text()') && str_contains($js, 'JSON.parse(rawText)'), $fail);
calAssert('JS loadSidePanelData uses text + JSON.parse', str_contains($js, '/calendar/side-panel?') && str_contains($js, 'const raw = await res.text()') && str_contains($js, 'JSON.parse(raw)'), $fail);
calAssert('JS handleApptContextAction present', str_contains($js, 'function handleApptContextAction'), $fail);

$calView = (string) file_get_contents($system . '/modules/appointments/views/calendar-day.php');
calAssert('calendar-day.php includes badge sprite', str_contains($calView, 'calendar-badge-sprite.php'), $fail);
calAssert('calendar-day.php legend uses CalendarBadgeRegistry', str_contains($calView, 'CalendarBadgeRegistry::legendItemsImplemented'), $fail);

$drawerEdit = (string) file_get_contents($system . '/modules/appointments/views/drawer/edit.php');
calAssert('drawer edit includes calendar meta partial', str_contains($drawerEdit, 'appointment-calendar-meta-fields.php'), $fail);

$reg = (string) file_get_contents($system . '/modules/appointments/services/CalendarBadgeRegistry.php');
$resolver = (string) file_get_contents($system . '/modules/appointments/services/CalendarAppointmentBadgeResolver.php');
calAssert('CalendarBadgeRegistry definitions()', str_contains($reg, 'function definitions'), $fail);
calAssert('CalendarAppointmentBadgeResolver resolve()', str_contains($resolver, 'function resolve'), $fail);

$w6 = (string) file_get_contents($system . '/scripts/read-only/verify_wave06_hot_path_cache_effectiveness_01.php');
calAssert('W6 proof references cal_v4', str_contains($w6, 'cal_v4:day_apts'), $fail);

echo "\n";
exit($fail ? 1 : 0);
