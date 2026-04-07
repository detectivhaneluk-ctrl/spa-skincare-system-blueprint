<?php
$title = 'Register Sessions';
$mainClass = 'sales-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = 'register';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
?>
<h2 class="sales-workspace-section-title">Register Sessions</h2>
<p class="hint">Cash drawer control: open and close sessions, record cash in/out, and reconcile physical cash. This is <strong>not</strong> checkout — use <strong>New sale</strong> for invoicing and taking payment on a sale.</p>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form">
    <label for="branch_id">Branch</label>
    <select id="branch_id" name="branch_id">
        <?php foreach ($branches as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= ((int) ($branchId ?? 0) === (int) $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars((string) $b['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Load</button>
</form>

<?php if ($openSession): ?>
<h2>Current Register Status</h2>
<p>
    <strong>Session:</strong> #<?= (int) $openSession['id'] ?> |
    <strong>Opened:</strong> <?= htmlspecialchars((string) ($openSession['opened_at'] ?? '')) ?> |
    <strong>Opening Cash:</strong> <?= number_format((float) ($openSession['opening_cash_amount'] ?? 0), 2) ?>
</p>

<h3>Cash In / Cash Out</h3>
<form method="post" action="/sales/register/<?= (int) $openSession['id'] ?>/movements" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="branch_id" value="<?= (int) ($branchId ?? 0) ?>">
    <div class="form-row">
        <label for="movement_type">Type</label>
        <select id="movement_type" name="type" required>
            <option value="cash_in">Cash In</option>
            <option value="cash_out">Cash Out</option>
        </select>
    </div>
    <div class="form-row">
        <label for="movement_amount">Amount</label>
        <input type="number" id="movement_amount" name="amount" min="0.01" step="0.01" required>
    </div>
    <div class="form-row">
        <label for="movement_reason">Reason</label>
        <input type="text" id="movement_reason" name="reason" required>
    </div>
    <div class="form-row">
        <label for="movement_notes">Notes</label>
        <textarea id="movement_notes" name="notes" rows="2"></textarea>
    </div>
    <div class="form-actions">
        <button type="submit">Record Movement</button>
    </div>
</form>

<h3>Close Register Session</h3>
<form method="post" action="/sales/register/<?= (int) $openSession['id'] ?>/close" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="branch_id" value="<?= (int) ($branchId ?? 0) ?>">
    <div class="form-row">
        <label for="closing_cash_amount">Closing Cash Amount</label>
        <input type="number" id="closing_cash_amount" name="closing_cash_amount" min="0" step="0.01" required>
    </div>
    <div class="form-row">
        <label for="closing_notes">Notes</label>
        <textarea id="closing_notes" name="notes" rows="2"></textarea>
    </div>
    <div class="form-actions">
        <button type="submit">Close Session</button>
    </div>
</form>

<?php if (!empty($movements)): ?>
<h3>Recent Cash Movements (Open Session)</h3>
<table class="index-table">
    <thead><tr><th>Type</th><th>Amount</th><th>Reason</th><th>Notes</th><th>Created</th></tr></thead>
    <tbody>
    <?php foreach ($movements as $m): ?>
    <tr>
        <td><?= htmlspecialchars((string) $m['type']) ?></td>
        <td><?= number_format((float) $m['amount'], 2) ?></td>
        <td><?= htmlspecialchars((string) $m['reason']) ?></td>
        <td><?= nl2br(htmlspecialchars((string) ($m['notes'] ?? ''))) ?></td>
        <td><?= htmlspecialchars((string) ($m['created_at'] ?? '')) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php else: ?>
<h2>Open Register Session</h2>
<p class="hint">No open session for this branch.</p>
<form method="post" action="/sales/register/open" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="branch_id" value="<?= (int) ($branchId ?? 0) ?>">
    <div class="form-row">
        <label for="opening_cash_amount">Opening Cash Amount</label>
        <input type="number" id="opening_cash_amount" name="opening_cash_amount" min="0" step="0.01" required>
    </div>
    <div class="form-row">
        <label for="opening_notes">Notes</label>
        <textarea id="opening_notes" name="notes" rows="2"></textarea>
    </div>
    <div class="form-actions">
        <button type="submit">Open Session</button>
    </div>
</form>
<?php endif; ?>

<h2>Session History</h2>
<?php if (empty($history)): ?>
<p class="hint">No register sessions found.</p>
<?php else: ?>
<table class="index-table">
    <thead><tr><th>ID</th><th>Status</th><th>Opened At</th><th>Closed At</th><th>Opening</th><th>Expected</th><th>Closing</th><th>Variance</th></tr></thead>
    <tbody>
    <?php foreach ($history as $s): ?>
    <tr>
        <td>#<?= (int) $s['id'] ?></td>
        <td><?= htmlspecialchars((string) $s['status']) ?></td>
        <td><?= htmlspecialchars((string) ($s['opened_at'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string) ($s['closed_at'] ?? '—')) ?></td>
        <td><?= number_format((float) ($s['opening_cash_amount'] ?? 0), 2) ?></td>
        <td><?= $s['expected_cash_amount'] !== null ? number_format((float) $s['expected_cash_amount'], 2) : '—' ?></td>
        <td><?= $s['closing_cash_amount'] !== null ? number_format((float) $s['closing_cash_amount'], 2) : '—' ?></td>
        <td><?= $s['variance_amount'] !== null ? number_format((float) $s['variance_amount'], 2) : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
<?php if ($total > count($history)): ?>
<p class="pagination">Page <?= (int) $page ?> · <?= (int) $total ?> total</p>
<?php endif; ?>
<p><a href="/sales">← Back to Sales</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
