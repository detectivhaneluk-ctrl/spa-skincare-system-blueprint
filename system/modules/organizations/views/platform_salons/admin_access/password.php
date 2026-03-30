<?php
/** @var int $salonId */
/** @var array<string, mixed> $admin */
/** @var list<string> $errors */
/** @var array<string, mixed>|null $flash */
/** @var string $csrf */
$errors = $errors ?? [];
$csrfName = config('app.csrf_token_name', 'csrf_token');
?>
<div class="workspace-shell platform-control-plane">
    <div class="founder-mutation">
        <a class="founder-mutation__back" href="/platform-admin/salons/<?= (int) $salonId ?>#admin-access">Salon</a>
        <div class="founder-mutation__card">
            <h1 class="founder-mutation__title">Set new password</h1>
            <p class="founder-mutation__hint">Minimum 8 characters.</p>
            <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
                <p class="founder-mutation__flash" role="<?= $t === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></p>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <p class="founder-mutation__flash" role="alert"><?= htmlspecialchars($err) ?></p>
            <?php endforeach; ?>
            <form method="post" action="/platform-admin/salons/<?= (int) $salonId ?>/admin-access/password" class="founder-mutation__form" autocomplete="off">
                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <label for="pw1">New password</label>
                <input type="password" id="pw1" name="password" required minlength="8" autocomplete="new-password">
                <label for="pw2">Confirm</label>
                <input type="password" id="pw2" name="password_confirm" required minlength="8" autocomplete="new-password">
                <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                <div class="founder-mutation__footer">
                    <button type="submit" class="founder-ctl-btn founder-ctl-btn--primary">Save</button>
                    <a class="founder-mutation__cancel" href="/platform-admin/salons/<?= (int) $salonId ?>#admin-access">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
