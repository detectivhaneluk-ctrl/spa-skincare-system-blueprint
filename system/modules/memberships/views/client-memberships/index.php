<?php
$title = 'Active Client Memberships';
$clientsWorkspaceActiveTab = 'list';
require base_path('modules/clients/views/partials/clients-workspace-data.php');
ob_start();
require base_path('modules/clients/views/partials/clients-workspace-shell.php');
?>
<h2>Active Client Memberships</h2>
<p class="hint" style="margin-top:0;">Client-owned membership records. Use filters to find active, paused, or cancelled memberships. To manage plan definitions, see <a href="/memberships">Membership plans</a>.</p>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form">
    <?php if (!empty($filterClientId) && (int) $filterClientId > 0): ?>
    <input type="hidden" name="client_id" value="<?= (int) $filterClientId ?>">
    <?php endif; ?>
    <input type="text" name="search" placeholder="Search client or plan..." value="<?= htmlspecialchars($search) ?>">
    <select name="status">
        <option value="">All statuses</option>
        <?php foreach (\Modules\Memberships\Services\MembershipService::CLIENT_MEMBERSHIP_STATUSES as $st): ?>
        <option value="<?= htmlspecialchars($st) ?>" <?= ($status === $st) ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="branch_id">
        <option value="">All branches</option>
        <option value="global" <?= ($branchRaw === 'global') ? 'selected' : '' ?>>Organisation-wide only</option>
        <?php foreach ($branches as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= ($branchRaw !== 'global' && $branchRaw !== '' && (int) $branchRaw === (int) $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
</form>

<p>
    <a class="btn" href="/memberships/client-memberships/assign">Enrol client in membership</a>
    <a class="btn" href="/memberships/refund-review">Refund review</a>
    <a class="btn" href="/memberships">← Membership plans</a>
</p>

<table class="index-table">
    <thead>
    <tr>
        <th>Client</th>
        <th>Plan</th>
        <th>Starts</th>
        <th>Ends</th>
        <th>Status</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $m): ?>
    <?php $clientDisplay = trim(($m['client_first_name'] ?? '') . ' ' . ($m['client_last_name'] ?? '')) ?: '—'; ?>
    <tr>
        <td><?= htmlspecialchars($clientDisplay) ?></td>
        <td><?= htmlspecialchars($m['definition_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($m['starts_at'] ?? '') ?></td>
        <td><?= htmlspecialchars($m['ends_at'] ?? '') ?></td>
        <td><span class="badge badge-muted"><?= htmlspecialchars($m['status'] ?? '') ?></span></td>
        <td>
            <?php if (($m['status'] ?? '') === 'active'): ?>
            <form method="post" action="/memberships/client-memberships/<?= (int) $m['id'] ?>/cancel" style="display:inline" onsubmit="return confirm('Cancel this membership?');">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <button type="submit">Cancel</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($total > $perPage): ?>
<p>Page <?= (int) $page ?> — <?= count($items) ?> of <?= (int) $total ?>.</p>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>

