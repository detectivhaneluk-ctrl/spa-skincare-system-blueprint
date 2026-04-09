<?php
$clientsWorkspaceActiveTab = 'intake';
require base_path('modules/clients/views/partials/clients-workspace-data.php');
ob_start();
require base_path('modules/clients/views/partials/clients-workspace-shell.php');
?>
<h2>Assign intake form</h2>
<?php if (!empty($flash['error'])): ?><p><?= htmlspecialchars((string) $flash['error']) ?></p><?php endif; ?>
<p><a href="/intake/templates">Templates</a> · <a href="/intake/assignments">Assignments</a></p>
<?php if (empty($templates)): ?>
    <p>No active templates. <a href="/intake/templates/create">Create one</a> and add fields first.</p>
<?php else: ?>
<form method="post" action="/intake/assign">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <p><label>Template<br>
        <select name="template_id" required>
            <?php foreach ($templates as $t): ?>
                <option value="<?= (int) $t['id'] ?>"><?= htmlspecialchars((string) ($t['name'] ?? '')) ?></option>
            <?php endforeach; ?>
        </select>
    </label></p>
    <p><label>Client ID<br><input name="client_id" type="number" min="1" required></label></p>
    <p><label>Appointment ID (optional)<br><input name="appointment_id" type="number" min="1"></label></p>
    <p><button type="submit">Create assignment</button></p>
</form>
<?php endif; ?>
<?php
$content = ob_get_clean();
$title = 'Assign intake form';
require shared_path('layout/base.php');
