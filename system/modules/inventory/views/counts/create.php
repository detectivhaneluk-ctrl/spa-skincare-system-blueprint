<?php
$title = 'New Inventory Count';
ob_start();
?>
<h1>New Inventory Count</h1>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/inventory/counts" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row">
        <label for="product_id">Product *</label>
        <select id="product_id" name="product_id" required>
            <option value="">Select product</option>
            <?php foreach ($products as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ((int)($count['product_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['name'] . ' [' . $p['sku'] . '] · Current ' . number_format((float)$p['stock_quantity'], 3)) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="counted_quantity">Counted Quantity *</label>
        <input type="number" id="counted_quantity" name="counted_quantity" min="0" step="0.001" required value="<?= htmlspecialchars((string)($count['counted_quantity'] ?? '')) ?>">
    </div>
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">Global</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= ((string)($count['branch_id'] ?? '') === (string)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($count['notes'] ?? '') ?></textarea></div>
    <div class="form-row">
        <label><input type="checkbox" name="apply_adjustment" value="1" <?= !empty($count['apply_adjustment']) ? 'checked' : '' ?>> Apply count adjustment movement now (requires inventory.adjust)</label>
    </div>
    <div class="form-actions"><button type="submit">Save Count</button> <a href="/inventory/counts">Cancel</a></div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
