<?php
$title = 'Add Room';
ob_start();
?>
<h1>Add Room</h1>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php foreach ($errors as $e): ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/services-resources/rooms" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row">
        <label for="name">Name *</label>
        <input type="text" id="name" name="name" required value="<?= htmlspecialchars($room['name'] ?? '') ?>">
        <?php if (!empty($errors['name'])): ?><span class="error"><?= htmlspecialchars($errors['name']) ?></span><?php endif; ?>
    </div>
    <div class="form-row">
        <label for="code">Code</label>
        <input type="text" id="code" name="code" value="<?= htmlspecialchars($room['code'] ?? '') ?>">
    </div>
    <div class="form-row">
        <label><input type="checkbox" name="is_active" value="1" <?= !isset($room['is_active']) || !empty($room['is_active']) ? 'checked' : '' ?>> Active</label>
    </div>
    <div class="form-row">
        <label><input type="checkbox" name="maintenance_mode" value="1" <?= !empty($room['maintenance_mode']) ? 'checked' : '' ?>> Maintenance mode</label>
    </div>
    <div class="form-actions">
        <button type="submit">Create</button>
        <a href="/services-resources/rooms">Cancel</a>
    </div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
