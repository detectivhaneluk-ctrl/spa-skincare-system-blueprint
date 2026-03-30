<?php
/** @var array<string, mixed>|null $flash */
/** @var array<string, mixed> $data */
/** @var bool $canManage */
$data = is_array($data ?? null) ? $data : [];
$salon = is_array($data['salon'] ?? null) ? $data['salon'] : [];
$primary = $data['primary_admin'] ?? null;
$primary = is_array($primary) ? $primary : null;
$branches = is_array($data['branches'] ?? null) ? $data['branches'] : [];
$problems = is_array($data['problems'] ?? null) ? $data['problems'] : [];
$dangerActions = is_array($data['danger_actions'] ?? null) ? $data['danger_actions'] : [];
$hero = is_array($data['hero_lifecycle'] ?? null) ? $data['hero_lifecycle'] : [];
$peopleSection = is_array($data['people_section'] ?? null) ? $data['people_section'] : ['rows' => [], 'can_add' => false, 'add_blocked_hint' => null];
$pplRows = is_array($peopleSection['rows'] ?? null) ? $peopleSection['rows'] : [];
$pplCanAdd = !empty($peopleSection['can_add']);
$pplHint = $peopleSection['add_blocked_hint'] ?? null;
$pplHint = is_string($pplHint) ? $pplHint : null;
$sid = (int) ($salon['id'] ?? 0);
$lifecycle = (string) ($salon['lifecycle_status'] ?? '');
$code = $salon['code'] ?? null;
$codeStr = ($code !== null && $code !== '') ? (string) $code : null;
$issueCount = count($problems);
$issueCountLabel = $issueCount === 1 ? '1 issue' : $issueCount . ' issues';
$branchCount = (int) ($salon['branch_count'] ?? 0);
$branchCountLabel = $branchCount === 1 ? '1 branch' : $branchCount . ' branches';
$archivedSalon = ($lifecycle === 'archived');
$suspendedSalon = ($lifecycle === 'suspended');
$canAddBranch = !empty($canManage) && !$archivedSalon && !$suspendedSalon;
$canEditSalon = !empty($canManage) && !$archivedSalon;
?>
<div class="founder-record">
    <a class="founder-record__back" href="/platform-admin/salons">Salons</a>

    <?php if ($flash && is_array($flash)): ?>
        <?php if (!empty($flash['error'])): ?>
            <p class="founder-record__flash" role="alert"><?= htmlspecialchars((string) $flash['error']) ?></p>
        <?php endif; ?>
        <?php if (!empty($flash['success'])): ?>
            <p class="founder-record__flash" role="status"><?= htmlspecialchars((string) $flash['success']) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <header class="founder-record__hero">
        <h1 class="founder-record__title"><?= htmlspecialchars((string) ($salon['name'] ?? 'Salon')) ?></h1>
        <div class="founder-record__hero-meta">
            <span class="founder-record__status founder-record__status--<?= htmlspecialchars($lifecycle) ?>"><?= htmlspecialchars($lifecycle) ?></span>
            <?php if ($codeStr !== null): ?>
                <code class="founder-record__code"><?= htmlspecialchars($codeStr) ?></code>
            <?php else: ?>
                <span class="founder-record__muted">No code</span>
            <?php endif; ?>
            <span class="founder-record__id">#<?= $sid ?></span>
        </div>
        <?php
        $heroPrimary = isset($hero['primary']) && is_array($hero['primary']) ? $hero['primary'] : null;
        $heroArchive = isset($hero['archive']) && is_array($hero['archive']) ? $hero['archive'] : null;
        ?>
        <?php if (!empty($canManage) && ($heroPrimary !== null || $heroArchive !== null)): ?>
            <div class="founder-record__hero-lifecycle" role="group" aria-label="Salon status">
                <?php if ($heroPrimary !== null): ?>
                    <?php
                    $hv = (string) ($heroPrimary['variant'] ?? 'primary');
                    $hCls = $hv === 'caution'
                        ? 'founder-ctl-btn founder-ctl-btn--primary founder-ctl-btn--caution founder-record__hero-ctl'
                        : 'founder-ctl-btn founder-ctl-btn--primary founder-record__hero-ctl';
                    ?>
                    <a class="<?= htmlspecialchars($hCls) ?>" href="<?= htmlspecialchars((string) ($heroPrimary['url'] ?? '#')) ?>"><?= htmlspecialchars((string) ($heroPrimary['label'] ?? '')) ?></a>
                <?php endif; ?>
                <?php if ($heroArchive !== null): ?>
                    <?php if (!empty($heroArchive['blocked'])): ?>
                        <span class="founder-record__archive-quiet"><?= htmlspecialchars((string) ($heroArchive['label'] ?? '')) ?> — <?= htmlspecialchars((string) ($heroArchive['note'] ?? '')) ?></span>
                    <?php elseif (!empty($heroArchive['url'])): ?>
                        <a class="founder-ctl-btn founder-ctl-btn--secondary founder-record__hero-ctl" href="<?= htmlspecialchars((string) $heroArchive['url']) ?>"><?= htmlspecialchars((string) ($heroArchive['label'] ?? '')) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($problems === []): ?>
        <div class="founder-issues__strip" role="status" aria-label="Issues">
            <span class="founder-issues__strip-title">No issues</span>
            <span class="founder-issues__strip-dot" aria-hidden="true">·</span>
            <span class="founder-issues__strip-sub">This salon looks operational</span>
        </div>
    <?php else: ?>
        <section id="issues" class="founder-record__section founder-record__section--issues" aria-labelledby="fr-issues-heading">
            <div class="founder-issues__head">
                <div class="founder-issues__head-text">
                    <h2 id="fr-issues-heading" class="founder-record__h">Issues</h2>
                    <span class="founder-issues__count"><?= htmlspecialchars($issueCountLabel) ?></span>
                </div>
            </div>
            <ul class="founder-issues__list">
                <?php foreach ($problems as $p): ?>
                    <?php if (!is_array($p)) {
                        continue;
                    } ?>
                    <?php
                    $sev = (string) ($p['severity'] ?? 'medium');
                    $sevLabel = match ($sev) {
                        'high', 'critical' => 'High',
                        'low' => 'Low',
                        default => 'Medium',
                    };
                    $detail = trim((string) ($p['detail'] ?? ''));
                    $action = $p['action'] ?? null;
                    $action = is_array($action) ? $action : null;
                    ?>
                    <li class="founder-issue founder-issue--<?= htmlspecialchars($sev === 'critical' ? 'high' : $sev) ?>">
                        <div class="founder-issue__head">
                            <span class="founder-issue__title"><?= htmlspecialchars((string) ($p['title'] ?? '')) ?></span>
                            <span class="founder-issue__severity founder-issue__severity--<?= htmlspecialchars($sev) ?>"><?= htmlspecialchars($sevLabel) ?></span>
                        </div>
                        <?php if ($detail !== ''): ?>
                            <p class="founder-issue__detail"><?= htmlspecialchars($detail) ?></p>
                        <?php endif; ?>
                        <?php if ($action !== null && !empty($action['href']) && !empty($action['label'])): ?>
                            <div class="founder-issue__action">
                                <?php if (($action['mode'] ?? '') === 'quiet'): ?>
                                    <a class="founder-issue__quiet" href="<?= htmlspecialchars((string) $action['href']) ?>"><?= htmlspecialchars((string) $action['label']) ?></a>
                                <?php else: ?>
                                    <a class="founder-ctl-btn founder-ctl-btn--secondary founder-ctl-btn--compact" href="<?= htmlspecialchars((string) $action['href']) ?>"><?= htmlspecialchars((string) $action['label']) ?></a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <section class="founder-record__section founder-record__section--admin-access" id="admin-access" aria-labelledby="admin-access-heading">
        <h2 id="admin-access-heading" class="founder-record__h">Admin access</h2>

        <div class="founder-access__state-strip founder-access__state-strip--account-only" aria-label="Admin account login state">
            <?php if ($primary !== null): ?>
                <?php $admLoginOn = !empty($primary['login_enabled']); ?>
                <div class="founder-access__state-item">
                    <span class="founder-access__state-label">Admin login</span>
                    <span class="founder-access__pill<?= $admLoginOn ? ' founder-access__pill--on' : ' founder-access__pill--off' ?>"><?= $admLoginOn ? 'On' : 'Off' ?></span>
                </div>
            <?php else: ?>
                <div class="founder-access__state-item">
                    <span class="founder-access__state-label">Admin account</span>
                    <span class="founder-access__pill founder-access__pill--none">None</span>
                </div>
            <?php endif; ?>
        </div>

        <?php
        $salonSuspended = ($lifecycle === 'suspended');
        $adminLoginOn = $primary !== null && !empty($primary['login_enabled']);
        ?>
        <?php if ($salonSuspended && $adminLoginOn): ?>
            <p class="founder-access__layer-hint">Salon suspension blocks tenant entry.</p>
        <?php endif; ?>

        <?php if ($primary === null): ?>
            <div class="founder-access__empty">
                <p class="founder-record__lede">No primary admin resolved for this salon.</p>
                <?php if (!empty($canManage)): ?>
                    <a class="founder-ctl-btn founder-ctl-btn--primary founder-ctl-btn--block" href="/platform-admin/access/provision">Provision access</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="founder-access__card">
                <div class="founder-access__info">
                    <dl class="founder-access__dl">
                        <div class="founder-access__row"><dt>Admin name</dt><dd><?= htmlspecialchars((string) ($primary['name'] ?? '')) ?></dd></div>
                        <div class="founder-access__row"><dt>Login email</dt><dd class="founder-access__mono"><?= htmlspecialchars((string) ($primary['email'] ?? '')) ?></dd></div>
                        <div class="founder-access__row"><dt>Account</dt><dd><?= !empty($primary['login_enabled']) ? 'Enabled' : 'Disabled' ?></dd></div>
                        <div class="founder-access__row"><dt>Password changed</dt><dd><?php $pwc = $primary['password_changed_at'] ?? null; echo $pwc !== null && $pwc !== '' ? htmlspecialchars((string) $pwc) : '<span class="founder-record__muted">Not available</span>'; ?></dd></div>
                    </dl>
                    <?php if (!empty($primary['admin_access_note'])): ?>
                        <p class="founder-access__note"><?= htmlspecialchars((string) $primary['admin_access_note']) ?></p>
                    <?php endif; ?>
                </div>
                <?php
                $acts = $primary['actions'] ?? [];
                $acts = is_array($acts) ? $acts : [];
                ?>
                <?php if ($acts !== []): ?>
                    <div class="founder-access__actions" role="group" aria-label="Admin account actions">
                        <?php foreach ($acts as $act): ?>
                            <?php if (!is_array($act)) {
                                continue;
                            } ?>
                            <?php
                            $key = (string) ($act['key'] ?? '');
                            $btnClass = 'founder-ctl-btn founder-ctl-btn--block founder-ctl-btn--secondary';
                            if ($key === 'disable_login') {
                                $btnClass = 'founder-ctl-btn founder-ctl-btn--block founder-ctl-btn--caution';
                            }
                            ?>
                            <a class="<?= htmlspecialchars($btnClass) ?>" href="<?= htmlspecialchars((string) ($act['url'] ?? '#')) ?>"><?= htmlspecialchars((string) ($act['label'] ?? '')) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section id="people" class="founder-record__section founder-record__section--people" aria-labelledby="fr-people-heading">
        <div class="founder-people__head">
            <h2 id="fr-people-heading" class="founder-record__h">People</h2>
            <?php if (!empty($canManage) && $pplCanAdd): ?>
                <a class="founder-ctl-btn founder-ctl-btn--primary founder-people__add" href="/platform-admin/salons/<?= $sid ?>/people/create">Add person</a>
            <?php endif; ?>
        </div>
        <?php if (!empty($canManage) && !$pplCanAdd && $pplHint !== null && $pplHint !== ''): ?>
            <p class="founder-record__muted founder-people__hint"><?= htmlspecialchars($pplHint) ?></p>
        <?php endif; ?>
        <?php if ($pplRows === []): ?>
            <div class="founder-people__empty">
                <p class="founder-record__lede">No linked accounts yet.</p>
            </div>
        <?php else: ?>
            <ul class="founder-people__list">
                <?php foreach ($pplRows as $pr): ?>
                    <?php if (!is_array($pr)) {
                        continue;
                    } ?>
                    <li class="founder-people__card">
                        <div class="founder-people__main">
                            <div class="founder-people__name-row">
                                <span class="founder-people__name"><?= htmlspecialchars((string) ($pr['name'] ?? '')) ?></span>
                                <?php if (!empty($pr['is_primary_admin'])): ?>
                                    <span class="founder-people__badge">Primary admin</span>
                                <?php endif; ?>
                            </div>
                            <div class="founder-people__meta">
                                <span class="founder-people__email"><?= htmlspecialchars((string) ($pr['email'] ?? '')) ?></span>
                                <span class="founder-people__role"><?= htmlspecialchars((string) ($pr['role_label'] ?? '')) ?></span>
                                <?php $on = !empty($pr['login_enabled']); ?>
                                <span class="founder-access__pill<?= $on ? ' founder-access__pill--on' : ' founder-access__pill--off' ?> founder-people__pill"><?= $on ? 'Enabled' : 'Disabled' ?></span>
                            </div>
                        </div>
                        <?php if (!empty($canManage)): ?>
                            <div class="founder-people__action">
                                <a class="founder-ctl-btn founder-ctl-btn--secondary founder-ctl-btn--compact" href="<?= htmlspecialchars((string) ($pr['access_url'] ?? '#')) ?>">Open in Access</a>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="founder-record__section founder-record__section--branches" id="branches" aria-labelledby="fr-branches-heading">
        <div class="founder-branches__head">
            <div class="founder-branches__head-text">
                <h2 id="fr-branches-heading" class="founder-record__h">Branches</h2>
                <span class="founder-branches__count"><?= htmlspecialchars($branchCountLabel) ?></span>
            </div>
            <?php if ($canAddBranch): ?>
                <a class="founder-ctl-btn founder-ctl-btn--primary founder-branches__add" href="/platform-admin/salons/<?= $sid ?>/branches/create">Add branch</a>
            <?php endif; ?>
        </div>
        <?php if ($branchCount > 0 && !$archivedSalon): ?>
            <p class="founder-branches__archive-hint">Archive needs zero branches.</p>
        <?php endif; ?>
        <?php if ($branches === []): ?>
            <div class="founder-branches__empty">
                <p class="founder-record__lede">No branches yet.</p>
                <?php if (!empty($canManage) && $suspendedSalon): ?>
                    <p class="founder-record__muted">Reactivate the salon to add a branch.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <ul class="founder-branches__list">
                <?php foreach ($branches as $b): ?>
                    <?php if (!is_array($b)) {
                        continue;
                    } ?>
                    <?php $bid = (int) ($b['id'] ?? 0); ?>
                    <li class="founder-branch-card">
                        <div class="founder-branch-card__main">
                            <span class="founder-branch-card__name"><?= htmlspecialchars((string) ($b['name'] ?? '')) ?></span>
                            <div class="founder-branch-card__meta">
                                <span class="founder-branch-card__id">#<?= $bid ?></span>
                                <?php $bcode = $b['code'] ?? null; ?>
                                <?php if ($bcode !== null && $bcode !== ''): ?>
                                    <code class="founder-branch-card__code"><?= htmlspecialchars((string) $bcode) ?></code>
                                <?php else: ?>
                                    <span class="founder-record__muted">No code</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($canManage) && !$archivedSalon): ?>
                            <div class="founder-branch-card__action">
                                <a class="founder-ctl-btn founder-ctl-btn--secondary founder-ctl-btn--compact" href="/platform-admin/salons/<?= $sid ?>/branches/<?= $bid ?>/edit">Edit</a>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="founder-record__section founder-record__section--general" aria-labelledby="fr-general">
        <div class="founder-record__section-head founder-record__section-head--general">
            <h2 id="fr-general" class="founder-record__h">General</h2>
            <?php if ($canEditSalon): ?>
                <a class="founder-ctl-btn founder-ctl-btn--secondary founder-record__general-edit" href="/platform-admin/salons/<?= $sid ?>/edit">Edit salon</a>
            <?php endif; ?>
        </div>
        <dl class="founder-record__dl">
            <div class="founder-record__row"><dt>Created</dt><dd><?= htmlspecialchars((string) ($salon['created_at'] ?? '')) ?></dd></div>
            <div class="founder-record__row"><dt>Updated</dt><dd><?= htmlspecialchars((string) ($salon['updated_at'] ?? '')) ?></dd></div>
            <div class="founder-record__row"><dt>Branches</dt><dd><?= (int) ($salon['branch_count'] ?? 0) ?></dd></div>
            <div class="founder-record__row">
                <dt>Plan</dt>
                <dd class="founder-record__plan"><?php $pl = (string) ($salon['plan_summary'] ?? '—'); echo ($pl === '' || $pl === '—') ? '<span class="founder-record__muted">Not set</span>' : htmlspecialchars($pl); ?></dd>
            </div>
        </dl>
    </section>

    <?php if ($dangerActions !== []): ?>
        <section class="founder-record__section founder-record__danger founder-record__section--danger-zone" aria-labelledby="fr-danger-heading">
            <h2 id="fr-danger-heading" class="founder-record__h">Danger zone</h2>
            <ul class="founder-record__danger-list">
                <?php foreach ($dangerActions as $da): ?>
                    <?php if (!is_array($da)) {
                        continue;
                    } ?>
                    <li class="founder-record__danger-row">
                        <?php if (!empty($da['url'])): ?>
                            <a class="founder-ctl-btn founder-ctl-btn--caution founder-ctl-btn--block" href="<?= htmlspecialchars((string) $da['url']) ?>"><?= htmlspecialchars((string) ($da['label'] ?? 'Action')) ?></a>
                        <?php else: ?>
                            <span class="founder-record__danger-label"><?= htmlspecialchars((string) ($da['label'] ?? '')) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($da['note'])): ?>
                            <p class="founder-record__danger-note"><?= htmlspecialchars((string) $da['note']) ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

</div>
