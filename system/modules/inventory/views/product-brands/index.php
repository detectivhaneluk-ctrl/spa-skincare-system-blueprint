<?php
$title = 'Product brands';
ob_start();
$stockWorkspaceActiveTab = 'brands';
$stockWorkspaceShellTitle = 'Stock';
require base_path('modules/inventory/views/partials/stock-workspace-shell.php');
?>
<h2>Product brands</h2>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<p><a href="/inventory" class="btn">← Inventory</a> <a href="/inventory/product-brands/create" class="btn">Add brand</a></p>
<?php
$rows = $brands;
$headers = [
    ['key' => 'name', 'label' => 'Name', 'link' => true],
    ['key' => '_scope_label', 'label' => 'Scope', 'link' => false],
    ['key' => 'sort_order', 'label' => 'Sort'],
];
$rowUrl = fn ($r) => '/inventory/product-brands/' . $r['id'];
$actions = fn ($r) => '<a href="/inventory/product-brands/' . $r['id'] . '/edit">Edit</a> | <form method="post" action="/inventory/product-brands/' . $r['id'] . '/delete" style="display:inline" onsubmit="return confirm(\'Delete?\')"><input type="hidden" name="' . htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) . '" value="' . htmlspecialchars($csrf) . '"><button type="submit">Delete</button></form>';
require shared_path('layout/table.php');
?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
