<?php
$title = $title ?? 'Payroll runs';
ob_start();
?>
<h1>Payroll runs</h1>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars((string) $t) ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></div>
<?php endif; ?>

<?php if (!empty($canManage)): ?>
<p>
    <a class="btn" href="/payroll/runs/create">Create run</a>
    <a href="/payroll/rules">Compensation rules</a>
</p>
<?php endif; ?>

<table class="index-table">
    <thead>
    <tr>
        <th>ID</th>
        <th>Branch</th>
        <th>Period</th>
        <th>Status</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $r): ?>
    <tr>
        <td><?= (int) ($r['id'] ?? 0) ?></td>
        <td>#<?= (int) ($r['branch_id'] ?? 0) ?></td>
        <td><?= htmlspecialchars((string) ($r['period_start'] ?? '')) ?> – <?= htmlspecialchars((string) ($r['period_end'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string) ($r['status'] ?? '')) ?></td>
        <td><a href="/payroll/runs/<?= (int) ($r['id'] ?? 0) ?>">View</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($items === []): ?>
<p>No payroll runs yet.</p>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
