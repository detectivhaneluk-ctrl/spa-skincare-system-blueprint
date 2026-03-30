<?php
$title = 'Adjust Gift Card';
$mainClass = 'sales-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = 'gift_cards';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
?>
<h2 class="sales-workspace-section-title">Adjust Gift Card <?= htmlspecialchars($giftCard['code'] ?? '') ?></h2>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<p><strong>Current Balance:</strong> <?= number_format((float)$currentBalance, 2) ?> <?= htmlspecialchars($giftCard['currency'] ?? '') ?></p>
<p class="hint">Use positive amount to increase balance, negative to decrease. Balance cannot go below zero.</p>
<form method="post" action="/gift-cards/<?= (int)$giftCard['id'] ?>/adjust" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row"><label for="amount">Adjustment Amount *</label><input type="number" step="0.01" id="amount" name="amount" required value="<?= htmlspecialchars((string)($adjustment['amount'] ?? '')) ?>"></div>
    <div class="form-row"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3"><?= htmlspecialchars((string)($adjustment['notes'] ?? '')) ?></textarea></div>
    <div class="form-actions"><button type="submit">Apply Adjustment</button> <a href="/gift-cards/<?= (int)$giftCard['id'] ?>">Cancel</a></div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
