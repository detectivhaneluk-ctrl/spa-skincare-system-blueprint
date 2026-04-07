<?php

use Modules\Settings\Support\PaymentSettingsMethodBuckets;

/** @var array $payment */
/** @var array $publicCommerce */
/** @var array $hardware */
/** @var list<array{code:string,name:string}> $paymentMethodsEffective */
/** @var int $paymentsBranchId */
/** @var list<array{id:int|string,name?:string,...}> $branches */
/** @var string $csrf */
/** @var string $paymentEdit */
/** @var bool $canViewPaymentMethodsLink */
/** @var bool $canViewPriceModificationReasonsLink */
/** @var bool $canViewVatRatesLink */
/** @var bool $canViewSettingsLink */
/** @var bool $canManageMembershipsLink */
/** @var array<string, mixed> $receiptInvoice */
/** @var string $receiptInvoiceFooterPreview */

$canManageMembershipsLink = !empty($canManageMembershipsLink);
$canViewPriceModificationReasonsLink = !empty($canViewPriceModificationReasonsLink);

$paymentEdit = strtolower(trim($paymentEdit ?? ''));
$allowedEdits = ['cards', 'receipt'];
if (!in_array($paymentEdit, $allowedEdits, true)) {
    $paymentEdit = '';
}

$buckets = PaymentSettingsMethodBuckets::bucket($paymentMethodsEffective);
$yn = static fn (bool $v): string => $v ? 'Yes' : 'No';

$paymentsBranchSelectName = 'payments_branch_id';
$paymentSettingsQuery = static function (int $branchId, string $editMode = '') use ($paymentsBranchSelectName): string {
    $q = ['section' => 'payments'];
    if ($branchId > 0) {
        $q[$paymentsBranchSelectName] = $branchId;
    }
    if ($editMode !== '') {
        $q['payment_edit'] = $editMode;
    }

    return '/settings?' . http_build_query($q);
};
$publicChannelsQuery = static function (int $branchId): string {
    $q = ['section' => 'public_channels'];
    if ($branchId > 0) {
        $q['online_booking_branch_id'] = $branchId;
    }

    return '/settings?' . http_build_query($q);
};

$methodLines = static function (array $rows): string {
    if ($rows === []) {
        return '—';
    }
    $parts = [];
    foreach ($rows as $r) {
        $code = htmlspecialchars((string) ($r['code'] ?? ''));
        $name = htmlspecialchars((string) ($r['name'] ?? ''));
        $parts[] = $name !== '' && $name !== $code ? "{$name} ({$code})" : $code;
    }

    return implode('; ', $parts);
};

$defaultCode = (string) ($payment['default_method_code'] ?? 'cash');
$allowedCodes = array_values(array_unique(array_map(
    static fn (array $r) => (string) ($r['code'] ?? ''),
    $paymentMethodsEffective
)));
$defaultResolves = $defaultCode !== '' && in_array($defaultCode, $allowedCodes, true);
$cardsSummaryOther = $methodLines($buckets['other']);

