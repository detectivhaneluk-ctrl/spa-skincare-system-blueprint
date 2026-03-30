<?php
$title = 'Stock Movements';
ob_start();
?>
<h1>Stock Movements</h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form">
    <select name="product_id">
        <option value="">All products</option>
        <?php foreach ($products as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= (int)($_GET['product_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($p['name'] . ' [' . $p['sku'] . ']') ?>
        </option>
        <?php endforeach; ?>
    </select>
    <select name="movement_type">
        <option value="">All movement types</option>
        <?php foreach (\Modules\Inventory\Services\StockMovementService::MOVEMENT_TYPES as $type): ?>
        <option value="<?= htmlspecialchars($type) ?>" <?= (($_GET['movement_type'] ?? '') === $type) ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="branch_id">
        <option value="">All branches (explicit mix)</option>
        <option value="global" <?= (($_GET['branch_id'] ?? '') === 'global') ? 'selected' : '' ?>>Global only</option>
        <?php foreach ($branches as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= (($_GET['branch_id'] ?? '') !== 'global' && (int)($_GET['branch_id'] ?? 0) === (int)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
</form>

<p><a href="/inventory" class="btn">← Inventory</a> <a href="/inventory/movements/create" class="btn">Add Movement</a></p>

<table class="index-table">
    <thead>
    <tr>
        <th>Date</th>
        <th>Product</th>
        <th>Type</th>
        <th>Quantity</th>
        <th>Reference</th>
        <th>Branch</th>
        <th>Notes</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($movements as $m): ?>
    <tr>
        <td><?= htmlspecialchars((string)$m['created_at']) ?></td>
        <td><?= htmlspecialchars(($m['product_name'] ?? '') . ' [' . ($m['product_sku'] ?? '') . ']') ?></td>
        <td><span class="badge badge-muted"><?= htmlspecialchars($m['movement_type']) ?></span></td>
        <td><span class="badge <?= ((float)$m['quantity'] >= 0) ? 'badge-success' : 'badge-warn' ?>"><?= number_format((float)$m['quantity'], 3) ?></span></td>
        <td><?= htmlspecialchars(($m['reference_type'] ?? '—') . (($m['reference_id'] ?? null) ? (' #' . (int)$m['reference_id']) : '')) ?></td>
        <td><?= $m['branch_id'] ? ('#' . (int)$m['branch_id']) : 'Global' ?></td>
        <td><?= htmlspecialchars($m['notes'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($total > count($movements)): ?>
<p class="pagination">Page <?= $page ?> · <?= $total ?> total</p>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
