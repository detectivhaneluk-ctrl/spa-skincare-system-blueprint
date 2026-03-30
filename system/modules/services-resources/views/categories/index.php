<?php
$title = 'Service Categories';
ob_start();
?>
<h1>Service Categories</h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<p><a href="/services-resources" class="btn">← Services & Resources</a> <a href="/services-resources/categories/create" class="btn">Add Category</a></p>
<?php
$rows = $categories;
$headers = [
    ['key' => 'name', 'label' => 'Name', 'link' => true],
    ['key' => 'sort_order', 'label' => 'Sort'],
];
$rowUrl = fn ($r) => '/services-resources/categories/' . $r['id'];
$actions = fn ($r) => '<a href="/services-resources/categories/' . $r['id'] . '/edit">Edit</a> | <form method="post" action="/services-resources/categories/' . $r['id'] . '/delete" style="display:inline" onsubmit="return confirm(\'Delete?\')"><input type="hidden" name="' . htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) . '" value="' . htmlspecialchars($csrf) . '"><button type="submit">Delete</button></form>';
require shared_path('layout/table.php');
?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
