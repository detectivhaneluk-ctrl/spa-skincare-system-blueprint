<?php
$salesWorkspaceShellTitle = 'Reports';
$salesWorkspaceShellSub = 'Measurement endpoints. Each link returns JSON; add date_from and date_to (Y-m-d) and optional branch_id where your role allows.';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
?>
<div class="reports-hub">
    <ul class="reports-hub__list">
        <li><a class="reports-hub__link" href="/reports/revenue-summary">Revenue summary</a></li>
        <li><a class="reports-hub__link" href="/reports/payments-by-method">Payments by method</a></li>
        <li><a class="reports-hub__link" href="/reports/refunds-summary">Refunds summary</a></li>
        <li><a class="reports-hub__link" href="/reports/appointments-volume">Appointments volume</a></li>
        <li><a class="reports-hub__link" href="/reports/new-clients">New clients</a></li>
        <li><a class="reports-hub__link" href="/reports/staff-appointment-count">Staff appointment count</a></li>
        <li><a class="reports-hub__link" href="/reports/gift-card-liability">Gift card liability</a></li>
        <li><a class="reports-hub__link" href="/reports/inventory-movements">Inventory movements</a></li>
        <li><a class="reports-hub__link" href="/reports/vat-distribution">VAT distribution</a></li>
    </ul>
</div>
<style>
.reports-hub { padding: 1.5rem 0; }
.reports-hub__list { margin: 0; padding-left: 1.2rem; color: #111827; }
.reports-hub__list li { margin: 0.35rem 0; }
.reports-hub__link { color: #2563eb; text-decoration: none; font-size: 0.9rem; }
.reports-hub__link:hover { text-decoration: underline; }
</style>
