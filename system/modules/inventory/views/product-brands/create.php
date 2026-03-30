<?php
$title = 'Add product brand';
ob_start();
?>
<h1>Add product brand</h1>
<?php if (!empty($errors['_general'])): ?>
<ul class="form-errors"><li><?= htmlspecialchars($errors['_general']) ?></li></ul>
<?php endif; ?>
<?php if (!empty($errors) && empty($errors['_general'])): ?>
<ul class="form-errors">
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/inventory/product-brands" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row">
        <label for="name">Name *</label>
        <input type="text" id="name" name="name" required value="<?= htmlspecialchars($brand['name'] ?? '') ?>">
        <?php if (!empty($errors['name'])): ?><span class="error"><?= htmlspecialchars($errors['name']) ?></span><?php endif; ?>
    </div>
    <div class="form-row">
        <label for="sort_order">Sort order</label>
        <input type="number" id="sort_order" name="sort_order" value="<?= htmlspecialchars((string)($brand['sort_order'] ?? 0)) ?>">
    </div>
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">Global</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= ((string)($brand['branch_id'] ?? '') === (string)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions">
        <button type="submit">Create</button>
        <a href="/inventory/product-brands">Cancel</a>
    </div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
