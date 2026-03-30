<?php
/** @var string $csrf */
/** @var string $title */
/** @var \Modules\Organizations\Services\FounderAccessPresenter $founderPresenter */
/** @var list<array<string,mixed>> $auditRows */
/** @var array{kill_online_booking: bool, kill_anonymous_public_apis: bool, kill_public_commerce: bool} $killState */
/** @var bool $canManage */
/** @var array<string,mixed>|null $flashMsg */
$flashMsg = isset($flashMsg) && is_array($flashMsg) ? $flashMsg : [];
/** @var array<string,mixed>|null $founderGuardrailResult */
$founderGuardrailResult = $founderGuardrailResult ?? null;
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
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
    <?php $pagePurposeKey = 'security'; require base_path('modules/organizations/views/platform_control_plane/partials/founder_page_purpose_panel.php'); ?>
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($title) ?></h1>
            <p class="workspace-module-head__sub">Deployment-wide public emergency controls and founder audit visibility — not staff workspace permissions alone.</p>
        </div>
    </header>

    <section class="platform-control-plane__actions" aria-label="Public surface kill switches">
        <h2 class="dashboard-quicklinks__heading">Public surface kill switches</h2>
        <p class="platform-control-plane__recent-lead">Public emergency controls — deployment-wide blocks before tenant entry for anonymous/public traffic. When enabled, they <strong>override</strong> tenant-level settings for that traffic. They do not replace routine user access repair in Access.</p>
        <dl class="platform-control-plane__meta">
            <div class="platform-control-plane__meta-row"><dt>Online booking</dt><dd><?= !empty($killState['kill_online_booking']) ? 'Blocking all public booking flows (including manage-token links)' : 'Off' ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>Anonymous public APIs</dt><dd><?= !empty($killState['kill_anonymous_public_apis']) ? 'Blocking anonymous booking/commerce APIs' : 'Off' ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>Public commerce</dt><dd><?= !empty($killState['kill_public_commerce']) ? 'Blocking public commerce surface (independent of the anonymous API switch)' : 'Off' ?></dd></div>
        </dl>
        <?php if ($canManage): ?>
            <p class="platform-control-plane__recent-lead">
                <a class="tenant-dash-table__link" href="/platform-admin/safe-actions/security/kill-switches-preview">Review and apply kill switch changes</a>
                — preview, operational reason, confirmation, and audit before any deployment-wide change.
            </p>
        <?php else: ?>
            <p class="platform-control-plane__recent-lead">Read-only: <code>platform.organizations.manage</code> is required to change switches.</p>
        <?php endif; ?>
    </section>

    <section class="platform-control-plane__events" aria-label="Access plane audit">
        <h2 class="dashboard-quicklinks__heading">Access and security audit</h2>
        <p class="platform-control-plane__recent-lead">Read-mostly trail of founder actions. Open a row’s details in the table below for technical metadata.</p>
        <details class="platform-impact-panel platform-impact-panel--advanced">
            <summary><span class="dashboard-quicklinks__heading">Advanced — full audit table (up to 200 rows)</span></summary>
            <p class="platform-control-plane__recent-lead">Includes access repair, provisioning, membership, branch catalog, kill switches, and support entry. Raw action codes on hover where helpful.</p>
        <div class="tenant-dash-table-wrap">
            <table class="tenant-dash-table">
                <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Action</th>
                    <th scope="col">Actor</th>
                    <th scope="col">Target</th>
                    <th scope="col">Branch</th>
                    <th scope="col">Details</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($auditRows as $row): ?>
                    <?php
                    $meta = $row['metadata_json'] ?? null;
                    $metaStr = '';
                    if ($meta !== null && $meta !== '') {
                        $decoded = json_decode((string) $meta, true);
                        $metaStr = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : (string) $meta;
                    }
                    $when = (string) ($row['created_at'] ?? '');
                    $rawAction = (string) ($row['action'] ?? '');
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($when) ?></td>
                        <td title="<?= htmlspecialchars($rawAction) ?>"><?= htmlspecialchars($founderPresenter->humanAuditAction($rawAction)) ?></td>
                        <td><?= htmlspecialchars(trim((string) ($row['actor_email'] ?? '') . ' ' . (string) ($row['actor_name'] ?? ''))) ?><?php if (!empty($row['actor_user_id'])): ?> (#<?= (int) $row['actor_user_id'] ?>)<?php endif; ?></td>
                        <td><?= htmlspecialchars((string) ($row['target_type'] ?? '')) ?><?php if (($row['target_id'] ?? null) !== null && (string) $row['target_id'] !== ''): ?> #<?= (int) $row['target_id'] ?><?php endif; ?></td>
                        <td><?= ($row['branch_id'] ?? null) !== null && (string) $row['branch_id'] !== '' ? (int) $row['branch_id'] : '—' ?></td>
                        <td class="platform-security-audit-meta"><?= $metaStr !== '' ? htmlspecialchars($metaStr) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($auditRows === []): ?>
                    <tr><td colspan="6">No audit events recorded yet for this category. After you run access repairs, provisioning, branch updates, or change kill switches, rows will appear here.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        </details>
    </section>
</div>
