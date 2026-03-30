<?php
$title = 'Reset password';
$hideNav = true;
$content = ob_start();
?>
<div class="auth-card">
    <h1>Reset password</h1>
    <?php if (!empty($success)): ?>
    <div class="flash flash-success"><?= htmlspecialchars((string) $success) ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
    <div class="flash flash-error"><?= htmlspecialchars((string) $error) ?></div>
    <?php endif; ?>
    <p class="auth-hint">Enter the email address for your staff account. If it exists, we will send a reset link.</p>
    <form method="post" action="/password/reset" class="auth-form">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <div>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <button type="submit">Send reset link</button>
    </form>
    <p class="auth-hint"><a href="/login">Back to login</a></p>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
