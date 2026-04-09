<?php
$title = 'Staff Groups';
ob_start();
$teamWorkspaceActiveTab = 'groups';
$teamWorkspaceShellTitle = 'Team';
require base_path('modules/staff/views/partials/team-workspace-shell.php');
?>
<div class="page-header">
    <h2 class="page-header__title">Staff Groups</h2>
    <a href="/staff/groups/admin/create" class="btn btn--primary">Create Group</a>
</div>

<?php if (!empty($flash['success'])): ?>
<div class="flash flash--success" role="status"><?= htmlspecialchars((string) $flash['success'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (!empty($flash['error'])): ?>
<div class="flash flash--error" role="alert"><?= htmlspecialchars((string) $flash['error'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<table class="data-table">
    <thead>
        <tr>
            <th>Name</th>
            <th>Description</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($groups)): ?>
        <tr>
            <td colspan="4" class="data-table__empty">No staff groups found. <a href="/staff/groups/admin/create">Create the first one.</a></td>
        </tr>
        <?php else: ?>
        <?php foreach ($groups as $g): ?>
        <tr class="<?= empty($g['is_active']) ? 'data-table__row--inactive' : '' ?>">
            <td><?= htmlspecialchars((string) ($g['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) ($g['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
            <td>
                <?php if (!empty($g['is_active'])): ?>
                <span class="badge badge--active">Active</span>
                <?php else: ?>
                <span class="badge badge--inactive">Inactive</span>
                <?php endif; ?>
            </td>
            <td>
                <a href="/staff/groups/admin/<?= (int) $g['id'] ?>/edit" class="btn btn--sm btn--secondary">Edit</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
