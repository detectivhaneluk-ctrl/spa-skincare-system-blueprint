<?php

declare(strict_types=1);

/**
 * PAYMENT-METHODS-CATALOG-CANONICALIZATION-FOUNDATION-03 — no DB.
 *
 * Usage:
 *   php system/scripts/verify_payment_methods_catalog_canonicalization_foundation_03.php
 */

$base = dirname(__DIR__);

require $base . '/core/app/autoload.php';

use Modules\Sales\Support\PaymentMethodFamily;
use Modules\Settings\Support\PaymentSettingsMethodBuckets;

$failed = 0;
$fail = static function (string $msg) use (&$failed): void {
    $failed++;
    fwrite(STDERR, 'FAIL  ' . $msg . "\n");
};
$pass = static function (string $msg): void {
    echo 'PASS  ' . $msg . "\n";
};

$gift = PaymentMethodFamily::resolve('gift_card', 'Gift card');
if ($gift !== PaymentMethodFamily::GIFT_CARD) {
    $fail('gift_card code must resolve to GIFT_CARD');
} elseif ($failed === 0) {
    $pass('PaymentMethodFamily resolves gift_card');
}

$cash = PaymentMethodFamily::resolve('cash', 'Cash');
if ($cash !== PaymentMethodFamily::CASH) {
    $fail('cash must resolve to CASH');
} elseif ($failed === 0) {
    $pass('PaymentMethodFamily resolves cash');
}

$chk = PaymentMethodFamily::resolve('check', 'Check');
if ($chk !== PaymentMethodFamily::CHECK) {
    $fail('check must resolve to CHECK');
} elseif ($failed === 0) {
    $pass('PaymentMethodFamily resolves check');
}

$rows = [
    ['code' => 'check', 'name' => 'Check'],
    ['code' => 'cash', 'name' => 'Cash'],
    ['code' => 'visa', 'name' => 'Visa'],
];
$b = PaymentSettingsMethodBuckets::bucket($rows);
if (count($b['checks']) !== 1 || ($b['checks'][0]['code'] ?? '') !== 'check') {
    $fail('bucket checks');
} elseif (count($b['cash']) !== 1 || ($b['cash'][0]['code'] ?? '') !== 'cash') {
    $fail('bucket cash');
} elseif (count($b['other']) !== 1 || ($b['other'][0]['code'] ?? '') !== 'visa') {
    $fail('bucket other expected visa');
} elseif ($failed === 0) {
    $pass('PaymentSettingsMethodBuckets aligns with canonical families (smoke)');
}

$path = $base . '/modules/settings/Support/PaymentSettingsMethodBuckets.php';
$src = is_file($path) ? (string) file_get_contents($path) : '';
if ($src === '' || str_contains($src, 'preg_match') || str_contains($src, 'isCheckLike')) {
    $fail('PaymentSettingsMethodBuckets must not duplicate heuristic regex; use PaymentMethodFamily');
} elseif ($failed === 0) {
    $pass('PaymentSettingsMethodBuckets delegates to PaymentMethodFamily (no duplicate heuristics)');
}

exit($failed > 0 ? 1 : 0);
