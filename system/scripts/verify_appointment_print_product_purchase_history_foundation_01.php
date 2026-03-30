<?php

declare(strict_types=1);

/**
 * APPOINTMENT-PRINT-PRODUCT-PURCHASE-HISTORY-FOUNDATION-01 — static proof (no DB).
 *
 * From system/:
 *   php scripts/verify_appointment_print_product_purchase_history_foundation_01.php
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function vPphPass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function vPphFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

$key = 'appointments.print_show_client_product_purchase_history';

$contract = (string) file_get_contents($root . '/core/contracts/ClientSalesProfileProvider.php');
if (!str_contains($contract, 'listRecentProductInvoiceLines')) {
    vPphFail('contract_method', 'ClientSalesProfileProvider::listRecentProductInvoiceLines missing');
} else {
    vPphPass('contract_listRecentProductInvoiceLines');
}

$impl = (string) file_get_contents($root . '/modules/sales/providers/ClientSalesProfileProviderImpl.php');
if (!str_contains($impl, 'function listRecentProductInvoiceLines')) {
    vPphFail('impl_method', 'Implementation missing');
} else {
    vPphPass('impl_listRecentProductInvoiceLines');
}
if (!str_contains($impl, "\\'product\\'") && !str_contains($impl, "item_type = 'product'")) {
    vPphFail('impl_product_filter', 'Expected item_type = product filter');
} else {
    vPphPass('impl_item_type_product');
}
if (!str_contains($impl, 'FROM invoice_items') || !str_contains($impl, 'INNER JOIN invoices')) {
    vPphFail('impl_joins', 'Expected invoice_items + invoices join');
} else {
    vPphPass('impl_line_level_joins');
}
if (!preg_match('/function\s+listRecentProductInvoiceLines\b[\s\S]*?FROM\s+invoice_items/m', $impl)) {
    vPphFail('impl_line_query', 'listRecentProductInvoiceLines must query invoice_items');
} else {
    vPphPass('impl_queries_invoice_items_not_headers_only');
}

$ss = (string) file_get_contents($root . '/core/app/SettingsService.php');
if (!str_contains($ss, "'" . $key . "'")) {
    vPphFail('settings_key', 'SettingsService missing canonical key');
} else {
    vPphPass('settings_key_present');
}
if (!str_contains($ss, "getBool('" . $key . "', false,")) {
    vPphFail('settings_default', 'Expected getBool(..., false,) for opt-in default');
} else {
    vPphPass('settings_getBool_false_default');
}
if (!str_contains($ss, 'print_show_client_product_purchase_history') || !str_contains($ss, 'patchAppointmentSettings')) {
    vPphFail('settings_patch', 'patchAppointmentSettings must handle new key');
} else {
    vPphPass('settings_patch_block');
}

$svc = (string) file_get_contents($root . '/modules/appointments/services/AppointmentPrintSummaryService.php');
if (!str_contains($svc, 'ClientSalesProfileProvider') || !str_contains($svc, 'listRecentProductInvoiceLines')) {
    vPphFail('print_compose', 'Print service must inject sales profile and call listRecentProductInvoiceLines');
} else {
    vPphPass('print_compose_wires_provider');
}
if (!str_contains($svc, 'print_show_client_product_purchase_history')) {
    vPphFail('print_setting_read', 'compose must read print_show_client_product_purchase_history');
} else {
    vPphPass('print_setting_read');
}
if (!str_contains($svc, 'client_product_purchase_history') || !str_contains($svc, 'product_purchase_lines')) {
    vPphFail('print_payload', 'section_visibility + product_purchase_lines');
} else {
    vPphPass('print_payload_keys');
}

$view = (string) file_get_contents($root . '/modules/appointments/views/print.php');
if (!str_contains($view, 'Client product purchase history') || !str_contains($view, 'product_purchase_lines')) {
    vPphFail('print_view', 'Dedicated section + product_purchase_lines');
} else {
    vPphPass('print_view_section');
}

$boot = (string) file_get_contents($root . '/modules/bootstrap/register_appointments_online_contracts.php');
if (!str_contains($boot, 'ClientSalesProfileProvider::class') || !str_contains($boot, 'AppointmentPrintSummaryService::class')) {
    vPphFail('di', 'Bootstrap must pass ClientSalesProfileProvider into AppointmentPrintSummaryService');
} else {
    // Ensure sales provider is in the same factory line as print summary (same constructor call)
    // Factory uses nested $c->get(Foo::class) — [^)]* stops at the first inner ')', so match to same statement instead.
    if (!preg_match(
        '/new\s+\\\\Modules\\\\Appointments\\\\Services\\\\AppointmentPrintSummaryService\s*\([^;]*ClientSalesProfileProvider::class/s',
        $boot
    )) {
        vPphFail('di_wiring', 'AppointmentPrintSummaryService factory must pass ClientSalesProfileProvider');
    } else {
        vPphPass('di_registers_sales_for_print');
    }
}

$sc = (string) file_get_contents($root . '/modules/settings/controllers/SettingsController.php');
if (!str_contains($sc, "'" . $key . "'")) {
    vPphFail('controller_allowlist', 'SettingsController must allowlist key');
} else {
    vPphPass('controller_allowlist');
}

$idx = (string) file_get_contents($root . '/modules/settings/views/index.php');
if (!str_contains($idx, 'appointments.print_show_client_product_purchase_history')) {
    vPphFail('settings_ui', 'Checkbox name for product purchase print');
} else {
    vPphPass('settings_ui_row');
}

echo "\nDone. Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
