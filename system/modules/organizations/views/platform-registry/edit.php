<?php
$title = $title ?? 'Edit salon';
ob_start();
$org = $org ?? [];
$errors = $errors ?? [];
$id = (int) ($org['id'] ?? 0);
$csrfName = config('app.csrf_token_name', 'csrf_token');
?>
<div class="workspace-shell platform-control-plane">
    <div class="founder-mutation">
        <a class="founder-mutation__back" href="/platform-admin/salons/<?= $id ?>">Salon</a>
        <div class="founder-mutation__card">
            <h1 class="founder-mutation__title"><?= htmlspecialchars($title) ?></h1>
            <p class="founder-mutation__hint">Update the salon name and code.</p>
            <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
                <p class="founder-mutation__flash<?= $t === 'error' ? ' founder-mutation__flash--error' : '' ?>" role="<?= $t === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></p>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <p class="founder-mutation__flash founder-mutation__flash--error" role="alert"><?= htmlspecialchars($err) ?></p>
            <?php endforeach; ?>
            <form method="post" action="/platform-admin/organizations/<?= $id ?>" class="founder-mutation__form founder-mutation__form--identity">
                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <label for="salon-name">Name</label>
                <input type="text" id="salon-name" name="name" required maxlength="255" value="<?= htmlspecialchars((string) ($org['name'] ?? '')) ?>">
                <label for="salon-code">Code</label>
                <p class="founder-mutation__field-muted" id="salon-code-hint-registry">Optional. Leave empty to clear.</p>
                <input type="text" id="salon-code" name="code" maxlength="50" value="<?= htmlspecialchars((string) ($org['code'] ?? '')) ?>" aria-describedby="salon-code-hint-registry">
                <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                <div class="founder-mutation__footer">
                    <button type="submit" class="founder-ctl-btn founder-ctl-btn--primary">Save changes</button>
                    <a class="founder-mutation__cancel" href="/platform-admin/salons/<?= $id ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require shared_path('layout/platform_admin.php');
