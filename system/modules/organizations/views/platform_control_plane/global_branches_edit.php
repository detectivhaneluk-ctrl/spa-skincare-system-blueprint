<?php
/** @var string $csrf */
/** @var string $title */
/** @var array<string,mixed> $branch */
/** @var list<string> $errors */
/** @var bool $canManage */
/** @var array<string, mixed> $branchImpact */
$branchImpact = $branchImpact ?? [];
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
$flashMsg = flash();
$bid = (int) ($branch['id'] ?? 0);
$oid = (int) ($branch['organization_id'] ?? 0);
?>
<div class="workspace-shell platform-control-plane">
    <?php if (is_array($flashMsg) && !empty($flashMsg['error'])): ?>
        <p class="platform-control-plane__recent-lead" role="alert"><?= htmlspecialchars((string) $flashMsg['error']) ?></p>
    <?php endif; ?>
    <?php
    $pagePurposeKey = 'branch_edit';
    $pagePurpose = \Core\App\Application::container()->get(\Modules\Organizations\Services\FounderPagePurposePresenter::class)->forPage('branch_edit');
    if ($oid > 0) {
        array_unshift($pagePurpose['next_best'], ['label' => 'This salon', 'href' => '/platform-admin/salons/' . $oid]);
    }
    require base_path('modules/organizations/views/platform_control_plane/partials/founder_page_purpose_panel.php');
    ?>
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($title) ?></h1>
            <p class="workspace-module-head__sub">Organization assignment is fixed here; use registry workflows if ownership must change.</p>
        </div>
    </header>
    <p class="platform-control-plane__recent-lead"><a class="tenant-dash-table__link" href="/platform-admin/branches">← Branches</a></p>

    <dl class="platform-control-plane__meta" aria-label="Branch ownership">
        <div class="platform-control-plane__meta-row">
            <dt>Organization</dt>
            <dd><?= htmlspecialchars((string) ($branch['organization_name'] ?? '')) ?> <span class="platform-control-plane__recent-lead">(id <?= $oid ?>)</span></dd>
        </div>
    </dl>

    <section class="platform-impact-panel" aria-label="Operational impact">
            <h2 class="dashboard-quicklinks__heading">Operational impact (summary)</h2>
            <p class="platform-control-plane__recent-lead"><strong>Safest next step:</strong> <?= htmlspecialchars((string) ($branchImpact['recommended_action'] ?? '')) ?></p>
            <?php $nameCodeWarn = trim((string) ($branchImpact['name_code_edit_warning'] ?? '')); ?>
            <?php if ($nameCodeWarn !== ''): ?>
            <p class="platform-control-plane__recent-lead" role="note"><strong>Wrong page?</strong> Editing branch name or code will not fix a suspended-organization access incident — <?= htmlspecialchars($nameCodeWarn) ?></p>
            <?php endif; ?>
            <p class="platform-control-plane__recent-lead">
                <a class="tenant-dash-table__link" href="/platform-admin/salons/<?= $oid ?>">Open salon</a>
                <?php if (!empty($branch['org_suspended_at'])): ?>
                    <span aria-hidden="true"> · </span>
                    <a class="tenant-dash-table__link" href="/platform-admin/organizations/<?= $oid ?>/guided-recovery">Guided org recovery</a>
                <?php endif; ?>
                <span aria-hidden="true"> · </span>
                <a class="tenant-dash-table__link" href="/platform-admin/access">Access</a>
            </p>
            <details class="platform-impact-panel platform-impact-panel--advanced founder-details-nested">
                <summary><span class="dashboard-quicklinks__heading">Advanced diagnostics — full branch impact</span></summary>
                <dl class="platform-control-plane__meta">
                    <div class="platform-control-plane__meta-row">
                        <dt>Owning organization</dt>
                        <dd><?= htmlspecialchars((string) ($branchImpact['org_lifecycle'] ?? '')) ?></dd>
                    </div>
                    <div class="platform-control-plane__meta-row">
                        <dt>Blocked before tenant entry (org suspension)</dt>
                        <dd><?= !empty($branchImpact['cascade_from_org_suspension']) ? 'Yes — downstream effect of the owning organization’s suspended state.' : 'No — organization is active (subject to user access-shape).' ?></dd>
                    </div>
                    <div class="platform-control-plane__meta-row">
                        <dt>Operationally blocked</dt>
                        <dd><?= !empty($branchImpact['operationally_blocked']) ? 'Yes — tenant workflows for this location are blocked while the organization is suspended.' : 'No — organization is active (subject to user access-shape).' ?></dd>
                    </div>
                    <div class="platform-control-plane__meta-row">
                        <dt>Affected users (linked)</dt>
                        <dd><?= (int) ($branchImpact['distinct_user_link_count'] ?? 0) ?> distinct active user(s) (branch pin and/or default branch on membership)</dd>
                    </div>
                    <div class="platform-control-plane__meta-row">
                        <dt>Access behavior</dt>
                        <dd><?= htmlspecialchars((string) ($branchImpact['access_behavior_note'] ?? '')) ?></dd>
                    </div>
                    <div class="platform-control-plane__meta-row">
                        <dt>Wrong page for org suspension?</dt>
                        <dd><?= htmlspecialchars((string) ($branchImpact['wrong_page_warning'] ?? '')) ?></dd>
                    </div>
                </dl>
                <?php if (!empty($branchImpact['detail_lines']) && is_array($branchImpact['detail_lines'])): ?>
                    <ul class="tenant-dash-attention__list">
                        <?php foreach ($branchImpact['detail_lines'] as $line): ?>
                            <li><?= htmlspecialchars((string) $line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </details>
        </section>

    <?php if ($errors !== []): ?>
        <ul class="tenant-dash-attention__list">
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($canManage && (($branch['deleted_at'] ?? null) === null || (string) ($branch['deleted_at'] ?? '') === '')): ?>
        <p class="platform-control-plane__recent-lead">
            <a class="tenant-dash-table__link" href="/platform-admin/safe-actions/branches/<?= $bid ?>/deactivate-preview">Deactivate branch (preview, reason, audit)</a>
        </p>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <form method="post" action="/platform-admin/branches/<?= $bid ?>" class="tenant-dash-form-row platform-control-plane__actions">
            <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <label>Name <input type="text" name="name" required maxlength="255" value="<?= htmlspecialchars((string) ($branch['name'] ?? '')) ?>"></label>
            <label>Code <input type="text" name="code" maxlength="50" placeholder="optional" value="<?= htmlspecialchars((string) ($branch['code'] ?? '')) ?>"></label>
            <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
            <button type="submit">Save</button>
        </form>
    <?php else: ?>
        <p class="platform-control-plane__recent-lead">Read-only: manage permission required to edit.</p>
    <?php endif; ?>
</div>
