<?php
$title = $title ?? 'Organization';
ob_start();
$org = $org ?? [];
$id = (int) ($org['id'] ?? 0);
$csrfName = config('app.csrf_token_name', 'csrf_token');
/** @var array<string, mixed> $orgImpact */
$orgImpact = is_array($orgImpact ?? null) ? $orgImpact : [];
?>
<div class="workspace-shell platform-control-plane">
    <p class="platform-control-plane__recent-lead">
        <a class="tenant-dash-table__link" href="/platform-admin/organizations">← Organizations</a>
        · <a class="tenant-dash-table__link" href="/platform-admin">Dashboard</a>
        <?php if (!empty($canManageOrganizations)): ?>
            · <a class="tenant-dash-table__link" href="/platform-admin/organizations/<?= $id ?>/edit">Edit</a>
        <?php endif; ?>
    </p>
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars((string) ($org['name'] ?? 'Organization')) ?></h1>
            <p class="workspace-module-head__sub">Registry record · ID <?= $id ?></p>
        </div>
    </header>
    <?php if (!empty($flash) && is_array($flash)): ?>
        <?php if (!empty($flash['error'])): ?>
            <p class="platform-control-plane__recent-lead" role="alert"><?= htmlspecialchars((string) $flash['error']) ?></p>
        <?php endif; ?>
        <?php if (!empty($flash['success'])): ?>
            <p class="platform-control-plane__recent-lead" role="status"><?= htmlspecialchars((string) $flash['success']) ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <?php
    $founderGuardrailResult = $founderGuardrailResult ?? null;
    require base_path('modules/organizations/views/platform_control_plane/partials/founder_guardrail_result.php');
    ?>

    <?php $pagePurposeKey = 'organization_detail'; require base_path('modules/organizations/views/platform_control_plane/partials/founder_page_purpose_panel.php'); ?>

    <section class="platform-impact-panel" aria-label="Operational impact">
        <h2 class="dashboard-quicklinks__heading">Operational impact (summary)</h2>
        <p class="platform-control-plane__recent-lead"><?= htmlspecialchars((string) ($orgImpact['blast_radius_summary'] ?? '')) ?></p>
        <p class="platform-control-plane__recent-lead"><strong>Safest next step:</strong> <?= htmlspecialchars((string) ($orgImpact['recommended_action'] ?? '')) ?></p>
        <p class="platform-control-plane__recent-lead" role="note"><strong>Wrong page?</strong> Reactivating the org may restore downstream tenant access, but affected users may still need review in <a class="tenant-dash-table__link" href="/platform-admin/access">Access</a>.</p>
        <details class="platform-impact-panel platform-impact-panel--advanced founder-details-nested">
            <summary><span class="dashboard-quicklinks__heading">Advanced diagnostics — full organization impact</span></summary>
            <dl class="platform-control-plane__meta">
                <div class="platform-control-plane__meta-row">
                    <dt>Problem nature</dt>
                    <dd><?= htmlspecialchars((string) ($orgImpact['problem_nature_label'] ?? '')) ?></dd>
                </div>
                <div class="platform-control-plane__meta-row">
                    <dt>Tenant/company lifecycle</dt>
                    <dd><?= htmlspecialchars((string) ($orgImpact['lifecycle'] ?? '')) ?></dd>
                </div>
                <div class="platform-control-plane__meta-row">
                    <dt>Blast radius (summary)</dt>
                    <dd><?= htmlspecialchars((string) ($orgImpact['blast_radius_summary'] ?? '')) ?></dd>
                </div>
                <div class="platform-control-plane__meta-row">
                    <dt>Affected branches</dt>
                    <dd><?= (int) ($orgImpact['branch_count'] ?? 0) ?> non-deleted branch row(s)</dd>
                </div>
                <div class="platform-control-plane__meta-row">
                    <dt>Login-capable users tied here</dt>
                    <dd><?= (int) ($orgImpact['login_capable_user_count'] ?? 0) ?> (active memberships, or branch-pin fallback if memberships are unavailable)</dd>
                </div>
                <?php if (((int) ($orgImpact['users_blocked_by_this_org_suspension'] ?? 0)) > 0): ?>
                    <div class="platform-control-plane__meta-row">
                        <dt>Users blocked by this suspension</dt>
                        <dd><?= (int) $orgImpact['users_blocked_by_this_org_suspension'] ?> account(s) with active membership on this organization while it is suspended</dd>
                    </div>
                <?php endif; ?>
                <div class="platform-control-plane__meta-row">
                    <dt>Stays blocked until</dt>
                    <dd><?= htmlspecialchars((string) ($orgImpact['stays_blocked_until'] ?? '')) ?></dd>
                </div>
                <div class="platform-control-plane__meta-row">
                    <dt>Downstream effect</dt>
                    <dd><?= htmlspecialchars((string) ($orgImpact['downstream_access_note'] ?? '')) ?> <a class="tenant-dash-table__link" href="/platform-admin/incidents">Incident Center</a></dd>
                </div>
                <div class="platform-control-plane__meta-row">
                    <dt>If reactivation is not the right fix</dt>
                    <dd><?= htmlspecialchars((string) ($orgImpact['if_reactivation_not_appropriate'] ?? '')) ?></dd>
                </div>
                <div class="platform-control-plane__meta-row">
                    <dt>Tenant public / customer impact</dt>
                    <dd><?= htmlspecialchars((string) ($orgImpact['tenant_public_surface_note'] ?? '')) ?></dd>
                </div>
                <div class="platform-control-plane__meta-row">
                    <dt>Deployment-wide public stops</dt>
                    <dd><?php
                        $kc = (int) ($orgImpact['deployment_kill_switch_count'] ?? 0);
                        echo $kc > 0
                            ? (int) $kc . ' kill switch(es) on — review '
                            : 'None on — still available in ';
                        ?><a class="tenant-dash-table__link" href="/platform-admin/security">Security</a>.</dd>
                </div>
            </dl>
            <?php if (!empty($orgImpact['detail_lines']) && is_array($orgImpact['detail_lines'])): ?>
                <ul class="tenant-dash-attention__list">
                    <?php foreach ($orgImpact['detail_lines'] as $line): ?>
                        <li><?= htmlspecialchars((string) $line) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </details>
    </section>

    <?php if (!empty($canManageOrganizations) && empty($org['suspended_at']) && empty($org['deleted_at'])): ?>
        <section class="platform-impact-panel" aria-label="Suspend organization">
            <h2 class="dashboard-quicklinks__heading">Suspend (high impact)</h2>
            <p class="platform-control-plane__recent-lead">
                <a class="tenant-dash-table__link" href="/platform-admin/safe-actions/organizations/<?= $id ?>/suspend-preview">Review and suspend organization</a>
                — preview impact, enter an operational reason, and confirm before the registry row is suspended.
            </p>
        </section>
    <?php endif; ?>

    <?php if (!empty($canManageOrganizations) && !empty($org['suspended_at']) && empty($org['deleted_at'])): ?>
        <section class="platform-impact-panel" aria-label="Guided recovery">
            <h2 class="dashboard-quicklinks__heading">Guided recovery</h2>
            <p class="platform-control-plane__recent-lead">
                <a class="tenant-dash-table__link" href="/platform-admin/organizations/<?= $id ?>/guided-recovery">Open suspended-organization recovery wizard</a>
                — confirmation, impact recap, and reactivation with audit.
            </p>
        </section>
    <?php endif; ?>

    <div class="tenant-dash-table-wrap">
        <table class="tenant-dash-table">
            <tbody>
            <tr><th scope="row">ID</th><td><?= $id ?></td></tr>
            <tr><th scope="row">Name</th><td><?= htmlspecialchars((string) ($org['name'] ?? '')) ?></td></tr>
            <tr><th scope="row">Code</th><td><code><?= htmlspecialchars((string) ($org['code'] ?? '')) ?></code></td></tr>
            <tr><th scope="row">Created</th><td><?= htmlspecialchars((string) ($org['created_at'] ?? '')) ?></td></tr>
            <tr><th scope="row">Updated</th><td><?= htmlspecialchars((string) ($org['updated_at'] ?? '')) ?></td></tr>
            <tr><th scope="row">Suspended at</th><td><?= htmlspecialchars((string) ($org['suspended_at'] ?? '')) ?: '—' ?></td></tr>
            <tr><th scope="row">Deleted at</th><td><?= htmlspecialchars((string) ($org['deleted_at'] ?? '')) ?: '—' ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php if (!empty($canManageOrganizations)): ?>
        <section class="platform-control-plane__actions" aria-label="Organization lifecycle">
            <h2 class="dashboard-quicklinks__heading">Lifecycle</h2>
            <?php if (empty($org['suspended_at'])): ?>
                <p class="platform-control-plane__recent-lead">Suspension is applied only from the <a class="tenant-dash-table__link" href="/platform-admin/safe-actions/organizations/<?= $id ?>/suspend-preview">suspend preview</a> (reason + confirmation + audit).</p>
            <?php else: ?>
                <p class="platform-control-plane__recent-lead">Reactivation is applied only from the <a class="tenant-dash-table__link" href="/platform-admin/safe-actions/organizations/<?= $id ?>/reactivate-preview">reactivate preview</a> (reason + confirmation + audit).</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require shared_path('layout/platform_admin.php');
