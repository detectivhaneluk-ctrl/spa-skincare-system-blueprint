<?php
/** @var array $print @see \Modules\Appointments\Services\AppointmentPrintSummaryService::compose */
/** @var string $title */
$a = $print['appointment'];
$clientContact = $print['client_contact'] ?? null;
$staffSameDay = $print['staff_same_day'] ?? [];
$staffScope = (string) ($print['staff_same_day_scope'] ?? '');
$serviceHistory = $print['service_history'] ?? [];
$packageUsages = $print['package_usages'] ?? [];
$packagesRecent = $print['packages_recent'] ?? [];
$productPurchaseLines = $print['product_purchase_lines'] ?? [];
$clientLabel = trim((string) ($a['client_first_name'] ?? '') . ' ' . (string) ($a['client_last_name'] ?? ''));
$staffLabel = trim((string) ($a['staff_first_name'] ?? '') . ' ' . (string) ($a['staff_last_name'] ?? ''));
$vis = is_array($print['section_visibility'] ?? null) ? $print['section_visibility'] : [];
$showStaffSection = !empty($vis['staff_appointment_list']);
$showHistorySection = !empty($vis['client_service_history']);
$showPackageSection = !empty($vis['package_detail']);
$showProductPurchaseSection = !empty($vis['client_product_purchase_history']);
$histShowStaff = array_filter($serviceHistory, static fn ($r) => array_key_exists('staff_name', $r) && $r['staff_name'] !== null && $r['staff_name'] !== '');
$histShowRoom = array_filter($serviceHistory, static fn ($r) => array_key_exists('room_name', $r) && $r['room_name'] !== null && $r['room_name'] !== '');
?>
<link rel="stylesheet" href="/assets/css/appointment-print.css">
<div class="appt-print" id="appt-print-root">
    <header class="appt-print__actions no-print">
        <button type="button" class="appt-print__btn" onclick="window.print()">Print</button>
        <a class="appt-print__link" href="/appointments/<?= (int) ($a['id'] ?? 0) ?>">Back to appointment</a>
    </header>

    <section class="appt-print__section" aria-labelledby="appt-print-h1">
        <h1 class="appt-print__h1" id="appt-print-h1">Appointment summary</h1>
        <dl class="appt-print__dl">
            <div class="appt-print__row"><dt>Reference</dt><dd>#<?= (int) ($a['id'] ?? 0) ?></dd></div>
            <div class="appt-print__row"><dt>Summary</dt><dd><?= htmlspecialchars((string) ($a['display_summary'] ?? '—')) ?></dd></div>
            <div class="appt-print__row"><dt>Date</dt><dd><?= htmlspecialchars((string) ($a['display_date_only'] ?? '—')) ?></dd></div>
            <div class="appt-print__row"><dt>Time</dt><dd><?= htmlspecialchars((string) ($a['display_time_range'] ?? '—')) ?></dd></div>
            <div class="appt-print__row"><dt>Service</dt><dd><?= htmlspecialchars((string) ($a['service_name'] ?? '—')) ?></dd></div>
            <div class="appt-print__row"><dt>Staff</dt><dd><?= htmlspecialchars($staffLabel !== '' ? $staffLabel : '—') ?></dd></div>
            <div class="appt-print__row"><dt>Room</dt><dd><?= htmlspecialchars((string) ($a['room_name'] ?? '—')) ?></dd></div>
            <div class="appt-print__row"><dt>Status</dt><dd><?= htmlspecialchars((string) ($a['status_label'] ?? '—')) ?></dd></div>
            <div class="appt-print__row appt-print__row--block"><dt>Appointment notes</dt><dd><?= nl2br(htmlspecialchars((string) ($a['notes'] ?? ''))) ?: '—' ?></dd></div>
        </dl>
    </section>

    <section class="appt-print__section" aria-labelledby="appt-print-client">
        <h2 class="appt-print__h2" id="appt-print-client">Client</h2>
        <p class="appt-print__p"><strong>Name:</strong> <?= htmlspecialchars($clientLabel !== '' ? $clientLabel : '—') ?></p>
        <?php if ($clientContact !== null): ?>
        <dl class="appt-print__dl">
            <div class="appt-print__row"><dt>Phone</dt><dd><?= htmlspecialchars((string) ($clientContact['phone'] ?? '—')) ?></dd></div>
            <div class="appt-print__row"><dt>Email</dt><dd><?= htmlspecialchars((string) ($clientContact['email'] ?? '—')) ?></dd></div>
            <div class="appt-print__row appt-print__row--block"><dt>Client profile notes</dt><dd><?= nl2br(htmlspecialchars((string) ($clientContact['notes'] ?? ''))) ?: '—' ?></dd></div>
        </dl>
        <?php else: ?>
        <p class="appt-print__muted">Extended contact fields are shown only when the client is visible on your current branch (same rule as client profile providers).</p>
        <?php endif; ?>
    </section>

    <?php if ($showStaffSection): ?>
    <section class="appt-print__section" aria-labelledby="appt-print-staff-day">
        <h2 class="appt-print__h2" id="appt-print-staff-day">Staff day schedule</h2>
        <p class="appt-print__scope"><?php
            if ($staffScope === 'no_staff') {
                echo 'This appointment has no primary staff; same-day staff column list is not applicable.';
            } else {
                echo 'Appointments for the same primary staff member on the same calendar day (org/branch-scoped, non-deleted). One staff per appointment row.';
            }
        ?></p>
        <?php if ($staffScope !== 'no_staff' && $staffSameDay !== []): ?>
        <table class="appt-print__table">
            <thead><tr><th>ID</th><th>Start</th><th>End</th><th>Status</th><th>Service</th><th>Client</th></tr></thead>
            <tbody>
            <?php foreach ($staffSameDay as $row): ?>
            <tr<?= (int) ($row['id'] ?? 0) === (int) ($a['id'] ?? 0) ? ' class="appt-print__tr--current"' : '' ?>>
                <td><?= (int) ($row['id'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($row['start_at'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($row['end_at'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($row['status'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($row['service_name'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string) ($row['client_label'] ?? '—')) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php elseif ($staffScope !== 'no_staff'): ?>
        <p class="appt-print__muted">No other rows for this staff on this day.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($showHistorySection): ?>
    <section class="appt-print__section" aria-labelledby="appt-print-history">
        <h2 class="appt-print__h2" id="appt-print-history">Recent client appointments</h2>
        <p class="appt-print__scope">Source: <code>ClientAppointmentProfileProvider::listRecent</code> (staff/space columns follow itinerary display settings).</p>
        <?php if ($serviceHistory !== []): ?>
        <table class="appt-print__table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Start</th>
                <th>End</th>
                <th>Status</th>
                <th>Service</th>
                <?php if ($histShowStaff !== []): ?><th>Staff</th><?php endif; ?>
                <?php if ($histShowRoom !== []): ?><th>Space</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($serviceHistory as $h): ?>
            <tr>
                <td><?= (int) ($h['id'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($h['start_at'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($h['end_at'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($h['status'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($h['service_name'] ?? '—')) ?></td>
                <?php if ($histShowStaff !== []): ?>
                <td><?= htmlspecialchars((string) ($h['staff_name'] ?? '—')) ?></td>
                <?php endif; ?>
                <?php if ($histShowRoom !== []): ?>
                <td><?= htmlspecialchars((string) ($h['room_name'] ?? '—')) ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="appt-print__muted">No history rows (no client or none returned for this branch).</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($showProductPurchaseSection): ?>
    <section class="appt-print__section" aria-labelledby="appt-print-product-purchase">
        <h2 class="appt-print__h2" id="appt-print-product-purchase">Client product purchase history</h2>
        <p class="appt-print__scope">Source: <code>ClientSalesProfileProvider::listRecentProductInvoiceLines</code> — retail <code>invoice_items</code> with <code>item_type = product</code> only (not services). Invoices are non-deleted and tenant-scoped; each row shows the invoice status as stored (no refund netting on this list).</p>
        <?php if ($productPurchaseLines !== []): ?>
        <table class="appt-print__table">
            <thead>
            <tr>
                <th>Product</th>
                <th>Qty</th>
                <th>Unit</th>
                <th>Line</th>
                <th>Invoice</th>
                <th>Inv. status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($productPurchaseLines as $pl): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($pl['product_name'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string) ($pl['quantity'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($pl['currency'] ?? '')) ?> <?= htmlspecialchars(number_format((float) ($pl['unit_price'] ?? 0), 2)) ?></td>
                <td><?= htmlspecialchars((string) ($pl['currency'] ?? '')) ?> <?= htmlspecialchars(number_format((float) ($pl['line_total'] ?? 0), 2)) ?></td>
                <td><?= htmlspecialchars((string) ($pl['invoice_number'] ?? '—')) ?></td>
                <td><?= htmlspecialchars((string) ($pl['invoice_status'] ?? '—')) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="appt-print__muted">No product lines (no client, client not visible on this branch, or no matching invoice product rows in scope).</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($showPackageSection): ?>
    <section class="appt-print__section" aria-labelledby="appt-print-packages">
        <h2 class="appt-print__h2" id="appt-print-packages">Packages</h2>
        <p class="appt-print__scope">Usage rows for this appointment (if any), plus recent client packages when the client profile provider returns rows (requires client home branch).</p>
        <?php if ($packageUsages !== []): ?>
        <h3 class="appt-print__h3">Recorded usage for this appointment</h3>
        <table class="appt-print__table">
            <thead><tr><th>Package</th><th>Qty</th><th>Remaining after</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($packageUsages as $u): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($u['package_name'] ?? '—')) ?></td>
                <td><?= (int) ($u['quantity'] ?? 0) ?></td>
                <td><?= (int) ($u['remaining_after'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($u['created_at'] ?? '')) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if ($packagesRecent !== []): ?>
        <h3 class="appt-print__h3">Recent client packages</h3>
        <table class="appt-print__table">
            <thead><tr><th>Package</th><th>Status</th><th>Remaining</th><th>Expires</th></tr></thead>
            <tbody>
            <?php foreach ($packagesRecent as $p): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($p['package_name'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($p['status'] ?? '')) ?></td>
                <td><?= (int) ($p['remaining_sessions'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($p['expires_at'] ?? '—')) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if ($packageUsages === [] && $packagesRecent === []): ?>
        <p class="appt-print__muted">No package usage on this appointment and no recent client-package rows from the profile provider (e.g. client without a home branch).</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
