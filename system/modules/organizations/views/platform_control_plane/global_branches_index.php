<?php
/** @var string $csrf */
/** @var string $title */
/** @var list<array<string,mixed>> $rows */
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
    <?php $pagePurposeKey = 'branches'; require base_path('modules/organizations/views/platform_control_plane/partials/founder_page_purpose_panel.php'); ?>
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($title) ?></h1>
            <p class="workspace-module-head__sub">Platform-wide branch catalog — scan the table and open a row to edit or review impact.</p>
        </div>
    </header>

    <?php if ($canManage): ?>
        <p class="platform-control-plane__recent-lead"><a class="tenant-dash-table__link" href="/platform-admin/branches/create">Create branch</a></p>
    <?php else: ?>
        <p class="platform-control-plane__recent-lead">Read-only: <code>platform.organizations.manage</code> is required to create or edit branches here.</p>
    <?php endif; ?>

    <div class="tenant-dash-table-wrap">
        <table class="tenant-dash-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Branch</th>
                <th>Code</th>
                <th>Organization</th>
                <th>Org status</th>
                <th>Branch status</th>
                <?php if ($canManage): ?><th>Actions</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php
                $bid = (int) ($r['id'] ?? 0);
                $oid = (int) ($r['organization_id'] ?? 0);
                $orgDel = ($r['org_deleted_at'] ?? null) !== null && (string) ($r['org_deleted_at'] ?? '') !== '';
                $orgSus = ($r['org_suspended_at'] ?? null) !== null && (string) ($r['org_suspended_at'] ?? '') !== '';
                $branchInactive = ($r['deleted_at'] ?? null) !== null && (string) ($r['deleted_at'] ?? '') !== '';
                ?>
                <tr>
                    <td><?= $bid ?></td>
                    <td><?= htmlspecialchars((string) ($r['name'] ?? '')) ?></td>
                    <td><code><?= htmlspecialchars((string) ($r['code'] ?? '')) ?></code></td>
                    <td>
                        <span title="organization_id=<?= $oid ?>"><?= htmlspecialchars((string) ($r['organization_name'] ?? '')) ?></span>
                        <span class="platform-control-plane__recent-lead"> (id <?= $oid ?>)</span>
                    </td>
                    <td>
                        <?php if ($orgDel): ?>deleted org<?php elseif ($orgSus): ?>suspended org<?php else: ?>active<?php endif; ?>
                    </td>
                    <td><?= $branchInactive ? 'Inactive' : 'Active' ?></td>
                    <?php if ($canManage): ?>
                        <td>
                            <a class="tenant-dash-table__link" href="/platform-admin/branches/<?= $bid ?>/edit">Edit</a>
                            <?php if (!$branchInactive): ?>
                                <a class="tenant-dash-table__link" href="/platform-admin/safe-actions/branches/<?= $bid ?>/deactivate-preview">Deactivate (preview)</a>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows === []): ?>
                <tr><td colspan="<?= $canManage ? 7 : 6 ?>">No branches found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="platform-control-plane__recent-lead">Restoring a deactivated branch is not available in the founder interface yet. Use a controlled maintenance process until restore tooling is added, or create a replacement branch if appropriate.</p>
</div>
