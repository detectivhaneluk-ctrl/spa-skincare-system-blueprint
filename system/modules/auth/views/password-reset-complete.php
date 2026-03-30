<?php
$title = 'Choose a new password';
$hideNav = true;
$token = $token ?? '';
$hasToken = $token !== '' && preg_match('/^[a-f0-9]{64}$/', $token);
$content = ob_start();
?>
<div class="auth-card">
    <h1>Choose a new password</h1>
    <?php if (!empty($error)): ?>
    <div class="flash flash-error"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>
    <?php if (!$hasToken): ?>
    <p class="auth-hint">This reset link is missing or invalid. Request a new link from the login page.</p>
    <p class="auth-hint"><a href="/password/reset">Request password reset</a> · <a href="/login">Back to login</a></p>
    <?php else: ?>
    <form method="post" action="/password/reset/complete" class="auth-form">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <div>
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" required minlength="8" autofocus>
        </div>
        <div>
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
        </div>
        <button type="submit">Update password</button>
    </form>
    <?php endif; ?>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
