<?php
$title = 'Rooms';
ob_start();
?>
<h1>Rooms</h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<p><a href="/services-resources" class="btn">← Services & Resources</a> <a href="/services-resources/rooms/create" class="btn">Add Room</a></p>
<?php
$rows = $rooms;
$headers = [
    ['key' => 'name', 'label' => 'Name', 'link' => true],
    ['key' => 'code', 'label' => 'Code'],
    ['key' => 'is_active', 'label' => 'Active'],
    ['key' => 'maintenance_mode', 'label' => 'Maintenance'],
];
$rowUrl = fn ($r) => '/services-resources/rooms/' . $r['id'];
$actions = fn ($r) => '<a href="/services-resources/rooms/' . $r['id'] . '/edit">Edit</a> | <form method="post" action="/services-resources/rooms/' . $r['id'] . '/delete" style="display:inline" onsubmit="return confirm(\'Delete?\')"><input type="hidden" name="' . htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) . '" value="' . htmlspecialchars($csrf) . '"><button type="submit">Delete</button></form>';
require shared_path('layout/table.php');
?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
