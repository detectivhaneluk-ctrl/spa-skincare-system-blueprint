<?php
$title = 'Room: ' . htmlspecialchars($room['name'] ?? '');
ob_start();
?>
<h1><?= htmlspecialchars($room['name'] ?? '') ?></h1>
<div class="entity-detail">
    <p><strong>Code:</strong> <?= htmlspecialchars($room['code'] ?? '—') ?></p>
    <p><strong>Active:</strong> <?= !empty($room['is_active']) ? 'Yes' : 'No' ?></p>
    <p><strong>Maintenance mode:</strong> <?= !empty($room['maintenance_mode']) ? 'Yes' : 'No' ?></p>
    <div class="entity-actions">
        <a href="/services-resources/rooms/<?= (int) $room['id'] ?>/edit" class="btn">Edit</a>
        <form method="post" action="/services-resources/rooms/<?= (int) $room['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete?')">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit">Delete</button>
        </form>
    </div>
</div>
<p><a href="/services-resources/rooms">← Back to Spaces</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
