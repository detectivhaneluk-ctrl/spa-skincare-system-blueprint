<?php
/** @var string $csrf */
/** @var string $title */
/** @var array<string, mixed> $org */
/** @var array<string, mixed> $orgImpact */
/** @var bool $suspended */
/** @var bool $canApply */
$id = (int) ($org['id'] ?? 0);
$flashMsg = flash();
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
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

    <p class="platform-control-plane__recent-lead">
        <a class="tenant-dash-table__link" href="/platform-admin/organizations/<?= $id ?>">← Organization</a>
    </p>

    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($title) ?></h1>
            <p class="workspace-module-head__sub">Clears organization suspension so tenant branches and memberships can operate again.</p>
        </div>
    </header>

    <section class="platform-impact-panel" aria-label="Diagnosis">
        <h2 class="dashboard-quicklinks__heading">1) Organization state</h2>
        <p class="platform-control-plane__recent-lead"><?= $suspended ? 'This organization is currently suspended.' : 'This organization is not suspended — recovery is not needed here.' ?></p>
    </section>

    <section class="platform-impact-panel" aria-label="Affected scale">
        <h2 class="dashboard-quicklinks__heading">2) Affected branches and users (registry truth)</h2>
        <dl class="platform-control-plane__meta">
            <div class="platform-control-plane__meta-row"><dt>Branches (non-deleted)</dt><dd><?= (int) ($orgImpact['branch_count'] ?? 0) ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>Users tied via active membership (or pin fallback)</dt><dd><?= (int) ($orgImpact['login_capable_user_count'] ?? 0) ?></dd></div>
            <?php if ($suspended): ?>
                <div class="platform-control-plane__meta-row"><dt>Users blocked by this suspension</dt><dd><?= (int) ($orgImpact['users_blocked_by_this_org_suspension'] ?? 0) ?></dd></div>
            <?php endif; ?>
        </dl>
        <?php if (!empty($orgImpact['detail_lines']) && is_array($orgImpact['detail_lines'])): ?>
            <ul class="tenant-dash-attention__list">
                <?php foreach ($orgImpact['detail_lines'] as $line): ?>
                    <li><?= htmlspecialchars((string) $line) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="platform-impact-panel" aria-label="What changes">
        <h2 class="dashboard-quicklinks__heading">3) What reactivation changes</h2>
        <p class="platform-control-plane__recent-lead"><strong>Changes:</strong> Clears <code>suspended_at</code> on this organization so tenant routing and branch operations can proceed under normal rules.</p>
        <p class="platform-control-plane__recent-lead"><strong>Unchanged:</strong> User rows, roles, membership rows (except what operators edit elsewhere), and other organizations.</p>
        <p class="platform-control-plane__recent-lead"><strong>Reversibility:</strong> You can suspend the organization again from the organization page if policy requires.</p>
    </section>

    <section class="platform-impact-panel" aria-label="Apply">
        <h2 class="dashboard-quicklinks__heading">4) Confirm and apply</h2>
        <?php if (!$suspended): ?>
            <p class="platform-control-plane__recent-lead" role="status">No reactivation to run.</p>
        <?php elseif (!$canApply): ?>
            <p class="platform-control-plane__recent-lead">Read-only: <code>platform.organizations.manage</code> required to reactivate.</p>
        <?php else: ?>
            <form method="post" action="/platform-admin/organizations/<?= $id ?>/guided-recovery" class="tenant-dash-form-row platform-guided-wizard__form">
                <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <label class="platform-guided-wizard__confirm"><input type="checkbox" name="confirm_reactivate" value="1" required> I confirm reactivation is intended and understand tenant users will be able to proceed per access-shape.</label>
                <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                <p><button type="submit">Apply — reactivate organization</button></p>
            </form>
        <?php endif; ?>
    </section>
</div>
