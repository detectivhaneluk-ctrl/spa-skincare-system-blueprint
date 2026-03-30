<?php
$title = 'Sale · ' . htmlspecialchars($invoice['invoice_number'] ?? '');
$pres = $receiptPresentation['presentation'];
$itemsView = $receiptPresentation['items_display'];
$showBarcode = !empty($pres['show_item_barcode']) && !empty($receiptPresentation['has_any_barcode']);
$mainClass = 'sales-workspace-page sale-detail-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = 'manage_sales';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');

$fmtTs = static function ($v): string {
    if ($v === null || $v === '') {
        return '—';
    }
    $t = strtotime((string) $v);

    return $t ? date('Y-m-d H:i', $t) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
$invoiceId = (int) ($invoice['id'] ?? 0);
$clientId = (int) ($invoice['client_id'] ?? 0);
$appointmentId = isset($invoice['appointment_id']) && $invoice['appointment_id'] !== '' && $invoice['appointment_id'] !== null
    ? (int) $invoice['appointment_id'] : 0;
$branchIdRaw = $invoice['branch_id'] ?? null;
$branchId = $branchIdRaw !== null && $branchIdRaw !== '' ? (int) $branchIdRaw : null;
?>
<div class="sale-detail-identity">
    <h2 class="sales-workspace-section-title">Sale <span class="sale-detail-order-num"><?= htmlspecialchars($invoice['invoice_number'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></h2>

    <?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
    <div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
    <?php endif; ?>

    <div class="entity-actions">
        <?php if (in_array($invoice['status'] ?? '', ['draft', 'open', 'partial'], true)): ?>
        <a href="/sales/invoices/<?= $invoiceId ?>/edit" class="btn">Edit</a>
        <a href="/sales/invoices/<?= $invoiceId ?>/payments/create" class="btn">Record Payment</a>
        <?php endif; ?>
        <?php if (in_array($invoice['status'] ?? '', ['draft', 'open', 'partial'], true)): ?>
        <form method="post" action="/sales/invoices/<?= $invoiceId ?>/cancel" style="display:inline" onsubmit="return confirm('Cancel invoice?')">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit">Cancel</button>
        </form>
        <?php endif; ?>
        <form method="post" action="/sales/invoices/<?= $invoiceId ?>/delete" style="display:inline" onsubmit="return confirm('Delete invoice?')">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit">Delete</button>
        </form>
    </div>

    <div class="sale-detail-identity-grid">
        <div class="sale-detail-identity-main">
            <dl class="sale-detail-dl">
                <dt>Status</dt>
                <dd><?= htmlspecialchars((string) ($invoice['status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Currency</dt>
                <dd><?= htmlspecialchars((string) ($invoice['currency'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Issued</dt>
                <dd><?= $fmtTs($invoice['issued_at'] ?? null) ?></dd>
                <dt>Created</dt>
                <dd><?= $fmtTs($invoice['created_at'] ?? null) ?></dd>
                <?php if ($branchId !== null): ?>
                <dt>Branch</dt>
                <dd>#<?= $branchId ?></dd>
                <?php endif; ?>
                <?php if ($appointmentId > 0): ?>
                <dt>Appointment</dt>
                <dd><a href="/appointments/<?= $appointmentId ?>">#<?= $appointmentId ?></a></dd>
                <?php endif; ?>
                <?php if (empty($pres['show_client_block']) && $clientId > 0): ?>
                <dt>Client</dt>
                <dd><a href="/clients/<?= $clientId ?>"><?= htmlspecialchars($invoice['client_display'] ?? '—', ENT_QUOTES, 'UTF-8') ?></a></dd>
                <?php endif; ?>
            </dl>
        </div>
        <div class="sale-detail-identity-client">
            <?php if (!empty($pres['show_client_block'])): ?>
            <h3 class="sale-detail-subheading">Client</h3>
            <div class="sale-detail-client-block">
                <?php if ($clientId > 0): ?>
                <p class="sale-detail-client-name"><a href="/clients/<?= $clientId ?>"><?= htmlspecialchars($invoice['client_display'] ?? '—', ENT_QUOTES, 'UTF-8') ?></a></p>
                <?php else: ?>
                <p class="sale-detail-client-name"><?= htmlspecialchars($invoice['client_display'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if (!empty($pres['show_client_phone']) && trim((string) ($receiptPresentation['client_phone'] ?? '')) !== ''): ?>
                <p class="invoice-client-detail"><?= htmlspecialchars((string) $receiptPresentation['client_phone'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if (!empty($pres['show_client_address']) && trim((string) ($receiptPresentation['client_address'] ?? '')) !== ''): ?>
                <p class="invoice-client-detail"><?= nl2br(htmlspecialchars((string) $receiptPresentation['client_address'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="sale-detail-identity-totals">
            <h3 class="sale-detail-subheading">Totals</h3>
            <dl class="sale-detail-dl sale-detail-dl--compact">
                <dt>Subtotal</dt>
                <dd><?= number_format((float) ($invoice['subtotal_amount'] ?? 0), 2) ?></dd>
                <dt>Discount</dt>
                <dd><?= number_format((float) ($invoice['discount_amount'] ?? 0), 2) ?></dd>
                <dt>Tax</dt>
                <dd><?= number_format((float) ($invoice['tax_amount'] ?? 0), 2) ?></dd>
                <dt>Total</dt>
                <dd><strong><?= number_format((float) ($invoice['total_amount'] ?? 0), 2) ?></strong></dd>
                <dt>Paid</dt>
                <dd><?= number_format((float) ($invoice['paid_amount'] ?? 0), 2) ?></dd>
                <dt>Balance due</dt>
                <dd><strong><?= number_format((float) ($invoice['balance_due'] ?? 0), 2) ?></strong></dd>
            </dl>
        </div>
    </div>

    <?php
    $hasEstablishment =
        (!empty($pres['show_establishment_name']) && !empty($invoice['establishment']['name']))
        || (!empty($pres['show_establishment_phone']) && !empty($invoice['establishment']['phone']))
        || (!empty($pres['show_establishment_email']) && !empty($invoice['establishment']['email']))
        || (!empty($pres['show_establishment_address']) && !empty($invoice['establishment']['address']));
    ?>
    <?php if ($hasEstablishment): ?>
    <div class="sale-detail-location">
        <h3 class="sale-detail-subheading">Location</h3>
        <dl class="sale-detail-dl">
            <?php if (!empty($pres['show_establishment_name']) && !empty($invoice['establishment']['name'])): ?>
            <dt>Spa</dt>
            <dd><?= htmlspecialchars((string) $invoice['establishment']['name'], ENT_QUOTES, 'UTF-8') ?></dd>
            <?php endif; ?>
            <?php if (!empty($pres['show_establishment_phone']) && !empty($invoice['establishment']['phone'])): ?>
            <dt>Spa phone</dt>
            <dd><?= htmlspecialchars((string) $invoice['establishment']['phone'], ENT_QUOTES, 'UTF-8') ?></dd>
            <?php endif; ?>
            <?php if (!empty($pres['show_establishment_email']) && !empty($invoice['establishment']['email'])): ?>
            <dt>Spa email</dt>
            <dd><?= htmlspecialchars((string) $invoice['establishment']['email'], ENT_QUOTES, 'UTF-8') ?></dd>
            <?php endif; ?>
            <?php if (!empty($pres['show_establishment_address']) && !empty($invoice['establishment']['address'])): ?>
            <dt>Spa address</dt>
            <dd><?= nl2br(htmlspecialchars((string) $invoice['establishment']['address'], ENT_QUOTES, 'UTF-8')) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
    <?php endif; ?>
</div>

<?php if (trim((string) ($pres['invoice_message'] ?? '')) !== ''): ?>
<div class="invoice-message-block">
    <p><?= nl2br(htmlspecialchars((string) $pres['invoice_message'], ENT_QUOTES, 'UTF-8')) ?></p>
</div>
<?php endif; ?>

<section class="sale-detail-section sale-detail-items" aria-labelledby="sale-detail-items-heading">
    <h3 id="sale-detail-items-heading" class="sale-detail-section-title">Ordered items</h3>
    <table class="index-table">
        <thead><tr>
            <th><?= htmlspecialchars((string) ($pres['item_header_label'] ?? 'Description'), ENT_QUOTES, 'UTF-8') ?></th>
            <?php if ($showBarcode): ?><th>Barcode</th><?php endif; ?>
            <th>Qty</th><th>Unit price</th><th>Discount</th><th>Line total</th>
        </tr></thead>
        <tbody>
        <?php foreach ($itemsView as $item): ?>
        <tr>
            <td><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <?php if ($showBarcode): ?><td><?= htmlspecialchars((string) ($item['product_barcode'] ?? ''), ENT_QUOTES, 'UTF-8') ?: '—' ?></td><?php endif; ?>
            <td><?= htmlspecialchars((string) ($item['quantity'] ?? 0), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= number_format((float) ($item['unit_price'] ?? 0), 2) ?></td>
            <td><?= number_format((float) ($item['discount_amount'] ?? 0), 2) ?></td>
            <td><?= number_format((float) ($item['line_total'] ?? 0), 2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="sale-detail-section sale-detail-payments" aria-labelledby="sale-detail-payments-heading">
    <h3 id="sale-detail-payments-heading" class="sale-detail-section-title">Payments</h3>

    <?php if (!empty($payments)): ?>
    <table class="index-table">
        <thead><tr><th>Type</th><th>Method</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $pay): ?>
        <tr>
            <td><?= htmlspecialchars((string) ($pay['entry_type'] ?? 'payment'), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) ($pay['payment_method'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= number_format((float) ($pay['amount'] ?? 0), 2) ?></td>
            <td><?= htmlspecialchars((string) ($pay['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) ($pay['paid_at'] ?? $pay['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
                <?php
                $pid = (int) ($pay['id'] ?? 0);
                $entryType = (string) ($pay['entry_type'] ?? 'payment');
                $refundable = (float) ($refundableByPaymentId[$pid] ?? 0);
                ?>
                <?php if ($entryType === 'payment' && ($pay['status'] ?? '') === 'completed' && $refundable > 0): ?>
                <form method="post" action="/sales/payments/<?= $pid ?>/refund">
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="number" name="amount" min="0.01" step="0.01" max="<?= htmlspecialchars((string) number_format($refundable, 2, '.', '')) ?>" value="<?= htmlspecialchars((string) number_format($refundable, 2, '.', '')) ?>" required>
                    <input type="text" name="notes" placeholder="Refund note">
                    <button type="submit">Refund</button>
                </form>
                <?php else: ?>
                —
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <p class="hint">No payment rows recorded for this sale.</p>
    <?php endif; ?>

    <?php if (!empty($giftCardRedemptions)): ?>
    <h4 class="sale-detail-subheading sale-detail-subheading--nested">Gift card redemptions</h4>
    <table class="index-table">
        <thead><tr><th>Gift Card</th><th>Amount</th><th>Balance After</th><th>Branch</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($giftCardRedemptions as $r): ?>
        <tr>
            <td><?= htmlspecialchars((string) ($r['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= number_format((float) ($r['amount'] ?? 0), 2) ?></td>
            <td><?= number_format((float) ($r['balance_after'] ?? 0), 2) ?></td>
            <td><?= $r['branch_id'] !== null ? ('#' . (int) $r['branch_id']) : 'global' ?></td>
            <td><?= htmlspecialchars((string) ($r['created_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!empty($invoice['client_id']) && in_array($invoice['status'] ?? '', ['draft', 'open', 'partial'], true)): ?>
    <div class="payment-summary sale-detail-gift-redeem">
        <h4 class="sale-detail-subheading sale-detail-subheading--nested">Redeem gift card</h4>
        <?php if (($invoice['balance_due'] ?? 0) <= 0): ?>
        <p class="hint">Invoice has no balance due.</p>
        <?php elseif (empty($eligibleGiftCards)): ?>
        <p class="hint">No eligible gift cards with balance for this client and branch context.</p>
        <?php else: ?>
        <form method="post" action="/sales/invoices/<?= $invoiceId ?>/redeem-gift-card" class="entity-form">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <div class="form-row">
                <label for="gift_card_id">Gift Card *</label>
                <select id="gift_card_id" name="gift_card_id" required>
                    <option value="">Select gift card</option>
                    <?php foreach ($eligibleGiftCards as $giftCard): ?>
                    <option value="<?= (int) $giftCard['gift_card_id'] ?>">
                        <?= htmlspecialchars((string) $giftCard['code']) ?> · balance <?= number_format((float) $giftCard['current_balance'], 2) ?> <?= htmlspecialchars((string) $giftCard['currency']) ?> · <?= $giftCard['branch_id'] !== null ? ('branch #' . (int) $giftCard['branch_id']) : 'global' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="amount">Amount *</label>
                <input type="number" id="amount" name="amount" min="0.01" step="0.01" max="<?= htmlspecialchars((string) number_format((float) ($invoice['balance_due'] ?? 0), 2, '.', '')) ?>" required>
            </div>
            <div class="form-row">
                <label for="redeem_notes">Notes</label>
                <textarea id="redeem_notes" name="notes" rows="2" placeholder="Optional redemption note"></textarea>
            </div>
            <div class="form-actions"><button type="submit">Redeem Gift Card</button></div>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<section class="sale-detail-section sale-detail-supporting" aria-labelledby="sale-detail-supporting-heading">
    <h3 id="sale-detail-supporting-heading" class="sale-detail-section-title">Notes &amp; reference</h3>
    <dl class="sale-detail-dl">
        <dt>Notes</dt>
        <dd><?= nl2br(htmlspecialchars((string) ($invoice['notes'] ?? ''), ENT_QUOTES, 'UTF-8')) ?: '—' ?></dd>
    </dl>
    <?php if (!empty($receiptPresentation['recorded_by_line'])): ?>
    <p class="invoice-recorded-by"><?= htmlspecialchars((string) $receiptPresentation['recorded_by_line'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if (trim((string) ($pres['receipt_message'] ?? '')) !== ''): ?>
    <div class="sale-detail-footer-text">
        <p><strong>Receipt footer</strong></p>
        <p><?= nl2br(htmlspecialchars((string) $pres['receipt_message'], ENT_QUOTES, 'UTF-8')) ?></p>
    </div>
    <?php endif; ?>
    <?php if (trim((string) ($pres['footer_bank_details'] ?? '')) !== ''): ?>
    <div class="invoice-footer-extra">
        <h4>Bank / payment details</h4>
        <p><?= nl2br(htmlspecialchars((string) $pres['footer_bank_details'], ENT_QUOTES, 'UTF-8')) ?></p>
    </div>
    <?php endif; ?>
    <?php if (trim((string) ($pres['footer_text'] ?? '')) !== ''): ?>
    <div class="invoice-footer-extra">
        <p><?= nl2br(htmlspecialchars((string) $pres['footer_text'], ENT_QUOTES, 'UTF-8')) ?></p>
    </div>
    <?php endif; ?>
</section>

<p class="sale-detail-back"><a href="/sales/invoices">← Back to sales orders</a></p>

<style>
.sale-detail-page .sale-detail-order-num { font-weight: 600; }
.sale-detail-identity-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 1rem;
    margin-top: 1rem;
    align-items: start;
}
@media (max-width: 960px) {
    .sale-detail-identity-grid { grid-template-columns: 1fr; }
}
.sale-detail-dl { margin: 0; }
.sale-detail-dl dt { font-weight: 600; margin-top: 0.35rem; color: #444; font-size: 0.85rem; }
.sale-detail-dl dd { margin: 0 0 0.25rem 0; }
.sale-detail-dl--compact dt { margin-top: 0.25rem; }
.sale-detail-subheading { font-size: 1rem; margin: 0 0 0.5rem 0; }
.sale-detail-subheading--nested { margin-top: 1rem; }
.sale-detail-section { margin-top: 1.75rem; border-top: 1px solid #ddd; padding-top: 1rem; }
.sale-detail-section-title { font-size: 1.15rem; margin: 0 0 0.75rem 0; }
.sale-detail-location { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; }
.sale-detail-gift-redeem { margin-top: 1rem; }
.sale-detail-back { margin-top: 1.5rem; }
</style>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
