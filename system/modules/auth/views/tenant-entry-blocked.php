<?php
$hideNav = true;
$content = ob_start();
?>
<div class="auth-card">
    <h1>Access unavailable</h1>
    <p>No active salon branch is available for this account.</p>
    <p>Please contact your administrator.</p>
    <form method="post" action="/logout" class="auth-form">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
        <button type="submit">Sign out</button>
    </form>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
