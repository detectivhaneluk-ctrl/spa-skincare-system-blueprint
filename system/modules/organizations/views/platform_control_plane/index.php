<?php
/** @var array<string, mixed> $platform */
/** @var \Modules\Organizations\Services\FounderAccessPresenter $founderPresenter */
$header = $platform['header'] ?? [];
$cards = $platform['cards'] ?? [];
$actions = $platform['actions'] ?? [];
$recentOrgs = $platform['recent_organizations'] ?? [];
$cc = $platform['command_center'] ?? [];
$counts = $cc['access_shape_counts'] ?? [];
$kill = $cc['kill_switches'] ?? ['kill_online_booking' => false, 'kill_anonymous_public_apis' => false, 'kill_public_commerce' => false];
$recentActions = $cc['recent_actions'] ?? [];
$activeSalons = (int) (($cards[0]['value'] ?? 0));
$suspendedSalons = (int) (($cards[1]['value'] ?? 0));
$founderAccounts = (int) ($counts['founder'] ?? 0);
$evaluatedAccounts = (int) ($cc['access_accounts_evaluated'] ?? 0);
$accessEvalErrors = (int) ($cc['access_eval_errors'] ?? 0);
$blockedAccess = (int) ($counts['tenant_orphan_blocked'] ?? 0);
$suspendedBinding = (int) ($counts['tenant_suspended_organization'] ?? 0);
$multiBranch = (int) ($counts['tenant_multi_branch'] ?? 0);
$branchesUnderSuspended = (int) ($cc['branches_under_suspended_orgs'] ?? 0);
$killSwitchActive = !empty($kill['kill_online_booking']) || !empty($kill['kill_anonymous_public_apis']) || !empty($kill['kill_public_commerce']);
$hasAttention = $blockedAccess > 0 || $suspendedBinding > 0 || $multiBranch > 0 || $branchesUnderSuspended > 0 || $accessEvalErrors > 0 || $killSwitchActive;
$incidentSignals = $accessEvalErrors;
$bindingAnomalies = $suspendedBinding + $branchesUnderSuspended;
$healthStatusSentence = 'Platform healthy.';
if ($blockedAccess > 0) {
    $healthStatusSentence = 'Attention needed in access.';
} elseif ($killSwitchActive) {
    $healthStatusSentence = 'Public risk requires review.';
} elseif ($incidentSignals > 0) {
    $healthStatusSentence = 'Attention needed in incidents.';
}

