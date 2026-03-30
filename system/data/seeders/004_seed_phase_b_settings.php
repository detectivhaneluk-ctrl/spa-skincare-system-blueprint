<?php

declare(strict_types=1);

/**
 * Phase B settings: cancellation policy, appointment booking window, online booking.
 * branch_id 0 = global defaults.
 */

if (!isset($db) || !$db instanceof \Core\App\Database) {
    require dirname(__DIR__, 2) . '/bootstrap.php';
    $db = app(\Core\App\Database::class);
}

$settings = \Core\App\Application::container()->get(\Core\App\SettingsService::class);

$settings->setCancellationSettings([
    'enabled' => true,
    'min_notice_hours' => 0,
    'reason_required' => false,
    'allow_privileged_override' => true,
], 0);
$settings->setAppointmentSettings([
    'min_lead_minutes' => 0,
    'max_days_ahead' => 180,
    'allow_past_booking' => false,
], 0);
$settings->setOnlineBookingSettings([
    'enabled' => false,
    'public_api_enabled' => true,
    'min_lead_minutes' => 120,
    'max_days_ahead' => 60,
    'allow_new_clients' => true,
], 0);

echo "Phase B settings seeded.\n";
