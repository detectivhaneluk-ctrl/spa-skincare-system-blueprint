<?php
$title = 'Add Product';
ob_start();
?>
<h1>Add Product</h1>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/inventory/products" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row"><label for="name">Name *</label><input type="text" id="name" name="name" required value="<?= htmlspecialchars($product['name'] ?? '') ?>"></div>
    <div class="form-row"><label for="sku">SKU *</label><input type="text" id="sku" name="sku" required value="<?= htmlspecialchars($product['sku'] ?? '') ?>"></div>
    <div class="form-row"><label for="barcode">Barcode</label><input type="text" id="barcode" name="barcode" value="<?= htmlspecialchars($product['barcode'] ?? '') ?>"></div>
    <div class="form-row"><label for="category">Category (legacy text)</label><input type="text" id="category" name="category" value="<?= htmlspecialchars($product['category'] ?? '') ?>"></div>
    <div class="form-row"><label for="brand">Brand (legacy text)</label><input type="text" id="brand" name="brand" value="<?= htmlspecialchars($product['brand'] ?? '') ?>"></div>
    <div class="form-row">
        <label for="product_category_id">Category (normalized)</label>
        <select id="product_category_id" name="product_category_id">
            <option value="">— None —</option>
            <?php foreach ($taxonomy['categories'] as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((string)($product['product_category_id'] ?? '') === (string)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="product_brand_id">Brand (normalized)</label>
        <select id="product_brand_id" name="product_brand_id">
            <option value="">— None —</option>
            <?php foreach ($taxonomy['brands'] as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= ((string)($product['product_brand_id'] ?? '') === (string)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="product_type">Product Type *</label>
        <select id="product_type" name="product_type" required>
            <?php foreach (\Modules\Inventory\Services\ProductService::PRODUCT_TYPES as $type): ?>
            <option value="<?= htmlspecialchars($type) ?>" <?= (($product['product_type'] ?? 'retail') === $type) ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($type)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label for="cost_price">Cost Price *</label><input type="number" min="0" step="0.01" id="cost_price" name="cost_price" required value="<?= htmlspecialchars((string)($product['cost_price'] ?? '0.00')) ?>"></div>
    <div class="form-row"><label for="sell_price">Sell Price *</label><input type="number" min="0" step="0.01" id="sell_price" name="sell_price" required value="<?= htmlspecialchars((string)($product['sell_price'] ?? '0.00')) ?>"></div>
    <div class="form-row"><label for="vat_rate">VAT Rate (%)</label><input type="number" min="0" max="100" step="0.01" id="vat_rate" name="vat_rate" value="<?= htmlspecialchars((string)($product['vat_rate'] ?? '')) ?>"></div>
    <div class="form-row"><label for="reorder_level">Reorder Level</label><input type="number" min="0" step="0.001" id="reorder_level" name="reorder_level" value="<?= htmlspecialchars((string)($product['reorder_level'] ?? '0')) ?>"></div>
    <div class="form-row"><label for="initial_quantity">Initial Quantity</label><input type="number" min="0" step="0.001" id="initial_quantity" name="initial_quantity" value="<?= htmlspecialchars((string)($product['initial_quantity'] ?? '0')) ?>"></div>
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">Global</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= ((string)($product['branch_id'] ?? '') === (string)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label><input type="checkbox" name="is_active" value="1" <?= ($product['is_active'] ?? 1) ? 'checked' : '' ?>> Active</label></div>
    <div class="form-actions"><button type="submit">Create</button> <a href="/inventory/products">Cancel</a></div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
