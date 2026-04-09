<?php
$title = $title ?? 'Create payroll run';
ob_start();
$teamWorkspaceActiveTab = 'payroll';
$teamWorkspaceShellTitle = 'Team';
require base_path('modules/staff/views/partials/team-workspace-shell.php');
?>
<h2>Create payroll run</h2>
<?php if (!empty($errors['_general'])): ?>
<div class="flash flash-error"><?= htmlspecialchars((string) $errors['_general']) ?></div>
<?php endif; ?>

<form method="post" action="/payroll/runs" class="stack-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">

    <?php if (($run['branch_id'] ?? null) === null && !empty($branches)): ?>
    <label>Branch
        <select name="branch_id" required>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int) ($b['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($b['name'] ?? '')) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php else: ?>
    <input type="hidden" name="branch_id" value="<?= (int) ($run['branch_id'] ?? 0) ?>">
    <p>Branch: #<?= (int) ($run['branch_id'] ?? 0) ?></p>
    <?php endif; ?>

    <label>Period start <input type="date" name="period_start" required value="<?= htmlspecialchars((string) ($run['period_start'] ?? '')) ?>"></label>
    <label>Period end <input type="date" name="period_end" required value="<?= htmlspecialchars((string) ($run['period_end'] ?? '')) ?>"></label>
    <label>Notes <textarea name="notes" rows="2"><?= htmlspecialchars((string) ($run['notes'] ?? '')) ?></textarea></label>
    <p><button type="submit" class="btn">Create</button> <a href="/payroll/runs">Cancel</a></p>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
