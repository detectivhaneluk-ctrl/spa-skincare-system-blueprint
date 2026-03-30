<?php
$title = 'Issue Gift Card';
$mainClass = 'sales-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = 'gift_cards';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
?>
<h2 class="sales-workspace-section-title">Issue Gift Card</h2>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="post" action="/gift-cards/issue" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row"><label for="code">Code (optional auto-generate)</label><input type="text" id="code" name="code" value="<?= htmlspecialchars((string)($giftCard['code'] ?? '')) ?>"></div>
    <div class="form-row"><label for="original_amount">Amount *</label><input type="number" min="0.01" step="0.01" id="original_amount" name="original_amount" required value="<?= htmlspecialchars((string)($giftCard['original_amount'] ?? '')) ?>"></div>
    <div class="form-row"><label for="currency">Currency</label><input type="text" id="currency" name="currency" value="<?= htmlspecialchars((string)($giftCard['currency'] ?? '')) ?>" placeholder="USD"></div>
    <div class="form-row"><label for="issued_at">Issued At *</label><input type="datetime-local" id="issued_at" name="issued_at" required value="<?= htmlspecialchars((string)($giftCard['issued_at'] ?? date('Y-m-d\TH:i'))) ?>"></div>
    <div class="form-row"><label for="expires_at">Expires At</label><input type="datetime-local" id="expires_at" name="expires_at" value="<?= htmlspecialchars((string)($giftCard['expires_at'] ?? '')) ?>"></div>
    <div class="form-row">
        <label for="client_id">Client (optional)</label>
        <select id="client_id" name="client_id">
            <option value="">No client</option>
            <?php foreach ($clientOptions as $c): ?>
            <?php $cid = (int)($c['id'] ?? 0); $display = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')); ?>
            <option value="<?= $cid ?>" <?= ((string)($giftCard['client_id'] ?? '') === (string)$cid) ? 'selected' : '' ?>><?= htmlspecialchars($display ?: ('Client #' . $cid)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">Global</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= ((string)($giftCard['branch_id'] ?? '') === (string)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3"><?= htmlspecialchars((string)($giftCard['notes'] ?? '')) ?></textarea></div>
    <div class="form-actions"><button type="submit">Issue</button> <a href="/gift-cards">Cancel</a></div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
