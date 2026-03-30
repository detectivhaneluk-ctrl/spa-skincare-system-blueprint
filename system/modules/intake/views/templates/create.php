<?php
ob_start();
?>
<h1>Create intake template</h1>
<?php if (!empty($flash['error'])): ?><p><?= htmlspecialchars((string) $flash['error']) ?></p><?php endif; ?>
<?php foreach ($errors ?? [] as $err): ?><p><?= htmlspecialchars((string) $err) ?></p><?php endforeach; ?>
<form method="post" action="/intake/templates">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <p><label>Name<br><input name="name" value="<?= htmlspecialchars((string) ($template['name'] ?? '')) ?>" required></label></p>
    <p><label>Description<br><textarea name="description" rows="3" cols="50"><?= htmlspecialchars((string) ($template['description'] ?? '')) ?></textarea></label></p>
    <p><label><input type="checkbox" name="is_active" value="1" <?= !empty($template['is_active']) ? 'checked' : '' ?>> Active</label></p>
    <p><label><input type="checkbox" name="required_before_appointment" value="1" <?= !empty($template['required_before_appointment']) ? 'checked' : '' ?>> Required before appointment (blocks new bookings while open pre-booking assignment exists)</label></p>
    <p><button type="submit">Create</button> <a href="/intake/templates">Cancel</a></p>
</form>
<?php
$content = ob_get_clean();
$title = 'Create intake template';
require shared_path('layout/base.php');
