<?php
$content = ob_start();
?>
<div class="auth-card">
    <h1>Select your branch</h1>
    <p>Choose an active salon branch to continue.</p>
    <form method="post" action="/account/branch-context" class="auth-form">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
        <input type="hidden" name="redirect_to" value="/dashboard">
        <div>
            <label for="branch_id">Branch</label>
            <select id="branch_id" name="branch_id" required>
                <?php foreach (($branches ?? []) as $branch): ?>
                <option value="<?= (int) ($branch['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($branch['name'] ?? 'Branch')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit">Continue</button>
    </form>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
