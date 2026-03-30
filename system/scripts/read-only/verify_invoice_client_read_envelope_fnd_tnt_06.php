<?php

declare(strict_types=1);

/**
 * FND-TNT-06 — Static proof: staff invoice/cashier read paths load linked clients via
 * {@see \Modules\Clients\Repositories\ClientRepository::findLiveReadableForProfile()} (branch envelope),
 * not org-scoped {@see find()} alone (same-org wrong-branch PII on invoice show).
 *
 * Run: php system/scripts/read-only/verify_invoice_client_read_envelope_fnd_tnt_06.php
 */

$base = dirname(__DIR__, 2);
$inv = (string) file_get_contents($base . '/modules/sales/controllers/InvoiceController.php');

$ok = true;
$fail = static function (string $m) use (&$ok): void {
    fwrite(STDERR, "FAIL: {$m}\n");
    $ok = false;
};

if ($inv === '') {
    $fail('InvoiceController.php unreadable');
    exit(1);
}

if (str_contains($inv, 'clientRepository->find(')) {
    $fail('InvoiceController must not use clientRepository->find(); use findLiveReadableForProfile for invoice/cashier client reads');
}

$c = substr_count($inv, 'findLiveReadableForProfile');
if ($c < 3) {
    $fail('expected at least three findLiveReadableForProfile calls (cashier prefill, invoice show, membership checkout), got ' . (string) $c);
}

if (!str_contains($inv, 'public function show(') || !preg_match('/public function show\([\s\S]*?findLiveReadableForProfile/s', $inv)) {
    $fail('invoice show must load client via findLiveReadableForProfile');
}

if (!$ok) {
    exit(1);
}

echo "PASS: verify_invoice_client_read_envelope_fnd_tnt_06\n";
exit(0);
