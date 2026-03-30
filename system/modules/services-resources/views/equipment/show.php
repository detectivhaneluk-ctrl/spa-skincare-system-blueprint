<?php
$title = 'Equipment: ' . htmlspecialchars($item['name'] ?? '');
ob_start();
?>
<h1><?= htmlspecialchars($item['name'] ?? '') ?></h1>
<div class="entity-detail">
    <p><strong>Code:</strong> <?= htmlspecialchars($item['code'] ?? '—') ?></p>
    <p><strong>Serial number:</strong> <?= htmlspecialchars($item['serial_number'] ?? '—') ?></p>
    <p><strong>Active:</strong> <?= !empty($item['is_active']) ? 'Yes' : 'No' ?></p>
    <p><strong>Maintenance mode:</strong> <?= !empty($item['maintenance_mode']) ? 'Yes' : 'No' ?></p>
    <div class="entity-actions">
        <a href="/services-resources/equipment/<?= (int) $item['id'] ?>/edit" class="btn">Edit</a>
        <form method="post" action="/services-resources/equipment/<?= (int) $item['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete?')">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit">Delete</button>
        </form>
    </div>
</div>
<p><a href="/services-resources/equipment">← Back to Equipment</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
