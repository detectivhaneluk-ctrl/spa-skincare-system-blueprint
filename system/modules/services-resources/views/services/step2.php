<?php
$title       = 'New Service: Select Products (Step 2 of 4)';
ob_start();
$currentStep = 2;
require __DIR__ . '/_wizard_nav.php';

// Build a lookup: product_id => row (for existing selections)
$selectedByProductId = [];
foreach ($selectedRows as $r) {
    $selectedByProductId[(int) $r['product_id']] = $r;
}

// Total cost (sum of quantity * unit_cost_snapshot for selected rows)
$totalCost = 0.0;
foreach ($selectedRows as $r) {
    $totalCost += (float) $r['quantity_used'] * (float) ($r['unit_cost_snapshot'] ?? $r['cost_price'] ?? 0);
}

$csrf = $csrf ?? '';
$svcId = (int) ($service['id'] ?? 0);
?>

<?php if (!empty($errors['products'])): ?>
<div class="alert alert-danger" style="margin-bottom:1rem;">
    <?= htmlspecialchars($errors['products']) ?>
</div>
<?php endif; ?>

<?php if (!empty($flash['success'])): ?>
<div class="alert alert-success" style="margin-bottom:1rem;"><?= htmlspecialchars($flash['success']) ?></div>
<?php endif; ?>

<form method="post" action="/services-resources/services/<?= $svcId ?>/step-2" id="step2-form" class="svc-step-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">

    <div class="svc-step-section">
        <p class="svc-step-section-title">Products used by this service</p>
        <p style="font-size:0.875rem; color:#64748b; margin-bottom:1rem;">
            Select any products or consumables used during delivery of this service.
            Leave empty if no products are consumed.
        </p>

        <?php if (empty($products)): ?>
        <p style="color:#94a3b8; font-style:italic;">
            No products available in the current branch catalog.
            Add products in the Inventory module first.
        </p>
        <?php else: ?>

        <!-- Selected products table -->
        <div id="selected-products-wrap">
            <table class="svc-products-table" id="selected-products-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="width:110px;">Qty used</th>
                        <th style="width:120px;">Unit cost (€)</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody id="selected-products-body">
                    <?php foreach ($selectedRows as $idx => $row): ?>
                    <tr data-idx="<?= $idx ?>">
                        <td>
                            <input type="hidden" name="product_ids[<?= $idx ?>]" value="<?= (int) $row['product_id'] ?>">
                            <?= htmlspecialchars($row['name'] ?? '') ?>
                            <span style="color:#94a3b8; font-size:0.8rem;">(<?= htmlspecialchars($row['sku'] ?? '') ?>)</span>
                        </td>
                        <td>
                            <input type="number" name="quantity_used[<?= $idx ?>]" step="0.001" min="0.001"
                                   value="<?= htmlspecialchars(number_format((float) $row['quantity_used'], 3, '.', '')) ?>"
                                   class="svc-input-narrow" required>
                        </td>
                        <td>
                            <input type="number" name="unit_cost_snapshot[<?= $idx ?>]" step="0.01" min="0"
                                   value="<?= htmlspecialchars(number_format((float) ($row['unit_cost_snapshot'] ?? $row['cost_price'] ?? 0), 2, '.', '')) ?>"
                                   class="svc-input-narrow">
                        </td>
                        <td>
                            <button type="button" class="btn-ghost svc-remove-row" title="Remove">×</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:0.5rem; font-size:0.875rem; color:#475569;">
                Total cost per service: <strong id="total-cost">€<?= number_format($totalCost, 2) ?></strong>
            </div>
        </div>

        <!-- Add product picker -->
        <div style="margin-top:1.25rem;">
            <label for="add-product-select" style="font-size:0.875rem; font-weight:600; display:block; margin-bottom:0.4rem;">
                Add product
            </label>
            <div style="display:flex; gap:0.5rem; align-items:flex-start; flex-wrap:wrap;">
                <select id="add-product-select" class="svc-select" style="min-width:260px; max-width:420px;">
                    <option value="">— choose a product —</option>
                    <?php foreach ($products as $p): ?>
                    <?php $alreadySelected = isset($selectedByProductId[(int) $p['id']]); ?>
                    <option value="<?= (int) $p['id'] ?>"
                            data-name="<?= htmlspecialchars($p['name']) ?>"
                            data-sku="<?= htmlspecialchars($p['sku']) ?>"
                            data-cost="<?= number_format((float) $p['cost_price'], 2, '.', '') ?>"
                            <?= $alreadySelected ? 'disabled' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['sku']) ?>)
                        <?= $alreadySelected ? '[already added]' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="add-product-btn" class="btn">Add</button>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <div class="svc-step-actions">
        <button type="submit" class="btn-primary">
            Save &amp; continue
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </button>
        <a href="/services-resources/services/<?= $svcId ?>/edit" class="btn-ghost">← Back to Step 1</a>
        <a href="/services-resources/services/<?= $svcId ?>/step-3" class="btn-ghost">Skip to Step 3 →</a>
        <a href="/services-resources/services/<?= $svcId ?>" class="btn-ghost">View service</a>
    </div>
