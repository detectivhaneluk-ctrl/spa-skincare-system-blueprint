<?php
$title = 'Client summary · ' . $client['display_name'];
$mainClass = 'client-resume-page client-ref-surface';
$clientId = (int) $client['id'];
$clientRefActiveTab = 'resume';
$clientRefHideAppointmentListFootnote = true;
ob_start();
?>
<div class="client-ref client-ref-surface">
<?php require base_path('modules/clients/views/partials/client-ref-header-tabs.php'); ?>

    <div class="client-ref-body">
<?php require base_path('modules/clients/views/partials/client-ref-sidebar.php'); ?>

        <div class="client-ref-main" role="main">
            <section class="client-ref-block" aria-labelledby="client-ref-contact-heading">
                <h2 id="client-ref-contact-heading" class="client-ref-block-title">Contact</h2>
                <dl class="client-ref-inline-dl">
                    <dt>Phone</dt>
                    <dd><?= htmlspecialchars($client['phone'] ?? '—') ?></dd>
                    <dt>Email</dt>
                    <dd><?= htmlspecialchars($client['email'] ?? '—') ?></dd>
                </dl>
            </section>

            <div class="client-ref-actions-row">
                <a class="btn" href="/clients/<?= $clientId ?>/edit">Edit profile</a>
                <a class="btn" href="/clients/merge?primary_id=<?= $clientId ?>">Merge / duplicates</a>
                <?php if (!empty($duplicates)): ?>
                <a class="btn" href="/clients/merge?primary_id=<?= $clientId ?>&secondary_id=<?= (int) $duplicates[0]['id'] ?>">Merge preview (duplicate detected)</a>
                <?php endif; ?>
            </div>
            <?php
            $dupSearch = $duplicateSearch ?? null;
            if (is_array($dupSearch) && empty($dupSearch['ready']) && !empty($dupSearch['blocked_reason'])): ?>
            <p class="hint" role="status"><?= htmlspecialchars((string) $dupSearch['blocked_reason']) ?></p>
            <?php endif; ?>

<?php
// Transitional: full appointments workspace remains on the summary route so existing bookmarks and
// the Sales tab anchor (#client-ref-ventes) still sit below the same list without a second navigation hop.
// Dedicated management lives at GET /clients/{id}/appointments; removing this block would be a UX break until a lightweight preview ships.
require base_path('modules/clients/views/partials/client-ref-rdv-workspace.php');
?>

            <section class="client-ref-block client-ref-block--primary" id="client-ref-ventes" aria-labelledby="client-ref-sales-heading">
                <h2 id="client-ref-sales-heading" class="client-ref-block-title">Recent sales</h2>
                <?php if (empty($recentInvoices)): ?>
                <p class="hint">No invoices.</p>
                <?php else: ?>
                <h3 class="client-ref-subblock-title">Invoices</h3>
                <table class="index-table">
                    <thead><tr><th>Invoice</th><th>Total</th><th>Paid</th><th>Status</th><th>Created</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recentInvoices as $inv): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $inv['invoice_number']) ?></td>
                        <td><?= number_format((float) $inv['total_amount'], 2) ?></td>
                        <td><?= number_format((float) $inv['paid_amount'], 2) ?></td>
                        <td><?= htmlspecialchars((string) $inv['status']) ?></td>
                        <td><?= htmlspecialchars((string) $inv['created_at']) ?></td>
                        <td><a href="/sales/invoices/<?= (int) $inv['id'] ?>">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <h3 class="client-ref-subblock-title">Recent payments</h3>
                <?php if (empty($recentPayments)): ?>
                <p class="hint">No recent payments in this list.</p>
                <?php else: ?>
                <table class="index-table">
                    <thead><tr><th>Invoice</th><th>Method</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentPayments as $pay): ?>
                    <tr>
                        <td><a href="/sales/invoices/<?= (int) $pay['invoice_id'] ?>">#<?= (int) $pay['invoice_id'] ?></a></td>
                        <td><?= htmlspecialchars((string) $pay['payment_method']) ?></td>
                        <td><?= number_format((float) $pay['amount'], 2) ?></td>
                        <td><?= htmlspecialchars((string) $pay['status']) ?></td>
                        <td><?= htmlspecialchars((string) ($pay['paid_at'] ?? $pay['created_at'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </section>

            <section class="client-ref-block" id="client-ref-waitlist" aria-labelledby="client-ref-wl-heading">
                <h2 id="client-ref-wl-heading" class="client-ref-block-title">Waitlist</h2>
                <p class="hint">Per-client waitlist entries are not listed here. Use the waitlist module to manage requests.</p>
                <p><a href="/appointments/waitlist">Open waitlist</a></p>
            </section>
        </div>
    </div>
</div>

<?php require base_path('modules/clients/views/partials/client-ref-shell-styles.php'); ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
