<?php

declare(strict_types=1);

/**
 * Internal notification settings defaults (branch_id 0 = global).
 * All event groups enabled by default so existing behaviour is unchanged.
 */

$settingsService = app(\Core\App\SettingsService::class);
$settingsService->setNotificationSettings([
    'appointments_enabled' => true,
    'sales_enabled' => true,
    'waitlist_enabled' => true,
    'memberships_enabled' => true,
], 0);
