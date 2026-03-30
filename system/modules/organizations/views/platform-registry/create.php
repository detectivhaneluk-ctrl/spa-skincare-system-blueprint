<?php
$title = $title ?? 'New organization';
ob_start();
$org = $org ?? ['name' => '', 'code' => ''];
$errors = $errors ?? [];
$csrfName = config('app.csrf_token_name', 'csrf_token');
?>
<div class="workspace-shell platform-control-plane">
    <p class="platform-control-plane__recent-lead"><a class="tenant-dash-table__link" href="/platform-admin/salons">← Salons</a></p>
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title">New organization</h1>
            <p class="workspace-module-head__sub">Adds a registry row. Branches and users are managed elsewhere in the control plane.</p>
        </div>
    </header>
    <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
        <p class="platform-control-plane__recent-lead" role="<?= $t === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <p class="platform-control-plane__recent-lead" role="alert"><?= htmlspecialchars($err) ?></p>
    <?php endforeach; ?>
    <form method="post" action="/platform-admin/organizations" class="tenant-dash-form-row">
        <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
        <label for="org-name">Name</label>
        <input type="text" id="org-name" name="name" required maxlength="255" value="<?= htmlspecialchars((string) ($org['name'] ?? '')) ?>">
        <label for="org-code">Code (optional)</label>
        <input type="text" id="org-code" name="code" maxlength="50" value="<?= htmlspecialchars((string) ($org['code'] ?? '')) ?>">
        <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
        <p><button type="submit">Create organization</button></p>
    </form>
</div>
<?php
$content = ob_get_clean();
require shared_path('layout/platform_admin.php');
