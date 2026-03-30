<?php
/** @var int $salonId */
/** @var array<string, mixed> $admin */
/** @var string $csrf */
/** @var string $title */
/** @var string $action */
$csrfName = config('app.csrf_token_name', 'csrf_token');
$isDisable = ($action ?? '') === 'disable';
$postUrl = $isDisable
    ? '/platform-admin/salons/' . (int) $salonId . '/admin-access/disable-login'
    : '/platform-admin/salons/' . (int) $salonId . '/admin-access/enable-login';
$label = $isDisable ? 'Sign-in stops until you enable login again.' : 'Sign-in can resume when access rules allow.';
?>
<div class="workspace-shell platform-control-plane">
    <div class="founder-mutation">
        <a class="founder-mutation__back" href="/platform-admin/salons/<?= (int) $salonId ?>#admin-access">Salon</a>
        <div class="founder-mutation__card">
            <h1 class="founder-mutation__title"><?= htmlspecialchars($title) ?></h1>
            <dl class="founder-mutation__dl">
                <div class="founder-mutation__dl-row"><dt>Account</dt><dd><?= htmlspecialchars((string) ($admin['email'] ?? '')) ?></dd></div>
                <div class="founder-mutation__dl-row"><dt>Name</dt><dd><?= htmlspecialchars((string) ($admin['name'] ?? '')) ?></dd></div>
            </dl>
            <p class="founder-mutation__hint"><?= htmlspecialchars($label) ?></p>
            <form method="post" action="<?= htmlspecialchars($postUrl) ?>" class="founder-mutation__form">
                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <label for="action-reason">Reason (audit)</label>
                <textarea id="action-reason" name="action_reason" required minlength="<?= (int) \Modules\Organizations\Services\FounderSafeActionGuardrailService::MIN_REASON_LENGTH ?>" rows="3" cols="40"></textarea>
                <label class="founder-mutation__check">
                    <input type="checkbox" name="confirm_high_impact" value="1" required>
                    Confirm
                </label>
                <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                <div class="founder-mutation__footer">
                    <button type="submit" class="founder-ctl-btn <?= $isDisable ? 'founder-ctl-btn--caution' : 'founder-ctl-btn--primary' ?>"><?= $isDisable ? 'Disable login' : 'Enable login' ?></button>
                    <a class="founder-mutation__cancel" href="/platform-admin/salons/<?= (int) $salonId ?>#admin-access">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
