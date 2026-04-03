<?php
$title = 'Create Staff Group';
ob_start();
?>
<div class="page-header">
    <h1 class="page-header__title">Create Staff Group</h1>
</div>

<?php if (!empty($errors)): ?>
<div class="form-errors" role="alert">
    <strong>Please correct the following:</strong>
    <ul>
        <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" action="/staff/groups/admin" class="entity-form">
    <input type="hidden"
        name="<?= htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'), ENT_QUOTES, 'UTF-8') ?>"
        value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8') ?>"
    >

    <div class="form-row <?= isset($errors['name']) ? 'form-row--error' : '' ?>">
        <label for="name" class="form-label form-label--required">Name</label>
        <input
            type="text"
            id="name"
            name="name"
            class="form-input"
            value="<?= htmlspecialchars((string) ($group['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            maxlength="120"
            required
        >
        <?php if (isset($errors['name'])): ?>
        <span class="form-field-error"><?= htmlspecialchars((string) $errors['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>

    <div class="form-row">
        <label for="description" class="form-label">Description</label>
        <textarea
            id="description"
            name="description"
            class="form-textarea"
            rows="3"
            maxlength="255"
        ><?= htmlspecialchars((string) ($group['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <div class="form-actions">
        <a href="/staff/groups" class="btn btn--secondary">Cancel</a>
        <button type="submit" class="btn btn--primary">Create Group</button>
    </div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
