<?php

declare(strict_types=1);

/**
 * Hardware settings defaults (branch_id 0 = global).
 * use_cash_register = true preserves current behaviour (require open register for cash).
 * use_receipt_printer = false by default; when true, sales calls ReceiptPrintDispatchProvider after committed payments (default impl is no-op).
 */

$settingsService = app(\Core\App\SettingsService::class);
$settingsService->setHardwareSettings([
    'use_cash_register' => true,
    'use_receipt_printer' => false,
], 0);