</form>

<script>
(function () {
    var body  = document.getElementById('selected-products-body');
    var total = document.getElementById('total-cost');
    var select = document.getElementById('add-product-select');
    var addBtn = document.getElementById('add-product-btn');

    function rowCount() {
        return body ? body.querySelectorAll('tr').length : 0;
    }

    function recalcTotal() {
        if (!total) return;
        var rows = body ? body.querySelectorAll('tr') : [];
        var sum = 0;
        rows.forEach(function (tr) {
            var qty  = parseFloat(tr.querySelector('input[name^="quantity_used"]').value) || 0;
            var cost = parseFloat(tr.querySelector('input[name^="unit_cost_snapshot"]').value) || 0;
            sum += qty * cost;
        });
        total.textContent = '€' + sum.toFixed(2);
    }

    if (body) {
        body.addEventListener('input', recalcTotal);
        body.addEventListener('click', function (e) {
            if (e.target.classList.contains('svc-remove-row')) {
                var tr = e.target.closest('tr');
                var pid = tr ? tr.querySelector('input[name^="product_ids"]').value : null;
                if (tr) tr.remove();
                // Re-enable option in select
                if (pid && select) {
                    var opt = select.querySelector('option[value="' + pid + '"]');
                    if (opt) { opt.disabled = false; opt.textContent = opt.textContent.replace(' [already added]', ''); }
                }
                reindex();
                recalcTotal();
            }
        });
    }

    if (addBtn && select && body) {
        addBtn.addEventListener('click', function () {
            var opt = select.options[select.selectedIndex];
            if (!opt || !opt.value) return;
            var pid  = opt.value;
            var name = opt.dataset.name;
            var sku  = opt.dataset.sku;
            var cost = opt.dataset.cost || '0.00';
            var idx  = rowCount();
            var tr   = document.createElement('tr');
            tr.dataset.idx = idx;
            tr.innerHTML =
                '<td>' +
                    '<input type="hidden" name="product_ids[' + idx + ']" value="' + escH(pid) + '">' +
                    escH(name) + ' <span style="color:#94a3b8;font-size:0.8rem;">(' + escH(sku) + ')</span>' +
                '</td>' +
                '<td><input type="number" name="quantity_used[' + idx + ']" step="0.001" min="0.001" value="1.000" class="svc-input-narrow" required></td>' +
                '<td><input type="number" name="unit_cost_snapshot[' + idx + ']" step="0.01" min="0" value="' + escH(cost) + '" class="svc-input-narrow"></td>' +
                '<td><button type="button" class="btn-ghost svc-remove-row" title="Remove">×</button></td>';
            body.appendChild(tr);
            opt.disabled = true;
            opt.textContent += ' [already added]';
            select.selectedIndex = 0;
            reindex();
            recalcTotal();
        });
    }

    function reindex() {
        if (!body) return;
        body.querySelectorAll('tr').forEach(function (tr, i) {
            tr.dataset.idx = i;
            tr.querySelectorAll('input').forEach(function (inp) {
                inp.name = inp.name.replace(/\[\d+\]/, '[' + i + ']');
            });
        });
    }

    function escH(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
}());
</script>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
