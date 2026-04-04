<?php

declare(strict_types=1);

/**
 * Read-only (filesystem): calendar day time-zoom min/max must match across PHP service, JS, and HTML slider.
 *
 * Usage (from repo root):
 *   php system/scripts/read-only/verify_calendar_tools_zoom_contract_readonly_01.php
 *
 * Exit 0: contract strings found.
 * Exit 1: mismatch or missing markers.
 */

$systemRoot = dirname(__DIR__, 2);
$fail = false;

$assert = static function (string $label, bool $ok) use (&$fail): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        $fail = true;
    }
};

$svc = @file_get_contents($systemRoot . '/modules/appointments/services/CalendarToolbarUiService.php') ?: '';
$js = @file_get_contents($systemRoot . '/public/assets/js/app-calendar-day.js') ?: '';
$view = @file_get_contents($systemRoot . '/modules/appointments/views/calendar-day.php') ?: '';
$ctl = @file_get_contents($systemRoot . '/modules/appointments/controllers/AppointmentController.php') ?: '';

$assert('CalendarToolbarUiService MIN_TIME_ZOOM_PERCENT = 25', (bool) preg_match('/public const MIN_TIME_ZOOM_PERCENT = 25;/', $svc));
$assert('CalendarToolbarUiService has no legacy min 8 for time zoom', !str_contains($svc, 'MIN_TIME_ZOOM_PERCENT = 8'));
$assert('app-calendar-day.js MIN_TIME_ZOOM_PERCENT = 25', (bool) preg_match('/const MIN_TIME_ZOOM_PERCENT = 25;/', $js));
$assert('app-calendar-day.js no AUTO_FIT_MIN_TIME_ZOOM_PERCENT symbol', !str_contains($js, 'AUTO_FIT_MIN_TIME_ZOOM_PERCENT'));
$assert('calendar-day.php time zoom range min 25', str_contains($view, 'id="cal-toolbar-zoom-slider"') && str_contains($view, 'min="25"') && str_contains($view, 'max="200"'));
$assert('AppointmentController bundles calendar_ui_storage', str_contains($ctl, "'calendar_ui_storage'") && str_contains($ctl, 'fetchUiPreferencesBundle'));
$assert('PERSISTENCE_UNAVAILABLE in controller', str_contains($ctl, 'PERSISTENCE_UNAVAILABLE'));

exit($fail ? 1 : 0);
