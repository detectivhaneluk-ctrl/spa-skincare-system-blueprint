<?php
$title = $category['name'] ?? 'Product category';
ob_start();
?>
<h1><?= htmlspecialchars($category['name'] ?? 'Product category') ?></h1>
<div class="entity-actions">
    <a href="/inventory/product-categories/<?= (int)$category['id'] ?>/edit">Edit</a>
    <form method="post" action="/inventory/product-categories/<?= (int)$category['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete?')">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit">Delete</button>
    </form>
</div>
<dl class="entity-detail">
    <dt>Sort</dt><dd><?= (int)($category['sort_order'] ?? 0) ?></dd>
    <dt>Scope</dt><dd><?= htmlspecialchars($scope_label) ?></dd>
    <dt>Parent</dt><dd><?= htmlspecialchars($parent_label) ?></dd>
</dl>
<p><a href="/inventory/product-categories">← Back</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
