<?php

declare(strict_types=1);

/**
 * Static proof: calendar appointment context menu → routes + controller + JS handlers.
 * Run from system/: php scripts/read-only/verify_calendar_context_menu_backend_routes_01.php
 */

$system = dirname(__DIR__, 2);
$routes = (string) file_get_contents($system . '/routes/web/register_appointments_calendar.php');
$ctl = (string) file_get_contents($system . '/modules/appointments/controllers/AppointmentController.php');
$sales = (string) file_get_contents($system . '/routes/web/register_sales_public_commerce_staff.php');
$svc = (string) file_get_contents($system . '/modules/appointments/services/AppointmentService.php');
$drawerEdit = (string) file_get_contents($system . '/modules/appointments/views/drawer/edit.php');
$js = (string) file_get_contents($system . '/public/assets/js/app-calendar-day.js');

$checks = [
    'POST /appointments/{id}/status (confirm, complete, in_progress, no_show, unconfirm)' =>
        str_contains($routes, '/status') && str_contains($routes, 'updateStatusAction'),
    'POST /appointments/{id}/check-in' =>
        str_contains($routes, '/check-in') && str_contains($routes, 'checkInAction'),
    'POST /appointments/{id}/cancel' =>
        str_contains($routes, '/cancel') && str_contains($routes, 'cancelAction'),
    'POST /appointments/{id}/buffer-cleanup (cleanup scopes)' =>
        str_contains($routes, 'buffer-cleanup') && str_contains($routes, 'bufferCleanupAction')
        && str_contains($ctl, 'function bufferCleanupAction'),
    'POST /appointments/{id}/staff-lock' =>
        str_contains($routes, 'staff-lock') && str_contains($routes, 'staffLockAction')
        && str_contains($ctl, 'function staffLockAction'),
    'POST /appointments/{id}/send-confirmation' =>
        str_contains($routes, 'send-confirmation') && str_contains($routes, 'sendConfirmationAction'),
    'POST /appointments/{id}/delete' =>
        str_contains($routes, '/delete') && str_contains($routes, 'destroy'),
    'GET /appointments/{id}/print' =>
        str_contains($routes, '/print') && str_contains($routes, 'printSummaryPage'),
    'GET /appointments/{id}/print-itinerary' =>
        str_contains($routes, 'print-itinerary') && str_contains($routes, 'printItineraryPage'),
    'GET /appointments/{id}/edit (drawer + notes anchor)' =>
        str_contains($routes, '/edit') && str_contains($routes, 'edit'),
    'GET /appointments/{id} → show (View Appointment drawer)' =>
        str_contains($routes, "\$router->get('/appointments/{id:\\d+}',")
        && str_contains($routes, 'AppointmentController::class')
        && str_contains($routes, "'show'"),
    'GET /sales/invoices/create (checkout/deposit prefill)' =>
        str_contains($sales, '/sales/invoices/create') && str_contains($sales, 'InvoiceController'),
    'GET /sales/invoices/{id} → show (view/print invoice)' =>
        str_contains($sales, "\$router->get('/sales/invoices/{id}',")
        && str_contains($sales, 'InvoiceController::class')
        && str_contains($sales, "'show'"),
    'GET /sales/invoices/{id}/payments/create' =>
        str_contains($sales, '/payments/create'),
    'AppointmentController uses buffer + lock service methods' =>
        str_contains($ctl, 'applyBufferCleanupScope') && str_contains($ctl, 'setStaffAssignmentLocked'),
    'AppointmentService defines applyBufferCleanupScope + setStaffAssignmentLocked' =>
        str_contains($svc, 'function applyBufferCleanupScope') && str_contains($svc, 'function setStaffAssignmentLocked'),
    'drawer/edit.php has textarea id=appt-notes (Edit Customer Notes)' =>
        str_contains($drawerEdit, 'id="appt-notes"'),
];

echo "--- Routes & PHP wiring ---\n";
$fail = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $fail = true;
    }
}

// handleApptContextAction: every menu action that can be clicked must be handled (clipboard = dedicated branch).
echo "\n--- JS handleApptContextAction branches (menu actions) ---\n";
$handlerPatterns = [
    'clipboard' => "action === 'clipboard'",
    'view' => "action === 'view'",
    'edit' => "action === 'edit'",
    'print_appointment' => "(action === 'print' || action === 'print_appointment')",
    'print_itinerary' => "action === 'print_itinerary'",
    'checkout_new_sale' => "checkout_new_sale' || action === 'take_deposit_sale",
    'take_deposit_sale' => "checkout_new_sale' || action === 'take_deposit_sale",
    'take_payment_invoice' => "action === 'take_payment_invoice'",
    'add_companion_booking' => "action === 'add_companion_booking'",
    'view_invoice' => "action === 'view_invoice'",
    'print_invoice' => "action === 'print_invoice'",
    'checkin' => "action === 'checkin'",
    'confirm' => "action === 'confirm'",
    'unconfirm' => "action === 'unconfirm'",
    'in_progress' => "action === 'in_progress'",
    'complete' => "action === 'complete'",
    'no_show' => "action === 'no_show'",
    'cancel' => "action === 'cancel'",
    'delete' => "action === 'delete'",
    'cleanup_all' => "action === 'cleanup_all'",
    'cleanup_employee' => "action === 'cleanup_employee'",
    'cleanup_room' => "action === 'cleanup_room'",
    'staff_lock' => "action === 'staff_lock'",
    'staff_unlock' => "action === 'staff_unlock'",
    'send_confirmation' => "action === 'send_confirmation'",
    'edit_notes' => "action === 'edit_notes'",
];

if (!str_contains($js, 'function handleApptContextAction')) {
    echo "[FAIL] app-calendar-day.js: handleApptContextAction not found\n";
    $fail = true;
} else {
    foreach ($handlerPatterns as $action => $needle) {
        $ok = str_contains($js, $needle);
        echo ($ok ? '[PASS] ' : '[FAIL] ') . "JS handler for action `{$action}`\n";
        if (!$ok) {
            $fail = true;
        }
    }
}

// Sanity: buildApptContextMenuItems references same action strings (quick check for typos).
echo "\n--- buildApptContextMenuItems action strings present ---\n";
$menuActions = [
    'complete', 'confirm', 'unconfirm', 'checkin', 'take_payment_invoice', 'view_invoice',
    'checkout_new_sale', 'take_deposit_sale', 'cancel', 'staff_lock', 'staff_unlock',
    'in_progress', 'no_show', 'clipboard', 'edit', 'view', 'print_appointment', 'print_itinerary',
    'print_invoice', 'send_confirmation', 'add_companion_booking', 'cleanup_all', 'cleanup_employee',
    'cleanup_room', 'edit_notes', 'delete',
];
$buildFn = 'function buildApptContextMenuItems';
$endFn = 'function appendApptContextMenuNodes';
$pos = strpos($js, $buildFn);
$end = $pos !== false ? strpos($js, $endFn, $pos) : false;
$buildSlice = ($pos !== false && $end !== false && $end > $pos) ? substr($js, $pos, $end - $pos) : '';
if ($buildSlice === '') {
    echo "[FAIL] buildApptContextMenuItems block not found\n";
    $fail = true;
} else {
    foreach ($menuActions as $a) {
        $needle = "action: '{$a}'";
        $ok = str_contains($buildSlice, $needle);
        echo ($ok ? '[PASS] ' : '[FAIL] ') . "menu defines {$needle}\n";
        if (!$ok) {
            $fail = true;
        }
    }
}

exit($fail ? 1 : 0);
