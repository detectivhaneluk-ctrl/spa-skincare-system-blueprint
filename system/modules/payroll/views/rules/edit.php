<?php
$title = $title ?? 'Edit rule';
ob_start();
$teamWorkspaceActiveTab = 'payroll';
$teamWorkspaceShellTitle = 'Team';
require base_path('modules/staff/views/partials/team-workspace-shell.php');
?>
<h2>Edit compensation rule</h2>
<?php if (!empty($errors['_general'])): ?>
<div class="flash flash-error"><?= htmlspecialchars((string) $errors['_general']) ?></div>
<?php endif; ?>

<form method="post" action="/payroll/rules/<?= (int) ($rule['id'] ?? 0) ?>" class="stack-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">

    <label>Name <input type="text" name="name" value="<?= htmlspecialchars((string) ($rule['name'] ?? '')) ?>"></label>
    <label>Branch ID (empty = all branches)
        <input type="text" name="branch_id" value="<?= htmlspecialchars((string) ($rule['branch_id'] ?? '')) ?>">
    </label>
    <label>Staff ID (empty = any)
        <input type="text" name="staff_id" value="<?= htmlspecialchars((string) ($rule['staff_id'] ?? '')) ?>">
    </label>
    <label>Service ID (empty = any)
        <input type="text" name="service_id" value="<?= htmlspecialchars((string) ($rule['service_id'] ?? '')) ?>">
    </label>
    <label>Service category ID (empty = any)
        <input type="text" name="service_category_id" value="<?= htmlspecialchars((string) ($rule['service_category_id'] ?? '')) ?>">
    </label>
    <label>Rule kind
        <select name="rule_kind">
            <option value="percent_service_line" <?= (($rule['rule_kind'] ?? '') === 'percent_service_line') ? 'selected' : '' ?>>Percent of service line</option>
            <option value="fixed_per_appointment" <?= (($rule['rule_kind'] ?? '') === 'fixed_per_appointment') ? 'selected' : '' ?>>Fixed per appointment</option>
        </select>
    </label>
    <label>Rate % (percent rules)
        <input type="text" name="rate_percent" value="<?= htmlspecialchars((string) ($rule['rate_percent'] ?? '')) ?>">
    </label>
    <?php if (!empty($errors['rate_percent'])): ?><p class="form-error"><?= htmlspecialchars($errors['rate_percent']) ?></p><?php endif; ?>

    <label>Fixed amount (fixed rules)
        <input type="text" name="fixed_amount" value="<?= htmlspecialchars((string) ($rule['fixed_amount'] ?? '')) ?>">
    </label>
    <label>Currency (fixed rules)
        <input type="text" name="currency" value="<?= htmlspecialchars((string) ($rule['currency'] ?? '')) ?>">
    </label>
    <?php if (!empty($errors['fixed_amount'])): ?><p class="form-error"><?= htmlspecialchars($errors['fixed_amount']) ?></p><?php endif; ?>
    <?php if (!empty($errors['currency'])): ?><p class="form-error"><?= htmlspecialchars($errors['currency']) ?></p><?php endif; ?>

    <label>Priority
        <input type="number" name="priority" value="<?= (int) ($rule['priority'] ?? 0) ?>">
    </label>
    <label><input type="checkbox" name="is_active" value="1" <?= ((int) ($rule['is_active'] ?? 0) === 1) ? 'checked' : '' ?>> Active</label>

    <p><button type="submit" class="btn">Save</button> <a href="/payroll/rules">Back</a></p>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
