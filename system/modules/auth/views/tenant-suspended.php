<?php
$hideNav = true;
$csrf = $csrf ?? (\Core\App\Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken());
$content = ob_start();
?>
<div class="auth-card">
    <h1>Tenant access suspended</h1>
    <p>This organization is currently suspended and tenant access is disabled.</p>
    <p>Please contact the platform administrator for reactivation.</p>
    <form method="post" action="/logout" class="auth-form">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit">Sign out</button>
    </form>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
