<?php
$title = 'Record Payment';
$mainClass = 'sales-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = 'manage_sales';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
?>
<h2 class="sales-workspace-section-title">Record Payment</h2>
<p class="hint">Apply a payment to this invoice&rsquo;s balance due — part of the same Sales money workspace as checkout and invoices. Cash drawer movements stay under <strong>Register</strong>.</p>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li class="error"><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<p>Balance due: <strong><?= number_format($balanceDue ?? 0, 2) ?></strong></p>
<form method="post" action="/sales/invoices/<?= (int) $payment['invoice_id'] ?>/payments" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="invoice_id" value="<?= (int) $payment['invoice_id'] ?>">
    <div class="form-row">
        <label for="payment_method">Method *</label>
        <select id="payment_method" name="payment_method" required>
            <?php
            $paymentMethods = $paymentMethods ?? [];
            $selected = $payment['payment_method'] ?? '';
            foreach ($paymentMethods as $pm):
                $code = $pm['code'] ?? '';
                $name = $pm['name'] ?? $code;
            ?>
            <option value="<?= htmlspecialchars($code) ?>" <?= $selected === $code ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
            <?php if (empty($paymentMethods)): ?>
            <option value="cash">Cash</option>
            <?php endif; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="amount">Amount *</label>
        <input type="number" id="amount" name="amount" required min="0.01" step="0.01" value="<?= htmlspecialchars((string)($payment['amount'] ?? '')) ?>" placeholder="<?= number_format($balanceDue ?? 0, 2) ?>">
        <?php if (!empty($errors['amount'])): ?><span class="error"><?= htmlspecialchars($errors['amount']) ?></span><?php endif; ?>
    </div>
    <div class="form-row">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="completed" <?= ($payment['status'] ?? 'completed') === 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="pending" <?= ($payment['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="failed" <?= ($payment['status'] ?? '') === 'failed' ? 'selected' : '' ?>>Failed</option>
        </select>
    </div>
    <div class="form-row">
        <label for="transaction_reference">Reference</label>
        <input type="text" id="transaction_reference" name="transaction_reference" value="<?= htmlspecialchars($payment['transaction_reference'] ?? '') ?>">
    </div>
    <div class="form-row">
        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" rows="2"><?= htmlspecialchars($payment['notes'] ?? '') ?></textarea>
    </div>
    <div class="form-actions">
        <button type="submit">Record Payment</button>
        <a href="/sales/invoices/<?= (int) $payment['invoice_id'] ?>">Cancel</a>
    </div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
