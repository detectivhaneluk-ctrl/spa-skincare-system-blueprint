<?php
$title = 'Edit Service';
ob_start();
?>
<h1>Edit Service</h1>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php foreach ($errors as $e): ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/services-resources/services/<?= (int) $service['id'] ?>" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id">
            <option value="">—</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= ((int)($service['category_id'] ?? 0)) === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="name">Name *</label>
        <input type="text" id="name" name="name" required value="<?= htmlspecialchars($service['name'] ?? '') ?>">
        <?php if (!empty($errors['name'])): ?><span class="error"><?= htmlspecialchars($errors['name']) ?></span><?php endif; ?>
    </div>
    <div class="form-row">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="5" cols="60"><?= htmlspecialchars((string) ($service['description'] ?? '')) ?></textarea>
        <?php if (!empty($errors['description'])): ?><span class="error"><?= htmlspecialchars($errors['description']) ?></span><?php endif; ?>
    </div>
    <div class="form-row">
        <label for="duration_minutes">Duration (minutes) *</label>
        <input type="number" id="duration_minutes" name="duration_minutes" min="1" value="<?= htmlspecialchars((string)($service['duration_minutes'] ?? 60)) ?>">
        <?php if (!empty($errors['duration_minutes'])): ?><span class="error"><?= htmlspecialchars($errors['duration_minutes']) ?></span><?php endif; ?>
    </div>
    <div class="form-row">
        <label for="buffer_before_minutes">Buffer before (minutes)</label>
        <input type="number" id="buffer_before_minutes" name="buffer_before_minutes" min="0" value="<?= htmlspecialchars((string)($service['buffer_before_minutes'] ?? 0)) ?>">
    </div>
    <div class="form-row">
        <label for="buffer_after_minutes">Buffer after (minutes)</label>
        <input type="number" id="buffer_after_minutes" name="buffer_after_minutes" min="0" value="<?= htmlspecialchars((string)($service['buffer_after_minutes'] ?? 0)) ?>">
    </div>
    <div class="form-row">
        <label for="price">Price</label>
        <input type="number" id="price" name="price" min="0" step="0.01" value="<?= htmlspecialchars((string)($service['price'] ?? 0)) ?>">
        <?php if (!empty($errors['price'])): ?><span class="error"><?= htmlspecialchars($errors['price']) ?></span><?php endif; ?>
    </div>
    <div class="form-row">
        <label for="vat_rate_id">VAT rate</label>
        <select id="vat_rate_id" name="vat_rate_id">
            <option value="">—</option>
            <?php foreach ($vatRates ?? [] as $vr): ?>
            <option value="<?= (int) $vr['id'] ?>" <?= ((int)($service['vat_rate_id'] ?? 0)) === (int)$vr['id'] ? 'selected' : '' ?>><?= htmlspecialchars($vr['name']) ?> (<?= number_format((float)$vr['rate_percent'], 2) ?>%)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label><input type="checkbox" name="is_active" value="1" <?= !empty($service['is_active']) ? 'checked' : '' ?>> Active</label>
    </div>
    <div class="form-row">
        <label>Staff who can perform</label>
        <?php foreach ($staff as $s): ?>
        <label style="display:block"><input type="checkbox" name="staff_ids[]" value="<?= (int) $s['id'] ?>" <?= in_array((int)$s['id'], $service['staff_ids'] ?? [], true) ? 'checked' : '' ?>> <?= htmlspecialchars(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))) ?></label>
        <?php endforeach; ?>
    </div>
    <div class="form-row">
        <label>Rooms used</label>
        <?php foreach ($rooms as $r): ?>
        <label style="display:block"><input type="checkbox" name="room_ids[]" value="<?= (int) $r['id'] ?>" <?= in_array((int)$r['id'], $service['room_ids'] ?? [], true) ? 'checked' : '' ?>> <?= htmlspecialchars($r['name']) ?></label>
        <?php endforeach; ?>
    </div>
    <div class="form-row">
        <label>Equipment used</label>
        <?php foreach ($equipment as $e): ?>
        <label style="display:block"><input type="checkbox" name="equipment_ids[]" value="<?= (int) $e['id'] ?>" <?= in_array((int)$e['id'], $service['equipment_ids'] ?? [], true) ? 'checked' : '' ?>> <?= htmlspecialchars($e['name']) ?></label>
        <?php endforeach; ?>
    </div>
    <input type="hidden" name="staff_group_ids_sync" value="1">
    <div class="form-row">
        <label>Staff groups (eligibility)</label>
        <?php foreach ($staffGroups ?? [] as $g): ?>
        <label style="display:block"><input type="checkbox" name="staff_group_ids[]" value="<?= (int) $g['id'] ?>" <?= in_array((int) $g['id'], $service['staff_group_ids'] ?? [], true) ? 'checked' : '' ?>> <?= htmlspecialchars($g['name'] ?? '') ?></label>
        <?php endforeach; ?>
        <?php if (!empty($errors['staff_group_ids'])): ?><span class="error"><?= htmlspecialchars($errors['staff_group_ids']) ?></span><?php endif; ?>
    </div>
    <div class="form-actions">
        <button type="submit">Update</button>
        <a href="/services-resources/services/<?= (int) $service['id'] ?>">Cancel</a>
    </div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
