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
            $ms = is_array($membershipSummary ?? null) ? $membershipSummary : [];
            $rm = is_array($recentMemberships ?? null) ? $recentMemberships : [];
            $ss = is_array($salesSummary ?? null) ? $salesSummary : [];
            $clientBranchId = (int) ($client['branch_id'] ?? 0);
            $billedMixed = !empty($ss['billed_mixed_currency']);
            $paidMixed = !empty($ss['paid_mixed_currency']);
            ?>
            <section class="client-ref-block client-ref-block--primary" id="client-ref-owned-value" aria-labelledby="client-ref-owned-heading">
                <h2 id="client-ref-owned-heading" class="client-ref-block-title">Owned value &amp; obligations</h2>
                <p class="hint" style="margin-top:0;">One place on this profile for <strong>held value</strong> (packages, gift cards, memberships) and <strong>invoice balance</strong> from Sales. Plan definitions stay in <strong>Catalog</strong>; money movement stays in <strong>Sales</strong>; stored-value liability measurement stays in <strong>Reports</strong> where applicable.</p>
                <?php if ($clientBranchId <= 0): ?>
                <p class="hint" role="status">Set a branch on this client to load package, gift-card, and membership summaries (same branch-scoped read model as the rest of the client workspace).</p>
                <?php endif; ?>

                <h3 class="client-ref-subblock-title">Invoices &amp; balance</h3>
                <p class="hint" style="margin-top:0;">Rollup from this client’s invoices (tenant-scoped). When multiple currencies are present, a single “balance due” total is not shown — open Sales for the full list.</p>
                <dl class="client-ref-inline-dl">
                    <dt>Invoices</dt><dd><?= (int) ($ss['invoice_count'] ?? 0) ?></dd>
                    <dt>Total billed</dt><dd><?= ($ss['total_billed'] ?? null) === null ? '—' : number_format((float) $ss['total_billed'], 2) ?></dd>
                    <dt>Total paid</dt><dd><?= ($ss['total_paid'] ?? null) === null ? '—' : number_format((float) $ss['total_paid'], 2) ?></dd>
                    <dt>Balance due</dt><dd><?= ($ss['total_due'] ?? null) === null ? '—' : number_format((float) $ss['total_due'], 2) ?><?php if ($billedMixed || $paidMixed): ?> <span class="hint">(multi-currency — see Sales)</span><?php endif; ?></dd>
                    <dt>Payments recorded</dt><dd><?= (int) ($ss['payment_count'] ?? 0) ?></dd>
                </dl>
                <p class="hint" style="margin-bottom:0;"><a href="/clients/<?= $clientId ?>/sales">Open client sales</a> for the searchable invoice list. Issuing and payments stay in the Sales workspace.</p>

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
                    <thead><tr><th>Plan name</th><th>Status</th><th>Sessions</th><th>Expires</th><th></th></tr></thead>
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
                <?php $clientDisplayName = (string) ($client['display_name'] ?? ''); ?>
                <p class="hint" style="margin-bottom:0;">
                    <a href="/packages/client-packages?search=<?= urlencode($clientDisplayName) ?>">View all packages for this client</a>
                    &nbsp;·&nbsp;
                    <a href="/packages/client-packages/assign">Assign new package</a>
                </p>

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
                <p class="hint" style="margin-bottom:0;">
                    <a href="/gift-cards?client_name=<?= urlencode($clientDisplayName) ?>">View all gift cards for this client</a>
                    &nbsp;·&nbsp;
                    <a href="/gift-cards/issue">Issue new gift card</a>
                </p>

                <h3 class="client-ref-subblock-title">Memberships</h3>
                <p class="hint" style="margin-top:0;">Client-owned enrollment rows (plan templates: <a href="/memberships">Membership plans</a>). There is no per-membership detail screen in this build — manage from the list below.</p>
                <?php if ($clientBranchId <= 0): ?>
                <p class="hint" role="status">Branch is required to count memberships the same way as packages and gift cards.</p>
                <?php else: ?>
                <dl class="client-ref-inline-dl">
                    <dt>Total</dt><dd><?= (int) ($ms['total'] ?? 0) ?></dd>
                    <dt>Active</dt><dd><?= (int) ($ms['active'] ?? 0) ?></dd>
                    <dt>Paused</dt><dd><?= (int) ($ms['paused'] ?? 0) ?></dd>
                    <dt>Expired</dt><dd><?= (int) ($ms['expired'] ?? 0) ?></dd>
                    <dt>Cancelled</dt><dd><?= (int) ($ms['cancelled'] ?? 0) ?></dd>
                </dl>
                <?php if ($rm === []): ?>
                <p class="hint">No membership enrollments in the recent list for this branch scope.</p>
                <?php else: ?>
                <table class="index-table">
                    <thead><tr><th>Plan</th><th>Status</th><th>Starts</th><th>Ends</th></tr></thead>
                    <tbody>
                    <?php foreach ($rm as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($row['definition_name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['status'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['starts_at'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['ends_at'] ?? '—')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <p class="hint" style="margin-bottom:0;">
                    <a href="/memberships/client-memberships?search=<?= urlencode($clientDisplayName) ?>">View all memberships for this client</a>
                    &nbsp;·&nbsp;
                    <a href="/memberships/client-memberships/assign">Enrol in membership</a>
                </p>
                <?php endif; ?>
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
