<?php
$title = $supplier['name'] ?? 'Supplier';
ob_start();
?>
<h1><?= htmlspecialchars($supplier['name'] ?? 'Supplier') ?></h1>
<div class="entity-actions">
    <a href="/inventory/suppliers/<?= (int)$supplier['id'] ?>/edit">Edit</a>
    <form method="post" action="/inventory/suppliers/<?= (int)$supplier['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete this supplier?')">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit">Delete</button>
    </form>
</div>
<dl class="entity-detail">
    <dt>Name</dt><dd><?= htmlspecialchars($supplier['name']) ?></dd>
    <dt>Contact</dt><dd><?= htmlspecialchars($supplier['contact_name'] ?? '—') ?></dd>
    <dt>Phone</dt><dd><?= htmlspecialchars($supplier['phone'] ?? '—') ?></dd>
    <dt>Email</dt><dd><?= htmlspecialchars($supplier['email'] ?? '—') ?></dd>
    <dt>Address</dt><dd><?= nl2br(htmlspecialchars($supplier['address'] ?? '—')) ?></dd>
    <dt>Notes</dt><dd><?= nl2br(htmlspecialchars($supplier['notes'] ?? '—')) ?></dd>
    <dt>Branch</dt><dd><?= $supplier['branch_id'] ? ('#' . (int)$supplier['branch_id']) : 'Global' ?></dd>
</dl>
<p><a href="/inventory/suppliers">← Back to suppliers</a></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
