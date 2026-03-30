<?php
$title = $product['name'] ?? 'Product';
ob_start();
?>
<h1><?= htmlspecialchars($product['name'] ?? 'Product') ?></h1>
<div class="entity-actions">
    <a href="/inventory/products/<?= (int)$product['id'] ?>/edit">Edit</a>
    <form method="post" action="/inventory/products/<?= (int)$product['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete this product?')">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit">Delete</button>
    </form>
</div>
<?php
    $stock = (float) ($product['stock_quantity'] ?? 0);
    $reorder = (float) ($product['reorder_level'] ?? 0);
    $isLow = $stock <= $reorder;
?>
<dl class="entity-detail">
    <dt>SKU</dt><dd><?= htmlspecialchars($product['sku']) ?></dd>
    <dt>Barcode</dt><dd><?= htmlspecialchars($product['barcode'] ?? '—') ?></dd>
    <dt>Category</dt><dd><?= !empty($product['category_display']) ? htmlspecialchars((string) $product['category_display']) : '—' ?></dd>
    <dt>Brand</dt><dd><?= !empty($product['brand_display']) ? htmlspecialchars((string) $product['brand_display']) : '—' ?></dd>
    <?php
    $legCat = trim((string) ($product['category'] ?? ''));
    $legBrand = trim((string) ($product['brand'] ?? ''));
    $dispCat = trim((string) ($product['category_display'] ?? ''));
    $dispBrand = trim((string) ($product['brand_display'] ?? ''));
    ?>
    <?php if ($legCat !== '' && $legCat !== $dispCat): ?>
    <dt>Category (legacy text)</dt><dd><?= htmlspecialchars($legCat) ?></dd>
    <?php endif; ?>
    <?php if ($legBrand !== '' && $legBrand !== $dispBrand): ?>
    <dt>Brand (legacy text)</dt><dd><?= htmlspecialchars($legBrand) ?></dd>
    <?php endif; ?>
    <dt>Type</dt><dd><?= htmlspecialchars($product['product_type']) ?></dd>
    <dt>Cost Price</dt><dd><?= number_format((float)($product['cost_price'] ?? 0), 2) ?></dd>
    <dt>Sell Price</dt><dd><?= number_format((float)($product['sell_price'] ?? 0), 2) ?></dd>
    <dt>VAT Rate</dt><dd><?= $product['vat_rate'] !== null ? number_format((float)$product['vat_rate'], 2) . '%' : '—' ?></dd>
    <dt>Stock Quantity</dt><dd><span class="badge <?= $isLow ? 'badge-warn' : 'badge-success' ?>"><?= number_format($stock, 3) ?></span></dd>
    <dt>Reorder Level</dt><dd><?= number_format($reorder, 3) ?></dd>
    <dt>Status</dt><dd><?= !empty($product['is_active']) ? 'Active' : 'Inactive' ?></dd>
    <dt>Branch</dt><dd><?= $product['branch_id'] ? ('#' . (int)$product['branch_id']) : 'Global' ?></dd>
</dl>
<p><a href="/inventory/products">← Back to products</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
