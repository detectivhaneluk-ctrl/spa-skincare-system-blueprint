<?php

declare(strict_types=1);

/**
 * Phase G: Security settings defaults (branch_id 0 = global).
 * password_expiration: never | 90_days
 * inactivity_timeout_minutes: 15 | 30 | 120
 */

$settingsService = app(\Core\App\SettingsService::class);
$settingsService->setSecuritySettings([
    'password_expiration' => 'never',
    'inactivity_timeout_minutes' => 30,
], 0);
