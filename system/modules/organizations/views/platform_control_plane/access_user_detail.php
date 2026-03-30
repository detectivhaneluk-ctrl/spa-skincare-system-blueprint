<?php
/** @var string $csrf */
/** @var string $title */
/** @var array<string,mixed> $row */
/** @var array<string,mixed> $shape */
/** @var \Modules\Organizations\Services\FounderAccessPresenter $presenter */
/** @var list<array<string,mixed>> $orgs */
/** @var list<array<string,mixed>> $branches */
/** @var bool $canManage */
/** @var bool $allowSupportEntry */
/** @var array<int|string> $usable */
/** @var array<string, mixed> $userImpact */
/** @var array<string,mixed>|null $flashMsg */
/** @var array<string,mixed> $accessDetailDiagnosis */
$flashMsg = isset($flashMsg) && is_array($flashMsg) ? $flashMsg : [];
/** @var array<string,mixed>|null $founderGuardrailResult */
$founderGuardrailResult = $founderGuardrailResult ?? [];
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
$uid = (int) ($row['id'] ?? 0);
$orgNames = [];
foreach ($orgs as $o) {
    $orgNames[(int) ($o['id'] ?? 0)] = (string) ($o['name'] ?? '');
}
$repHuman = $presenter->humanRepairRecommendations($shape);
$memberships = $shape['organization_memberships'] ?? [];
if (!is_array($memberships)) {
    $memberships = [];
}
$displayName = trim((string) ($row['name'] ?? ''));
$heroTitle = $displayName !== '' ? $displayName : 'User #' . $uid;
$signInBadge = $presenter->accessDetailSignInBadge($shape);
$orgBadge = $presenter->accessDetailOrgBadge($shape);
$branchBadge = $presenter->accessDetailBranchBadge($shape);
$diag = isset($accessDetailDiagnosis) && is_array($accessDetailDiagnosis) ? $accessDetailDiagnosis : [];
$diagTitle = (string) ($diag['title'] ?? 'Needs review');
$diagExplanation = (string) ($diag['explanation'] ?? '');
$diagActionLabel = (string) ($diag['action_label'] ?? '');
$diagActionHref = isset($diag['action_href']) && $diag['action_href'] !== null && $diag['action_href'] !== '' ? (string) $diag['action_href'] : null;
$guidedFirst = !empty($diag['guided_repair_first']);
$healthyCase = !empty($diag['healthy_case']);
$badgeToneClass = static function (string $tone): string {
    return match ($tone) {
        'success' => 'access-record-badge--success',
        'danger' => 'access-record-badge--danger',
        'warn' => 'access-record-badge--warn',
        default => 'access-record-badge--neutral',
    };
};
?>
<div class="workspace-shell platform-control-plane">
    <?php if ($flashMsg !== []): ?>
        <?php if (!empty($flashMsg['success'])): ?>
            <p class="platform-control-plane__recent-lead" role="status"><?= htmlspecialchars((string) $flashMsg['success']) ?></p>
        <?php endif; ?>
        <?php if (!empty($flashMsg['error'])): ?>
            <p class="platform-control-plane__recent-lead" role="alert"><?= htmlspecialchars((string) $flashMsg['error']) ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <?php require base_path('modules/organizations/views/platform_control_plane/partials/founder_guardrail_result.php'); ?>

    <p class="access-record-back">
        <a class="tenant-dash-table__link" href="/platform-admin/access">← Access</a>
    </p>

    <header class="access-record-hero">
        <div class="access-record-hero__main">
            <h1 class="access-record-hero__title"><?= htmlspecialchars($heroTitle) ?></h1>
            <p class="access-record-hero__email"><?= htmlspecialchars((string) ($row['email'] ?? '')) ?></p>
            <p class="access-record-hero__context">
                #<?= $uid ?>
                <span aria-hidden="true"> · </span>
                <?= htmlspecialchars($presenter->humanPrincipalPlane($shape)) ?>
                <span aria-hidden="true"> · </span>
                <?= htmlspecialchars($presenter->humanizeRoleCodes($row['role_codes'] ?? '')) ?>
            </p>
        </div>
        <ul class="access-record-badge-strip" aria-label="Access state">
            <li class="access-record-badge-row">
                <span class="access-record-badge-label">Sign-in</span>
                <span class="access-record-badge <?= $badgeToneClass($signInBadge['tone']) ?>"><?= htmlspecialchars($signInBadge['label']) ?></span>
            </li>
            <li class="access-record-badge-row">
                <span class="access-record-badge-label">Organization</span>
                <span class="access-record-badge <?= $badgeToneClass($orgBadge['tone']) ?>"><?= htmlspecialchars($orgBadge['label']) ?></span>
            </li>
            <li class="access-record-badge-row">
                <span class="access-record-badge-label">Branch</span>
                <span class="access-record-badge <?= $badgeToneClass($branchBadge['tone']) ?>"><?= htmlspecialchars($branchBadge['label']) ?></span>
            </li>
        </ul>
    </header>

    <section class="access-record-diagnosis<?= $healthyCase ? ' access-record-diagnosis--healthy' : '' ?>" aria-labelledby="access-diag-heading">
        <?php if ($healthyCase): ?>
            <div class="access-record-diagnosis__strip">
                <h2 id="access-diag-heading" class="access-record-diagnosis__title access-record-diagnosis__title--compact"><?= htmlspecialchars($diagTitle) ?></h2>
                <p class="access-record-diagnosis__one"><?= htmlspecialchars($diagExplanation) ?> <span class="access-record-diagnosis__muted"><?= htmlspecialchars($diagActionLabel) ?></span></p>
            </div>
        <?php else: ?>
            <div class="access-record-diagnosis__card">
                <h2 id="access-diag-heading" class="access-record-diagnosis__title"><?= htmlspecialchars($diagTitle) ?></h2>
                <p class="access-record-diagnosis__explain"><?= htmlspecialchars($diagExplanation) ?></p>
                <p class="access-record-diagnosis__action">
                    <?php if ($diagActionHref !== null): ?>
                        <a class="tenant-dash-table__link access-record-diagnosis__action-link" href="<?= htmlspecialchars($diagActionHref) ?>"><?= htmlspecialchars($diagActionLabel) ?></a>
                    <?php else: ?>
                        <span class="access-record-diagnosis__action-muted"><?= htmlspecialchars($diagActionLabel) ?></span>
                    <?php endif; ?>
                </p>
                <?php if ($repHuman !== []): ?>
                    <ul class="access-record-diagnosis__hints">
                        <?php foreach (array_slice($repHuman, 0, 3) as $line): ?>
                            <li><?= htmlspecialchars($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($canManage): ?>
        <section class="access-record-primary" aria-labelledby="access-record-primary-title">
            <h2 id="access-record-primary-title" class="access-record-section-heading">Actions</h2>
            <div class="access-record-primary__cluster">
                <?php if ($guidedFirst): ?>
                    <div class="access-record-primary__tier access-record-primary__tier--recommended">
                        <span class="access-record-primary__label">Recommended</span>
                        <div class="access-record-primary__links">
                            <a class="tenant-dash-table__link" href="/platform-admin/access/<?= $uid ?>/guided-repair">Open guided repair</a>
                            <span aria-hidden="true"> · </span>
                            <a class="tenant-dash-table__link" href="/platform-admin/access/<?= $uid ?>/guided-repair/pin">Branch pin</a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="access-record-primary__tier access-record-primary__tier--routine">
                    <div class="access-record-primary__item">
                        <span class="access-record-primary__label">Login</span>
                        <div class="access-record-primary__links">
                            <a class="tenant-dash-table__link" href="/platform-admin/safe-actions/access/<?= $uid ?>/user-activate-preview">Enable</a>
                            <span aria-hidden="true"> · </span>
                            <a class="tenant-dash-table__link" href="/platform-admin/safe-actions/access/<?= $uid ?>/user-deactivate-preview">Disable</a>
                        </div>
                    </div>

                    <?php if (!$guidedFirst): ?>
                        <div class="access-record-primary__item<?= $healthyCase ? ' access-record-primary__item--quiet' : '' ?>">
                            <span class="access-record-primary__label">Guided repair</span>
                            <div class="access-record-primary__links">
                                <a class="tenant-dash-table__link" href="/platform-admin/access/<?= $uid ?>/guided-repair">Open</a>
                                <span aria-hidden="true"> · </span>
                                <a class="tenant-dash-table__link" href="/platform-admin/access/<?= $uid ?>/guided-repair/pin">Branch pin</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($allowSupportEntry): ?>
                        <div class="access-record-primary__item">
                            <span class="access-record-primary__label">Support entry</span>
                            <?php if (count($usable) > 1): ?>
                                <form method="get" action="/platform-admin/safe-actions/support-entry/preview" class="access-record-support-preview">
                                    <input type="hidden" name="tenant_user_id" value="<?= $uid ?>">
                                    <label class="access-record-support-preview__branch">Branch
                                        <select name="branch_id" required>
                                            <?php foreach ($usable as $bid): ?>
                                                <option value="<?= (int) $bid ?>"><?= (int) $bid ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button type="submit" class="access-record-support-preview__btn">Preview</button>
                                </form>
                            <?php else: ?>
                                <div class="access-record-primary__links">
                                    <a class="tenant-dash-table__link" href="/platform-admin/safe-actions/support-entry/preview?tenant_user_id=<?= $uid ?>&branch_id=<?= (int) ($usable[0] ?? 0) ?>">Preview</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php else: ?>
        <p class="platform-control-plane__recent-lead access-record-readonly">Read-only — platform.organizations.manage is required to change access on this page.</p>
    <?php endif; ?>

    <section class="access-record-panel access-record-summary" aria-label="Account summary">
        <h2 class="access-record-section-heading">Summary</h2>
        <dl class="platform-control-plane__meta access-record-panel__dl">
            <div class="platform-control-plane__meta-row"><dt>Access status</dt><dd><?= htmlspecialchars($presenter->humanAccessStatus($shape)) ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>Organization status</dt><dd><?= htmlspecialchars($presenter->humanOrganizationStatus($shape)) ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>Branch access</dt><dd><?= htmlspecialchars($presenter->humanBranchSummary($shape)) ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>Expected destination after sign-in</dt><dd><?= htmlspecialchars($presenter->humanExpectedDestination($shape)) ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>Branch resolution</dt><dd><?= htmlspecialchars($presenter->humanTenantEntryState($shape)) ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>Risk / attention</dt><dd><?= htmlspecialchars($presenter->humanRiskAttention($shape)) ?></dd></div>
        </dl>
    </section>

    <section class="access-record-panel access-record-memberships" aria-label="Organization memberships">
        <h2 class="access-record-section-heading">Memberships</h2>
        <?php if ($memberships === []): ?>
            <p class="access-record-panel__empty">—</p>
        <?php else: ?>
            <div class="tenant-dash-table-wrap">
                <table class="tenant-dash-table">
                    <thead>
                    <tr>
                        <th>Organization</th>
                        <th>Status</th>
                        <th>Default branch</th>
                        <th>Org suspended</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($memberships as $m): ?>
                        <?php if (!is_array($m)) { continue; } ?>
                        <tr>
                            <td><?= htmlspecialchars($orgNames[(int) ($m['organization_id'] ?? 0)] ?? ('#' . (int) ($m['organization_id'] ?? 0))) ?></td>
                            <td><?= htmlspecialchars((string) ($m['status'] ?? '')) ?></td>
                            <td><?= isset($m['default_branch_id']) && $m['default_branch_id'] !== null && $m['default_branch_id'] !== '' ? (int) $m['default_branch_id'] : '—' ?></td>
                            <td><?= !empty($m['org_suspended']) ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($canManage): ?>
        <details class="access-record-advanced-outer"<?= $healthyCase ? '' : ' open' ?>>
            <summary class="access-record-advanced-outer__summary"><span class="access-record-advanced-outer__title">Advanced</span><span class="access-record-advanced-outer__hint"><?= $healthyCase ? 'Optional direct tools' : 'Direct mutations &amp; detail' ?></span></summary>
            <div class="access-record-advanced-outer__body">
                <div class="access-record-advanced__group">
                    <h3 class="access-record-advanced__h">Identity cleanup</h3>
                    <form method="post" action="/platform-admin/access/canonicalize-platform" class="tenant-dash-form-row" onsubmit="return confirm('Strip tenant roles and memberships from this platform principal? This cannot be undone from this screen.');">
                        <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                        <p><button type="submit">Canonicalize founder roles</button></p>
                    </form>
                </div>

                <details class="access-record-advanced__details">
                    <summary>Repair branch and membership (raw)</summary>
                    <p class="access-record-advanced__micro">Branch pin and membership. Prefer guided repair.</p>
                    <p class="access-record-advanced__micro">
                        <a class="tenant-dash-table__link" href="#" id="platform-access-repair-preview">Repair preview</a>
                    </p>
                    <form method="post" action="/platform-admin/access/repair" class="tenant-dash-form-row platform-access-repair-form">
                        <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
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
                        <p class="access-record-advanced__micro">Apply from preview only.</p>
                    </form>
                    <script>
                    (function () {
                        var link = document.getElementById('platform-access-repair-preview');
                        var form = document.querySelector('form.platform-access-repair-form');
                        if (!link || !form) return;
                        link.addEventListener('click', function (e) {
                            e.preventDefault();
                            var oid = form.querySelector('[name="organization_id"]');
                            var bid = form.querySelector('[name="branch_id"]');
                            var orgId = oid && oid.value ? oid.value : '0';
                            var branchId = bid && bid.value ? bid.value : '0';
                            if (parseInt(orgId, 10) <= 0 || parseInt(branchId, 10) <= 0) {
                                window.alert('Choose organization and branch in the form first.');
                                return;
                            }
                            window.location.href = '/platform-admin/safe-actions/access/<?= (int) $uid ?>/repair-preview?organization_id=' + encodeURIComponent(orgId) + '&branch_id=' + encodeURIComponent(branchId);
                        });
                    })();
                    </script>
                </details>

                <div class="access-record-advanced__group">
                    <h3 class="access-record-advanced__h">Membership</h3>
                    <form method="post" action="/platform-admin/access/membership-suspend" class="tenant-dash-form-row" onsubmit="return confirm('Suspend membership for this organization?');">
                        <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <label>Organization
                            <select name="organization_id" required>
                                <?php foreach ($orgs as $o): ?>
                                    <option value="<?= (int) ($o['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($o['name'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                        <p><button type="submit">Suspend</button></p>
                    </form>
                    <form method="post" action="/platform-admin/access/membership-unsuspend" class="tenant-dash-form-row">
                        <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <label>Organization
                            <select name="organization_id" required>
                                <?php foreach ($orgs as $o): ?>
                                    <option value="<?= (int) ($o['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($o['name'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
                        <p><button type="submit">Reactivate</button></p>
                    </form>
                </div>

                <details class="access-record-advanced__details access-record-advanced__details--impact">
                    <summary>Impact detail</summary>
                    <dl class="platform-control-plane__meta access-record-advanced__dl">
                        <div class="platform-control-plane__meta-row"><dt>Cause type</dt><dd><?= htmlspecialchars((string) ($userImpact['cause_kind_label'] ?? '')) ?></dd></div>
                        <div class="platform-control-plane__meta-row"><dt>Exact cause</dt><dd><?= htmlspecialchars((string) ($userImpact['exact_cause'] ?? '')) ?></dd></div>
                        <?php if (!empty($userImpact['cascade_explanation'])): ?>
                            <div class="platform-control-plane__meta-row"><dt>Organization vs branch</dt><dd><?= htmlspecialchars((string) $userImpact['cascade_explanation']) ?></dd></div>
                        <?php endif; ?>
                        <div class="platform-control-plane__meta-row"><dt>Sign-in destination</dt><dd><?= htmlspecialchars((string) ($userImpact['impact_on_destination'] ?? '')) ?></dd></div>
                        <div class="platform-control-plane__meta-row"><dt>After a fix</dt><dd><?= htmlspecialchars((string) ($userImpact['what_changes_when_fixed'] ?? '')) ?></dd></div>
                        <div class="platform-control-plane__meta-row"><dt>Usually unchanged</dt><dd><?= htmlspecialchars((string) ($userImpact['what_stays_unchanged_after_likely_repair'] ?? '')) ?></dd></div>
                        <div class="platform-control-plane__meta-row"><dt>Recommended step</dt><dd><?= htmlspecialchars((string) ($userImpact['safest_next_step'] ?? '')) ?></dd></div>
                        <?php if (($userImpact['alternative_fix'] ?? null) !== null && (string) $userImpact['alternative_fix'] !== ''): ?>
                            <div class="platform-control-plane__meta-row"><dt>Alternative</dt><dd><?= htmlspecialchars((string) $userImpact['alternative_fix']) ?></dd></div>
                        <?php endif; ?>
                        <div class="platform-control-plane__meta-row"><dt>Reversibility</dt><dd><?= htmlspecialchars((string) ($userImpact['reversibility_note'] ?? '')) ?></dd></div>
                        <?php
                        $opLabels = $userImpact['operator_labels'] ?? [];
                        if (is_array($opLabels) && $opLabels !== []):
                        ?>
                        <div class="platform-control-plane__meta-row"><dt>Labels</dt><dd><?= htmlspecialchars(implode(' · ', array_map('strval', $opLabels))) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </details>
            </div>
        </details>
    <?php endif; ?>

    <section class="access-record-diagnostics<?= $healthyCase ? ' access-record-diagnostics--calm' : '' ?>" aria-label="Diagnostics">
        <h2 class="access-record-section-heading access-record-section-heading--footer">Diagnostics</h2>
        <p class="access-record-diagnostics__linkline"><a class="tenant-dash-table__link" href="/platform-admin/access/<?= $uid ?>/diagnostics">Open full diagnostics</a><?= $healthyCase ? '' : ' — raw access-shape output' ?></p>
    </section>
</div>
