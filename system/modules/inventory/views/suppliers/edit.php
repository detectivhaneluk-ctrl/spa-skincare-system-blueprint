<?php
$title = 'Edit Supplier';
ob_start();
?>
<h1>Edit Supplier</h1>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/inventory/suppliers/<?= (int)$supplier['id'] ?>" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row"><label for="name">Name *</label><input type="text" id="name" name="name" required value="<?= htmlspecialchars($supplier['name'] ?? '') ?>"></div>
    <div class="form-row"><label for="contact_name">Contact Name</label><input type="text" id="contact_name" name="contact_name" value="<?= htmlspecialchars($supplier['contact_name'] ?? '') ?>"></div>
    <div class="form-row"><label for="phone">Phone</label><input type="text" id="phone" name="phone" value="<?= htmlspecialchars($supplier['phone'] ?? '') ?>"></div>
    <div class="form-row"><label for="email">Email</label><input type="email" id="email" name="email" value="<?= htmlspecialchars($supplier['email'] ?? '') ?>"></div>
    <div class="form-row"><label for="address">Address</label><textarea id="address" name="address" rows="2"><?= htmlspecialchars($supplier['address'] ?? '') ?></textarea></div>
    <div class="form-row"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($supplier['notes'] ?? '') ?></textarea></div>
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">Global</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= ((string)($supplier['branch_id'] ?? '') === (string)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions"><button type="submit">Update</button> <a href="/inventory/suppliers/<?= (int)$supplier['id'] ?>">Cancel</a></div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
