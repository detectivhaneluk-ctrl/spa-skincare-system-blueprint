<?php
/** @var int $salonId */
/** @var array<string, mixed> $admin */
/** @var list<string> $errors */
/** @var array<string, mixed>|null $flash */
/** @var string $csrf */
$errors = $errors ?? [];
$csrfName = config('app.csrf_token_name', 'csrf_token');
$current = (string) ($admin['email'] ?? '');
?>
<div class="workspace-shell platform-control-plane">
    <div class="founder-mutation">
        <a class="founder-mutation__back" href="/platform-admin/salons/<?= (int) $salonId ?>#admin-access">Salon</a>
        <div class="founder-mutation__card">
            <h1 class="founder-mutation__title">Change login email</h1>
            <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
                <p class="founder-mutation__flash" role="<?= $t === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></p>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <p class="founder-mutation__flash" role="alert"><?= htmlspecialchars($err) ?></p>
            <?php endforeach; ?>
            <dl class="founder-mutation__dl">
                <div class="founder-mutation__dl-row"><dt>Current</dt><dd><?= htmlspecialchars($current) ?></dd></div>
            </dl>
            <form method="post" action="/platform-admin/salons/<?= (int) $salonId ?>/admin-access/email" class="founder-mutation__form">
                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <label for="admin-email">New email</label>
                <input type="email" id="admin-email" name="email" required maxlength="255" autocomplete="off" value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>">
                <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                <div class="founder-mutation__footer">
                    <button type="submit" class="founder-ctl-btn founder-ctl-btn--primary">Save</button>
                    <a class="founder-mutation__cancel" href="/platform-admin/salons/<?= (int) $salonId ?>#admin-access">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
