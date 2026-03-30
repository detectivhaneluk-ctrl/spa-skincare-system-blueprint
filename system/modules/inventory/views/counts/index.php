<?php
$title = 'Inventory Counts';
ob_start();
?>
<h1>Inventory Counts</h1>
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
    <select name="branch_id">
        <option value="">All branches (explicit mix)</option>
        <option value="global" <?= (($_GET['branch_id'] ?? '') === 'global') ? 'selected' : '' ?>>Global only</option>
        <?php foreach ($branches as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= (($_GET['branch_id'] ?? '') !== 'global' && (int)($_GET['branch_id'] ?? 0) === (int)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
</form>

<p><a href="/inventory" class="btn">← Inventory</a> <a href="/inventory/counts/create" class="btn">New Count</a></p>

<table class="index-table">
    <thead>
    <tr>
        <th>Date</th>
        <th>Product</th>
        <th>Expected</th>
        <th>Counted</th>
        <th>Variance</th>
        <th>Branch</th>
        <th>Notes</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($counts as $c): ?>
    <tr>
        <td><?= htmlspecialchars((string)$c['created_at']) ?></td>
        <td><?= htmlspecialchars(($c['product_name'] ?? '') . ' [' . ($c['product_sku'] ?? '') . ']') ?></td>
        <td><?= number_format((float)$c['expected_quantity'], 3) ?></td>
        <td><?= number_format((float)$c['counted_quantity'], 3) ?></td>
        <td><span class="badge <?= ((float)$c['variance_quantity'] >= 0) ? 'badge-success' : 'badge-warn' ?>"><?= number_format((float)$c['variance_quantity'], 3) ?></span></td>
        <td><?= $c['branch_id'] ? ('#' . (int)$c['branch_id']) : 'Global' ?></td>
        <td><?= htmlspecialchars($c['notes'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($total > count($counts)): ?>
<p class="pagination">Page <?= $page ?> · <?= $total ?> total</p>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
