<?php
$title = 'Services & Resources';
ob_start();
?>
<h1>Services & Resources</h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<nav class="entity-nav">
    <a href="/services-resources/categories">Service Categories</a>
    <a href="/services-resources/services">Services</a>
    <a href="/services-resources/rooms">Rooms</a>
    <a href="/services-resources/equipment">Equipment</a>
</nav>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
