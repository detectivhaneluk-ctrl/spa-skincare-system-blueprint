<?php
/** @var string $csrf */
/** @var string $title */
/** @var array<string, mixed> $model */
/** @var bool $canApply */
$flashMsg = flash();
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
$uid = (int) ($model['user_id'] ?? 0);
$impact = $model['impact'] ?? [];
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
        <a class="tenant-dash-table__link" href="/platform-admin/access/<?= $uid ?>">← User Access</a>
        <span aria-hidden="true"> · </span>
        <a class="tenant-dash-table__link" href="/platform-admin/access">Access list</a>
    </p>

    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars((string) ($model['title'] ?? 'Guided repair')) ?></h1>
            <p class="workspace-module-head__sub">Stepwise repair for blocked tenant accounts — same backend rules as hardened Access actions.</p>
        </div>
    </header>

    <?php if (!empty($model['error_message'])): ?>
        <p class="platform-control-plane__recent-lead" role="alert"><?= htmlspecialchars((string) $model['error_message']) ?></p>
    <?php else: ?>

    <section class="platform-guided-wizard__step platform-impact-panel" aria-label="Step 1 — Diagnosis">
        <h2 class="dashboard-quicklinks__heading">1) Diagnosis</h2>
        <p class="platform-control-plane__recent-lead"><?= htmlspecialchars((string) ($model['diagnosis'] ?? '')) ?></p>
    </section>

    <section class="platform-guided-wizard__step platform-impact-panel" aria-label="Step 2 — Why">
        <h2 class="dashboard-quicklinks__heading">2) Why this happened</h2>
        <p class="platform-control-plane__recent-lead"><?= htmlspecialchars((string) ($model['why'] ?? '')) ?></p>
    </section>

    <section class="platform-guided-wizard__step platform-impact-panel" aria-label="Step 3 — Fixes">
        <h2 class="dashboard-quicklinks__heading">3) Recommended fix</h2>
        <p class="platform-control-plane__recent-lead"><?= htmlspecialchars((string) ($model['recommended_fix'] ?? '')) ?></p>
        <?php if (($model['alternative_fix'] ?? null) !== null && (string) $model['alternative_fix'] !== ''): ?>
            <p class="platform-control-plane__recent-lead"><strong>Alternative:</strong> <?= htmlspecialchars((string) $model['alternative_fix']) ?></p>
        <?php endif; ?>
    </section>

    <section class="platform-guided-wizard__step platform-impact-panel" aria-label="Step 4 — Outcome">
        <h2 class="dashboard-quicklinks__heading">4) What changes / what stays the same</h2>
        <dl class="platform-control-plane__meta">
            <div class="platform-control-plane__meta-row"><dt>After apply</dt><dd><?= htmlspecialchars((string) ($model['after_apply'] ?? '')) ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>Unchanged</dt><dd><?= htmlspecialchars((string) ($model['unchanged'] ?? '')) ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>Reversibility</dt><dd><?= htmlspecialchars((string) ($model['reversibility'] ?? '')) ?></dd></div>
        </dl>
    </section>

    <?php if (($model['scenario'] ?? '') === 'tenant_suspended_organization' && !empty($model['org_recovery_links']) && is_array($model['org_recovery_links'])): ?>
        <section class="platform-impact-panel" aria-label="Organization recovery">
            <h2 class="dashboard-quicklinks__heading">Suspended organization recovery</h2>
            <p class="platform-control-plane__recent-lead">Open guided recovery for the suspended organization first:</p>
            <ul class="tenant-dash-attention__list">
                <?php foreach ($model['org_recovery_links'] as $lnk): ?>
                    <?php if (!is_array($lnk)) { continue; } ?>
                    <li><a class="tenant-dash-table__link" href="<?= htmlspecialchars((string) ($lnk['url'] ?? '#')) ?>"><?= htmlspecialchars((string) ($lnk['label'] ?? 'Open')) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="platform-impact-panel" id="repair" aria-label="Apply">
        <h2 class="dashboard-quicklinks__heading">5) Apply (requires confirmation)</h2>
        <?php if (!$canApply): ?>
            <p class="platform-control-plane__recent-lead">You need <code>platform.organizations.manage</code> to apply changes, or this wizard has no automated action for this state.</p>
        <?php elseif (empty($model['can_apply'])): ?>
            <p class="platform-control-plane__recent-lead" role="status">No automated apply step for this diagnosis — use Diagnostics or other Access tools.</p>
        <?php elseif (($model['apply_kind'] ?? '') === 'activate_user'): ?>
            <form method="post" action="/platform-admin/access/<?= $uid ?>/guided-repair" class="tenant-dash-form-row platform-guided-wizard__form">
                <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="wizard_action" value="activate_user">
                <label class="platform-guided-wizard__confirm"><input type="checkbox" name="confirm_apply" value="1" required> I have read the impact summary and want to reactivate this account.</label>
                <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                <p><button type="submit">Apply — reactivate account</button></p>
            </form>
        <?php elseif (($model['apply_kind'] ?? '') === 'repair_tenant_access'): ?>
            <?php
            $orgs = $model['orgs'] ?? [];
            $branches = $model['branches'] ?? [];
            if (!is_array($orgs)) {
                $orgs = [];
            }
            if (!is_array($branches)) {
                $branches = [];
            }
            ?>
            <?php if ($orgs === [] || $branches === []): ?>
                <p class="platform-control-plane__recent-lead" role="alert">No organizations or branches are available to select — fix registry data before applying repair.</p>
            <?php else: ?>
            <form method="post" action="/platform-admin/access/<?= $uid ?>/guided-repair" class="tenant-dash-form-row platform-guided-wizard__form">
                <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="wizard_action" value="repair_tenant_access">
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
                            ?>
                            <option value="<?= $bid ?>"><?= htmlspecialchars('Org ' . $oid . ' — ' . (string) ($b['name'] ?? '') . ' (#' . $bid . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="platform-guided-wizard__confirm"><input type="checkbox" name="confirm_apply" value="1" required> I confirm the organization and branch match policy; this sets branch pin and active membership.</label>
                <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                <p><button type="submit">Apply — repair branch pin and membership</button></p>
            </form>
            <p class="platform-control-plane__recent-lead" role="note">This is the same operation as the hardened “Repair branch and membership” action — presented here with prerequisites explained.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="platform-control-plane__recent-lead">No apply control rendered for this state.</p>
        <?php endif; ?>
    </section>

    <section class="platform-control-plane__actions" aria-label="Result">
        <h2 class="dashboard-quicklinks__heading">After you finish</h2>
        <p class="platform-control-plane__recent-lead">Return to the user page to verify summary and run diagnostics if needed.</p>
        <p class="platform-control-plane__recent-lead"><a class="tenant-dash-table__link" href="/platform-admin/access/<?= $uid ?>/diagnostics">Open diagnostics</a></p>
    </section>

    <?php endif; ?>
</div>
