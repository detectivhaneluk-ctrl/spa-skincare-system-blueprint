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

            <?php
            $ps = is_array($packageSummary ?? null) ? $packageSummary : [];
            $rp = is_array($recentPackages ?? null) ? $recentPackages : [];
            $gs = is_array($giftCardSummary ?? null) ? $giftCardSummary : [];
            $rg = is_array($recentGiftCards ?? null) ? $recentGiftCards : [];
            $clientBranchId = (int) ($client['branch_id'] ?? 0);
            ?>
            <section class="client-ref-block client-ref-block--primary" id="client-ref-owned-value" aria-labelledby="client-ref-owned-heading">
                <h2 id="client-ref-owned-heading" class="client-ref-block-title">Owned value</h2>
                <p class="hint" style="margin-top:0;">Packages and gift cards assigned to this client. Plan definitions stay in Catalog; held records are owned here in Clients.</p>
                <?php if ($clientBranchId <= 0): ?>
                <p class="hint" role="status">Set a branch on this client to load package and gift-card summaries (branch-scoped read model).</p>
                <?php endif; ?>

                <h3 class="client-ref-subblock-title">Packages held</h3>
                <dl class="client-ref-inline-dl">
                    <dt>Total</dt><dd><?= (int) ($ps['total'] ?? 0) ?></dd>
                    <dt>Active</dt><dd><?= (int) ($ps['active'] ?? 0) ?></dd>
                    <dt>Used</dt><dd><?= (int) ($ps['used'] ?? 0) ?></dd>
                    <dt>Expired</dt><dd><?= (int) ($ps['expired'] ?? 0) ?></dd>
                    <dt>Cancelled</dt><dd><?= (int) ($ps['cancelled'] ?? 0) ?></dd>
                    <dt>Remaining sessions (all)</dt><dd><?= (int) ($ps['total_remaining_sessions'] ?? 0) ?></dd>
                </dl>
                <?php if ($rp === []): ?>
                <p class="hint">No package assignments in the recent list.</p>
                <?php else: ?>
                <table class="index-table">
                    <thead><tr><th>Package</th><th>Status</th><th>Sessions</th><th>Expires</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rp as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($row['package_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['status'] ?? '')) ?></td>
                        <td><?= (int) ($row['remaining_sessions'] ?? 0) ?> / <?= (int) ($row['assigned_sessions'] ?? 0) ?></td>
                        <td><?= htmlspecialchars((string) ($row['expires_at'] ?? '—')) ?></td>
                        <td><a href="/packages/client-packages/<?= (int) ($row['id'] ?? 0) ?>">Open</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <h3 class="client-ref-subblock-title">Gift cards</h3>
                <dl class="client-ref-inline-dl">
                    <dt>Cards</dt><dd><?= (int) ($gs['total'] ?? 0) ?></dd>
                    <dt>Active</dt><dd><?= (int) ($gs['active'] ?? 0) ?></dd>
                    <dt>Combined balance</dt><dd><?= number_format((float) ($gs['total_balance'] ?? 0), 2) ?></dd>
                </dl>
                <?php if ($rg === []): ?>
                <p class="hint">No gift cards in the recent list.</p>
                <?php else: ?>
                <table class="index-table">
                    <thead><tr><th>Code</th><th>Status</th><th>Balance</th><th>Expires</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rg as $row): ?>
                    <tr>
                        <td><code><?= htmlspecialchars((string) ($row['code'] ?? '')) ?></code></td>
                        <td><?= htmlspecialchars((string) ($row['status'] ?? '')) ?></td>
                        <td><?= number_format((float) ($row['current_balance'] ?? 0), 2) ?></td>
                        <td><?= htmlspecialchars((string) ($row['expires_at'] ?? '—')) ?></td>
                        <td><a href="/gift-cards/<?= (int) ($row['id'] ?? 0) ?>">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <h3 class="client-ref-subblock-title">Memberships</h3>
                <p class="hint" style="margin-bottom:0;">Active membership enrollments are not summarized on this profile yet — the client profile read layer has no membership provider wired. Use <strong>Active client memberships</strong> on the Clients list or the memberships module for that workspace.</p>
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
