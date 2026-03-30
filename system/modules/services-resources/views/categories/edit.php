<?php
$title = 'Edit Service Category';
ob_start();
?>
<h1>Edit Service Category</h1>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php foreach ($errors as $e): ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/services-resources/categories/<?= (int) $category['id'] ?>" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row">
        <label for="name">Name *</label>
        <input type="text" id="name" name="name" required value="<?= htmlspecialchars($category['name'] ?? '') ?>">
        <?php if (!empty($errors['name'])): ?><span class="error"><?= htmlspecialchars($errors['name']) ?></span><?php endif; ?>
    </div>
    <div class="form-row">
        <label for="sort_order">Sort order</label>
        <input type="number" id="sort_order" name="sort_order" value="<?= htmlspecialchars((string)($category['sort_order'] ?? 0)) ?>">
    </div>
    <div class="form-actions">
        <button type="submit">Update</button>
        <a href="/services-resources/categories/<?= (int) $category['id'] ?>">Cancel</a>
    </div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
