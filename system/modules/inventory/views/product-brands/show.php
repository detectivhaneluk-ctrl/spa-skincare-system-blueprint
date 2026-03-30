<?php
$title = $brand['name'] ?? 'Product brand';
ob_start();
?>
<h1><?= htmlspecialchars($brand['name'] ?? 'Product brand') ?></h1>
<div class="entity-actions">
    <a href="/inventory/product-brands/<?= (int)$brand['id'] ?>/edit">Edit</a>
    <form method="post" action="/inventory/product-brands/<?= (int)$brand['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete?')">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit">Delete</button>
    </form>
</div>
<dl class="entity-detail">
    <dt>Sort</dt><dd><?= (int)($brand['sort_order'] ?? 0) ?></dd>
    <dt>Scope</dt><dd><?= htmlspecialchars($scope_label) ?></dd>
</dl>
<p><a href="/inventory/product-brands">← Back</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
