<?php
/** @var int $clientId */
/** @var string $salesTabBasePath */
/** @var array{invoice: string, date_from: string, date_to: string, page: int, per_page: int, total: int, total_pages: int} $salesInvoiceFilters */
/** @var list<array<string, mixed>> $salesInvoicePageRows */
/** @var list<array<string, mixed>> $salesProductLines */
/** @var bool $canCreateSale */
/** @var string $salesNewInvoiceUrl */

$sf = $salesInvoiceFilters;
$buildSalesUrl = static function (string $base, array $sf, array $extra): string {
    $q = array_filter([
        'sales_invoice' => $sf['invoice'] !== '' ? $sf['invoice'] : null,
        'sales_date_from' => $sf['date_from'] !== '' ? $sf['date_from'] : null,
        'sales_date_to' => $sf['date_to'] !== '' ? $sf['date_to'] : null,
        'sales_per_page' => $sf['per_page'],
    ], static fn ($v) => $v !== null && $v !== '');
    $q = array_merge($q, $extra);
    $qs = http_build_query($q);

    return $qs !== '' ? $base . '?' . $qs : $base;
};
$hasFilters = $sf['invoice'] !== '' || $sf['date_from'] !== '' || $sf['date_to'] !== '';
?>
            <div class="client-ref-tab-workspace client-ref-sales-workspace">
                <header class="client-ref-tab-workspace__head client-ref-sales-workspace__head">
                    <div>
                        <h2 class="client-ref-tab-workspace__title" id="client-sales-heading">Sales</h2>
                        <p class="client-ref-tab-workspace__lede">Orders and invoices for this client. Search runs in the database with tenant-safe filters; use <strong>Sales &rarr; Invoices</strong> for organisation-wide lists and staff workflows.</p>
                    </div>
                    <?php if ($canCreateSale): ?>
                    <a class="client-ref-tab-workspace__cta client-ref-sales-workspace__add-order" href="<?= htmlspecialchars($salesNewInvoiceUrl) ?>">Add order</a>
                    <?php endif; ?>
                </header>

                <div class="client-ref-tab-workspace__filter-card client-ref-sales-workspace__criteria">
                    <div class="client-ref-sales-workspace__criteria-head">
                        <h3 class="client-ref-sales-workspace__criteria-title">Search criteria</h3>
                    </div>
                    <form class="client-ref-tab-workspace__filter-form" method="get" action="<?= htmlspecialchars($salesTabBasePath) ?>" aria-label="Sales search criteria">
                        <input type="hidden" name="sales_page" value="1">
                        <div class="client-ref-tab-workspace__filter-grid client-ref-sales-workspace__criteria-grid">
                            <div class="client-ref-tab-workspace__field client-ref-sales-workspace__field--order">
                                <label for="sales_invoice">Order number</label>
                                <input type="text" id="sales_invoice" name="sales_invoice" value="<?= htmlspecialchars($sf['invoice']) ?>" placeholder="Contains invoice number&hellip;" maxlength="80" autocomplete="off" aria-describedby="sales-invoice-hint">
                                <span id="sales-invoice-hint" class="client-ref-sales-workspace__field-hint">Matches the invoice number stored with each sale.</span>
                            </div>
                            <div class="client-ref-tab-workspace__field">
                                <label for="sales_date_from">Date from</label>
                                <input type="date" id="sales_date_from" name="sales_date_from" value="<?= htmlspecialchars($sf['date_from']) ?>">
                            </div>
                            <div class="client-ref-tab-workspace__field">
                                <label for="sales_date_to">Date to</label>
                                <input type="date" id="sales_date_to" name="sales_date_to" value="<?= htmlspecialchars($sf['date_to']) ?>">
                            </div>
                            <div class="client-ref-tab-workspace__field">
                                <label for="sales_per_page">Results per page</label>
                                <select id="sales_per_page" name="sales_per_page">
                                    <?php foreach ([10, 15, 25, 50] as $pp): ?>
                                    <option value="<?= $pp ?>"<?= $sf['per_page'] === $pp ? ' selected' : '' ?>><?= $pp ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="client-ref-tab-workspace__filter-actions client-ref-sales-workspace__criteria-actions">
                            <a class="client-ref-tab-workspace__btn client-ref-tab-workspace__btn--ghost" href="<?= htmlspecialchars($salesTabBasePath) ?>">Reset</a>
                            <button type="submit" class="client-ref-tab-workspace__btn client-ref-tab-workspace__btn--primary">Search</button>
                        </div>
                    </form>
                </div>

                <div class="client-ref-tab-workspace__panel client-ref-sales-workspace__results-panel">
                    <?php if ($sf['total'] === 0): ?>
                    <div class="client-ref-tab-workspace__empty client-ref-sales-workspace__empty-results" role="status">
                        <p class="client-ref-tab-workspace__empty-title"><?= $hasFilters ? 'No matching orders' : 'No orders yet' ?></p>
                        <p class="client-ref-tab-workspace__empty-text"><?= $hasFilters
                            ? 'Adjust the criteria above or reset to show all loaded invoices.'
                            : 'When you create invoices for this client, they will appear in this list.' ?></p>
                        <?php if ($canCreateSale && !$hasFilters): ?>
                        <p class="client-ref-tab-workspace__empty-cta"><a class="client-ref-tab-workspace__cta client-ref-tab-workspace__cta--inline" href="<?= htmlspecialchars($salesNewInvoiceUrl) ?>">Add order</a></p>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="client-ref-sales-workspace__results-bar" aria-live="polite">
                        <span class="client-ref-sales-workspace__results-label">Results</span>
                        <span class="client-ref-sales-workspace__results-count"><?= (int) $sf['total'] ?> invoice<?= (int) $sf['total'] === 1 ? '' : 's' ?></span>
                    </div>
                    <div class="client-ref-tab-workspace__table-wrap">
                        <table class="client-ref-tab-workspace__table">
                            <thead>
                                <tr>
                                    <th scope="col">Invoice</th>
                                    <th scope="col">Total</th>
                                    <th scope="col">Paid</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Created</th>
                                    <th scope="col" class="client-ref-tab-workspace__col-action"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($salesInvoicePageRows as $inv): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($inv['invoice_number'] ?? '')) ?></td>
                                    <td><?= number_format((float) ($inv['total_amount'] ?? 0), 2) ?></td>
                                    <td><?= number_format((float) ($inv['paid_amount'] ?? 0), 2) ?></td>
                                    <td><?= htmlspecialchars((string) ($inv['status'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($inv['created_at'] ?? '')) ?></td>
                                    <td class="client-ref-tab-workspace__col-action"><a class="client-ref-tab-workspace__link" href="/sales/invoices/<?= (int) ($inv['id'] ?? 0) ?>">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($sf['total_pages'] > 1): ?>
                    <nav class="client-ref-tab-workspace__pagination" aria-label="Sales pagination">
                        <?php if ($sf['page'] > 1): ?>
                        <a class="client-ref-tab-workspace__page-link" href="<?= htmlspecialchars($buildSalesUrl($salesTabBasePath, $sf, ['sales_page' => $sf['page'] - 1])) ?>">Previous</a>
                        <?php endif; ?>
                        <span class="client-ref-tab-workspace__page-meta">Page <?= (int) $sf['page'] ?> / <?= (int) $sf['total_pages'] ?> &middot; <?= (int) $sf['total'] ?> total</span>
                        <?php if ($sf['page'] < $sf['total_pages']): ?>
                        <a class="client-ref-tab-workspace__page-link" href="<?= htmlspecialchars($buildSalesUrl($salesTabBasePath, $sf, ['sales_page' => $sf['page'] + 1])) ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if (!empty($salesProductLines)): ?>
                <section class="client-ref-sales-workspace__products" aria-labelledby="client-sales-products-heading">
                    <h3 id="client-sales-products-heading" class="client-ref-tab-workspace__subhead">Recent product lines</h3>
                    <p class="client-ref-tab-workspace__muted">Retail product rows from recent invoices (read-only).</p>
                    <div class="client-ref-tab-workspace__table-wrap">
                        <table class="client-ref-tab-workspace__table">
                            <thead>
                                <tr>
                                    <th scope="col">Product</th>
                                    <th scope="col">Qty</th>
                                    <th scope="col">Line total</th>
                                    <th scope="col">Invoice</th>
                                    <th scope="col" class="client-ref-tab-workspace__col-action"></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($salesProductLines as $line): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($line['product_name'] ?? $line['description'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($line['quantity'] ?? '')) ?></td>
                                    <td><?= number_format((float) ($line['line_total'] ?? 0), 2) ?> <?= htmlspecialchars((string) ($line['currency'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($line['invoice_number'] ?? '')) ?></td>
                                    <td class="client-ref-tab-workspace__col-action"><a class="client-ref-tab-workspace__link" href="/sales/invoices/<?= (int) ($line['invoice_id'] ?? 0) ?>">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>
            </div>
