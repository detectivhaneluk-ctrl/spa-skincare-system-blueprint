<?php
$title = 'Service Category: ' . htmlspecialchars($category['name'] ?? '');
ob_start();
?>
<h1><?= htmlspecialchars($category['name'] ?? '') ?></h1>
<div class="entity-detail">
    <p><strong>Sort order:</strong> <?= (int)($category['sort_order'] ?? 0) ?></p>
    <div class="entity-actions">
        <a href="/services-resources/categories/<?= (int) $category['id'] ?>/edit" class="btn">Edit</a>
        <form method="post" action="/services-resources/categories/<?= (int) $category['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete?')">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit">Delete</button>
        </form>
    </div>
</div>
<p><a href="/services-resources/categories">← Back to Categories</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
