<?php
$isEdit = isset($invoice['id']);
$invoiceAction = $isEdit ? '/sales/invoices/' . (int) $invoice['id'] : '/sales/invoices';
$submitLabel = $isEdit ? 'Update Draft' : 'Save Draft';
$clientId = (int) ($invoice['client_id'] ?? 0);
$clientLabel = 'Walk-in / No appointment';
foreach (($clients ?? []) as $clientRow) {
    if ((int) ($clientRow['id'] ?? 0) === $clientId) {
        $name = trim(((string) ($clientRow['first_name'] ?? '')) . ' ' . ((string) ($clientRow['last_name'] ?? '')));
        $clientLabel = $name !== '' ? $name : ('Client #' . $clientId);
        break;
    }
}
$clientSelected = $clientId > 0;
$serviceCatalog = [];
foreach (($services ?? []) as $svc) {
    $serviceCatalog[] = [
        'id' => (int) ($svc['id'] ?? 0),
        'name' => (string) ($svc['name'] ?? ''),
        'price' => (float) ($svc['price'] ?? 0),
    ];
}
$productCatalog = [];
foreach (($products ?? []) as $prod) {
    $productCatalog[] = [
        'id' => (int) ($prod['id'] ?? 0),
        'name' => (string) ($prod['name'] ?? ''),
        'sku' => (string) ($prod['sku'] ?? ''),
        'sell_price' => (float) ($prod['sell_price'] ?? 0),
        'category' => trim((string) ($prod['category'] ?? '')),
        'brand' => trim((string) ($prod['brand'] ?? '')),
    ];
}
$quickAdd = array_slice($productCatalog, 0, 5);
$packages = $packages ?? [];
$packageCatalog = [];
foreach ($packages as $p) {
    $packageCatalog[] = [
        'id' => (int) ($p['id'] ?? 0),
        'name' => (string) ($p['name'] ?? ''),
        'price' => isset($p['price']) && $p['price'] !== null && $p['price'] !== '' ? (float) $p['price'] : 0.0,
        'total_sessions' => (int) ($p['total_sessions'] ?? 0),
    ];
}
$cashierPaidBaseline = $isEdit ? round((float) ($invoice['paid_amount'] ?? 0), 2) : 0.0;

$cashierHasMeaningfulLines = false;
foreach (($invoice['items'] ?? []) as $_cashierItem) {
    if (!is_array($_cashierItem)) {
        continue;
    }
    $_d = trim((string) ($_cashierItem['description'] ?? ''));
    $_q = (float) ($_cashierItem['quantity'] ?? 0);
    $_p = (float) ($_cashierItem['unit_price'] ?? 0);
    if (($_d !== '' && ($_q > 0 || $_p > 0)) || $_p > 0) {
        $cashierHasMeaningfulLines = true;
        break;
    }
}
$cashierErrs = $errors ?? [];
$cashierDisclosureExpandedInitially = $isEdit || $cashierHasMeaningfulLines || !empty($cashierErrs);
$cashierLinesQuiet = !$isEdit && !$cashierHasMeaningfulLines && empty($cashierErrs);

