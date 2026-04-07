<?php
$title = 'Adjust package sessions';
$mainClass = 'sales-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = '';
$salesWorkspaceShellTitle = 'Client packages';
$salesWorkspaceShellSub = 'Client-held package records (Clients). Plan templates: Catalog. Checkout can sell an assignment: Sales — not where definitions live.';
require base_path('modules/sales/views/partials/sales-workspace-shell.php');
?>
<h2 class="sales-workspace-section-title">Adjust package sessions</h2>
<p class="hint" style="margin-top:0;"><strong>Client-held record</strong> — you are changing session counts on the client&rsquo;s row, not editing the Catalog plan template.</p>
<p><strong>Client-held #<?= (int) $clientPackage['id'] ?></strong> · <?= htmlspecialchars($clientPackage['package_name']) ?> · Remaining: <?= (int) $currentRemaining ?></p>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php if (!empty($errors['_general'])): ?><li><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
    <?php foreach ($errors as $k => $e): if ($k[0] === '_') continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : 'Invalid') ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="post" action="/packages/client-packages/<?= (int) $clientPackage['id'] ?>/adjust" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row"><label for="quantity">Quantity Delta *</label><input type="number" step="1" id="quantity" name="quantity" required value="<?= htmlspecialchars((string) ($adjustment['quantity'] ?? 1)) ?>"></div>
    <p class="hint">Use negative number to reduce sessions, positive number to add sessions (cannot exceed assigned sessions).</p>
    <div class="form-row"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3"><?= htmlspecialchars((string) ($adjustment['notes'] ?? '')) ?></textarea></div>
    <div class="form-actions"><button type="submit">Apply Adjustment</button> <a href="/packages/client-packages/<?= (int) $clientPackage['id'] ?>">Cancel</a></div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
