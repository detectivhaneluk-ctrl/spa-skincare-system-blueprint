<?php
$title = 'Membership Definitions';
ob_start();
?>
<h1>Membership Definitions</h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form">
    <input type="text" name="search" placeholder="Search name..." value="<?= htmlspecialchars($search) ?>">
    <select name="status">
        <option value="">All statuses</option>
        <?php foreach (\Modules\Memberships\Services\MembershipService::DEFINITION_STATUSES as $st): ?>
        <option value="<?= htmlspecialchars($st) ?>" <?= ($status === $st) ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="branch_id">
        <option value="">All branches</option>
        <option value="global" <?= ($branchRaw === 'global') ? 'selected' : '' ?>>Global only</option>
        <?php foreach ($branches as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= ($branchRaw !== 'global' && $branchRaw !== '' && (int) $branchRaw === (int) $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
</form>

<p>
    <a class="btn" href="/memberships/create">Create Membership Definition</a>
    <a class="btn" href="/memberships/client-memberships">Client Memberships</a>
</p>

<table class="index-table">
    <thead>
    <tr>
        <th>Name</th>
        <th>Status</th>
        <th>Duration (days)</th>
        <th>Price</th>
        <th>Branch</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $d): ?>
    <tr>
        <td><?= htmlspecialchars($d['name']) ?></td>
        <td><span class="badge badge-muted"><?= htmlspecialchars($d['status']) ?></span></td>
        <td><?= (int) $d['duration_days'] ?></td>
        <td><?= $d['price'] !== null ? number_format((float) $d['price'], 2) : '—' ?></td>
        <td><?= $d['branch_id'] ? ('#' . (int) $d['branch_id']) : 'Global' ?></td>
        <td><a href="/memberships/<?= (int) $d['id'] ?>/edit">Edit</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($total > $perPage): ?>
<p>Page <?= (int) $page ?> — <?= count($items) ?> of <?= (int) $total ?>.</p>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
