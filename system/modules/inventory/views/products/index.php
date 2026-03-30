<?php
$title = 'Products';
ob_start();
?>
<h1>Products</h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form">
    <input type="text" name="search" placeholder="Search name, SKU, barcode, category, brand…" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
    <input type="text" name="filter_category" placeholder="Category (norm. or legacy)" value="<?= htmlspecialchars($_GET['filter_category'] ?? '') ?>">
    <input type="text" name="filter_brand" placeholder="Brand (norm. or legacy)" value="<?= htmlspecialchars($_GET['filter_brand'] ?? '') ?>">
    <select name="product_type">
        <option value="">All types</option>
        <?php foreach (\Modules\Inventory\Services\ProductService::PRODUCT_TYPES as $type): ?>
        <option value="<?= htmlspecialchars($type) ?>" <?= (($_GET['product_type'] ?? '') === $type) ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($type)) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="is_active">
        <option value="">Active + inactive</option>
        <option value="1" <?= (($_GET['is_active'] ?? '') === '1') ? 'selected' : '' ?>>Active only</option>
        <option value="0" <?= (($_GET['is_active'] ?? '') === '0') ? 'selected' : '' ?>>Inactive only</option>
    </select>
    <?php if (!empty($productsIndexBranchContextId)): ?>
    <p class="form-readonly-value products-index-branch-scope" title="Matches show/edit/delete access: global products plus this branch.">
        Branch scope: <strong><?= htmlspecialchars((string) ($productsIndexBranchContextLabel ?? '')) ?></strong>
        <span class="badge badge-muted">global + this branch</span>
    </p>
    <?php else: ?>
    <select name="branch_id">
        <option value="">All branches (explicit mix)</option>
        <option value="global" <?= (($_GET['branch_id'] ?? '') === 'global') ? 'selected' : '' ?>>Global only</option>
        <?php foreach ($branches as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= (($_GET['branch_id'] ?? '') !== 'global' && (int)($_GET['branch_id'] ?? 0) === (int)$b['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button type="submit">Filter</button>
</form>

<p><a href="/inventory" class="btn">← Inventory</a> <a href="<?= htmlspecialchars($productCreateHref ?? '/inventory/products/create') ?>" class="btn">Add Product</a></p>

<table class="index-table">
    <thead>
        <tr>
            <th>Name</th>
            <th>SKU</th>
            <th>Type</th>
            <th>Stock</th>
            <th>Reorder</th>
            <th>Status</th>
            <th>Branch</th>
            <th>Category</th>
            <th>Brand</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($products as $p): ?>
        <?php
            $stock = (float) ($p['stock_quantity'] ?? 0);
            $reorder = (float) ($p['reorder_level'] ?? 0);
            $isLow = $stock <= $reorder;
        ?>
        <tr>
            <td><a href="/inventory/products/<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></a></td>
            <td><?= htmlspecialchars($p['sku']) ?></td>
            <td><span class="badge badge-muted"><?= htmlspecialchars($p['product_type']) ?></span></td>
            <td><span class="badge <?= $isLow ? 'badge-warn' : 'badge-success' ?>"><?= number_format($stock, 3) ?></span></td>
            <td><?= number_format($reorder, 3) ?></td>
            <td><?= !empty($p['is_active']) ? 'Active' : 'Inactive' ?></td>
            <td><?= $p['branch_id'] ? ('#' . (int)$p['branch_id']) : 'Global' ?></td>
            <td><?= !empty($p['category_display']) ? htmlspecialchars((string) $p['category_display']) : '—' ?></td>
            <td><?= !empty($p['brand_display']) ? htmlspecialchars((string) $p['brand_display']) : '—' ?></td>
            <td>
                <a href="/inventory/products/<?= (int) $p['id'] ?>/edit">Edit</a> |
                <form method="post" action="/inventory/products/<?= (int) $p['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete product?')">
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <button type="submit">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php if ($total > count($products)): ?>
<p class="pagination">Page <?= $page ?> · <?= $total ?> total</p>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