?>
<section class="settings-establishment settings-payment-control-plane">
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Payments, Checkout &amp; Tax</h2>
        <p class="settings-establishment__lead">
            Configure payment method defaults, recording rules, receipt and invoice layout, and access related finance settings.
        </p>
    </header>

    <div class="settings-payment-scope-bar" role="region" aria-label="Branch context for preview and gift card limits">
        <div class="settings-payment-scope-bar__copy">
            <span class="settings-payment-scope-bar__label">Scope</span>
            <span class="settings-payment-scope-bar__rules">
                <strong>Org-wide:</strong> recording defaults. <strong>Per branch:</strong> receipt/invoice policy (when a branch is selected). <strong>Preview:</strong> active methods for selected branch.
            </span>
        </div>
        <form method="get" action="/settings" class="settings-payment-scope-bar__form settings-branch-form">
            <input type="hidden" name="section" value="payments">
            <?php if ($paymentEdit !== ''): ?>
                <input type="hidden" name="payment_edit" value="<?= htmlspecialchars($paymentEdit) ?>">
            <?php endif; ?>
            <label class="settings-payment-scope-bar__label-input" for="payments_branch_id">Branch</label>
            <select id="payments_branch_id" name="<?= htmlspecialchars($paymentsBranchSelectName) ?>">
                <option value="0" <?= $paymentsBranchId === 0 ? 'selected' : '' ?>>Organization default</option>
                <?php foreach ($branches as $b): $bid = (int) ($b['id'] ?? 0); ?>
                    <option value="<?= $bid ?>" <?= $paymentsBranchId === $bid ? 'selected' : '' ?>><?= htmlspecialchars((string) ($b['name'] ?? '')) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="settings-payment-scope-bar__apply">Apply</button>
        </form>
    </div>
    <p class="settings-payment-scope-hint">
        <?= $paymentsBranchId > 0
            ? 'Saving applies to this branch for receipt/invoice policy.'
            : 'Saving uses organization defaults for receipt/invoice policy.' ?>
    </p>

    <div class="settings-payment-subsection-stack">

        <section class="settings-establishment-card settings-payment-subsection" data-payment-mode="derived">
            <h3 class="settings-establishment-card__title">Checks</h3>
            <p class="settings-establishment-card__help">From active payment methods.</p>
            <div class="settings-establishment-summary">
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Accept checks (preview)</span>
                    <span class="settings-establishment-summary__value"><?= $buckets['checks'] === [] ? 'No' : 'Yes' ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Active methods</span>
                    <span class="settings-establishment-summary__value"><?= $methodLines($buckets['checks']) ?></span>
                </div>
            </div>
            <div class="settings-establishment-actions">
                <?php if (!empty($canViewPaymentMethodsLink)): ?>
                    <a class="settings-establishment-btn" href="/settings/payment-methods">Manage payment methods</a>
                <?php else: ?>
                    <span class="settings-payment-subsection-unavailable">Not available for your role.</span>
                <?php endif; ?>
            </div>
        </section>

        <section class="settings-establishment-card settings-payment-subsection" data-payment-mode="derived">
            <h3 class="settings-establishment-card__title">Cash</h3>
            <p class="settings-establishment-card__help">From active methods; register requirement is under Hardware.</p>
            <div class="settings-establishment-summary">
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Accept cash (preview)</span>
                    <span class="settings-establishment-summary__value"><?= $buckets['cash'] === [] ? 'No' : 'Yes' ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Active methods</span>
                    <span class="settings-establishment-summary__value"><?= $methodLines($buckets['cash']) ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Require open register (hardware)</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($hardware['use_cash_register']))) ?></span>
                </div>
            </div>
            <div class="settings-establishment-actions">
                <?php if (!empty($canViewPaymentMethodsLink)): ?>
                    <a class="settings-establishment-btn" href="/settings/payment-methods">Manage payment methods</a>
                <?php else: ?>
                    <span class="settings-payment-subsection-unavailable">Not available for your role.</span>
                <?php endif; ?>
                <?php if (!empty($canViewSettingsLink)): ?>
                    <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings?section=hardware">Open hardware</a>
                <?php endif; ?>
            </div>
        </section>

        <section class="settings-establishment-card settings-payment-subsection" data-payment-mode="editable">
            <h3 class="settings-establishment-card__title">Credit cards / recorded non-cash</h3>
            <p class="settings-establishment-card__help">Org-wide recording defaults (no processor integration).</p>
            <div class="settings-establishment-summary">
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Recorded non-cash methods (preview)</span>
                    <span class="settings-establishment-summary__value"><?= $cardsSummaryOther === '—' ? '—' : $cardsSummaryOther ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Default method code</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars($defaultCode) ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Default allowed in preview</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars($yn($defaultResolves)) ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Partial payments</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($payment['allow_partial_payments']))) ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Overpayments</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($payment['allow_overpayments']))) ?></span>
                </div>
            </div>
            <?php if ($paymentEdit !== 'cards'): ?>
                <div class="settings-establishment-actions">
                    <a class="settings-establishment-btn" href="<?= htmlspecialchars($paymentSettingsQuery($paymentsBranchId, 'cards')) ?>">Edit recording defaults</a>
                </div>
            <?php else: ?>
                <form method="post" action="/settings" class="settings-form">
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="section" value="payments">
                    <input type="hidden" name="payments_context_branch_id" value="<?= (int) $paymentsBranchId ?>">
                    <h4 class="settings-establishment-card__subtitle">Edit recording defaults</h4>
                    <div class="settings-grid">
                        <div class="setting-row">
                            <label for="payments-default_method_code">Default method code</label>
                            <?php if ($allowedCodes !== []): ?>
                                <select id="payments-default_method_code" name="settings[payments.default_method_code]">
                                    <?php foreach ($allowedCodes as $c): ?>
                                        <option value="<?= htmlspecialchars($c) ?>" <?= $defaultCode === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="setting-help">Must be active on the invoice branch when recording.</p>
                            <?php else: ?>
                                <input type="text" id="payments-default_method_code" name="settings[payments.default_method_code]" value="<?= htmlspecialchars($defaultCode) ?>">
                                <p class="setting-help">No methods in preview—add methods or enter a code.</p>
                            <?php endif; ?>
                        </div>
                        <div class="setting-row">
                            <input type="hidden" name="settings[payments.allow_partial_payments]" value="0">
                            <label><input type="checkbox" name="settings[payments.allow_partial_payments]" value="1" <?= !empty($payment['allow_partial_payments']) ? 'checked' : '' ?>> Allow partial payments</label>
                        </div>
                        <div class="setting-row">
                            <input type="hidden" name="settings[payments.allow_overpayments]" value="0">
                            <label><input type="checkbox" name="settings[payments.allow_overpayments]" value="1" <?= !empty($payment['allow_overpayments']) ? 'checked' : '' ?>> Allow overpayments</label>
                        </div>
                    </div>
                    <div class="settings-establishment-actions">
                        <button type="submit" class="settings-establishment-btn">Save</button>
                        <a class="settings-establishment-btn settings-establishment-btn--muted" href="<?= htmlspecialchars($paymentSettingsQuery($paymentsBranchId, '')) ?>">Done</a>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="settings-establishment-card settings-payment-subsection" data-payment-mode="editable">
            <h3 class="settings-establishment-card__title">Gift cards</h3>
            <p class="settings-establishment-card__help">Ownership moved: public/anonymous gift-card commerce controls live under Public Channels / Public Commerce. Issue, redemption, and balance operations for gift cards are managed from Sales in the main navigation.</p>
            <div class="settings-establishment-summary">
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Public gift card sales</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($publicCommerce['allow_gift_cards']))) ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Min / max amount</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($publicCommerce['gift_card_min_amount'] ?? '')) ?> / <?= htmlspecialchars((string) ($publicCommerce['gift_card_max_amount'] ?? '')) ?></span>
                </div>
            </div>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn" href="<?= htmlspecialchars($publicChannelsQuery($paymentsBranchId)) ?>">Open public commerce controls</a>
            </div>
        </section>

        <section class="settings-establishment-card settings-payment-subsection" data-payment-mode="editable">
            <h3 class="settings-establishment-card__title">Receipt &amp; invoice</h3>
            <p class="settings-establishment-card__help">
                Invoice screen and payment footers (<code>receipt_invoice.*</code>). Receipt footer syncs first 500 chars to legacy <code>payments.receipt_notes</code>.
            </p>
            <div class="settings-establishment-summary">
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Branch scope</span>
                    <span class="settings-establishment-summary__value"><?= $paymentsBranchId > 0 ? 'Branch #' . (int) $paymentsBranchId : 'Organization default' ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Receipt footer (effective)</span>
                    <span class="settings-establishment-summary__value"><?= $receiptInvoiceFooterPreview !== '' ? htmlspecialchars($receiptInvoiceFooterPreview) : '—' ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Invoice message</span>
                    <span class="settings-establishment-summary__value"><?= trim((string) ($receiptInvoice['invoice_message'] ?? '')) !== '' ? htmlspecialchars((string) $receiptInvoice['invoice_message']) : '—' ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Header (name / address / phone / email)</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($receiptInvoice['show_establishment_name']))) ?> / <?= htmlspecialchars($yn(!empty($receiptInvoice['show_establishment_address']))) ?> / <?= htmlspecialchars($yn(!empty($receiptInvoice['show_establishment_phone']))) ?> / <?= htmlspecialchars($yn(!empty($receiptInvoice['show_establishment_email']))) ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Client block / phone / address</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($receiptInvoice['show_client_block']))) ?> / <?= htmlspecialchars($yn(!empty($receiptInvoice['show_client_phone']))) ?> / <?= htmlspecialchars($yn(!empty($receiptInvoice['show_client_address']))) ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Recorded-by line / product barcode column</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($receiptInvoice['show_recorded_by']))) ?> / <?= htmlspecialchars($yn(!empty($receiptInvoice['show_item_barcode']))) ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Item label / sort</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($receiptInvoice['item_header_label'] ?? 'Description')) ?> · <?= htmlspecialchars((string) ($receiptInvoice['item_sort_mode'] ?? 'as_entered')) ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Bank footer / custom footer</span>
                    <span class="settings-establishment-summary__value"><?= trim((string) ($receiptInvoice['footer_bank_details'] ?? '')) !== '' ? 'Yes' : '—' ?> / <?= trim((string) ($receiptInvoice['footer_text'] ?? '')) !== '' ? 'Yes' : '—' ?></span>
                </div>
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Receipt printer dispatch</span>
                    <span class="settings-establishment-summary__value"><?= htmlspecialchars($yn(!empty($hardware['use_receipt_printer']))) ?> (managed in Hardware)</span>
                </div>
            </div>
            <?php if ($paymentEdit !== 'receipt'): ?>
                <div class="settings-establishment-actions">
                    <a class="settings-establishment-btn" href="<?= htmlspecialchars($paymentSettingsQuery($paymentsBranchId, 'receipt')) ?>">Edit receipt &amp; invoice</a>
                </div>
            <?php else: ?>
                <form method="post" action="/settings" class="settings-form">
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="section" value="payments">
                    <input type="hidden" name="payments_context_branch_id" value="<?= (int) $paymentsBranchId ?>">
                    <h4 class="settings-establishment-card__subtitle">Edit receipt &amp; invoice</h4>
                    <p class="setting-help">Uses branch from scope bar (or org default).</p>
                    <div class="settings-grid">
                        <h4 class="settings-establishment-card__subtitle" style="grid-column:1/-1;margin:0.5rem 0 0;font-size:0.95rem;">Establishment header on invoice</h4>
                        <div class="setting-row">
                            <input type="hidden" name="settings[receipt_invoice.show_establishment_name]" value="0">
                            <label><input type="checkbox" name="settings[receipt_invoice.show_establishment_name]" value="1" <?= !empty($receiptInvoice['show_establishment_name']) ? 'checked' : '' ?>> Show establishment name</label>
                        </div>
                        <div class="setting-row">
                            <input type="hidden" name="settings[receipt_invoice.show_establishment_address]" value="0">
                            <label><input type="checkbox" name="settings[receipt_invoice.show_establishment_address]" value="1" <?= !empty($receiptInvoice['show_establishment_address']) ? 'checked' : '' ?>> Show establishment address</label>
                        </div>
                        <div class="setting-row">
                            <input type="hidden" name="settings[receipt_invoice.show_establishment_phone]" value="0">
                            <label><input type="checkbox" name="settings[receipt_invoice.show_establishment_phone]" value="1" <?= !empty($receiptInvoice['show_establishment_phone']) ? 'checked' : '' ?>> Show establishment phone</label>
                        </div>
                        <div class="setting-row">
                            <input type="hidden" name="settings[receipt_invoice.show_establishment_email]" value="0">
                            <label><input type="checkbox" name="settings[receipt_invoice.show_establishment_email]" value="1" <?= !empty($receiptInvoice['show_establishment_email']) ? 'checked' : '' ?>> Show establishment email</label>
                        </div>
                        <h4 class="settings-establishment-card__subtitle" style="grid-column:1/-1;margin:0.75rem 0 0;font-size:0.95rem;">Client on invoice</h4>
                        <div class="setting-row">
                            <input type="hidden" name="settings[receipt_invoice.show_client_block]" value="0">
                            <label><input type="checkbox" name="settings[receipt_invoice.show_client_block]" value="1" <?= !empty($receiptInvoice['show_client_block']) ? 'checked' : '' ?>> Show client block</label>
                        </div>
                        <div class="setting-row">
                            <input type="hidden" name="settings[receipt_invoice.show_client_phone]" value="0">
                            <label><input type="checkbox" name="settings[receipt_invoice.show_client_phone]" value="1" <?= !empty($receiptInvoice['show_client_phone']) ? 'checked' : '' ?>> Include client phone (when stored)</label>
                        </div>
                        <div class="setting-row">
                            <input type="hidden" name="settings[receipt_invoice.show_client_address]" value="0">
                            <label><input type="checkbox" name="settings[receipt_invoice.show_client_address]" value="1" <?= !empty($receiptInvoice['show_client_address']) ? 'checked' : '' ?>> Include client address (when stored)</label>
                        </div>
                        <h4 class="settings-establishment-card__subtitle" style="grid-column:1/-1;margin:0.75rem 0 0;font-size:0.95rem;">Lines &amp; footer</h4>
                        <div class="setting-row">
                            <input type="hidden" name="settings[receipt_invoice.show_recorded_by]" value="0">
                            <label><input type="checkbox" name="settings[receipt_invoice.show_recorded_by]" value="1" <?= !empty($receiptInvoice['show_recorded_by']) ? 'checked' : '' ?>> Show “recorded by” from latest completed payment</label>
                        </div>
                        <div class="setting-row">
                            <input type="hidden" name="settings[receipt_invoice.show_item_barcode]" value="0">
                            <label><input type="checkbox" name="settings[receipt_invoice.show_item_barcode]" value="1" <?= !empty($receiptInvoice['show_item_barcode']) ? 'checked' : '' ?>> Show product barcode column (product lines only)</label>
                        </div>
                        <div class="setting-row">
                            <label for="receipt-item_header_label">Item table first column label</label>
                            <input type="text" id="receipt-item_header_label" name="settings[receipt_invoice.item_header_label]" maxlength="40" value="<?= htmlspecialchars((string) ($receiptInvoice['item_header_label'] ?? 'Description')) ?>">
                        </div>
                        <div class="setting-row">
                            <label for="receipt-item_sort_mode">Line order</label>
                            <select id="receipt-item_sort_mode" name="settings[receipt_invoice.item_sort_mode]">
                                <option value="as_entered" <?= (($receiptInvoice['item_sort_mode'] ?? '') === 'as_entered') ? 'selected' : '' ?>>As entered</option>
                                <option value="description_asc" <?= (($receiptInvoice['item_sort_mode'] ?? '') === 'description_asc') ? 'selected' : '' ?>>Description A–Z</option>
                            </select>
                        </div>
                        <div class="setting-row">
                            <label for="receipt-footer_bank_details">Bank / payment details</label>
                            <textarea id="receipt-footer_bank_details" name="settings[receipt_invoice.footer_bank_details]" rows="2" maxlength="500"><?= htmlspecialchars((string) ($receiptInvoice['footer_bank_details'] ?? '')) ?></textarea>
                        </div>
                        <div class="setting-row">
                            <label for="receipt-footer_text">Footer text</label>
                            <textarea id="receipt-footer_text" name="settings[receipt_invoice.footer_text]" rows="2" maxlength="500"><?= htmlspecialchars((string) ($receiptInvoice['footer_text'] ?? '')) ?></textarea>
                        </div>
                        <h4 class="settings-establishment-card__subtitle" style="grid-column:1/-1;margin:0.75rem 0 0;font-size:0.95rem;">Messages</h4>
                        <div class="setting-row">
                            <label for="receipt-receipt_message">Receipt footer (printed / summary; max 1000)</label>
                            <textarea id="receipt-receipt_message" name="settings[receipt_invoice.receipt_message]" rows="3" maxlength="1000"><?= htmlspecialchars((string) ($receiptInvoice['receipt_message'] ?? '')) ?></textarea>
                            <p class="setting-help">Empty → legacy <code>payments.receipt_notes</code> (same scope).</p>
                        </div>
                        <div class="setting-row">
                            <label for="receipt-invoice_message">Invoice message (body text above details)</label>
                            <textarea id="receipt-invoice_message" name="settings[receipt_invoice.invoice_message]" rows="2" maxlength="1000"><?= htmlspecialchars((string) ($receiptInvoice['invoice_message'] ?? '')) ?></textarea>
                        </div>
                        <h4 class="settings-establishment-card__subtitle" style="grid-column:1/-1;margin:0.75rem 0 0;font-size:0.95rem;">Hardware ownership</h4>
                        <div class="setting-row">
                            <p class="setting-help">Receipt printer dispatch is hardware-owned and editable under Hardware settings.</p>
                        </div>
                    </div>
                    <div class="settings-establishment-actions">
                        <button type="submit" class="settings-establishment-btn">Save</button>
                        <?php if (!empty($canViewSettingsLink)): ?>
                            <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings?section=hardware">Open hardware</a>
                        <?php endif; ?>
                        <a class="settings-establishment-btn settings-establishment-btn--muted" href="<?= htmlspecialchars($paymentSettingsQuery($paymentsBranchId, '')) ?>">Done</a>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="settings-establishment-card settings-payment-subsection settings-payment-subsection--linked settings-payment-subsection--compact-linked" data-payment-mode="linked">
            <h3 class="settings-establishment-card__title">Packages (prepaid series)</h3>
            <p class="settings-establishment-card__help">No package payment toggles in this section. Package <em>plan</em> definitions are managed in Catalog; client-held packages are managed in Clients — use the main navigation.</p>
        </section>

        <section class="settings-establishment-card settings-payment-subsection settings-payment-subsection--linked" data-payment-mode="linked">
            <h3 class="settings-establishment-card__title">Related finance surfaces</h3>
            <p class="settings-establishment-card__help">Methods, VAT types, VAT guide.</p>
            <div class="settings-establishment-summary">
                <div class="settings-establishment-summary__row">
                    <span class="settings-establishment-summary__key">Shortcuts</span>
                    <span class="settings-establishment-summary__value">Payment methods · Price reasons · VAT · Guide</span>
                </div>
            </div>
            <div class="settings-establishment-actions">
                <?php if (!empty($canViewPaymentMethodsLink)): ?>
                    <a class="settings-establishment-btn" href="/settings/payment-methods">Custom Payment Methods</a>
                <?php endif; ?>
                <?php if (!empty($canViewPriceModificationReasonsLink)): ?>
                    <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings/price-modification-reasons">Price Modification Reasons</a>
                <?php endif; ?>
                <?php if (!empty($canViewVatRatesLink)): ?>
                    <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings/vat-rates">VAT Types</a>
                <?php endif; ?>
                <?php if (!empty($canViewSettingsLink)): ?>
                    <a class="settings-establishment-btn settings-establishment-btn--muted" href="/settings/vat-distribution-guide">VAT Distribution Guide</a>
                <?php endif; ?>
                <?php if (empty($canViewPaymentMethodsLink) && empty($canViewPriceModificationReasonsLink) && empty($canViewVatRatesLink) && empty($canViewSettingsLink)): ?>
                    <span class="settings-payment-subsection-unavailable">No related links for your role.</span>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($canManageMembershipsLink): ?>
        <section class="settings-establishment-card settings-payment-subsection settings-payment-subsection--linked settings-payment-subsection--compact-linked" data-payment-mode="linked">
            <h3 class="settings-establishment-card__title">Membership refund review</h3>
            <p class="settings-establishment-card__help">Operational queue — not a policy editor. Open it from the Memberships area in the main navigation when you need to process refunds.</p>
        </section>
        <?php endif; ?>
    </div>
