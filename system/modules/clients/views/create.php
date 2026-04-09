<?php
declare(strict_types=1);

$isDrawer = (isset($_GET['drawer']) && (string) $_GET['drawer'] === '1')
    || (string) ($_SERVER['HTTP_X_APP_DRAWER'] ?? '') === '1';

$csrfName = htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'), ENT_QUOTES, 'UTF-8');
$csrfVal = htmlspecialchars((string) ($csrf ?? ''), ENT_QUOTES, 'UTF-8');

if (!$isDrawer) {
    $title = 'Add Client';
    ob_start();
    echo '<h1>Add Client</h1>';
}
?>

<div
    class="client-create-drawer-content<?= $isDrawer ? ' staff-create-drawer-content' : '' ?>"
    <?php if ($isDrawer): ?>
    data-drawer-content-root
    data-drawer-title="New Client"
    data-drawer-subtitle="Quick intake — essentials first"
    data-drawer-width="medium"
    <?php endif; ?>
>
<?php if (!empty($errors)): ?>
<?php if ($isDrawer): ?>
<div class="staff-create-errors" role="alert">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div>
        <strong>Please correct the following:</strong>
        <ul class="staff-create-errors__list">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid'), ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php else: ?>
<ul class="form-errors">
    <?php foreach ($errors as $e): ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<?php endif; ?>

<form
    method="post"
    action="/clients"
    class="entity-form client-create-form staff-create-form<?= $isDrawer ? '' : ' client-create-form--fullpage' ?>"
    <?php if ($isDrawer): ?>data-drawer-submit data-drawer-dirty-track novalidate<?php endif; ?>
>
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
    <?php require base_path('modules/clients/views/partials/client-create-form-fields.php'); ?>
    <div class="form-actions <?= $isDrawer ? 'staff-create-actions' : 'client-create-form-actions' ?>">
        <?php if ($isDrawer): ?>
        <button type="button" class="staff-create-btn-cancel" data-app-drawer-close>Cancel</button>
        <?php else: ?>
        <a href="/clients">Cancel</a>
        <?php endif; ?>
        <button type="submit" class="<?= $isDrawer ? 'staff-create-btn-submit' : 'client-create-btn-submit' ?>">Create client</button>
    </div>
</form>
</div>

<?php if (!$isDrawer): ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
<?php endif; ?>
