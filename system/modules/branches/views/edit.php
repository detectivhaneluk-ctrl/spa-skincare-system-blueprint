<?php
$title = $title ?? 'Edit branch';
$branch = $branch ?? [];
$errors = $errors ?? [];
$id = (int) ($branch['id'] ?? 0);
$csrfName = config('app.csrf_token_name', 'csrf_token');
$isInactive = !empty($branch['deleted_at']);
ob_start();
require base_path('modules/branches/views/partials/branches-workspace-shell.php');
?>
<h2>Edit branch</h2>
<?php if ($isInactive): ?>
<div class="flash flash-error">This branch is inactive (soft-deleted). You can still edit the record; it will not appear in operational selectors until restored (not available in UI).</div>
<?php endif; ?>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
<div class="flash flash-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
<form method="post" action="/branches/<?= $id ?>">
    <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
    <div class="setting-row">
        <label for="branch-name">Name</label>
        <input type="text" id="branch-name" name="name" required maxlength="255" value="<?= htmlspecialchars((string) ($branch['name'] ?? '')) ?>">
    </div>
    <div class="setting-row">
        <label for="branch-code">Code (optional)</label>
        <input type="text" id="branch-code" name="code" maxlength="50" value="<?= htmlspecialchars((string) ($branch['code'] ?? '')) ?>">
    </div>
    <p><button type="submit">Save</button> <a href="/branches">Cancel</a></p>
</form>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
?>
