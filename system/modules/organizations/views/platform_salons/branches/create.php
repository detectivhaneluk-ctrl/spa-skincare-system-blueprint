<?php
/** @var int $organizationId */
/** @var string $salonName */
/** @var array{name:string,code:string} $branch */
/** @var list<string> $errors */
/** @var array<string, mixed>|null $flash */
/** @var string $csrf */
/** @var string $title */
$errors = $errors ?? [];
$csrfName = config('app.csrf_token_name', 'csrf_token');
?>
<div class="workspace-shell platform-control-plane">
    <div class="founder-mutation">
        <a class="founder-mutation__back" href="/platform-admin/salons/<?= (int) $organizationId ?>#branches">Salon</a>
        <div class="founder-mutation__card">
            <h1 class="founder-mutation__title"><?= htmlspecialchars($title) ?></h1>
            <p class="founder-mutation__hint"><?= htmlspecialchars($salonName) ?></p>
            <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
                <p class="founder-mutation__flash" role="<?= $t === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></p>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <p class="founder-mutation__flash" role="alert"><?= htmlspecialchars($err) ?></p>
            <?php endforeach; ?>
            <form method="post" action="/platform-admin/salons/<?= (int) $organizationId ?>/branches" class="founder-mutation__form">
                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <input type="hidden" name="organization_id" value="<?= (int) $organizationId ?>">
                <label for="branch-name">Name</label>
                <input type="text" id="branch-name" name="name" required maxlength="255" value="<?= htmlspecialchars((string) ($branch['name'] ?? '')) ?>">
                <label for="branch-code">Code <span class="founder-branches-form__optional">optional</span></label>
                <input type="text" id="branch-code" name="code" maxlength="50" value="<?= htmlspecialchars((string) ($branch['code'] ?? '')) ?>" placeholder="Unique if set">
                <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                <div class="founder-mutation__footer">
                    <button type="submit" class="founder-ctl-btn founder-ctl-btn--primary">Add branch</button>
                    <a class="founder-mutation__cancel" href="/platform-admin/salons/<?= (int) $organizationId ?>#branches">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
