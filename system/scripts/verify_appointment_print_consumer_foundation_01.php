<?php

declare(strict_types=1);

/**
 * APPOINTMENT-PRINT-CONSUMER-FOUNDATION-01 — static proof (no DB): route, controller, view, auth stack, honest sections.
 * Companion: verify_appointment_print_settings_supported_sections_implementation_01.php + verify_appointment_print_product_purchase_history_foundation_01.php.
 *
 * From system/:
 *   php scripts/verify_appointment_print_consumer_foundation_01.php
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function vPrintPass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function vPrintFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

$routeFile = $root . '/routes/web/register_appointments_calendar.php';
$routeSrc = is_file($routeFile) ? (string) file_get_contents($routeFile) : '';
if ($routeSrc === '') {
    vPrintFail('route_file', 'missing');
    exit(1);
}

if (!str_contains($routeSrc, "/appointments/{id:\\d+}/print")) {
    vPrintFail('route_path', 'Expected GET /appointments/{id}/print registration');
} else {
    vPrintPass('route_path_registered');
}

if (!str_contains($routeSrc, "'printSummaryPage'") && !str_contains($routeSrc, 'printSummaryPage')) {
    vPrintFail('route_action', 'Expected printSummaryPage action');
} else {
    vPrintPass('route_action_printSummaryPage');
}

if (!str_contains($routeSrc, 'AuthMiddleware::class')) {
    vPrintFail('route_auth', 'Expected AuthMiddleware');
} else {
    vPrintPass('route_has_auth_middleware');
}

if (!str_contains($routeSrc, 'TenantProtectedRouteMiddleware::class')) {
    vPrintFail('route_tenant', 'Expected TenantProtectedRouteMiddleware');
} else {
    vPrintPass('route_has_tenant_middleware');
}

if (!str_contains($routeSrc, "PermissionMiddleware::for('appointments.view')")) {
    vPrintFail('route_permission', 'Expected appointments.view');
} else {
    vPrintPass('route_appointments_view_permission');
}

$ctlFile = $root . '/modules/appointments/controllers/AppointmentController.php';
$ctlSrc = is_file($ctlFile) ? (string) file_get_contents($ctlFile) : '';
if (!str_contains($ctlSrc, 'function printSummaryPage')) {
    vPrintFail('controller_method', 'printSummaryPage missing');
} else {
    vPrintPass('controller_printSummaryPage_exists');
}

if (!str_contains($ctlSrc, 'ensureBranchAccess')) {
    vPrintFail('controller_branch', 'ensureBranchAccess reference missing');
} else {
    vPrintPass('controller_uses_branch_access');
}

if (!str_contains($ctlSrc, 'AppointmentPrintSummaryService')) {
    vPrintFail('controller_service', 'AppointmentPrintSummaryService not referenced');
} else {
    vPrintPass('controller_wires_print_summary_service');
}

$viewFile = $root . '/modules/appointments/views/print.php';
if (!is_file($viewFile)) {
    vPrintFail('view_file', 'print.php missing');
} else {
    vPrintPass('view_print_php_exists');
}

$viewSrc = (string) file_get_contents($viewFile);
if (!str_contains($viewSrc, 'Client product purchase history') || !str_contains($viewSrc, 'listRecentProductInvoiceLines')) {
    vPrintFail('view_product_section', 'Expected honest product purchase section wired to listRecentProductInvoiceLines');
} else {
    vPrintPass('view_has_product_purchase_section');
}

$svcFile = $root . '/modules/appointments/services/AppointmentPrintSummaryService.php';
if (!is_file($svcFile)) {
    vPrintFail('service_file', 'AppointmentPrintSummaryService missing');
} else {
    vPrintPass('service_file_exists');
}

$svcSrc = (string) file_get_contents($svcFile);
if (!str_contains($svcSrc, 'listRecent')) {
    vPrintFail('service_history', 'Expected listRecent for client history');
} else {
    vPrintPass('service_uses_listRecent');
}
if (!str_contains($svcSrc, 'listRecentProductInvoiceLines') || !str_contains($svcSrc, 'ClientSalesProfileProvider')) {
    vPrintFail('service_product_lines', 'Expected ClientSalesProfileProvider::listRecentProductInvoiceLines');
} else {
    vPrintPass('service_uses_listRecentProductInvoiceLines');
}

if (!str_contains($svcSrc, 'listAppointmentConsumptions')) {
    vPrintFail('service_packages', 'Expected listAppointmentConsumptions');
} else {
    vPrintPass('service_uses_listAppointmentConsumptions');
}

$cssFile = $root . '/public/assets/css/appointment-print.css';
if (!is_file($cssFile)) {
    vPrintFail('css_file', 'appointment-print.css missing');
} else {
    vPrintPass('css_file_exists');
}

$cssSrc = (string) file_get_contents($cssFile);
if (!str_contains($cssSrc, '@media print') || !str_contains($cssSrc, '.no-print')) {
    vPrintFail('css_print_rules', 'Expected @media print and .no-print');
} else {
    vPrintPass('css_print_media_and_no_print');
}

$bootFile = $root . '/modules/bootstrap/register_appointments_online_contracts.php';
$bootSrc = is_file($bootFile) ? (string) file_get_contents($bootFile) : '';
if (!str_contains($bootSrc, 'AppointmentPrintSummaryService::class')) {
    vPrintFail('di_print_service', 'Bootstrap must register AppointmentPrintSummaryService');
} else {
    vPrintPass('di_registers_print_summary_service');
}

echo "\nDone. Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
