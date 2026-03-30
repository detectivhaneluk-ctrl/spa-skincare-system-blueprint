<?php

declare(strict_types=1);

/**
 * Phase F: Marketing settings defaults (branch_id 0 = global).
 * default_opt_in = false, consent_label = "Marketing communications".
 */

$settingsService = app(\Core\App\SettingsService::class);
$settingsService->setMarketingSettings([
    'default_opt_in' => false,
    'consent_label' => 'Marketing communications',
], 0);
