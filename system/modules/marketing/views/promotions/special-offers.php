<?php

declare(strict_types=1);

$title = $title ?? 'Special Offers';
$mainClass = 'marketing-promotions-special-offers-page';
$marketingTopActive = 'promotions';
$items = is_array($items ?? null) ? $items : [];
$storageReady = !empty($storageReady ?? false);
$filters = is_array($filters ?? null) ? $filters : ['name' => '', 'code' => '', 'origin' => '', 'adjustment_type' => '', 'offer_option' => ''];
$editingOffer = is_array($editingOffer ?? null) ? $editingOffer : null;
$resultCount = count($items);
$csrfName = (string) config('app.csrf_token_name', 'csrf_token');
$specialOffersAdminOnlyNotice = trim((string) ($specialOffersAdminOnlyNotice ?? ''));
ob_start();
?>
<div class="marketing-module">
    <?php require base_path('modules/marketing/views/partials/marketing-top-nav.php'); ?>

    <div class="marketing-module__body marketing-module__body--single">
        <div class="marketing-module__workspace">
            <?php if (!empty($flash) && is_array($flash)): $type = (string) array_key_first($flash); ?>
            <div class="flash flash-<?= htmlspecialchars($type) ?>"><?= htmlspecialchars((string) ($flash[$type] ?? '')) ?></div>
            <?php endif; ?>

            <div class="entity-form">
                <h2 style="margin-top:0;">Special Offers</h2>
                <?php if ($storageReady && $specialOffersAdminOnlyNotice !== ''): ?>
                <div role="status" style="border-left:4px solid #b45309;background:#fffbeb;padding:10px 12px;max-width:1050px;margin-bottom:12px;">
                    <strong>Admin catalog only — not wired to live pricing.</strong>
                    <?= htmlspecialchars($specialOffersAdminOnlyNotice) ?>
                    The “active” column is a legacy DB flag; activation is blocked in code (repository + service) until a checkout/booking/invoice consumer exists — not wired.
                </div>
                <?php endif; ?>
                <?php if (!$storageReady): ?>
                <p class="hint">Special offers storage is not initialized yet. Apply migration 106 first.</p>
                <?php else: ?>

                <div style="font-weight:600;background:#efefef;padding:4px 8px;border:1px solid #ddd;border-bottom:none;max-width:1050px;">Search Criteria</div>
                <form method="get" action="/marketing/promotions/special-offers" class="marketing-toolbar" role="search" style="display:grid;grid-template-columns:2fr 2fr 1fr 1fr 1fr auto auto;gap:8px;align-items:end;max-width:1050px;border:1px solid #ddd;padding:8px;">
                    <label class="marketing-toolbar__field">
                        <span class="marketing-toolbar__label">Name</span>
                        <input type="text" name="name" placeholder="Name" value="<?= htmlspecialchars((string) ($filters['name'] ?? '')) ?>">
                    </label>
                    <label class="marketing-toolbar__field">
                        <span class="marketing-toolbar__label">Code</span>
                        <input type="text" name="code" placeholder="Code" value="<?= htmlspecialchars((string) ($filters['code'] ?? '')) ?>">
                    </label>
                    <label class="marketing-toolbar__field">
                        <span class="marketing-toolbar__label">Origin</span>
                        <select name="origin">
                            <?php $fOrigin = (string) ($filters['origin'] ?? ''); ?>
                            <option value=""<?= $fOrigin === '' ? ' selected' : '' ?>>All</option>
                            <option value="manual"<?= $fOrigin === 'manual' ? ' selected' : '' ?>>Manual</option>
                            <option value="auto"<?= $fOrigin === 'auto' ? ' selected' : '' ?>>Business</option>
                        </select>
                    </label>
                    <label class="marketing-toolbar__field">
                        <span class="marketing-toolbar__label">Adjustment Type</span>
                        <?php $fAdj = (string) ($filters['adjustment_type'] ?? ''); ?>
                        <select name="adjustment">
                            <option value=""<?= $fAdj === '' ? ' selected' : '' ?>>All</option>
                            <option value="percent"<?= $fAdj === 'percent' ? ' selected' : '' ?>>Percent</option>
                            <option value="fixed"<?= $fAdj === 'fixed' ? ' selected' : '' ?>>Fixed Amount</option>
                        </select>
                    </label>
                    <label class="marketing-toolbar__field">
                        <span class="marketing-toolbar__label">Options</span>
                        <?php $fOpt = (string) ($filters['offer_option'] ?? ''); ?>
                        <select name="options">
                            <option value=""<?= $fOpt === '' ? ' selected' : '' ?>>All</option>
                            <option value="hide_from_customer"<?= $fOpt === 'hide_from_customer' ? ' selected' : '' ?>>Hide from Customer</option>
                            <option value="internal_only"<?= $fOpt === 'internal_only' ? ' selected' : '' ?>>Internal Use Only</option>
                        </select>
                    </label>
                    <a href="/marketing/promotions/special-offers" class="marketing-btn marketing-btn--secondary">Reset</a>
                    <button type="submit" class="marketing-btn marketing-btn--primary">Search</button>
                </form>

                <div style="display:flex;align-items:center;gap:8px;margin:12px 0;max-width:1050px;">
                    <button type="button" id="special-offer-toggle-create" class="marketing-btn marketing-btn--primary">New Special Offer</button>
                    <span class="hint">Reorganize Special Offers is not available yet (ordering is not editable in this screen).</span>
                </div>

                <form id="special-offer-create-form" method="post" action="/marketing/promotions/special-offers" style="display:none;grid-template-columns:repeat(8,minmax(120px,1fr));gap:8px;align-items:end;margin:12px 0;max-width:1200px;">
                    <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                    <label>
                        <span class="marketing-toolbar__label">Name</span>
                        <input type="text" name="name" maxlength="160" required>
                    </label>
                    <label>
                        <span class="marketing-toolbar__label">Code</span>
                        <input type="text" name="code" maxlength="60" required>
                    </label>
                    <label>
                        <span class="marketing-toolbar__label">Origin</span>
                        <select name="origin">
                            <option value="manual">Manual</option>
                            <option value="auto">Business</option>
                        </select>
                    </label>
                    <label>
                        <span class="marketing-toolbar__label">Adjustment Type</span>
                        <select name="adjustment_type">
                            <option value="percent">Percent</option>
                            <option value="fixed">Fixed Amount</option>
                        </select>
                    </label>
                    <label>
                        <span class="marketing-toolbar__label">Adjustment Value</span>
                        <input type="number" step="0.01" min="0.01" name="adjustment_value" required>
                    </label>
                    <label>
                        <span class="marketing-toolbar__label">Options</span>
                        <select name="offer_option">
                            <option value="all">All</option>
                            <option value="hide_from_customer">Hide from Customer</option>
                            <option value="internal_only">Internal Use Only</option>
                        </select>
                    </label>
                    <label>
                        <span class="marketing-toolbar__label">Start Date</span>
                        <input type="date" name="start_date">
                    </label>
                    <label>
                        <span class="marketing-toolbar__label">End Date</span>
                        <input type="date" name="end_date">
                    </label>
                    <button type="submit" class="marketing-btn marketing-btn--primary">Save Offer</button>
                </form>

                <?php if ($editingOffer !== null): ?>
                    <form method="post" action="/marketing/promotions/special-offers/<?= (int) ($editingOffer['id'] ?? 0) ?>" style="display:grid;grid-template-columns:repeat(8,minmax(120px,1fr));gap:8px;align-items:end;margin:12px 0;max-width:1200px;padding:10px;border:1px solid #ddd;background:#fafafa;">
                        <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                        <div style="grid-column:1 / -1;font-weight:600;">Edit Special Offer #<?= (int) ($editingOffer['id'] ?? 0) ?></div>
                        <label>
                            <span class="marketing-toolbar__label">Name</span>
                            <input type="text" name="name" maxlength="160" required value="<?= htmlspecialchars((string) ($editingOffer['name'] ?? '')) ?>">
                        </label>
                        <label>
                            <span class="marketing-toolbar__label">Code</span>
                            <input type="text" name="code" maxlength="60" required value="<?= htmlspecialchars((string) ($editingOffer['code'] ?? '')) ?>">
                        </label>
                        <label>
                            <span class="marketing-toolbar__label">Origin</span>
                            <?php $eOrigin = (string) ($editingOffer['origin'] ?? 'manual'); ?>
                            <select name="origin">
                                <option value="manual"<?= $eOrigin === 'manual' ? ' selected' : '' ?>>Manual</option>
                                <option value="auto"<?= $eOrigin === 'auto' ? ' selected' : '' ?>>Business</option>
                            </select>
                        </label>
                        <label>
                            <span class="marketing-toolbar__label">Adjustment Type</span>
                            <?php $eAdj = (string) ($editingOffer['adjustment_type'] ?? 'percent'); ?>
                            <select name="adjustment_type">
                                <option value="percent"<?= $eAdj === 'percent' ? ' selected' : '' ?>>Percent</option>
                                <option value="fixed"<?= $eAdj === 'fixed' ? ' selected' : '' ?>>Fixed Amount</option>
                            </select>
                        </label>
                        <label>
                            <span class="marketing-toolbar__label">Adjustment Value</span>
                            <input type="number" step="0.01" min="0.01" name="adjustment_value" required value="<?= htmlspecialchars((string) ($editingOffer['adjustment_value'] ?? '0.00')) ?>">
                        </label>
                        <label>
                            <span class="marketing-toolbar__label">Options</span>
                            <?php $eOpt = (string) ($editingOffer['offer_option'] ?? 'all'); ?>
                            <select name="offer_option">
                                <option value="all"<?= $eOpt === 'all' ? ' selected' : '' ?>>All</option>
                                <option value="hide_from_customer"<?= $eOpt === 'hide_from_customer' ? ' selected' : '' ?>>Hide from Customer</option>
                                <option value="internal_only"<?= $eOpt === 'internal_only' ? ' selected' : '' ?>>Internal Use Only</option>
                            </select>
                        </label>
                        <label>
                            <span class="marketing-toolbar__label">Start Date</span>
                            <input type="date" name="start_date" value="<?= htmlspecialchars((string) ($editingOffer['start_date'] ?? '')) ?>">
                        </label>
                        <label>
                            <span class="marketing-toolbar__label">End Date</span>
                            <input type="date" name="end_date" value="<?= htmlspecialchars((string) ($editingOffer['end_date'] ?? '')) ?>">
                        </label>
                        <div style="grid-column:1 / -1;display:flex;gap:8px;">
                            <button type="submit" class="marketing-btn marketing-btn--primary">Update Offer</button>
                            <a class="marketing-btn marketing-btn--secondary" href="/marketing/promotions/special-offers">Cancel Edit</a>
                        </div>
                    </form>
                <?php endif; ?>

                <p class="hint">
                    <?php if ($resultCount === 0): ?>
                        Results 0 of 0
                    <?php else: ?>
                        Results 1-<?= $resultCount ?> of <?= $resultCount ?>
                    <?php endif; ?>
                </p>
                <div class="marketing-table-wrap">
                    <table class="index-table marketing-campaigns-table">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>Code</th>
                            <th>Active flag<br><span class="hint" style="font-weight:400;">(admin-only, not checkout)</span></th>
                            <th>Origin</th>
                            <th>Adjustment Type</th>
                            <th>Adjustment</th>
                            <th>Options</th>
                            <th>Date Window</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($items === []): ?>
                            <tr>
                                <td colspan="9" class="hint">No offers found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $row): ?>
                                <?php
                                $id = (int) ($row['id'] ?? 0);
                                $adjType = (string) ($row['adjustment_type'] ?? '');
                                $adjVal = (float) ($row['adjustment_value'] ?? 0);
                                $adjLabel = $adjType === 'fixed' ? number_format($adjVal, 2) . ' Fixed' : number_format($adjVal, 2) . '%';
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) ($row['name'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($row['code'] ?? '')) ?></td>
                                    <td><?= ((int) ($row['is_active'] ?? 0) === 1) ? 'On (stored only—not executed)' : 'Off' ?></td>
                                    <td><?= htmlspecialchars((string) ($row['origin'] ?? 'manual')) ?></td>
                                    <td><?= htmlspecialchars($adjType) ?></td>
                                    <td><?= htmlspecialchars($adjLabel) ?></td>
                                    <td><?= htmlspecialchars((string) ($row['offer_option'] ?? 'all')) ?></td>
                                    <td>
                                        <?= htmlspecialchars((string) ($row['start_date'] ?? '')) ?>
                                        <?= !empty($row['start_date']) || !empty($row['end_date']) ? ' - ' : '' ?>
                                        <?= htmlspecialchars((string) ($row['end_date'] ?? '')) ?>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                            <a class="marketing-btn marketing-btn--secondary" href="/marketing/promotions/special-offers/<?= $id ?>/edit">Edit</a>
                                            <?php if ((int) ($row['is_active'] ?? 0) === 1): ?>
                                            <form method="post" action="/marketing/promotions/special-offers/<?= $id ?>/toggle-active" title="Clears legacy active flag only; does not affect live pricing.">
                                                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                                                <button type="submit" class="marketing-btn marketing-btn--secondary">Clear active flag</button>
                                            </form>
                                            <?php else: ?>
                                            <span class="hint" title="Activation is disabled until offers are wired into booking/checkout.">No activate (not wired)</span>
                                            <?php endif; ?>
                                            <form method="post" action="/marketing/promotions/special-offers/<?= $id ?>/delete" onsubmit="return confirm('Delete this special offer?');">
                                                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                                                <button type="submit" class="marketing-btn marketing-btn--secondary">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <script>
                (function () {
                    var toggle = document.getElementById('special-offer-toggle-create');
                    var form = document.getElementById('special-offer-create-form');
                    if (!toggle || !form) return;
                    toggle.addEventListener('click', function () {
                        var shown = form.style.display !== 'none';
                        form.style.display = shown ? 'none' : 'grid';
                        if (!shown) {
                            var first = form.querySelector('input[name="name"]');
                            if (first) first.focus();
                        }
                    });
                })();
                </script>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>

