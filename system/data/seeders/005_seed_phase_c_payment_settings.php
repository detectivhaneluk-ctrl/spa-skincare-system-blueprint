<?php

declare(strict_types=1);

/**
 * Phase C: Payment settings defaults (branch_id 0 = global).
 * Defaults: default_method_code=cash, allow_partial_payments=1, allow_overpayments=0, receipt_notes=''
 */

$settings = \Core\App\Application::container()->get(\Core\App\SettingsService::class);

$settings->setPaymentSettings([
    'default_method_code' => 'cash',
    'allow_partial_payments' => true,
    'allow_overpayments' => false,
    'receipt_notes' => '',
], 0);
