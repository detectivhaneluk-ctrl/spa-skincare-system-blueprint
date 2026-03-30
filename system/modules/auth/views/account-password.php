<?php
$title = 'Change password';
$hideNav = true;
$content = ob_start();
?>
<div class="auth-card">
    <h1>Change password</h1>
    <?php if ($error = ($error ?? null)): ?>
    <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success = ($success ?? null)): ?>
    <div class="flash flash-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <form method="post" action="/account/password" class="auth-form">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <div>
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password" required autofocus>
        </div>
        <div>
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" required minlength="8">
        </div>
        <div>
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
        </div>
        <button type="submit">Update password</button>
    </form>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
