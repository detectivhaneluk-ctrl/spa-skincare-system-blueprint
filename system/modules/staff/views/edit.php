<?php
// Simple edit — redirects to the full profile editor
$title = 'Edit Staff';
ob_start();
$teamWorkspaceActiveTab  = 'directory';
$teamWorkspaceShellTitle = 'Team';
require base_path('modules/staff/views/partials/team-workspace-shell.php');
?>
<div class="staff-wizard-card">
    <div class="staff-wizard-card__header">
        <h1 class="staff-wizard-card__title">Edit Staff Member</h1>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="staff-create-errors" role="alert">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <ul class="staff-create-errors__list">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="/staff/<?= (int) $staff['id'] ?>" class="staff-create-form" novalidate>
        <input type="hidden" name="<?= htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8') ?>">

        <div class="staff-create-section">
            <h3 class="staff-create-section__title">Linked User Account</h3>
            <div class="staff-create-field">
                <label for="user_id" class="staff-create-label">User</label>
                <select id="user_id" name="user_id" class="staff-create-select">
                    <option value="">— None —</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= ($staff['user_id'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name'] . ' (' . $u['email'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="staff-create-section">
            <h3 class="staff-create-section__title">Basic Info</h3>
            <div class="staff-create-row-2">
                <div class="staff-create-field">
                    <label for="first_name" class="staff-create-label staff-create-label--required">First Name</label>
                    <input type="text" id="first_name" name="first_name" class="staff-create-input" required value="<?= htmlspecialchars($staff['first_name'] ?? '') ?>">
                </div>
                <div class="staff-create-field">
                    <label for="last_name" class="staff-create-label">Last Name</label>
                    <input type="text" id="last_name" name="last_name" class="staff-create-input" value="<?= htmlspecialchars($staff['last_name'] ?? '') ?>">
                </div>
            </div>
            <div class="staff-create-field">
                <label for="phone" class="staff-create-label">Phone</label>
                <input type="tel" id="phone" name="phone" class="staff-create-input" value="<?= htmlspecialchars($staff['phone'] ?? '') ?>">
            </div>
            <div class="staff-create-field">
                <label for="email" class="staff-create-label">Email</label>
                <input type="email" id="email" name="email" class="staff-create-input" value="<?= htmlspecialchars($staff['email'] ?? '') ?>">
            </div>
            <div class="staff-create-field">
                <label for="job_title" class="staff-create-label">Job Title</label>
                <input type="text" id="job_title" name="job_title" class="staff-create-input" value="<?= htmlspecialchars($staff['job_title'] ?? '') ?>">
            </div>
            <label class="staff-create-checkbox">
                <input type="checkbox" name="is_active" value="1" <?= ($staff['is_active'] ?? true) ? 'checked' : '' ?>>
                <span>Active</span>
            </label>
        </div>

        <div class="staff-create-actions">
            <a href="/staff/<?= (int) $staff['id'] ?>" class="staff-create-btn-cancel">Cancel</a>
            <button type="submit" class="staff-create-btn-submit">Update</button>
        </div>
    </form>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
