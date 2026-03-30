<?php
$title = 'Client · Billing · ' . ($client['display_name'] ?? '');
$mainClass = 'client-resume-page client-ref-surface client-ref--client-tab client-ref--tab-billing';
$clientRefTitleRowSecondaryTab = true;
ob_start();
?>
<div class="client-ref client-ref-surface client-ref--client-tab client-ref--tab-billing">
<?php require base_path('modules/clients/views/partials/client-ref-header-tabs.php'); ?>

    <div class="client-ref-body">
<?php require base_path('modules/clients/views/partials/client-ref-sidebar.php'); ?>

        <div class="client-ref-main client-ref-main--client-tab" role="main">
            <div class="client-ref-tab-workspace client-ref-billing-workspace">
                <header class="client-ref-tab-workspace__head client-ref-billing-workspace__page-head">
                    <div>
                        <h2 class="client-ref-tab-workspace__title">Billing</h2>
                        <p class="client-ref-tab-workspace__lede">Payment methods and account billing options. Card storage and bank mandates are not connected in this build; amounts below come from invoices and recorded payments.</p>
                    </div>
                    <a class="client-ref-tab-workspace__btn client-ref-tab-workspace__btn--ghost" href="<?= htmlspecialchars($billingInvoicesListUrl) ?>">All invoices</a>
                </header>

                <section class="client-ref-billing-workspace__block" aria-labelledby="billing-card-heading">
                    <h3 id="billing-card-heading" class="client-ref-tab-workspace__subhead client-ref-billing-workspace__block-title">Credit card</h3>
                    <p class="client-ref-tab-workspace__muted client-ref-billing-workspace__block-lede">Saved cards and hosted card capture (vault or payment-provider popup) are not implemented for client profiles. Record card payments when you post payments against invoices in Sales.</p>
                    <div class="client-ref-billing-workspace__add-card" role="group" aria-labelledby="billing-add-card-label">
                        <span id="billing-add-card-label" class="client-ref-billing-workspace__add-card-label">Add new card</span>
                        <p class="client-ref-billing-workspace__add-card-note">Not available &mdash; no secure card-on-file API is configured for this screen.</p>
                        <button type="button" class="client-ref-billing-workspace__add-card-btn" disabled>Add new card</button>
                    </div>
                </section>

                <section class="client-ref-billing-workspace__block" aria-labelledby="billing-bank-heading">
                    <h3 id="billing-bank-heading" class="client-ref-tab-workspace__subhead client-ref-billing-workspace__block-title">Bank / direct debit</h3>
                    <div class="client-ref-billing-workspace__empty-slot">
                        <p class="client-ref-billing-workspace__empty-slot-title">No bank mandate on file</p>
                        <p class="client-ref-billing-workspace__empty-slot-text">Direct debit and ACH profiles are not linked to clients in the current schema.</p>
                    </div>
                </section>

                <section class="client-ref-billing-workspace__block" aria-labelledby="billing-credit-heading">
                    <h3 id="billing-credit-heading" class="client-ref-tab-workspace__subhead client-ref-billing-workspace__block-title">Client account credit</h3>
                    <p class="client-ref-tab-workspace__muted">A separate &ldquo;store credit&rdquo; balance is not persisted on the client record here; use invoice and payment entries to reflect balances.</p>
                    <label class="client-ref-billing-workspace__credit-toggle">
                        <input type="checkbox" disabled aria-disabled="true">
                        <span>Enable account credit for this client</span>
                        <span class="client-ref-billing-workspace__credit-badge">Not available</span>
                    </label>
                </section>

                <section class="client-ref-billing-workspace__block" aria-labelledby="billing-summary-heading">
                    <h3 id="billing-summary-heading" class="client-ref-tab-workspace__subhead client-ref-billing-workspace__block-title">Account summary</h3>
                    <dl class="client-ref-billing-workspace__dl">
                        <dt>Invoices</dt>
                        <dd><?= (int) ($salesSummary['invoice_count'] ?? 0) ?></dd>
                        <dt>Balance due</dt>
                        <dd><?= ($salesSummary['total_due'] ?? null) === null ? '—' : number_format((float) $salesSummary['total_due'], 2) ?></dd>
                        <dt>Payments recorded</dt>
                        <dd><?= (int) ($salesSummary['payment_count'] ?? 0) ?></dd>
                    </dl>
                    <?php if (!empty($salesSummary['billed_mixed_currency']) || !empty($salesSummary['paid_mixed_currency'])): ?>
                    <p class="client-ref-tab-workspace__muted">Totals may be split by currency in Sales when multiple currencies are present.</p>
                    <?php endif; ?>
                </section>

                <section class="client-ref-billing-workspace__block client-ref-billing-workspace__block--flush" aria-labelledby="billing-payments-heading">
                    <h3 id="billing-payments-heading" class="client-ref-tab-workspace__subhead client-ref-billing-workspace__block-title">Recent payments</h3>
                    <?php if (empty($recentPayments)): ?>
                    <div class="client-ref-billing-workspace__empty-slot client-ref-billing-workspace__empty-slot--compact">
                        <p class="client-ref-billing-workspace__empty-slot-text">No recent payments in this list.</p>
                    </div>
                    <?php else: ?>
                    <div class="client-ref-tab-workspace__table-wrap">
                        <table class="client-ref-tab-workspace__table">
                            <thead>
                                <tr>
                                    <th scope="col">Invoice</th>
                                    <th scope="col">Method</th>
                                    <th scope="col">Amount</th>
                                    <th scope="col">Currency</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Date</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentPayments as $pay): ?>
                                <tr>
                                    <td><a class="client-ref-tab-workspace__link" href="/sales/invoices/<?= (int) ($pay['invoice_id'] ?? 0) ?>">#<?= (int) ($pay['invoice_id'] ?? 0) ?></a></td>
                                    <td><?= htmlspecialchars((string) ($pay['payment_method'] ?? '')) ?></td>
                                    <td><?= number_format((float) ($pay['amount'] ?? 0), 2) ?></td>
                                    <td><?= htmlspecialchars((string) ($pay['currency'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($pay['status'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($pay['paid_at'] ?? $pay['created_at'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>

                <footer class="client-ref-billing-workspace__save-footer">
                    <p class="client-ref-billing-workspace__save-note">Nothing on this page can be saved yet. Use <a class="client-ref-tab-workspace__link" href="/clients/<?= (int) $clientId ?>/edit">client details</a> for profile fields that persist.</p>
                    <button type="button" class="client-ref-tab-workspace__btn client-ref-tab-workspace__btn--primary client-ref-billing-workspace__save-btn" disabled title="Billing profile fields are not writable from this screen">Save</button>
                </footer>
            </div>
        </div>
    </div>
</div>

<?php require base_path('modules/clients/views/partials/client-ref-shell-styles.php'); ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
