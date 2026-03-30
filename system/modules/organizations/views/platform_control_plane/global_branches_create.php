<?php
/** @var string $csrf */
/** @var string $title */
/** @var list<array{id:int,name:string}> $orgs */
/** @var array{name:string,code:string,organization_id:int} $branch */
/** @var list<string> $errors */
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
$flashMsg = flash();
?>
<div class="workspace-shell platform-control-plane">
    <?php if (is_array($flashMsg) && !empty($flashMsg['error'])): ?>
        <p class="platform-control-plane__recent-lead" role="alert"><?= htmlspecialchars((string) $flashMsg['error']) ?></p>
    <?php endif; ?>
    <?php $pagePurposeKey = 'branch_create'; require base_path('modules/organizations/views/platform_control_plane/partials/founder_page_purpose_panel.php'); ?>
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($title) ?></h1>
            <p class="workspace-module-head__sub">Creates a branch row tied to an organization. Codes must be unique across the whole system.</p>
        </div>
    </header>
    <p class="platform-control-plane__recent-lead"><a class="tenant-dash-table__link" href="/platform-admin/branches">← Branches</a></p>

    <?php if ($errors !== []): ?>
        <ul class="tenant-dash-attention__list">
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="/platform-admin/branches" class="tenant-dash-form-row platform-control-plane__actions">
        <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <label>Organization
            <select name="organization_id" required>
                <option value="0">— Select —</option>
                <?php foreach ($orgs as $o): ?>
                    <option value="<?= (int) ($o['id'] ?? 0) ?>"<?= (int) ($branch['organization_id'] ?? 0) === (int) ($o['id'] ?? 0) ? ' selected' : '' ?>><?= htmlspecialchars((string) ($o['name'] ?? '')) ?> (id <?= (int) ($o['id'] ?? 0) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Name <input type="text" name="name" required maxlength="255" value="<?= htmlspecialchars((string) ($branch['name'] ?? '')) ?>"></label>
        <label>Code <input type="text" name="code" maxlength="50" placeholder="optional" value="<?= htmlspecialchars((string) ($branch['code'] ?? '')) ?>"></label>
        <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
        <button type="submit">Create branch</button>
    </form>
</div>
