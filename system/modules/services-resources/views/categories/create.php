<?php
// Standalone create page — redirects to the index panel for the main management flow.
// This page is still accessible directly via /services-resources/categories/create
// and handles back-navigation from the index panel URL.
$title = 'Add Service Category';
ob_start();
$selectedParentId = (int) ($category['parent_id'] ?? $preParentId ?? 0);
?>
<div class="taxmgr-standalone-wrap">
    <div class="taxmgr-standalone-header">
        <a href="/services-resources/categories" class="taxmgr-back-link">← Back to Categories</a>
        <h1>Add Service Category</h1>
    </div>
    <?php if (!empty($errors)): ?>
    <div class="taxmgr-panel-errors">
        <?php foreach ($errors as $field => $msg): ?>
        <p><?= htmlspecialchars(is_string($msg) ? $msg : ($msg['message'] ?? 'Error')) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <form method="post" action="/services-resources/categories" class="taxmgr-form taxmgr-form--standalone">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <div class="taxmgr-form-row">
            <label for="name">Name <span class="taxmgr-required">*</span></label>
            <input type="text" id="name" name="name" required value="<?= htmlspecialchars($category['name'] ?? '') ?>">
            <?php if (!empty($errors['name'])): ?><span class="taxmgr-field-error"><?= htmlspecialchars($errors['name']) ?></span><?php endif; ?>
        </div>
        <div class="taxmgr-form-row">
            <label for="parent_id">Parent category</label>
            <select id="parent_id" name="parent_id">
                <option value="">— None (root category) —</option>
                <?php foreach ($treeRows as $row): ?>
                <?php $depth = (int) ($row['depth'] ?? 0); ?>
                <option value="<?= (int) $row['id'] ?>" <?= $selectedParentId === (int) $row['id'] ? 'selected' : '' ?>>
                    <?= str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth) ?><?= $depth > 0 ? '└ ' : '' ?><?= htmlspecialchars($row['name'] ?? '') ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['parent_id'])): ?><span class="taxmgr-field-error"><?= htmlspecialchars($errors['parent_id']) ?></span><?php endif; ?>
        </div>
        <div class="taxmgr-form-row taxmgr-form-row--inline">
            <label for="sort_order">Sort order</label>
            <input type="number" id="sort_order" name="sort_order" value="<?= htmlspecialchars((string) ($category['sort_order'] ?? 0)) ?>" style="width:6rem;" min="0">
        </div>
        <div class="taxmgr-form-actions">
            <button type="submit" class="btn taxmgr-btn-primary">Create Category</button>
            <a href="/services-resources/categories" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