</section>
<style>
    .settings-payment-control-plane .settings-payment-scope-bar {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem 1rem;
        padding: 0.65rem 0.85rem;
        border: 1px solid #e5e7eb;
        border-radius: 0.65rem;
        background: #f9fafb;
    }
    .settings-payment-control-plane .settings-payment-scope-bar__copy {
        flex: 1 1 16rem;
        min-width: min(100%, 16rem);
        font-size: 0.86rem;
        line-height: 1.45;
        color: #4b5563;
    }
    .settings-payment-control-plane .settings-payment-scope-bar__label {
        display: inline-block;
        margin-right: 0.35rem;
        font-weight: 600;
        color: #111827;
    }
    .settings-payment-control-plane .settings-payment-scope-bar__form {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.4rem 0.5rem;
    }
    .settings-payment-control-plane .settings-payment-scope-bar__label-input {
        font-size: 0.82rem;
        font-weight: 600;
        color: #374151;
    }
    .settings-payment-control-plane .settings-payment-scope-bar__form select {
        min-width: 10rem;
        max-width: 100%;
    }
    .settings-payment-control-plane .settings-payment-scope-bar__apply {
        font-size: 0.85rem;
    }
    .settings-payment-control-plane .settings-payment-scope-hint {
        margin: 0.35rem 0 0.75rem;
        font-size: 0.8rem;
        color: #6b7280;
    }
    .settings-payment-subsection-stack {
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
    }
    .settings-payment-subsection--deferred {
        background: #fafafa;
    }
    .settings-payment-subsection--deferred .settings-establishment-card__title {
        font-size: 0.95rem;
    }
    .settings-payment-subsection--compact-linked .settings-establishment-card__title {
        font-size: 0.98rem;
    }
    .settings-payment-subsection-unavailable,
    .settings-payment-subsection-noaction {
        font-size: 0.86rem;
        color: #6b7280;
        align-self: center;
    }
</style>
