<?php
$title = 'Staff';
ob_start();
?>
<h1>Staff</h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<p><a href="/staff/create" class="btn">Add Staff</a></p>
<?php
$headers = [
    ['key' => 'display_name', 'label' => 'Name', 'link' => true],
    ['key' => 'job_title', 'label' => 'Job Title'],
    ['key' => 'email', 'label' => 'Email'],
    ['key' => 'is_active', 'label' => 'Active'],
];
$rows = $staff;
$rowUrl = fn ($r) => '/staff/' . $r['id'];
$actions = fn ($r) => '<a href="/staff/' . $r['id'] . '/edit">Edit</a> | <form method="post" action="/staff/' . $r['id'] . '/delete" style="display:inline" onsubmit="return confirm(\'Delete?\')"><input type="hidden" name="' . htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) . '" value="' . htmlspecialchars($csrf) . '"><button type="submit">Delete</button></form>';
foreach ($staff as &$s) {
    $s['is_active'] = $s['is_active'] ? 'Yes' : 'No';
}
unset($s);
require shared_path('layout/table.php');
?>
<?php if ($total > count($staff)): ?>
<p class="pagination">Page <?= $page ?> · <?= $total ?> total</p>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
