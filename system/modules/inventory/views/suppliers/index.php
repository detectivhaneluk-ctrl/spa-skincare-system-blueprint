<?php
$title = 'Suppliers';
ob_start();
?>
<h1>Suppliers</h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form">
    <input type="text" name="search" placeholder="Search supplier..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
    <select name="branch_id">
        <option value="">All branches (explicit mix)</option>
        <option value="global" <?= (($_GET['branch_id'] ?? '') === 'global') ? 'selected' : '' ?>>Global only</option>
        <?php foreach ($branches as $b): ?>
        <option value="<?= (int) $b['id'] ?>" <?= (($_GET['branch_id'] ?? '') !== 'global' && (int)($_GET['branch_id'] ?? 0) === (int)$b['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
</form>

<p><a href="/inventory" class="btn">← Inventory</a> <a href="/inventory/suppliers/create" class="btn">Add Supplier</a></p>
<?php
$rows = $suppliers;
$headers = [
    ['key' => 'name', 'label' => 'Name', 'link' => true],
    ['key' => 'contact_name', 'label' => 'Contact'],
    ['key' => 'phone', 'label' => 'Phone'],
    ['key' => 'email', 'label' => 'Email'],
];
$rowUrl = fn ($r) => '/inventory/suppliers/' . $r['id'];
$actions = fn ($r) => '<a href="/inventory/suppliers/' . $r['id'] . '/edit">Edit</a> | <form method="post" action="/inventory/suppliers/' . $r['id'] . '/delete" style="display:inline" onsubmit="return confirm(\'Delete supplier?\')"><input type="hidden" name="' . htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) . '" value="' . htmlspecialchars($csrf) . '"><button type="submit">Delete</button></form>';
require shared_path('layout/table.php');
?>
<?php if ($total > count($suppliers)): ?>
<p class="pagination">Page <?= $page ?> · <?= $total ?> total</p>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
