<?php
$title = 'Edit product category';
ob_start();
?>
<h1>Edit product category</h1>
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
<form method="post" action="/inventory/product-categories/<?= (int) $category['id'] ?>" class="entity-form">
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
    <?php
    $inventoryBranchReassignmentLocked = !empty($inventoryBranchReassignmentLocked);
    $categoryBranchId = isset($category['branch_id']) && $category['branch_id'] !== '' && $category['branch_id'] !== null ? (int) $category['branch_id'] : null;
    $branchDisplayLabel = 'Global';
    if ($categoryBranchId !== null) {
        foreach ($branches as $b) {
            if ((int) $b['id'] === $categoryBranchId) {
                $branchDisplayLabel = (string) $b['name'];
                break;
            }
        }
    }
    ?>
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <?php if ($inventoryBranchReassignmentLocked): ?>
        <input type="hidden" name="branch_id" value="<?= $categoryBranchId === null ? '' : (string) $categoryBranchId ?>">
        <p class="form-readonly-value" id="branch_id_display"><?= htmlspecialchars($branchDisplayLabel) ?></p>
        <?php else: ?>
        <select id="branch_id" name="branch_id">
            <option value="">Global</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= ((string)($category['branch_id'] ?? '') === (string)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>
    <div class="form-row">
        <label for="parent_id">Parent</label>
        <select id="parent_id" name="parent_id">
            <option value="">— None —</option>
            <?php foreach ($parentOptions as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ((string)($category['parent_id'] ?? '') === (string)$p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions">
        <button type="submit">Update</button>
        <a href="/inventory/product-categories/<?= (int) $category['id'] ?>">Cancel</a>
    </div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
