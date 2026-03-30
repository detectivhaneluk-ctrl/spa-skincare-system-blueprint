<?php
/** @var string $csrf */
/** @var string $title */
/** @var list<array<string,mixed>> $orgs */
/** @var list<array<string,mixed>> $branches */
$flashMsg = flash();
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
$orgNames = [];
foreach ($orgs as $o) {
    $orgNames[(int) ($o['id'] ?? 0)] = (string) ($o['name'] ?? '');
}
?>
<div class="workspace-shell platform-control-plane">
    <?php if (is_array($flashMsg)): ?>
        <?php if (!empty($flashMsg['success'])): ?>
            <p class="platform-control-plane__recent-lead" role="status"><?= htmlspecialchars((string) $flashMsg['success']) ?></p>
        <?php endif; ?>
        <?php if (!empty($flashMsg['error'])): ?>
            <p class="platform-control-plane__recent-lead" role="alert"><?= htmlspecialchars((string) $flashMsg['error']) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php $pagePurposeKey = 'access_provision'; require base_path('modules/organizations/views/platform_control_plane/partials/founder_page_purpose_panel.php'); ?>

    <p class="platform-control-plane__recent-lead">
        <a class="tenant-dash-table__link" href="/platform-admin/access">← Access</a>
        · <a class="tenant-dash-table__link" href="/platform-admin">Dashboard</a>
    </p>

    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($title) ?></h1>
            <p class="workspace-module-head__sub">Create new tenant logins with a valid access shape. Confirm organization and branch before submitting.</p>
        </div>
    </header>

    <section class="platform-control-plane__actions" aria-label="Provision tenant admin">
        <h2 class="dashboard-quicklinks__heading">Tenant admin</h2>
        <form method="post" action="/platform-admin/access/provision-admin" class="tenant-dash-form-row">
            <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <label>Email <input type="email" name="email" required autocomplete="off"></label>
            <label>Name <input type="text" name="name" required></label>
            <label>Password <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
            <label>Organization
                <select name="organization_id" required>
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int) ($o['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($o['name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Branch
                <select name="branch_id" required>
                    <?php foreach ($branches as $b): ?>
                        <?php
                        $bid = (int) ($b['id'] ?? 0);
                        $oid = (int) ($b['organization_id'] ?? 0);
                        $oname = $orgNames[$oid] ?? ('Org #' . $oid);
                        ?>
                        <option value="<?= $bid ?>"><?= htmlspecialchars($oname . ' — ' . (string) ($b['name'] ?? '') . ' (#' . $bid . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
            <p><button type="submit">Create tenant admin</button></p>
        </form>
    </section>

    <section class="platform-control-plane__actions" aria-label="Provision reception staff">
        <h2 class="dashboard-quicklinks__heading">Reception / staff</h2>
        <form method="post" action="/platform-admin/access/provision-staff" class="tenant-dash-form-row">
            <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <label>Email <input type="email" name="email" required autocomplete="off"></label>
            <label>Name <input type="text" name="name" required></label>
            <label>Password <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
            <label>Organization
                <select name="organization_id" required>
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= (int) ($o['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($o['name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Branch
                <select name="branch_id" required>
                    <?php foreach ($branches as $b): ?>
                        <?php
                        $bid = (int) ($b['id'] ?? 0);
                        $oid = (int) ($b['organization_id'] ?? 0);
                        $oname = $orgNames[$oid] ?? ('Org #' . $oid);
                        ?>
                        <option value="<?= $bid ?>"><?= htmlspecialchars($oname . ' — ' . (string) ($b['name'] ?? '') . ' (#' . $bid . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
            <p><button type="submit">Create reception user</button></p>
        </form>
    </section>
</div>
