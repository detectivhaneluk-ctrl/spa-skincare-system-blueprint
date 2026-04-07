<?php
$title = 'Equipment';
ob_start();
?>
<h1>Equipment</h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<p class="hint" style="margin-top:0;">Equipment resources used during services. Assign equipment to services to track resource usage and prevent double-booking.</p>
<p><a href="/services-resources" class="btn">← Catalog</a> <a href="/services-resources/equipment/create" class="btn">New equipment</a></p>
<?php
$rows = $equipment;
$headers = [
    ['key' => 'name', 'label' => 'Name', 'link' => true],
    ['key' => 'code', 'label' => 'Code'],
    ['key' => 'serial_number', 'label' => 'Serial'],
    ['key' => 'is_active', 'label' => 'Active'],
    ['key' => 'maintenance_mode', 'label' => 'Maintenance'],
];
$rowUrl = fn ($r) => '/services-resources/equipment/' . $r['id'];
$actions = fn ($r) => '<a href="/services-resources/equipment/' . $r['id'] . '/edit">Edit</a> | <form method="post" action="/services-resources/equipment/' . $r['id'] . '/delete" style="display:inline" onsubmit="return confirm(\'Delete?\')"><input type="hidden" name="' . htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) . '" value="' . htmlspecialchars($csrf) . '"><button type="submit">Delete</button></form>';
require shared_path('layout/table.php');
?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