$primaryActionTitle = 'No urgent action';
$primaryActionTruth = 'No high-priority issues in the latest scan.';
$primaryActionHref = '/platform-admin/incidents';
$primaryActionCta = 'Review incidents';
if ($blockedAccess > 0) {
    $primaryActionTitle = 'Review blocked access';
    $primaryActionTruth = $blockedAccess . ' account(s) need access repair.';
    $primaryActionHref = '/platform-admin/access';
    $primaryActionCta = 'Open Access';
} elseif ($incidentSignals > 0) {
    $primaryActionTitle = 'Review incidents';
    $primaryActionTruth = $incidentSignals . ' signal(s) need diagnosis.';
    $primaryActionHref = '/platform-admin/incidents';
    $primaryActionCta = 'Open Incident Center';
} elseif ($killSwitchActive) {
    $primaryActionTitle = 'Check security state';
    $primaryActionTruth = 'Public risk controls are active.';
    $primaryActionHref = '/platform-admin/security';
    $primaryActionCta = 'Open Security';
}
?>
<div class="workspace-shell platform-control-plane">
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars((string) ($header['title'] ?? '')) ?></h1>
            <p class="workspace-module-head__sub">See platform health and act where needed.</p>
        </div>
    </header>

    <section class="dashboard-summary platform-control-plane__snap" aria-label="Health">
        <h2 class="dashboard-summary__heading">HEALTH</h2>
        <p class="platform-control-plane__recent-lead">
            <?= htmlspecialchars($healthStatusSentence) ?>
        </p>
        <div class="dashboard-summary__grid">
            <div class="dashboard-summary__card">
                <span class="dashboard-summary__label">Salons (active)</span>
                <span class="dashboard-summary__value"><?= $activeSalons ?></span>
            </div>
            <div class="dashboard-summary__card">
                <span class="dashboard-summary__label">Salons (suspended)</span>
                <span class="dashboard-summary__value"><?= $suspendedSalons ?></span>
            </div>
            <div class="dashboard-summary__card">
                <span class="dashboard-summary__label">Founder accounts</span>
                <span class="dashboard-summary__value"><?= $founderAccounts ?></span>
            </div>
            <div class="dashboard-summary__card">
                <span class="dashboard-summary__label">Public risk</span>
                <span class="dashboard-summary__value"><?= $killSwitchActive ? 'Active' : 'Clear' ?></span>
                <span class="dashboard-summary__hint">
                    <?php if ($killSwitchActive): ?>
                        <a class="tenant-dash-table__link" href="/platform-admin/security">Open Security</a>
                    <?php else: ?>
                        No public risk
                    <?php endif; ?>
                </span>
            </div>
            <div class="dashboard-summary__card">
                <span class="dashboard-summary__label">Evaluated accounts</span>
                <span class="dashboard-summary__value"><?= $evaluatedAccounts ?></span>
            </div>
        </div>
    </section>

    <section class="platform-control-plane__actions" aria-label="Act now">
        <h2 class="dashboard-quicklinks__heading">ACT NOW</h2>
        <div class="dashboard-summary__card">
            <span class="dashboard-summary__label"><?= htmlspecialchars($primaryActionTitle) ?></span>
            <span class="dashboard-summary__value"><?= htmlspecialchars($primaryActionTruth) ?></span>
            <span class="dashboard-summary__hint"><a class="tenant-dash-table__link" href="<?= htmlspecialchars($primaryActionHref) ?>"><?= htmlspecialchars($primaryActionCta) ?></a></span>
        </div>
        <div class="tenant-dash-actions__grid">
            <a class="tenant-dash-action-card" href="/platform-admin/incidents">
                <span class="tenant-dash-action-card__label">Review incidents</span>
                <span class="tenant-dash-action-card__hint"><?= $incidentSignals ?> signal(s)</span>
            </a>
            <a class="tenant-dash-action-card" href="/platform-admin/security">
                <span class="tenant-dash-action-card__label">Check security state</span>
                <span class="tenant-dash-action-card__hint">Public risk is <?= $killSwitchActive ? 'active' : 'clear' ?></span>
            </a>
            <a class="tenant-dash-action-card" href="/platform-admin/salons">
                <span class="tenant-dash-action-card__label">Review salon binding</span>
                <span class="tenant-dash-action-card__hint"><?= $bindingAnomalies ?> anomaly signal(s)</span>
            </a>
        </div>
        <?php if (!$hasAttention): ?>
            <p class="platform-control-plane__recent-lead">
                <a class="tenant-dash-table__link" href="/platform-admin/access">Review Access</a>
                · <a class="tenant-dash-table__link" href="/platform-admin/incidents">Review Incident Center</a>
            </p>
        <?php endif; ?>
    </section>

    <section class="platform-control-plane__actions" aria-label="Go deeper">
        <h2 class="dashboard-quicklinks__heading">GO DEEPER</h2>
        <p class="platform-control-plane__recent-lead">
            <a class="tenant-dash-table__link" href="/platform-admin/incidents">Incident diagnostics</a>
            · <a class="tenant-dash-table__link" href="/platform-admin/guide">Operator guide</a>
            · <a class="tenant-dash-table__link" href="/platform-admin/access/provision">User provisioning</a>
            · <a class="tenant-dash-table__link" href="/platform-admin/branches">Branches</a>
        </p>

        <details>
            <summary><span class="dashboard-quicklinks__heading">Latest actions</span></summary>
            <?php if ($recentActions === []): ?>
                <p class="platform-control-plane__recent-lead" role="status">No recent founder actions.</p>
            <?php else: ?>
                <div class="tenant-dash-table-wrap">
                    <table class="tenant-dash-table">
                        <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Action</th>
                            <th scope="col">Actor</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentActions as $ev): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($ev['created_at'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($founderPresenter->humanAuditAction((string) ($ev['action'] ?? ''))) ?></td>
                                <td><?= htmlspecialchars(trim((string) ($ev['actor_email'] ?? ''))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="platform-control-plane__recent-lead"><a class="tenant-dash-table__link" href="/platform-admin/security">Full audit log</a></p>
            <?php endif; ?>
        </details>

        <details>
            <summary><span class="dashboard-quicklinks__heading">Platform scale</span></summary>
            <div class="dashboard-summary__grid">
                <?php foreach ($cards as $card): ?>
                    <?php
                    $cardLabel = (string) ($card['label'] ?? '');
                    if ($cardLabel === 'Organizations (active)') {
                        $cardLabel = 'Salons (active)';
                    } elseif ($cardLabel === 'Organizations (suspended)') {
                        $cardLabel = 'Salons (suspended)';
                    } elseif ($cardLabel === 'Organizations (total)') {
                        $cardLabel = 'Salons (total)';
                    }
                    ?>
                    <div class="dashboard-summary__card">
                        <span class="dashboard-summary__label"><?= htmlspecialchars($cardLabel) ?></span>
                        <span class="dashboard-summary__value"><?= (int) ($card['value'] ?? 0) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>

        <?php if ($recentOrgs !== []): ?>
        <details>
            <summary><span class="dashboard-quicklinks__heading">Recent salons</span></summary>
            <div class="tenant-dash-table-wrap">
                <table class="tenant-dash-table">
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Name</th>
                            <th scope="col">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrgs as $r): ?>
                            <tr>
                                <td><?= (int) ($r['id'] ?? 0) ?></td>
                                <td>
                                    <a class="tenant-dash-table__link" href="/platform-admin/organizations/<?= (int) ($r['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($r['name'] ?? '')) ?></a>
                                </td>
                                <td><?= htmlspecialchars((string) ($r['created_display'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endif; ?>
    </section>
</div>
