<?php
$title = 'Service: ' . htmlspecialchars($service['name'] ?? '');
ob_start();
?>
<h1><?= htmlspecialchars($service['name'] ?? '') ?></h1>
<div class="entity-detail">
    <p><strong>Category:</strong> <?= htmlspecialchars($service['category_name'] ?? '—') ?></p>
    <?php
    $__desc = isset($service['description']) && $service['description'] !== null ? trim((string) $service['description']) : '';
    if ($__desc !== ''):
    ?>
    <p><strong>Description:</strong></p>
    <pre class="service-description"><?= htmlspecialchars($__desc) ?></pre>
    <?php endif; ?>
    <p><strong>Duration:</strong> <?= (int)($service['duration_minutes'] ?? 0) ?> min</p>
    <p><strong>Buffer:</strong> <?= (int)($service['buffer_before_minutes'] ?? 0) ?> min before, <?= (int)($service['buffer_after_minutes'] ?? 0) ?> min after</p>
    <p><strong>Price:</strong> <?= htmlspecialchars(number_format((float)($service['price'] ?? 0), 2)) ?></p>
    <p><strong>Active:</strong> <?= !empty($service['is_active']) ? 'Yes' : 'No' ?></p>
    <div class="entity-actions">
        <a href="/services-resources/services/<?= (int) $service['id'] ?>/edit" class="btn">Edit</a>
        <form method="post" action="/services-resources/services/<?= (int) $service['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete?')">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit">Delete</button>
        </form>
    </div>
</div>
<p><a href="/services-resources/services">← Back to Services</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
