<?php
/** @var int $organizationId */
/** @var string $salonName */
/** @var list<array<string, mixed>> $branches */
/** @var list<string> $errors */
/** @var array<string, mixed>|null $flash */
/** @var string $csrf */
/** @var string $title */
$errors = $errors ?? [];
$csrfName = config('app.csrf_token_name', 'csrf_token');
?>
<div class="workspace-shell platform-control-plane">
    <div class="founder-mutation">
        <a class="founder-mutation__back" href="/platform-admin/salons/<?= (int) $organizationId ?>#people">Salon</a>
        <div class="founder-mutation__card">
            <h1 class="founder-mutation__title"><?= htmlspecialchars($title) ?></h1>
            <p class="founder-mutation__hint"><?= htmlspecialchars($salonName) ?></p>
            <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
                <p class="founder-mutation__flash" role="<?= $t === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></p>
            <?php endif; ?>
            <?php foreach ($errors as $err): ?>
                <p class="founder-mutation__flash" role="alert"><?= htmlspecialchars($err) ?></p>
            <?php endforeach; ?>
            <form method="post" action="/platform-admin/salons/<?= (int) $organizationId ?>/people" class="founder-mutation__form">
                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <label for="person-name">Name</label>
                <input type="text" id="person-name" name="name" required maxlength="255" value="<?= htmlspecialchars(trim((string) ($_POST['name'] ?? ''))) ?>">
                <label for="person-email">Login email</label>
                <input type="email" id="person-email" name="email" required autocomplete="off" value="<?= htmlspecialchars(trim((string) ($_POST['email'] ?? ''))) ?>">
                <label for="person-password">Password</label>
                <input type="password" id="person-password" name="password" required minlength="8" autocomplete="new-password" value="">
                <label for="person-branch">Branch</label>
                <select id="person-branch" name="branch_id" required>
                    <?php foreach ($branches as $b): ?>
                        <?php if (!is_array($b)) {
                            continue;
                        } ?>
                        <option value="<?= (int) ($b['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($b['name'] ?? '') . ' #' . (int) ($b['id'] ?? 0)) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="person-role">Role</label>
                <select id="person-role" name="role">
                    <option value="admin"<?= (string) ($_POST['role'] ?? 'admin') === 'reception' ? '' : ' selected' ?>>Admin</option>
                    <option value="reception"<?= (string) ($_POST['role'] ?? '') === 'reception' ? ' selected' : '' ?>>Reception</option>
                </select>
                <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                <div class="founder-mutation__footer">
                    <button type="submit" class="founder-ctl-btn founder-ctl-btn--primary">Add person</button>
                    <a class="founder-mutation__cancel" href="/platform-admin/salons/<?= (int) $organizationId ?>#people">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
