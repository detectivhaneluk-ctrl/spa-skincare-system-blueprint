<?php
$title = $title ?? 'New branch';
$content = ob_start();
$branch = $branch ?? ['name' => '', 'code' => ''];
$errors = $errors ?? [];
$csrfName = config('app.csrf_token_name', 'csrf_token');
?>
<h1>New branch</h1>
<p><a href="/branches">← Branches</a></p>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
<div class="flash flash-error"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>
<form method="post" action="/branches">
    <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
    <div class="setting-row">
        <label for="branch-name">Name</label>
        <input type="text" id="branch-name" name="name" required maxlength="255" value="<?= htmlspecialchars((string) ($branch['name'] ?? '')) ?>">
    </div>
    <div class="setting-row">
        <label for="branch-code">Code (optional)</label>
        <input type="text" id="branch-code" name="code" maxlength="50" value="<?= htmlspecialchars((string) ($branch['code'] ?? '')) ?>" placeholder="e.g. MAIN">
    </div>
    <p><button type="submit">Create</button></p>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