$deferredPersistedRows = [];
foreach (($invoice['items'] ?? []) as $it) {
    if (!is_array($it)) {
        continue;
    }
    $t = (string) ($it['item_type'] ?? '');
    if (!in_array($t, ['gift_card', 'gift_voucher', 'series'], true)) {
        continue;
    }
    $lm = [];
    if (isset($it['line_meta']) && $it['line_meta'] !== null && $it['line_meta'] !== '') {
        $raw = $it['line_meta'];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $lm = is_array($decoded) ? $decoded : [];
        } elseif (is_array($raw)) {
            $lm = $raw;
        }
    }
    $deferredPersistedRows[] = [
        'item_type' => $t,
        'description' => (string) ($it['description'] ?? ''),
        'quantity' => $it['quantity'] ?? null,
        'unit_price' => $it['unit_price'] ?? null,
        'source_id' => $it['source_id'] ?? null,
        'line_meta' => $lm,
    ];
}
?>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li class="error"><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($invoiceAction) ?>" class="entity-form cashier-layout" id="cashier-workspace-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <?php if (!empty($invoice['appointment_id'])): ?>
    <input type="hidden" name="appointment_id" value="<?= (int) $invoice['appointment_id'] ?>">
    <?php endif; ?>
    <input type="hidden" name="branch_id" value="<?= (int) ($invoice['branch_id'] ?? 0) ?>">
    <input type="hidden" name="client_id" id="cashier-hidden-client-id" value="<?= (int) ($invoice['client_id'] ?? 0) ?>">

    <aside class="cashier-left-rail">
        <div class="cashier-card">
            <h3>Look up on Manage Sales</h3>
            <p class="hint cashier-card__lede">Goes to the invoice list with this number filled in. It does <strong>not</strong> open that invoice in this checkout screen.</p>
            <label for="order-number-search">Invoice number</label>
            <div class="cashier-inline">
                <input type="text" id="order-number-search" placeholder="e.g. INV-1001" autocomplete="off">
                <button type="button" id="order-search-btn" class="ds-btn ds-btn--secondary ds-btn--sm">Go to list</button>
            </div>
            <p class="cashier-rail-nav"><a href="/sales">Sales home (new sale)</a></p>
        </div>

        <div class="cashier-card">
            <h3>Quick Add</h3>
            <?php if (!empty($quickAdd)): ?>
            <ul class="cashier-quick-list">
                <?php foreach ($quickAdd as $qa): ?>
                <li>
                    <button type="button" data-quick-product-id="<?= (int) $qa['id'] ?>">
                        <span><?= htmlspecialchars((string) $qa['name']) ?></span>
                        <small><?= number_format((float) $qa['sell_price'], 2) ?></small>
                    </button>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="hint">Quick add is deferred until branch products are available.</p>
            <?php endif; ?>
        </div>
    </aside>

    <section class="cashier-main">
        <header class="cashier-client-banner <?= $clientSelected ? 'cashier-client-banner--has-client' : 'cashier-client-banner--walk-in' ?>">
            <div class="cashier-client-banner__main">
                <p class="cashier-client-banner__eyebrow">Client for this checkout</p>
                <p class="cashier-client-banner__status-row">
                    <?php if ($clientSelected): ?>
                    <span class="cashier-client-status cashier-client-status--linked">Client selected</span>
                    <?php else: ?>
                    <span class="cashier-client-status cashier-client-status--walk-in">No client — walk-in</span>
                    <?php endif; ?>
                </p>
                <h2 class="cashier-client-banner__name"><?= htmlspecialchars($clientLabel) ?></h2>
                <?php if ($isEdit): ?>
                <p class="cashier-client-banner__invoice-context">
                    <?php
                    $invNo = trim((string) ($invoice['invoice_number'] ?? ''));
                    $invRef = $invNo !== '' ? $invNo : ('#' . (int) ($invoice['id'] ?? 0));
                    ?>
                    Editing invoice <strong><?= htmlspecialchars($invRef) ?></strong> in this workspace.
                </p>
                <?php endif; ?>
                <?php if (!empty($invoice['appointment_id'])): ?>
                <p class="cashier-client-banner__sub hint">Appointment #<?= (int) $invoice['appointment_id'] ?> is linked to this sale.</p>
                <?php endif; ?>
            </div>
            <div class="cashier-client-banner__actions">
                <button type="button" id="toggle-client-context" class="ds-btn ds-btn--toolbar">Choose client</button>
                <div class="cashier-banner-secondary">
                    <a href="/sales/invoices" class="cashier-banner-link">Manage Sales</a>
                    <p class="cashier-banner-microhint">Leave checkout: invoice list, search, and open orders.</p>
                </div>
            </div>
        </header>
        <section class="cashier-client-context" id="cashier-client-context" hidden>
            <p class="hint cashier-client-context__lede">Pick who this sale is for. Walk-in stays at “No client”; the form still submits the same way.</p>
            <div class="form-row">
                <label for="client_id_picker">Client on this invoice</label>
                <select id="client_id_picker">
                    <option value="0">Walk-in / No appointment</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= ((int) ($invoice['client_id'] ?? 0)) === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim((string) $c['first_name'] . ' ' . (string) $c['last_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </section>

        <div class="cashier-workspace">
            <div class="cashier-workspace__head">
                <div>
                    <h2 class="cashier-workspace__title">Ordered articles</h2>
                </div>
                <button type="button" data-tab-target="tab-products" class="ds-btn ds-btn--toolbar">Browse branch sellables</button>
            </div>

            <div class="cashier-ops-strip">
                <div class="form-row">
                    <label for="scanner_input">Scanner <span class="cashier-muted-label">(not available)</span></label>
                    <input id="scanner_input" type="text" value="" readonly disabled placeholder="Not connected" title="Scanner hardware is not wired up yet.">
                </div>
                <div class="form-row">
                    <label for="source_input">Source <span class="cashier-muted-label">(not available)</span></label>
                    <input id="source_input" type="text" value="" readonly disabled placeholder="Not available" title="Source tracking is not enabled for this screen.">
                </div>
            </div>

            <div class="cashier-tab-row">
                <button type="button" class="cashier-tab is-active" data-tab-target="tab-products">Products</button>
                <button type="button" class="cashier-tab" data-tab-target="tab-services">Services</button>
                <button type="button" class="cashier-tab" data-tab-target="tab-deferred">Gift voucher / Gift card / Series</button>
                <button type="button" class="cashier-tab" data-tab-target="tab-membership">Membership</button>
                <button type="button" class="cashier-tab" data-tab-target="tab-tips">Tips</button>
            </div>

            <section id="tab-products" data-tab-panel>
                <div class="cashier-panel-toolbar">
                    <input type="text" id="product-search" placeholder="Search products">
                    <select id="product-category-filter"><option value="">All categories</option></select>
                    <select id="product-brand-filter"><option value="">All brands</option></select>
                </div>
                <table class="index-table">
                    <thead><tr><th>Product</th><th>Price</th><th>Qty</th><th></th></tr></thead>
                    <tbody id="product-list-body"></tbody>
                </table>
            </section>

            <section id="tab-services" data-tab-panel style="display:none;">
                <table class="index-table">
                    <thead><tr><th>Service</th><th>Default price</th><th>Qty</th><th></th></tr></thead>
                    <tbody id="service-list-body"></tbody>
                </table>
            </section>

            <section id="tab-deferred" data-tab-panel style="display:none;" data-cashier-deferred-root>
                <p class="hint">Pick stored-value or package sale lines. Gift cards and package series are finalized when you <strong>first save</strong> the invoice (not when editing an existing draft).</p>
                <?php if (!empty($deferredPersistedRows)): ?>
                <div class="cashier-deferred-persisted" aria-label="Persisted deferred lines on this invoice">
                    <strong>On this invoice</strong>
                    <ul>
                        <?php foreach ($deferredPersistedRows as $pr): ?>
                        <?php
                        $cd = is_array($pr['line_meta']['cashier_domain'] ?? null) ? $pr['line_meta']['cashier_domain'] : [];
                        $gcId = (int) ($cd['issued_gift_card_id'] ?? 0);
                        $cpId = (int) ($cd['client_package_id'] ?? 0);
                        $extra = [];
                        if (($pr['item_type'] ?? '') === 'gift_card' && $gcId > 0) {
                            $extra[] = 'Issued gift card #' . $gcId;
                        }
                        if (($pr['item_type'] ?? '') === 'series' && $cpId > 0) {
                            $extra[] = 'Client package #' . $cpId;
                        }
                        if (($pr['item_type'] ?? '') === 'gift_voucher' && !empty($pr['source_id'])) {
                            $extra[] = 'Linked product #' . (int) $pr['source_id'];
                        }
                        $extraS = $extra !== [] ? (' — ' . implode('; ', $extra)) : '';
                        ?>
                        <li><?= htmlspecialchars((string) ($pr['item_type'] ?? '')) ?>: <?= htmlspecialchars((string) ($pr['description'] ?? '')) ?><?= htmlspecialchars($extraS) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <div class="cashier-deferred-subtab-row" role="tablist" aria-label="Deferred sale type">
                    <button type="button" class="cashier-deferred-subtab is-active" role="tab" aria-selected="true" data-deferred-submode="gift_voucher">Gift voucher</button>
                    <button type="button" class="cashier-deferred-subtab" role="tab" aria-selected="false" data-deferred-submode="gift_card">Gift card</button>
                    <button type="button" class="cashier-deferred-subtab" role="tab" aria-selected="false" data-deferred-submode="series">Series</button>
                    <button type="button" class="cashier-deferred-subtab cashier-deferred-subtab--muted" role="tab" aria-selected="false" data-deferred-submode="client_account" title="Read-only: not available">Client account</button>
                </div>
                <div class="cashier-deferred-panels">
                    <div id="cashier-deferred-panel-gift_voucher" class="cashier-deferred-panel" data-deferred-panel="gift_voucher" role="tabpanel">
                        <div class="form-row">
                            <label for="cashier-voucher-amount">Amount <span aria-label="required">*</span></label>
                            <input type="number" id="cashier-voucher-amount" min="0.01" step="0.01" value="" required>
                        </div>
                        <div class="form-row">
                            <label for="cashier-voucher-product-select">Optional catalog product</label>
                            <select id="cashier-voucher-product-select">
                                <option value="0">— None —</option>
                                <?php foreach ($productCatalog as $pc): ?>
                                <?php if ((int) ($pc['id'] ?? 0) <= 0) {
                                    continue;
                                } ?>
                                <option value="<?= (int) $pc['id'] ?>"><?= htmlspecialchars((string) ($pc['name'] ?? '')) ?> (<?= htmlspecialchars((string) ($pc['sku'] ?? '')) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <p class="hint">Adds a gift voucher line. If you tie it to a product, that product must exist on this branch&rsquo;s sellable list.</p>
                        <button type="button" id="cashier-add-voucher" class="ds-btn ds-btn--primary ds-btn--sm">Add voucher line</button>
                    </div>
                    <div id="cashier-deferred-panel-gift_card" class="cashier-deferred-panel" data-deferred-panel="gift_card" style="display:none;" role="tabpanel">
                        <div class="form-row">
                            <label for="cashier-gift-card-amount">Face value <span aria-label="required">*</span></label>
                            <input type="number" id="cashier-gift-card-amount" min="0.01" step="0.01" value="" required>
                        </div>
                        <p class="hint">Adds a gift card line (no tax on the line). When you save a <strong>new</strong> invoice, the system issues the card and links it to this sale.</p>
                        <button type="button" id="cashier-add-gift-card" class="ds-btn ds-btn--primary ds-btn--sm">Add gift card line</button>
                    </div>
                    <div id="cashier-deferred-panel-series" class="cashier-deferred-panel" data-deferred-panel="series" style="display:none;" role="tabpanel">
                        <p id="cashier-series-client-hint" class="hint"><?= $clientId <= 0 ? 'Choose a client on this invoice before selling a package series.' : 'Client is set; you can sell a package series to them here.' ?></p>
                        <p class="hint">Package <strong>plans</strong> are defined in Catalog; this checkout sells a plan and creates the client&rsquo;s held package.</p>
                        <div class="form-row">
                            <label for="cashier-series-package-id">Package plan <span aria-label="required">*</span></label>
                            <select id="cashier-series-package-id">
                                <option value="0">— Select package —</option>
                                <?php foreach ($packages as $p): ?>
                                <option value="<?= (int) ($p['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($p['name'] ?? '')) ?> (<?= (int) ($p['total_sessions'] ?? 0) ?> sessions)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="cashier-series-sessions">Sessions to assign <span aria-label="required">*</span></label>
                            <input type="number" id="cashier-series-sessions" min="1" step="1" value="1" required>
                        </div>
                        <p class="hint">Adds a package series line. On first save, the sessions are assigned to the client on this invoice.</p>
                        <button type="button" id="cashier-add-series" class="ds-btn ds-btn--primary ds-btn--sm" <?= $clientId <= 0 ? 'disabled' : '' ?>>Add series line</button>
                    </div>
                    <div id="cashier-deferred-panel-client_account" class="cashier-deferred-panel" data-deferred-panel="client_account" style="display:none;" role="tabpanel">
                        <p class="hint"><strong>Client account</strong> is not available yet — there is no house-account balance to charge. You cannot post this line type from the till.</p>
                    </div>
                </div>
            </section>

            <section id="tab-membership" data-tab-panel style="display:none;">
                <?php if ($clientId <= 0): ?>
                <p class="hint">Select a client first to sell membership.</p>
                <?php endif; ?>
                <?php if ($isEdit): ?>
                <p class="hint">Membership launch is create-only.</p>
                <?php else: ?>
                <div class="form-row">
                    <label for="membership_definition_id">Membership Plan</label>
                    <select id="membership_definition_id" name="membership_definition_id">
                        <option value="0">- Not a membership checkout -</option>
                        <?php foreach (($membershipDefinitions ?? []) as $md): ?>
                        <option value="<?= (int) $md['id'] ?>" <?= ((int) ($invoice['membership_definition_id'] ?? 0)) === (int) $md['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) ($md['name'] ?? '')) ?> (<?= number_format((float) ($md['price'] ?? 0), 2) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="membership_starts_at">Membership start (optional YYYY-MM-DD)</label>
                    <input type="text" id="membership_starts_at" name="membership_starts_at" placeholder="YYYY-MM-DD" value="<?= htmlspecialchars((string) ($invoice['membership_starts_at'] ?? '')) ?>">
                </div>
                <p class="hint">Membership is sold from this block on a <strong>new</strong> invoice; changing plan here does not replace Catalog membership definitions.</p>
                <?php endif; ?>
            </section>

            <section id="tab-tips" data-tab-panel style="display:none;">
                <div class="cashier-panel-toolbar">
                    <input type="text" id="tip-description" value="Tip" placeholder="Tip label">
                    <input type="number" id="tip-amount" min="0" step="0.01" value="0" placeholder="Amount">
                    <button type="button" id="add-tip-line" class="ds-btn ds-btn--primary ds-btn--sm">Add tip line</button>
                </div>
            </section>
        </div>

        <div class="cashier-lines-panel<?= $cashierLinesQuiet ? ' cashier-lines-panel--quiet' : '' ?>" data-cashier-lines-panel="1">
            <h3 class="cashier-lines-panel__title">Current line items</h3>
            <table class="index-table">
                <thead><tr><th>Description</th><th>Qty</th><th>Price</th><th></th></tr></thead>
                <tbody id="invoice-lines-body">
                <?php foreach (($invoice['items'] ?? []) as $idx => $item): ?>
                <tr data-line-row>
                    <td><input type="text" name="items[<?= $idx ?>][description]" value="<?= htmlspecialchars((string) ($item['description'] ?? '')) ?>"></td>
                    <td><input type="number" name="items[<?= $idx ?>][quantity]" value="<?= htmlspecialchars((string) ($item['quantity'] ?? 1)) ?>" min="0" step="0.01"></td>
                    <td><input type="number" name="items[<?= $idx ?>][unit_price]" value="<?= htmlspecialchars((string) ($item['unit_price'] ?? 0)) ?>" min="0" step="0.01"></td>
                    <td class="cashier-line-actions"><button type="button" class="ds-btn ds-btn--ghost ds-btn--sm" data-remove-line>Remove</button></td>
                    <td hidden><input type="hidden" name="items[<?= $idx ?>][item_type]" value="<?= htmlspecialchars((string) ($item['item_type'] ?? 'manual')) ?>"></td>
                    <td hidden><input type="hidden" name="items[<?= $idx ?>][source_id]" value="<?= (int) ($item['source_id'] ?? 0) ?>"></td>
                    <td hidden><input type="hidden" name="items[<?= $idx ?>][discount_amount]" value="<?= htmlspecialchars((string) ($item['discount_amount'] ?? 0)) ?>"></td>
                    <td hidden><input type="hidden" name="items[<?= $idx ?>][tax_rate]" value="<?= htmlspecialchars((string) ($item['tax_rate'] ?? 0)) ?>"></td>
                    <td hidden><?php
                        $lmRaw = $item['line_meta'] ?? null;
                        $lmJson = '';
                        if ($lmRaw !== null && $lmRaw !== '') {
                            $lmJson = is_string($lmRaw) ? $lmRaw : json_encode($lmRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                        ?>
                    <input type="hidden" name="items[<?= $idx ?>][line_meta_json]" value="<?= htmlspecialchars($lmJson) ?>">
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="cashier-lines-panel__footer-actions"><button type="button" id="add-manual-line" class="ds-btn ds-btn--secondary ds-btn--sm">+ Add manual line</button></p>
        </div>

        <details class="cashier-totals-summary" id="cashier-totals-summary" aria-live="polite" <?= $cashierDisclosureExpandedInitially ? 'open' : '' ?>>
            <summary class="cashier-disclosure-summary cashier-totals-summary__summary">Totals <span class="cashier-disclosure-summary__muted">(preview — click to expand)</span></summary>
            <div class="cashier-totals-summary__body">
                <p class="hint cashier-totals-summary__explainer">Preview uses the same line math as save: each line is (qty × unit − line discount) × (1 + line tax %). Header fields below apply invoice discount and additional invoice tax.</p>
                <dl class="cashier-totals-grid">
                    <div class="cashier-totals-row">
                        <dt>Subtotal (lines)</dt>
                        <dd><span data-cashier-display="lines_subtotal">0.00</span></dd>
                    </div>
                    <div class="cashier-totals-row">
                        <dt>Invoice discount</dt>
                        <dd><span data-cashier-display="invoice_discount">0.00</span></dd>
                    </div>
                    <div class="cashier-totals-row">
                        <dt>Invoice tax (additional)</dt>
                        <dd><span data-cashier-display="invoice_tax">0.00</span></dd>
                    </div>
                    <div class="cashier-totals-row cashier-totals-row--emph">
                        <dt>Total</dt>
                        <dd><span data-cashier-display="invoice_total">0.00</span></dd>
                    </div>
                    <div class="cashier-totals-row"<?= $isEdit ? '' : ' hidden' ?>>
                        <dt>Paid to date</dt>
                        <dd><span data-cashier-display="paid_amount"><?= htmlspecialchars(number_format($cashierPaidBaseline, 2, '.', '')) ?></span></dd>
                    </div>
                    <div class="cashier-totals-row cashier-totals-row--emph"<?= $isEdit ? '' : ' hidden' ?>>
                        <dt>Balance due</dt>
                        <dd><span data-cashier-display="balance_due">0.00</span></dd>
                    </div>
                </dl>
            </div>
        </details>

        <details class="cashier-foot-fields" id="cashier-foot-disclosure" <?= $cashierDisclosureExpandedInitially ? 'open' : '' ?>>
            <summary class="cashier-disclosure-summary cashier-foot-fields__summary">Finalize sale <span class="cashier-disclosure-summary__muted">— discount, tax, notes &amp; save</span></summary>
            <div class="cashier-foot-fields__body">
                <div class="form-row">
                    <label for="discount_amount">Invoice discount</label>
                    <input type="number" id="discount_amount" name="discount_amount" value="<?= htmlspecialchars((string) ($invoice['discount_amount'] ?? 0)) ?>" min="0" step="0.01">
                </div>
                <div class="form-row">
                    <label for="tax_amount">Invoice tax (additional)</label>
                    <input type="number" id="tax_amount" name="tax_amount" value="<?= htmlspecialchars((string) ($invoice['tax_amount'] ?? 0)) ?>" min="0" step="0.01">
                </div>
                <div class="form-row">
                    <label for="notes">Notes</label>
                    <textarea id="notes" name="notes" rows="2"><?= htmlspecialchars((string) ($invoice['notes'] ?? '')) ?></textarea>
                </div>
                <div class="form-actions">
                    <button type="submit" class="ds-btn ds-btn--primary"><?= htmlspecialchars($submitLabel) ?></button>
                    <?php if ($isEdit): ?>
                    <a href="/sales/invoices/<?= (int) $invoice['id'] ?>" class="ds-btn ds-btn--secondary ds-btn--sm">Back to invoice</a>
                    <?php else: ?>
                    <a href="/sales/invoices" class="ds-btn ds-btn--secondary ds-btn--sm">Back to Manage Sales</a>
                    <?php endif; ?>
                </div>
            </div>
        </details>
    </section>
</form>

<script>
(function () {
    var products = <?= json_encode($productCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
    var services = <?= json_encode($serviceCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
    var packagesCatalog = <?= json_encode($packageCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
    var lineBody = document.getElementById('invoice-lines-body');
    var lineIndex = lineBody ? lineBody.querySelectorAll('tr[data-line-row]').length : 0;
    var paidBaseline = <?= json_encode($cashierPaidBaseline) ?>;
    var isEditInvoice = <?= $isEdit ? 'true' : 'false' ?>;

    function roundMoney(n) {
        if (!isFinite(n)) {
            return 0;
        }
        return Math.round((n + Number.EPSILON) * 100) / 100;
    }

    function computeLineTotalLikeServer(qty, unit, disc, taxRate) {
        var q = parseFloat(String(qty)) || 0;
        var u = parseFloat(String(unit)) || 0;
        var d = parseFloat(String(disc)) || 0;
        var t = parseFloat(String(taxRate)) || 0;
        var sub = q * u - d;
        return roundMoney(sub * (1 + t / 100));
    }

    function setCashierDisplay(key, text) {
        var el = document.querySelector('[data-cashier-display="' + key + '"]');
        if (el) {
            el.textContent = text;
        }
    }

    function refreshCashierTotals() {
        var rows = lineBody ? lineBody.querySelectorAll('tr[data-line-row]') : [];
        var lineSum = 0;
        rows.forEach(function (row) {
            var q = row.querySelector('input[name*="[quantity]"]');
            var u = row.querySelector('input[name*="[unit_price]"]');
            var d = row.querySelector('input[name*="[discount_amount]"]');
            var t = row.querySelector('input[name*="[tax_rate]"]');
            lineSum += computeLineTotalLikeServer(
                q ? q.value : '0',
                u ? u.value : '0',
                d ? d.value : '0',
                t ? t.value : '0'
            );
        });
        lineSum = roundMoney(lineSum);
        var invDisc = parseFloat(String((document.getElementById('discount_amount') || {}).value || '0')) || 0;
        var invTax = parseFloat(String((document.getElementById('tax_amount') || {}).value || '0')) || 0;
        invDisc = roundMoney(invDisc);
        invTax = roundMoney(invTax);
        var total = roundMoney(lineSum - invDisc + invTax);
        setCashierDisplay('lines_subtotal', lineSum.toFixed(2));
        setCashierDisplay('invoice_discount', invDisc.toFixed(2));
        setCashierDisplay('invoice_tax', invTax.toFixed(2));
        setCashierDisplay('invoice_total', total.toFixed(2));
        if (isEditInvoice) {
            var paid = roundMoney(parseFloat(String(paidBaseline)) || 0);
            setCashierDisplay('paid_amount', paid.toFixed(2));
            setCashierDisplay('balance_due', roundMoney(total - paid).toFixed(2));
        }
    }

    function rowIsMeaningful(row) {
        var d = row.querySelector('input[name*="[description]"]');
        var q = row.querySelector('input[name*="[quantity]"]');
        var u = row.querySelector('input[name*="[unit_price]"]');
        var desc = String(d && d.value ? d.value : '').trim();
        var qty = parseFloat(String(q && q.value ? q.value : '0'));
        var price = parseFloat(String(u && u.value ? u.value : '0'));
        if (!isFinite(qty)) qty = 0;
        if (!isFinite(price)) price = 0;
        return (desc !== '' && (qty > 0 || price > 0)) || price > 0;
    }

    function hasAnyMeaningfulLine() {
        if (!lineBody) return false;
        var rows = lineBody.querySelectorAll('tr[data-line-row]');
        for (var i = 0; i < rows.length; i++) {
            if (rowIsMeaningful(rows[i])) return true;
        }
        return false;
    }

    function syncCashierProgressiveDisclosure() {
        if (isEditInvoice) return;
        var lp = document.querySelector('.cashier-lines-panel[data-cashier-lines-panel]');
        if (hasAnyMeaningfulLine()) {
            if (lp) lp.classList.remove('cashier-lines-panel--quiet');
            var totalsD = document.getElementById('cashier-totals-summary');
            var footD = document.getElementById('cashier-foot-disclosure');
            if (totalsD && totalsD.tagName === 'DETAILS' && !totalsD.open) {
                totalsD.open = true;
            }
            if (footD && !footD.open) {
                footD.open = true;
            }
        } else if (lp) {
            lp.classList.add('cashier-lines-panel--quiet');
        }
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = String(text || '');
        return div.innerHTML;
    }

    function reindexRows() {
        var rows = lineBody ? lineBody.querySelectorAll('tr[data-line-row]') : [];
        rows.forEach(function (row, idx) {
            row.querySelectorAll('input, select').forEach(function (el) {
                if (!el.name) return;
                el.name = el.name.replace(/items\[\d+\]/, 'items[' + idx + ']');
            });
        });
        lineIndex = rows.length;
    }

    function appendLine(line) {
        if (!lineBody) return;
        var tr = document.createElement('tr');
        tr.setAttribute('data-line-row', '1');
        tr.innerHTML =
            '<td><input type="text" name="items[' + lineIndex + '][description]"></td>' +
            '<td><input type="number" name="items[' + lineIndex + '][quantity]" min="0" step="0.01"></td>' +
            '<td><input type="number" name="items[' + lineIndex + '][unit_price]" min="0" step="0.01"></td>' +
            '<td class="cashier-line-actions"><button type="button" class="ds-btn ds-btn--ghost ds-btn--sm" data-remove-line>Remove</button></td>' +
            '<td hidden><input type="hidden" name="items[' + lineIndex + '][item_type]"></td>' +
            '<td hidden><input type="hidden" name="items[' + lineIndex + '][source_id]"></td>' +
            '<td hidden><input type="hidden" name="items[' + lineIndex + '][discount_amount]"></td>' +
            '<td hidden><input type="hidden" name="items[' + lineIndex + '][tax_rate]"></td>' +
            '<td hidden><input type="hidden" name="items[' + lineIndex + '][line_meta_json]" value=""></td>';
        tr.querySelector('input[name*="[item_type]"]').value = line.item_type || 'manual';
        tr.querySelector('input[name*="[description]"]').value = line.description || '';
        tr.querySelector('input[name*="[quantity]"]').value = line.quantity != null ? String(line.quantity) : '1';
        tr.querySelector('input[name*="[unit_price]"]').value = line.unit_price != null ? String(line.unit_price) : '0';
        tr.querySelector('input[name*="[discount_amount]"]').value = line.discount_amount != null ? String(line.discount_amount) : '0';
        tr.querySelector('input[name*="[tax_rate]"]').value = line.tax_rate != null ? String(line.tax_rate) : '0';
        tr.querySelector('input[name*="[source_id]"]').value = line.source_id != null ? String(line.source_id) : '0';
        var metaIn = tr.querySelector('input[name*="[line_meta_json]"]');
        if (metaIn && line.line_meta_json) {
            metaIn.value = line.line_meta_json;
        }
        lineBody.appendChild(tr);
        lineIndex += 1;
        refreshCashierTotals();
        syncCashierProgressiveDisclosure();
    }

    function bindRemoveActions() {
        document.querySelectorAll('[data-remove-line]').forEach(function (btn) {
            btn.onclick = function () {
                var row = btn.closest('tr[data-line-row]');
                if (!row) return;
                row.remove();
                reindexRows();
                refreshCashierTotals();
                syncCashierProgressiveDisclosure();
            };
        });
    }

    function renderProductFilters() {
        var categoryEl = document.getElementById('product-category-filter');
        var brandEl = document.getElementById('product-brand-filter');
        if (!categoryEl || !brandEl) return;
        var categories = {};
        var brands = {};
        products.forEach(function (p) {
            if (p.category) categories[p.category] = true;
            if (p.brand) brands[p.brand] = true;
        });
        Object.keys(categories).sort().forEach(function (name) {
            var opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            categoryEl.appendChild(opt);
        });
        Object.keys(brands).sort().forEach(function (name) {
            var opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            brandEl.appendChild(opt);
        });
    }

    function renderProductList() {
        var tbody = document.getElementById('product-list-body');
        if (!tbody) return;
        var q = (document.getElementById('product-search') || {}).value || '';
        q = q.toLowerCase();
        var category = (document.getElementById('product-category-filter') || {}).value || '';
        var brand = (document.getElementById('product-brand-filter') || {}).value || '';
        tbody.innerHTML = '';
        var filtered = products.filter(function (p) {
            if (category && p.category !== category) return false;
            if (brand && p.brand !== brand) return false;
            if (!q) return true;
            return (p.name + ' ' + p.sku + ' ' + p.category + ' ' + p.brand).toLowerCase().indexOf(q) >= 0;
        });
        if (filtered.length === 0) {
            var empty = document.createElement('tr');
            empty.innerHTML = '<td colspan="4"><span class="hint">No matching products for current branch catalog.</span></td>';
            tbody.appendChild(empty);
            return;
        }
        filtered.forEach(function (p) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + escapeHtml(p.name || '') + '</td>' +
                '<td>' + Number(p.sell_price || 0).toFixed(2) + '</td>' +
                '<td><input type="number" min="0.01" step="0.01" value="1" data-product-qty="' + Number(p.id) + '"></td>' +
                '<td><button type="button" class="ds-btn ds-btn--primary ds-btn--sm" data-add-product="' + Number(p.id) + '">Add</button></td>';
            tbody.appendChild(tr);
        });
        tbody.querySelectorAll('[data-add-product]').forEach(function (btn) {
            btn.onclick = function () {
                var id = Number(btn.getAttribute('data-add-product') || '0');
                var product = products.find(function (p) { return Number(p.id) === id; });
                if (!product) return;
                var qtyInput = tbody.querySelector('[data-product-qty="' + id + '"]');
                var qty = Number(qtyInput ? qtyInput.value : '1');
                if (!isFinite(qty) || qty <= 0) qty = 1;
                appendLine({
                    item_type: 'product',
                    source_id: product.id,
                    description: product.name || 'Product',
                    quantity: qty,
                    unit_price: Number(product.sell_price || 0).toFixed(2),
                    discount_amount: 0,
                    tax_rate: 0
                });
                bindRemoveActions();
            };
        });
    }

    function renderServiceList() {
        var tbody = document.getElementById('service-list-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (services.length === 0) {
            var empty = document.createElement('tr');
            empty.innerHTML = '<td colspan="4"><span class="hint">No active services for current branch.</span></td>';
            tbody.appendChild(empty);
            return;
        }
        services.forEach(function (s) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + escapeHtml(s.name || '') + '</td>' +
                '<td>' + Number(s.price || 0).toFixed(2) + '</td>' +
                '<td><input type="number" min="0.01" step="0.01" value="1" data-service-qty="' + Number(s.id) + '"></td>' +
                '<td><button type="button" class="ds-btn ds-btn--primary ds-btn--sm" data-add-service="' + Number(s.id) + '">Add</button></td>';
            tbody.appendChild(tr);
        });
        tbody.querySelectorAll('[data-add-service]').forEach(function (btn) {
            btn.onclick = function () {
                var id = Number(btn.getAttribute('data-add-service') || '0');
                var service = services.find(function (s) { return Number(s.id) === id; });
                if (!service) return;
                var qtyInput = tbody.querySelector('[data-service-qty="' + id + '"]');
                var qty = Number(qtyInput ? qtyInput.value : '1');
                if (!isFinite(qty) || qty <= 0) qty = 1;
                appendLine({
                    item_type: 'service',
                    source_id: service.id,
                    description: service.name || 'Service',
                    quantity: qty,
                    unit_price: Number(service.price || 0).toFixed(2),
                    discount_amount: 0,
                    tax_rate: 0
                });
                bindRemoveActions();
            };
        });
    }

    function setupTabs() {
        var buttons = document.querySelectorAll('[data-tab-target]');
        var panels = document.querySelectorAll('[data-tab-panel]');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-tab-target');
                panels.forEach(function (panel) {
                    panel.style.display = panel.id === target ? '' : 'none';
                });
                document.querySelectorAll('.cashier-tab').forEach(function (tabBtn) {
                    tabBtn.classList.toggle('is-active', tabBtn.getAttribute('data-tab-target') === target);
                });
            });
        });
    }

    function showDeferredSubmode(mode) {
        var root = document.querySelector('[data-cashier-deferred-root]');
        if (!root || !mode) return;
        root.querySelectorAll('[data-deferred-panel]').forEach(function (p) {
            p.style.display = p.getAttribute('data-deferred-panel') === mode ? '' : 'none';
        });
        root.querySelectorAll('[data-deferred-submode]').forEach(function (b) {
            var on = b.getAttribute('data-deferred-submode') === mode;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    function setupDeferredSubmodes() {
        var root = document.querySelector('[data-cashier-deferred-root]');
        if (!root) return;
        root.querySelectorAll('[data-deferred-submode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showDeferredSubmode(btn.getAttribute('data-deferred-submode') || 'gift_voucher');
            });
        });
        showDeferredSubmode('gift_voucher');
    }

    function syncDeferredClientState() {
        var hid = document.getElementById('cashier-hidden-client-id');
        var cid = hid ? parseInt(String(hid.value || '0'), 10) : 0;
        if (!isFinite(cid)) cid = 0;
        var hint = document.getElementById('cashier-series-client-hint');
        var btn = document.getElementById('cashier-add-series');
        if (hint) {
            hint.textContent = cid <= 0
                ? 'Choose a client on this invoice before selling a package series.'
                : 'Client is set; you can sell a package series to them here.';
        }
        if (btn) {
            btn.disabled = cid <= 0;
        }
    }

    function syncClientBannerVisual() {
        var hid = document.getElementById('cashier-hidden-client-id');
        var cid = hid ? parseInt(String(hid.value || '0'), 10) : 0;
        if (!isFinite(cid)) cid = 0;
        var banner = document.querySelector('.cashier-client-banner');
        var statusEl = document.querySelector('.cashier-client-status');
        var nameEl = document.querySelector('.cashier-client-banner__name');
        var picker = document.getElementById('client_id_picker');
        if (!banner || !statusEl || !nameEl || !picker) return;
        banner.classList.toggle('cashier-client-banner--has-client', cid > 0);
        banner.classList.toggle('cashier-client-banner--walk-in', cid <= 0);
        if (cid <= 0) {
            statusEl.textContent = 'No client — walk-in';
            statusEl.className = 'cashier-client-status cashier-client-status--walk-in';
            nameEl.textContent = 'Walk-in / No appointment';
        } else {
            statusEl.textContent = 'Client selected';
            statusEl.className = 'cashier-client-status cashier-client-status--linked';
            var pv = parseInt(String(picker.value || '0'), 10);
            if (pv === cid) {
                var opt = picker.options[picker.selectedIndex];
                nameEl.textContent = opt && opt.textContent ? String(opt.textContent).trim() : ('Client #' + cid);
            }
        }
    }

    document.getElementById('order-search-btn')?.addEventListener('click', function () {
        var value = ((document.getElementById('order-number-search') || {}).value || '').trim();
        window.location.href = value ? '/sales/invoices?invoice_number=' + encodeURIComponent(value) : '/sales/invoices';
    });
    document.getElementById('product-search')?.addEventListener('input', renderProductList);
    document.getElementById('product-category-filter')?.addEventListener('change', renderProductList);
    document.getElementById('product-brand-filter')?.addEventListener('change', renderProductList);
    document.getElementById('add-manual-line')?.addEventListener('click', function () {
        appendLine({ item_type: 'manual', source_id: 0, description: '', quantity: 1, unit_price: 0, discount_amount: 0, tax_rate: 0 });
        bindRemoveActions();
    });
    document.getElementById('add-tip-line')?.addEventListener('click', function () {
        var desc = ((document.getElementById('tip-description') || {}).value || 'Tip');
        var amount = Number(((document.getElementById('tip-amount') || {}).value || '0'));
        if (!isFinite(amount) || amount <= 0) return;
        appendLine({
            item_type: 'tip',
            source_id: 0,
            description: desc,
            quantity: 1,
            unit_price: amount.toFixed(2),
            discount_amount: 0,
            tax_rate: 0
        });
        bindRemoveActions();
    });
    document.getElementById('cashier-add-gift-card')?.addEventListener('click', function () {
        var amount = Number(((document.getElementById('cashier-gift-card-amount') || {}).value || '0'));
        if (!isFinite(amount) || amount <= 0) return;
        appendLine({
            item_type: 'gift_card',
            source_id: 0,
            description: 'Gift card',
            quantity: 1,
            unit_price: amount.toFixed(2),
            discount_amount: 0,
            tax_rate: 0
        });
        bindRemoveActions();
    });
    document.getElementById('cashier-add-voucher')?.addEventListener('click', function () {
        var amount = Number(((document.getElementById('cashier-voucher-amount') || {}).value || '0'));
        var sel = document.getElementById('cashier-voucher-product-select');
        var pid = sel ? parseInt(String(sel.value || '0'), 10) : 0;
        if (!isFinite(amount) || amount <= 0) return;
        appendLine({
            item_type: 'gift_voucher',
            source_id: pid > 0 ? pid : 0,
            description: 'Gift voucher',
            quantity: 1,
            unit_price: amount.toFixed(2),
            discount_amount: 0,
            tax_rate: 0
        });
        bindRemoveActions();
    });
    document.getElementById('cashier-add-series')?.addEventListener('click', function () {
        var hid = document.getElementById('cashier-hidden-client-id');
        var cid = hid ? parseInt(String(hid.value || '0'), 10) : 0;
        if (!isFinite(cid) || cid <= 0) return;
        var pkgId = Number(((document.getElementById('cashier-series-package-id') || {}).value || '0'));
        var sessions = Number(((document.getElementById('cashier-series-sessions') || {}).value || '0'));
        if (pkgId <= 0 || !isFinite(sessions) || sessions < 1) return;
        var pkg = packagesCatalog.find(function (p) { return Number(p.id) === pkgId; });
        var price = pkg && isFinite(Number(pkg.price)) ? Number(pkg.price) : 0;
        appendLine({
            item_type: 'series',
            source_id: pkgId,
            description: pkg ? (pkg.name || 'Series') : 'Series',
            quantity: sessions,
            unit_price: price.toFixed(2),
            discount_amount: 0,
            tax_rate: 0
        });
        bindRemoveActions();
    });
    document.querySelectorAll('[data-quick-product-id]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = Number(btn.getAttribute('data-quick-product-id') || '0');
            var product = products.find(function (p) { return Number(p.id) === id; });
            if (!product) return;
            appendLine({
                item_type: 'product',
                source_id: product.id,
                description: product.name || 'Product',
                quantity: 1,
                unit_price: Number(product.sell_price || 0).toFixed(2),
                discount_amount: 0,
                tax_rate: 0
            });
            bindRemoveActions();
        });
    });
    document.getElementById('toggle-client-context')?.addEventListener('click', function () {
        var panel = document.getElementById('cashier-client-context');
        if (!panel) return;
        panel.hidden = !panel.hidden;
    });
    document.getElementById('client_id_picker')?.addEventListener('change', function (event) {
        var picker = event.target;
        if (!picker) return;
        var hiddenClient = document.getElementById('cashier-hidden-client-id');
        if (hiddenClient) {
            hiddenClient.value = picker.value || '0';
        }
        syncDeferredClientState();
        syncClientBannerVisual();
    });

    if (lineBody) {
        lineBody.addEventListener('input', function () {
            refreshCashierTotals();
            syncCashierProgressiveDisclosure();
        });
    }
    document.getElementById('discount_amount')?.addEventListener('input', refreshCashierTotals);
    document.getElementById('tax_amount')?.addEventListener('input', refreshCashierTotals);

    setupTabs();
    setupDeferredSubmodes();
    syncDeferredClientState();
    syncClientBannerVisual();
    bindRemoveActions();
    renderProductFilters();
    renderProductList();
    renderServiceList();
    refreshCashierTotals();
    syncCashierProgressiveDisclosure();
})();
</script>
