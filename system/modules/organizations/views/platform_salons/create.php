<?php
/** @var string $title */
/** @var array{name?: string, code?: string|null} $org */
/** @var list<string> $errors */
/** @var array<string, mixed>|null $flash */
/** @var string $csrf */
$org = $org ?? ['name' => '', 'code' => ''];
$errors = $errors ?? [];
$csrfName = config('app.csrf_token_name', 'csrf_token');
?>
<div class="workspace-shell platform-control-plane">
    <p class="platform-control-plane__recent-lead"><a class="tenant-dash-table__link" href="/platform-admin/salons">← Salons</a></p>
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title">Add salon</h1>
            <p class="workspace-module-head__sub">Creates a tenant organization record. Branches and access are managed elsewhere.</p>
        </div>
    </header>
    <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
        <p class="platform-control-plane__recent-lead" role="<?= $t === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></p>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?>
        <p class="platform-control-plane__recent-lead" role="alert"><?= htmlspecialchars($err) ?></p>
    <?php endforeach; ?>
    <form method="post" action="/platform-admin/salons" class="tenant-dash-form-row">
        <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
        <label for="salon-name">Salon name</label>
        <input type="text" id="salon-name" name="name" required maxlength="255" value="<?= htmlspecialchars((string) ($org['name'] ?? '')) ?>">
        <label for="salon-code">Code (optional)</label>
        <input type="text" id="salon-code" name="code" maxlength="50" value="<?= htmlspecialchars((string) ($org['code'] ?? '')) ?>">
        <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
        <p><button type="submit">Create salon</button></p>
    </form>
</div>
