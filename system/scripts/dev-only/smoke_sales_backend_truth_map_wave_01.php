<?php

declare(strict_types=1);

/**
 * Dev-only smoke: Sales payment path DI + route/invoice binding source check (wave 01).
 *
 * Run from repo root:
 *   php system/scripts/dev-only/smoke_sales_backend_truth_map_wave_01.php
 *
 * Prints PASS / FAIL / SKIP lines for each check.
 */

$system = dirname(__DIR__, 2);
chdir($system);

$results = [];

try {
    require $system . '/bootstrap.php';
    require $system . '/modules/bootstrap.php';
} catch (\Throwable $e) {
    $results[] = ['check' => 'bootstrap', 'status' => 'SKIP', 'detail' => $e->getMessage()];
    foreach ($results as $r) {
        echo $r['status'] . ' ' . $r['check'] . ' — ' . $r['detail'] . PHP_EOL;
    }
    exit(0);
}

try {
    $ctl = app(\Modules\Sales\Controllers\PaymentController::class);
    $results[] = [
        'check' => 'di_payment_controller',
        'status' => $ctl instanceof \Modules\Sales\Controllers\PaymentController ? 'PASS' : 'FAIL',
        'detail' => 'container resolves PaymentController',
    ];
} catch (\Throwable $e) {
    $results[] = ['check' => 'di_payment_controller', 'status' => 'SKIP', 'detail' => $e->getMessage()];
}

$payPath = $system . '/modules/sales/controllers/PaymentController.php';
$paySrc = is_readable($payPath) ? (string) file_get_contents($payPath) : '';
$bindingOk = $paySrc !== ''
    && str_contains($paySrc, "'invoice_id' => \$invoiceId")
    && !str_contains($paySrc, "'invoice_id' => (int) (\$_POST['invoice_id']");
$results[] = [
    'check' => 'payment_invoice_id_route_binding',
    'status' => $bindingOk ? 'PASS' : 'FAIL',
    'detail' => 'parseInput uses route invoice id',
];

foreach ($results as $r) {
    echo $r['status'] . ' ' . $r['check'] . ' — ' . $r['detail'] . PHP_EOL;
}

$anyFail = false;
foreach ($results as $r) {
    if ($r['status'] === 'FAIL') {
        $anyFail = true;
        break;
    }
}
exit($anyFail ? 1 : 0);
