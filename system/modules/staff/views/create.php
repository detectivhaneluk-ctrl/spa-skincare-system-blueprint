<?php
$title = 'Add Staff';
ob_start();
?>
<h1>Add Staff</h1>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php foreach ($errors as $e): ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/staff" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row">
        <label for="user_id">Link to User</label>
        <select id="user_id" name="user_id">
            <option value="">— None —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= ($staff['user_id'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name'] . ' (' . $u['email'] . ')') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="first_name">First name *</label>
        <input type="text" id="first_name" name="first_name" required value="<?= htmlspecialchars($staff['first_name'] ?? '') ?>">
    </div>
    <div class="form-row">
        <label for="last_name">Last name *</label>
        <input type="text" id="last_name" name="last_name" required value="<?= htmlspecialchars($staff['last_name'] ?? '') ?>">
    </div>
    <div class="form-row">
        <label for="phone">Phone</label>
        <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($staff['phone'] ?? '') ?>">
    </div>
    <div class="form-row">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= htmlspecialchars($staff['email'] ?? '') ?>">
    </div>
    <div class="form-row">
        <label for="job_title">Job Title</label>
        <input type="text" id="job_title" name="job_title" value="<?= htmlspecialchars($staff['job_title'] ?? '') ?>">
    </div>
    <div class="form-row">
        <label><input type="checkbox" name="is_active" value="1" <?= ($staff['is_active'] ?? true) ? 'checked' : '' ?>> Active</label>
    </div>
    <div class="form-actions">
        <button type="submit">Create</button>
        <a href="/staff">Cancel</a>
    </div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
