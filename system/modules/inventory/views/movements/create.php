<?php
$title = 'Add Stock Movement';
ob_start();
?>
<h1>Add Stock Movement</h1>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/inventory/movements" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row">
        <label for="product_id">Product *</label>
        <select id="product_id" name="product_id" required>
            <option value="">Select product</option>
            <?php foreach ($products as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ((int)($movement['product_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['name'] . ' [' . $p['sku'] . '] · Stock ' . number_format((float)$p['stock_quantity'], 3)) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="movement_type">Movement Type *</label>
        <select id="movement_type" name="movement_type" required>
            <?php foreach (\Modules\Inventory\Services\StockMovementService::MANUAL_ENTRY_MOVEMENT_TYPES as $type): ?>
            <option value="<?= htmlspecialchars($type) ?>" <?= (($movement['movement_type'] ?? 'purchase_in') === $type) ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="quantity">Quantity *</label>
        <input type="number" id="quantity" name="quantity" step="0.001" required value="<?= htmlspecialchars((string)($movement['quantity'] ?? '')) ?>">
        <small>Use positive for purchase/manual increase. Use negative for manual decrease. For internal usage/damaged, absolute value is used and stored as negative.</small>
    </div>
    <div class="form-row"><p class="help"><small>Reference links (invoices, counts, etc.) are reserved for system flows. Use Notes for your own context.</small></p></div>
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">Global</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= ((string)($movement['branch_id'] ?? '') === (string)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($movement['notes'] ?? '') ?></textarea></div>
    <div class="form-actions"><button type="submit">Save Movement</button> <a href="/inventory/movements">Cancel</a></div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
