<?php
$title = 'Client Packages';
$mainClass = 'sales-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = '';
$salesWorkspaceShellTitle = 'Client packages';
$salesWorkspaceShellSub = 'Client-held package records (Clients). Plan templates: Catalog. Checkout can sell an assignment: Sales — not where definitions live.';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
?>
<h2 class="sales-workspace-section-title">Client Packages</h2>
<p class="hint" style="margin-top:0;">Packages currently held by clients — each row is a <strong>client-owned record</strong> (sessions used/remaining, expiry). The <strong>plan template</strong> behind it is defined in <strong>Catalog</strong> (<a href="/packages">Package plans</a>).</p>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form">
    <input type="text" name="search" placeholder="Search package/client..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
    <select name="status">
        <option value="">All statuses</option>
        <?php foreach (\Modules\Packages\Services\PackageService::CLIENT_PACKAGE_STATUSES as $status): ?>
        <option value="<?= htmlspecialchars($status) ?>" <?= (($_GET['status'] ?? '') === $status) ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="branch_id">
        <option value="">All branches</option>
        <option value="global" <?= (($_GET['branch_id'] ?? '') === 'global') ? 'selected' : '' ?>>Organisation-wide only</option>
        <?php foreach ($branches as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= (($_GET['branch_id'] ?? '') !== 'global' && (int) ($_GET['branch_id'] ?? 0) === (int) $b['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
</form>

<p>
    <a class="btn" href="/packages/client-packages/assign">Assign package to client</a>
    <a class="btn" href="/packages">← Package plans</a>
</p>

<table class="index-table">
    <thead>
    <tr>
        <th>ID</th>
        <th>Client</th>
        <th>Plan name</th>
        <th>Assigned</th>
        <th>Remaining</th>
        <th>Status</th>
        <th>Expires</th>
        <th>Branch</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><a href="/packages/client-packages/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
        <td><?= htmlspecialchars($r['client_display']) ?></td>
        <td><?= htmlspecialchars($r['package_name']) ?></td>
        <td><?= (int) $r['assigned_sessions'] ?></td>
        <td><span class="badge <?= ((int) $r['remaining_now'] <= 0) ? 'badge-warn' : 'badge-success' ?>"><?= (int) $r['remaining_now'] ?></span></td>
        <td><span class="badge badge-muted"><?= htmlspecialchars($r['status']) ?></span></td>
        <td><?= htmlspecialchars($r['expires_at'] ?? '—') ?></td>
        <td><?= $r['branch_id'] ? ('#' . (int) $r['branch_id']) : 'Organisation-wide' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($total > count($rows)): ?>
<p class="pagination">Page <?= $page ?> · <?= $total ?> total</p>
<?php endif; ?>

<p class="hint">Branch-assigned packages are managed within their branch. Organisation-wide packages have no branch restriction.</p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
