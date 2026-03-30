<?php
$title = $title ?? 'Compensation rules';
ob_start();
?>
<h1>Compensation rules</h1>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars((string) $t) ?>"><?= htmlspecialchars((string) ($flash[$t] ?? '')) ?></div>
<?php endif; ?>

<p><a class="btn" href="/payroll/rules/create">Create rule</a>
   <a href="/payroll/runs">Payroll runs</a></p>

<table class="index-table">
    <thead>
    <tr>
        <th>Name</th>
        <th>Kind</th>
        <th>Branch</th>
        <th>Staff</th>
        <th>Service / category</th>
        <th>Rate / fixed</th>
        <th>Active</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $r): ?>
    <tr>
        <td><?= htmlspecialchars((string) ($r['name'] ?? '')) ?></td>
        <td><code><?= htmlspecialchars((string) ($r['rule_kind'] ?? '')) ?></code></td>
        <td><?= !empty($r['branch_id']) ? '#' . (int) $r['branch_id'] : 'All' ?></td>
        <td><?= !empty($r['staff_id']) ? '#' . (int) $r['staff_id'] : 'Any' ?></td>
        <td><?php
            $bits = [];
            if (!empty($r['service_id'])) {
                $bits[] = 'svc #' . (int) $r['service_id'];
            }
            if (!empty($r['service_category_id'])) {
                $bits[] = 'cat #' . (int) $r['service_category_id'];
            }
            echo htmlspecialchars($bits === [] ? 'Any' : implode(', ', $bits));
        ?></td>
        <td><?php
            if (($r['rule_kind'] ?? '') === 'percent_service_line') {
                echo htmlspecialchars((string) ($r['rate_percent'] ?? '')) . '%';
            } else {
                echo htmlspecialchars((string) ($r['fixed_amount'] ?? '')) . ' ' . htmlspecialchars((string) ($r['currency'] ?? ''));
            }
        ?></td>
        <td><?= !empty($r['is_active']) ? 'yes' : 'no' ?></td>
        <td><a href="/payroll/rules/<?= (int) ($r['id'] ?? 0) ?>/edit">Edit</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($items === []): ?>
<p>No rules yet.</p>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
