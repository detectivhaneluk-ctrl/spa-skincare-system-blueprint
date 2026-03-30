<?php
$title = 'Add Client';
ob_start();
?>
<h1>Add Client</h1>
<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php foreach ($errors as $e): ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/clients" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <p class="hint">Field order follows the <em>customer_details</em> page layout when organization context is available.</p>
    <h2 class="client-ref-block-title">Client details</h2>
    <?php
    $detailsLayoutKeys = $detailsLayoutKeys ?? [];
    require base_path('modules/clients/views/partials/client-details-layout-render.php');
    ?>
    <div class="form-actions">
        <button type="submit">Create</button>
        <a href="/clients">Cancel</a>
    </div>
</form>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
