<?php
$title = 'Gift Card ' . ($giftCard['code'] ?? '');
$mainClass = 'sales-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = 'gift_cards';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
?>
<h2 class="sales-workspace-section-title">Gift Card <?= htmlspecialchars($giftCard['code'] ?? '') ?></h2>
<div class="entity-actions">
    <?php if (($giftCard['status'] ?? '') === 'active'): ?>
    <a class="btn" href="/gift-cards/<?= (int)$giftCard['id'] ?>/redeem">Redeem</a>
    <a class="btn" href="/gift-cards/<?= (int)$giftCard['id'] ?>/adjust">Adjust</a>
    <form method="post" action="/gift-cards/<?= (int)$giftCard['id'] ?>/cancel" style="display:inline" onsubmit="return confirm('Cancel this gift card?')">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit">Cancel</button>
    </form>
    <?php endif; ?>
</div>

<dl class="entity-detail">
    <dt>Code</dt><dd><?= htmlspecialchars($giftCard['code']) ?></dd>
    <dt>Client</dt><dd><?= htmlspecialchars($clientDisplay) ?></dd>
    <dt>Status</dt><dd><span class="badge badge-muted"><?= htmlspecialchars($giftCard['status']) ?></span></dd>
    <dt>Original Amount</dt><dd><?= number_format((float)$giftCard['original_amount'], 2) ?> <?= htmlspecialchars($giftCard['currency']) ?></dd>
    <dt>Current Balance</dt><dd><span class="badge <?= ($currentBalance <= 0) ? 'badge-warn' : 'badge-success' ?>"><?= number_format($currentBalance, 2) ?></span></dd>
    <dt>Issued At</dt><dd><?= htmlspecialchars((string)$giftCard['issued_at']) ?></dd>
    <dt>Expires At</dt><dd><?= htmlspecialchars($giftCard['expires_at'] ?? '—') ?></dd>
    <dt>Branch</dt><dd><?= $giftCard['branch_id'] ? ('#' . (int)$giftCard['branch_id']) : 'Global' ?></dd>
    <dt>Notes</dt><dd><?= nl2br(htmlspecialchars($giftCard['notes'] ?? '—')) ?></dd>
</dl>

<h3>Transaction History</h3>
<table class="index-table">
    <thead>
    <tr>
        <th>Date</th>
        <th>Type</th>
        <th>Amount</th>
        <th>Balance After</th>
        <th>Reference</th>
        <th>Notes</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($transactions as $tx): ?>
    <tr>
        <td><?= htmlspecialchars((string)$tx['created_at']) ?></td>
        <td><?= htmlspecialchars($tx['type']) ?></td>
        <td><?= number_format((float)$tx['amount'], 2) ?></td>
        <td><?= number_format((float)$tx['balance_after'], 2) ?></td>
        <td><?= htmlspecialchars(($tx['reference_type'] ?? '—') . (($tx['reference_id'] ?? null) ? (' #' . (int)$tx['reference_id']) : '')) ?></td>
        <td><?= htmlspecialchars($tx['notes'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<p><a href="/gift-cards">← Back to Gift Cards</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
