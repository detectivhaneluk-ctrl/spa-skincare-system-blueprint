<?php
$title = 'Login';
$hideNav = true;
$content = ob_start();
?>
<div class="auth-card">
    <h1>Login</h1>
    <?php if (!empty($success = ($success ?? null))): ?>
    <div class="flash flash-success"><?= htmlspecialchars((string) $success) ?></div>
    <?php endif; ?>
    <?php if ($error = ($error ?? null)): ?>
    <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/login" class="auth-form">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <div>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <div>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button type="submit">Login</button>
    </form>
    <p class="auth-hint"><a href="/password/reset">Forgot your password?</a></p>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
