<?php
$title = 'Edit product brand';
ob_start();
?>
<h1>Edit product brand</h1>
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
<form method="post" action="/inventory/product-brands/<?= (int) $brand['id'] ?>" class="entity-form">
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
    <?php
    $inventoryBranchReassignmentLocked = !empty($inventoryBranchReassignmentLocked);
    $brandBranchId = isset($brand['branch_id']) && $brand['branch_id'] !== '' && $brand['branch_id'] !== null ? (int) $brand['branch_id'] : null;
    $branchDisplayLabel = 'Global';
    if ($brandBranchId !== null) {
        foreach ($branches as $b) {
            if ((int) $b['id'] === $brandBranchId) {
                $branchDisplayLabel = (string) $b['name'];
                break;
            }
        }
    }
    ?>
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <?php if ($inventoryBranchReassignmentLocked): ?>
        <input type="hidden" name="branch_id" value="<?= $brandBranchId === null ? '' : (string) $brandBranchId ?>">
        <p class="form-readonly-value" id="branch_id_display"><?= htmlspecialchars($branchDisplayLabel) ?></p>
        <?php else: ?>
        <select id="branch_id" name="branch_id">
            <option value="">Global</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= ((string)($brand['branch_id'] ?? '') === (string)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>
    <div class="form-actions">
        <button type="submit">Update</button>
        <a href="/inventory/product-brands/<?= (int) $brand['id'] ?>">Cancel</a>
    </div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
