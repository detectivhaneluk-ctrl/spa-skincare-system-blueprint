<?php
$title = $title ?? 'Organizations';
ob_start();
$rows = $rows ?? [];
?>
<div class="workspace-shell platform-control-plane">
    <?php $pagePurposeKey = 'organizations'; require base_path('modules/organizations/views/platform_control_plane/partials/founder_page_purpose_panel.php'); ?>
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title">Organizations</h1>
            <p class="workspace-module-head__sub">Scan the registry — open a row for lifecycle and impact; use suspend/reactivate only from safe previews on the detail page.</p>
        </div>
    </header>
    <p class="platform-control-plane__recent-lead">
        <a class="tenant-dash-table__link" href="/platform-admin">Dashboard</a>
        <?php if (!empty($canManageOrganizations)): ?>
            · <a class="tenant-dash-table__link" href="/platform-admin/salons/create">Add organization</a>
        <?php endif; ?>
    </p>
    <?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
        <p class="platform-control-plane__recent-lead" role="<?= $t === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></p>
    <?php endif; ?>
    <div class="tenant-dash-table-wrap">
        <table class="tenant-dash-table">
            <thead>
            <tr>
                <th scope="col">ID</th>
                <th scope="col">Name</th>
                <th scope="col">Code</th>
                <th scope="col">Suspended</th>
                <th scope="col">Archived</th>
                <th scope="col"></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int) ($r['id'] ?? 0) ?></td>
                    <td><?= htmlspecialchars((string) ($r['name'] ?? '')) ?></td>
                    <td><code><?= htmlspecialchars((string) ($r['code'] ?? '')) ?></code></td>
                    <td><?= !empty($r['suspended_at']) ? 'Yes' : 'No' ?></td>
                    <td><?= !empty($r['deleted_at']) ? 'Yes' : 'No' ?></td>
                    <td>
                        <a class="tenant-dash-table__link" href="/platform-admin/organizations/<?= (int) ($r['id'] ?? 0) ?>">Open</a>
                        <?php if (!empty($canManageOrganizations)): ?>
                            · <a class="tenant-dash-table__link" href="/platform-admin/organizations/<?= (int) ($r['id'] ?? 0) ?>/edit">Edit</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6">No organizations in the registry.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
require shared_path('layout/platform_admin.php');
