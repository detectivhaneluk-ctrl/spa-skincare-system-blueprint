<?php
$title = $trashView ? 'Services — Trash' : 'Services';
$csrfName = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$csrfVal  = htmlspecialchars($csrf ?? '');
ob_start();
?>
<h1><?= htmlspecialchars($title) ?></h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<form method="get" class="search-form">
    <?php if (!empty($trashView)): ?>
    <input type="hidden" name="status" value="trash">
    <?php endif; ?>
    <select name="category">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= ($categoryId !== null && (int) $categoryId === (int) $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Filter</button>
</form>

<p class="svc-list-toolbar" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;margin:1rem 0;">
    <span style="font-size:0.875rem;color:#64748b;">View:</span>
    <a href="/services-resources/services<?= $categoryId !== null ? ('?category=' . (int) $categoryId) : '' ?>" class="btn btn-ghost" style="font-size:0.875rem;">All <span class="badge badge-muted"><?= (int) ($countActive ?? 0) ?></span></a>
    <a href="/services-resources/services?status=trash<?= $categoryId !== null ? ('&amp;category=' . (int) $categoryId) : '' ?>" class="btn btn-ghost" style="font-size:0.875rem;">Trash <span class="badge badge-muted"><?= (int) ($countTrash ?? 0) ?></span></a>
</p>

<p>
    <a href="/services-resources" class="btn">← Catalog</a>
    <?php if (empty($trashView)): ?>
    <a href="/services-resources/services/create" class="btn">New service</a>
    <?php endif; ?>
</p>

<?php if (empty($services)): ?>
<p style="color:#64748b;"><?= $trashView ? 'Trash is empty.' : 'No services yet.' ?> <?php if (!$trashView): ?><a href="/services-resources/services/create">Create your first service.</a><?php endif; ?></p>
<?php else: ?>

<form method="post" action="/services-resources/services/bulk-trash" id="svc-bulk-form" class="svc-bulk-form" style="margin-bottom:0.5rem;">
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
    <input type="hidden" name="list_category" value="<?= $categoryId !== null ? (int) $categoryId : '' ?>">
    <input type="hidden" name="list_status" value="<?= $trashView ? 'trash' : '' ?>">
    <div style="margin-bottom:0.5rem;display:flex;flex-wrap:wrap;align-items:center;gap:0.35rem;">
        <label for="svc-bulk-action" style="font-size:0.875rem;">Bulk actions</label>
        <select name="bulk_action" id="svc-bulk-action" style="min-width:11rem;">
            <option value="">— Select —</option>
            <?php if (empty($trashView)): ?>
            <option value="move_to_trash">Move to Trash</option>
            <?php else: ?>
            <option value="restore">Restore</option>
            <option value="delete_permanently">Delete permanently</option>
            <?php endif; ?>
        </select>
        <button type="submit" class="btn" id="svc-bulk-apply">Apply</button>
    </div>
</form>

<table class="index-table">
    <thead>
        <tr>
            <th style="width:2.25rem;text-align:center;">
                <input type="checkbox" title="Select all visible" aria-label="Select all visible rows" id="svc-check-all">
            </th>
            <th>Name</th>
            <th>Type</th>
            <th>Category</th>
            <th>SKU</th>
            <th>Duration</th>
            <th>Price</th>
            <th>Staff</th>
            <th>Spaces</th>
            <th>Products</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($services as $r):
        $dMin    = (int)($r['duration_minutes'] ?? 0);
        $active  = !empty($r['is_active']);
        $svcType = match($r['service_type'] ?? 'service') {
            'package_item' => 'Package',
            'other'        => 'Other',
            default        => 'Service',
        };
        $dLabel  = $dMin > 0 ? $dMin . 'min' : '—';
        $addOn   = !empty($r['add_on']);
        $online  = !empty($r['show_in_online_menu']);
    ?>
    <tr>
        <td style="text-align:center;">
            <input class="svc-row-check" type="checkbox" name="service_ids[]" value="<?= (int) $r['id'] ?>" form="svc-bulk-form">
        </td>
        <td>
            <a href="/services-resources/services/<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['name'] ?? '') ?></a>
            <?php if ($addOn): ?><span class="badge badge-muted" style="font-size:0.7rem;">Add-on</span><?php endif; ?>
            <?php if ($online): ?><span class="badge badge-muted" style="font-size:0.7rem;">Online</span><?php endif; ?>
        </td>
        <td><?= htmlspecialchars($svcType) ?></td>
        <td><?= htmlspecialchars($r['category_name'] ?? '—') ?></td>
        <td><?= htmlspecialchars($r['sku'] ?? '—') ?></td>
        <td><?= htmlspecialchars($dLabel) ?></td>
        <td><?= htmlspecialchars(number_format((float)($r['price'] ?? 0), 2)) ?></td>
        <td style="text-align:center; color:<?= (int)($r['staff_count']??0) > 0 ? '#0f172a' : '#94a3b8' ?>;">
            <?= (int)($r['staff_count'] ?? 0) ?: '—' ?>
        </td>
        <td style="text-align:center; color:<?= (int)($r['room_count']??0) > 0 ? '#0f172a' : '#94a3b8' ?>;">
            <?= (int)($r['room_count'] ?? 0) ?: '—' ?>
        </td>
        <td style="text-align:center; color:<?= (int)($r['product_count']??0) > 0 ? '#0f172a' : '#94a3b8' ?>;">
            <?= (int)($r['product_count'] ?? 0) ?: '—' ?>
        </td>
        <td><span class="badge <?= $active ? 'badge-success' : 'badge-muted' ?>"><?= $active ? 'Active' : 'Inactive' ?></span></td>
        <td>
            <?php if (empty($trashView)): ?>
            <a href="/services-resources/services/<?= (int) $r['id'] ?>/edit">Edit</a>
            | <form method="post" action="/services-resources/services/<?= (int) $r['id'] ?>/delete"
                style="display:inline"
                onsubmit="return confirm('Move this service to Trash?')">
                <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                <button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;padding:0;font:inherit;">Trash</button>
            </form>
            <?php else: ?>
            <form method="post" action="/services-resources/services/<?= (int) $r['id'] ?>/restore" style="display:inline">
                <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                <button type="submit" style="background:none;border:none;color:#0f766e;cursor:pointer;padding:0;font:inherit;">Restore</button>
            </form>
            | <form method="post" action="/services-resources/services/<?= (int) $r['id'] ?>/permanent-delete"
                style="display:inline"
                onsubmit="return confirm('Permanently delete this service? This cannot be undone.')">
                <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                <button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;padding:0;font:inherit;">Delete permanently</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<script>
(function () {
  var form = document.getElementById('svc-bulk-form');
  var sel = document.getElementById('svc-bulk-action');
  var all = document.getElementById('svc-check-all');
  if (!form || !sel) return;
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('.svc-row-check').forEach(function (c) { c.checked = all.checked; });
    });
  }
  form.addEventListener('submit', function (e) {
    var act = sel.value;
    if (!act) {
      e.preventDefault();
      return false;
    }
    var boxes = document.querySelectorAll('.svc-row-check:checked');
    var n = boxes.length;
    if (n === 0) {
      e.preventDefault();
      alert('Select at least one service.');
      return false;
    }
    var msg;
    if (act === 'move_to_trash') {
      msg = 'Move ' + n + ' service(s) to Trash?';
      form.action = '/services-resources/services/bulk-trash';
    } else if (act === 'restore') {
      msg = 'Restore ' + n + ' service(s)?';
      form.action = '/services-resources/services/bulk-restore';
    } else if (act === 'delete_permanently') {
      msg = 'Permanently delete ' + n + ' service(s)? This cannot be undone.';
      form.action = '/services-resources/services/bulk-permanent-delete';
    } else {
      e.preventDefault();
      return false;
    }
    if (!confirm(msg)) {
      e.preventDefault();
      return false;
    }
  });
})();
</script>
<?php endif; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
