<?php

declare(strict_types=1);

/**
 * Phase D: Waitlist settings defaults (branch_id 0 = global).
 * Defaults: waitlist.enabled=1, waitlist.auto_offer_enabled=0, waitlist.max_active_per_client=3, waitlist.default_expiry_minutes=30.
 */

$settingsService = app(\Core\App\SettingsService::class);
$settingsService->setWaitlistSettings([
    'enabled' => true,
    'auto_offer_enabled' => false,
    'max_active_per_client' => 3,
    'default_expiry_minutes' => 30,
], 0);
