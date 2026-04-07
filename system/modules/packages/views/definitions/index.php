<?php
$title = 'Packages';
$mainClass = 'sales-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = '';
$salesWorkspaceShellTitle = 'Package plans';
$salesWorkspaceShellSub = 'Catalog package plans (templates). Client-held records: Clients. Checkout may sell a plan assignment: Sales — not the home for definitions or held records.';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
?>
<h2 class="sales-workspace-section-title">Packages</h2>
<p class="hint" style="margin-top:0;"><strong>Catalog</strong> — Package plan definitions (sessions, validity, price): templates, not rows a client already holds. Client-held packages are managed in Clients (main navigation), not on this screen.</p>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form">
    <input type="text" name="search" placeholder="Search package name..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
    <select name="status">
        <option value="">All statuses</option>
        <?php foreach (\Modules\Packages\Services\PackageService::PACKAGE_STATUSES as $status): ?>
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
    <a class="btn" href="/packages/create">New package plan</a>
</p>

<table class="index-table">
    <thead>
    <tr>
        <th>Name</th>
        <th>Status</th>
        <th>Total Sessions</th>
        <th>Validity (days)</th>
        <th>Price</th>
        <th>Branch</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($packageDefs as $p): ?>
    <tr>
        <td><?= htmlspecialchars($p['name']) ?></td>
        <td><span class="badge badge-muted"><?= htmlspecialchars($p['status']) ?></span></td>
        <td><?= (int) $p['total_sessions'] ?></td>
        <td><?= $p['validity_days'] !== null ? (int) $p['validity_days'] : '—' ?></td>
        <td><?= $p['price'] !== null ? number_format((float) $p['price'], 2) : '—' ?></td>
        <td><?= $p['branch_id'] ? ('#' . (int) $p['branch_id']) : 'Organisation-wide' ?></td>
        <td><a href="/packages/<?= (int) $p['id'] ?>/edit">Edit</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($total > count($packageDefs)): ?>
<p class="pagination">Page <?= $page ?> · <?= $total ?> total</p>
<?php endif; ?>

<p class="hint">Plans scoped to a branch are only available at that branch. Organisation-wide plans are available across all branches.</p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
