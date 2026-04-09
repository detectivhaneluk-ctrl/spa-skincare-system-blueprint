<?php
$clientsWorkspaceActiveTab = 'intake';
require base_path('modules/clients/views/partials/clients-workspace-data.php');
ob_start();
require base_path('modules/clients/views/partials/clients-workspace-shell.php');
?>
<h2>Consultation Form Templates</h2>
<?php if (!empty($flash['success'])): ?><p><?= htmlspecialchars((string) $flash['success']) ?></p><?php endif; ?>
<?php if (!empty($flash['error'])): ?><p><?= htmlspecialchars((string) $flash['error']) ?></p><?php endif; ?>
<p><a href="/intake/templates/create">Create template</a> · <a href="/intake/assign">Assign form</a> · <a href="/intake/assignments">Assignments</a></p>
<table border="1" cellpadding="6" cellspacing="0">
    <thead><tr><th>Name</th><th>Active</th><th>Required before appt</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($templates as $t): ?>
        <tr>
            <td><?= htmlspecialchars((string) ($t['name'] ?? '')) ?></td>
            <td><?= !empty($t['is_active']) ? 'yes' : 'no' ?></td>
            <td><?= !empty($t['required_before_appointment']) ? 'yes' : 'no' ?></td>
            <td><a href="/intake/templates/<?= (int) $t['id'] ?>/edit">Edit</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
$content = ob_get_clean();
$title = 'Intake templates';
require shared_path('layout/base.php');
