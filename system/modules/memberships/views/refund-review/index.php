<?php
$title = 'Membership refund review';
$csrfName = config('app.csrf_token_name', 'csrf_token');
ob_start();
$memWorkspaceActiveTab = 'refund-review';
require base_path('modules/memberships/views/partials/memberships-workspace-shell.php');
?>
<h2>Membership refund review</h2>
<p class="muted">Canonical truth is invoices and payments. Use <strong>Reconcile from invoice</strong> after correcting an invoice so membership rows resync. <strong>Acknowledge</strong> records operator review only—it does not change money or membership terms.</p>

<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form">
    <select name="branch_id">
        <option value="">All branches</option>
        <option value="global" <?= (($_GET['branch_id'] ?? '') === 'global') ? 'selected' : '' ?>>Global only</option>
        <?php foreach ($branches as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= (($_GET['branch_id'] ?? '') !== 'global' && (int) ($_GET['branch_id'] ?? 0) === (int) $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name'] ?? '') ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
</form>

<p>
    <a class="btn" href="/memberships/client-memberships">Client memberships</a>
    <a class="btn" href="/memberships">Definitions</a>
</p>

<h2>Initial membership sales (status: refund_review)</h2>
<?php if ($saleRows === []): ?>
<p class="muted">No sales in refund review for this filter.</p>
<?php else: ?>
<table class="index-table">
    <thead>
    <tr>
        <th>Sale</th>
        <th>Client</th>
        <th>Invoice</th>
        <th>Branch</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($saleRows as $s): ?>
    <tr>
        <td>#<?= (int) ($s['id'] ?? 0) ?></td>
        <td>#<?= (int) ($s['client_id'] ?? 0) ?></td>
        <td>
            <?php $iid = (int) ($s['invoice_id'] ?? 0); ?>
            <?php if ($iid > 0): ?>
            <a href="/sales/invoices/<?= $iid ?>">Invoice #<?= $iid ?></a>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td><?= isset($s['branch_id']) && $s['branch_id'] !== null && $s['branch_id'] !== '' ? (int) $s['branch_id'] : '—' ?></td>
        <td>
            <form method="post" action="/memberships/refund-review/sales/<?= (int) ($s['id'] ?? 0) ?>/reconcile" style="display:inline">
                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <button type="submit">Reconcile from invoice</button>
            </form>
            <form method="post" action="/memberships/refund-review/sales/<?= (int) ($s['id'] ?? 0) ?>/acknowledge" style="display:inline-block; margin-left:0.5rem">
                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <input type="text" name="note" placeholder="Optional note" maxlength="2000" style="max-width:14rem">
                <button type="submit">Acknowledge</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h2>Renewal billing cycles (term applied; invoice out of balance or refunded)</h2>
<?php if ($cycleRows === []): ?>
<p class="muted">No billing cycles in refund review for this filter.</p>
<?php else: ?>
<table class="index-table">
    <thead>
    <tr>
        <th>Cycle</th>
        <th>Client membership</th>
        <th>Invoice</th>
        <th>Invoice status</th>
        <th>Paid / Total</th>
        <th>Branch</th>
        <th>Actions</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($cycleRows as $c): ?>
    <tr>
        <td>#<?= (int) ($c['id'] ?? 0) ?></td>
        <td>#<?= (int) ($c['client_membership_id'] ?? 0) ?></td>
        <td>
            <?php $ciid = (int) ($c['invoice_id'] ?? 0); ?>
            <?php if ($ciid > 0): ?>
            <a href="/sales/invoices/<?= $ciid ?>">Invoice #<?= $ciid ?></a>
            <?php else: ?>—<?php endif; ?>
        </td>
        <td><?= htmlspecialchars((string) ($c['invoice_status'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string) ($c['invoice_paid_amount'] ?? '')) ?> / <?= htmlspecialchars((string) ($c['invoice_total_amount'] ?? '')) ?></td>
        <td><?= isset($c['membership_branch_id']) && $c['membership_branch_id'] !== null && $c['membership_branch_id'] !== '' ? (int) $c['membership_branch_id'] : '—' ?></td>
        <td>
            <form method="post" action="/memberships/refund-review/billing-cycles/<?= (int) ($c['id'] ?? 0) ?>/reconcile" style="display:inline">
                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <button type="submit">Reconcile from invoice</button>
            </form>
            <form method="post" action="/memberships/refund-review/billing-cycles/<?= (int) ($c['id'] ?? 0) ?>/acknowledge" style="display:inline-block; margin-left:0.5rem">
                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf ?? '') ?>">
                <input type="text" name="note" placeholder="Optional note" maxlength="2000" style="max-width:14rem">
                <button type="submit">Acknowledge</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
?>
