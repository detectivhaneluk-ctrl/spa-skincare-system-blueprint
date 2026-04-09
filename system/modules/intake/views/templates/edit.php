<?php
$clientsWorkspaceActiveTab = 'intake';
require base_path('modules/clients/views/partials/clients-workspace-data.php');
ob_start();
require base_path('modules/clients/views/partials/clients-workspace-shell.php');
?>
<h2>Edit intake template</h2>
<?php if (!empty($flash['success'])): ?><p><?= htmlspecialchars((string) $flash['success']) ?></p><?php endif; ?>
<?php if (!empty($flash['error'])): ?><p><?= htmlspecialchars((string) $flash['error']) ?></p><?php endif; ?>
<p><a href="/intake/templates">All templates</a> · <a href="/intake/assign">Assign</a> · <a href="/intake/assignments">Assignments</a></p>

<h2>Details</h2>
<form method="post" action="/intake/templates/<?= (int) $template['id'] ?>">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <p><label>Name<br><input name="name" value="<?= htmlspecialchars((string) ($template['name'] ?? '')) ?>" required></label></p>
    <p><label>Description<br><textarea name="description" rows="3" cols="50"><?= htmlspecialchars((string) ($template['description'] ?? '')) ?></textarea></label></p>
    <p><label><input type="checkbox" name="is_active" value="1" <?= !empty($template['is_active']) ? 'checked' : '' ?>> Active</label></p>
    <p><label><input type="checkbox" name="required_before_appointment" value="1" <?= !empty($template['required_before_appointment']) ? 'checked' : '' ?>> Required before appointment (blocks new bookings while open pre-booking assignment exists)</label></p>
    <p><button type="submit">Save</button></p>
</form>

<h2>Fields</h2>
<table border="1" cellpadding="6" cellspacing="0">
    <thead><tr><th>Key</th><th>Label</th><th>Type</th><th>Required</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($fields as $f): ?>
        <tr>
            <td><?= htmlspecialchars((string) ($f['field_key'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($f['label'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($f['field_type'] ?? '')) ?></td>
            <td><?= !empty($f['required']) ? 'yes' : 'no' ?></td>
            <td>
                <form method="post" action="/intake/templates/<?= (int) $template['id'] ?>/fields/<?= (int) $f['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this field?');">
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <button type="submit">Remove</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h3>Add field</h3>
<p>Types: text, textarea, checkbox, select, date, email, phone, number.</p>
<form method="post" action="/intake/templates/<?= (int) $template['id'] ?>/fields">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <p><label>field_key (a-z, 0-9, _)<br><input name="field_key" required pattern="[a-z0-9_]{1,64}"></label></p>
    <p><label>Label<br><input name="label" required></label></p>
    <p><label>Type<br>
        <select name="field_type" required>
            <?php foreach (\Modules\Intake\Services\IntakeFormService::FIELD_TYPES as $ft): ?>
                <option value="<?= htmlspecialchars($ft) ?>"><?= htmlspecialchars($ft) ?></option>
            <?php endforeach; ?>
        </select>
    </label></p>
    <p><label><input type="checkbox" name="required" value="1"> Required</label></p>
    <p><label>Select options (one per line, for type select only)<br><textarea name="options_lines" rows="4" cols="40"></textarea></label></p>
    <p><button type="submit">Add field</button></p>
</form>
<?php
$content = ob_get_clean();
$title = 'Edit intake template';
require shared_path('layout/base.php');
