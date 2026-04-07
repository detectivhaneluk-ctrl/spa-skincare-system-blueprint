<?php
$title = !empty($trashView) ? 'Staff — Trash' : 'Staff';
$csrfName = htmlspecialchars(config('app.csrf_token_name', 'csrf_token'));
$csrfVal  = htmlspecialchars($csrf ?? '');
$showInactive = empty($trashView) && isset($_GET['active']) && (string) $_GET['active'] === '0';
$trashQs = '?status=trash';
if ($page > 1) {
    $trashQs .= '&page=' . (int) $page;
}
ob_start();
?>
<h1><?= htmlspecialchars($title) ?></h1>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<p class="svc-list-toolbar" style="display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;margin:0.75rem 0;">
    <span style="font-size:0.875rem;color:#64748b;">View:</span>
    <a href="/staff" class="btn btn-ghost" style="font-size:0.875rem;">Active roster <span class="badge badge-muted"><?= (int) ($countActive ?? 0) ?></span></a>
    <a href="/staff<?= htmlspecialchars($trashQs) ?>" class="btn btn-ghost" style="font-size:0.875rem;">Trash <span class="badge badge-muted"><?= (int) ($countTrash ?? 0) ?></span></a>
</p>

<?php if (empty($trashView)): ?>
<p>
    <a href="/staff/create" class="btn">Add Staff</a>
    <?php if (!$showInactive): ?>
    <a href="/staff?active=0" class="btn btn-ghost">Show inactive</a>
    <?php else: ?>
    <a href="/staff" class="btn btn-ghost">Active only</a>
    <?php endif; ?>
</p>
<?php else: ?>
<p><a href="/staff" class="btn btn-ghost">← Back to staff list</a></p>
<?php endif; ?>

<?php if (empty($staff)): ?>
<p style="color:#64748b;"><?= !empty($trashView) ? 'Trash is empty.' : 'No staff found.' ?></p>
<?php else: ?>

<form method="post" action="/staff/bulk-trash" id="stf-bulk-form" style="margin-bottom:0.5rem;">
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
    <input type="hidden" name="list_status" value="<?= !empty($trashView) ? 'trash' : '' ?>">
    <input type="hidden" name="list_page" value="<?= (int) $page ?>">
    <input type="hidden" name="list_active" value="<?= $showInactive ? '0' : '1' ?>">
    <div style="margin-bottom:0.5rem;display:flex;flex-wrap:wrap;align-items:center;gap:0.35rem;">
        <label for="stf-bulk-action" style="font-size:0.875rem;">Bulk actions</label>
        <select name="bulk_action" id="stf-bulk-action" style="min-width:11rem;">
            <option value="">— Select —</option>
            <?php if (empty($trashView)): ?>
            <option value="move_to_trash">Move to Trash</option>
            <?php else: ?>
            <option value="restore">Restore</option>
            <option value="delete_permanently">Delete permanently</option>
            <?php endif; ?>
        </select>
        <button type="submit" class="btn">Apply</button>
    </div>
</form>

<table class="index-table">
    <thead>
        <tr>
            <th style="width:2.25rem;text-align:center;">
                <input type="checkbox" title="Select all visible" aria-label="Select all visible rows" id="stf-check-all">
            </th>
            <th>Name</th>
            <th>Job Title</th>
            <th>Email</th>
            <th>Active</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($staff as $r): ?>
    <tr>
        <td style="text-align:center;">
            <input class="stf-row-check" type="checkbox" name="staff_ids[]" value="<?= (int) $r['id'] ?>" form="stf-bulk-form">
        </td>
        <td><a href="/staff/<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['display_name'] ?? '') ?></a></td>
        <td><?= htmlspecialchars($r['job_title'] ?? '—') ?></td>
        <td><?= htmlspecialchars($r['email'] ?? '—') ?></td>
        <td><?= !empty($r['is_active']) ? 'Yes' : 'No' ?></td>
        <td>
            <?php if (empty($trashView)): ?>
            <a href="/staff/<?= (int) $r['id'] ?>/edit">Edit</a>
            | <form method="post" action="/staff/<?= (int) $r['id'] ?>/delete" style="display:inline"
                onsubmit="return confirm('Move this staff member to Trash?')">
                <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                <button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;padding:0;font:inherit;">Trash</button>
            </form>
            <?php else: ?>
            <form method="post" action="/staff/<?= (int) $r['id'] ?>/restore" style="display:inline">
                <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">
                <button type="submit" style="background:none;border:none;color:#0f766e;cursor:pointer;padding:0;font:inherit;">Restore</button>
            </form>
            | <form method="post" action="/staff/<?= (int) $r['id'] ?>/permanent-delete" style="display:inline"
                onsubmit="return confirm('Permanently delete this staff member? This cannot be undone.')">
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
  var form = document.getElementById('stf-bulk-form');
  var sel = document.getElementById('stf-bulk-action');
  var all = document.getElementById('stf-check-all');
  if (!form || !sel) return;
  if (all) {
    all.addEventListener('change', function () {
      document.querySelectorAll('.stf-row-check').forEach(function (c) { c.checked = all.checked; });
    });
  }
  form.addEventListener('submit', function (e) {
    var act = sel.value;
    if (!act) { e.preventDefault(); return false; }
    var boxes = document.querySelectorAll('.stf-row-check:checked');
    var n = boxes.length;
    if (n === 0) { e.preventDefault(); alert('Select at least one staff member.'); return false; }
    var msg;
    if (act === 'move_to_trash') {
      msg = 'Move ' + n + ' staff member(s) to Trash?';
      form.action = '/staff/bulk-trash';
    } else if (act === 'restore') {
      msg = 'Restore ' + n + ' staff member(s)?';
      form.action = '/staff/bulk-restore';
    } else if (act === 'delete_permanently') {
      msg = 'Permanently delete ' + n + ' staff member(s)? This cannot be undone.';
      form.action = '/staff/bulk-permanent-delete';
    } else { e.preventDefault(); return false; }
    if (!confirm(msg)) { e.preventDefault(); return false; }
  });
})();
</script>
<?php endif; ?>

<?php if (!empty($total) && $total > count($staff)): ?>
<?php
$paginationQs = [];
if (!empty($trashView)) {
    $paginationQs[] = 'status=trash';
}
if ($showInactive) {
    $paginationQs[] = 'active=0';
}
$paginationPrefix = '/staff' . ($paginationQs !== [] ? ('?' . implode('&', $paginationQs) . '&') : '?');
?>
<p class="pagination">Page <?= (int) $page ?> · <?= (int) $total ?> total
    <?php if ($page > 1): ?> · <a href="<?= htmlspecialchars($paginationPrefix) ?>page=<?= (int) $page - 1 ?>">Previous</a><?php endif; ?>
    <?php if ($page * 20 < (int) $total): ?> · <a href="<?= htmlspecialchars($paginationPrefix) ?>page=<?= (int) $page + 1 ?>">Next</a><?php endif; ?>
</p>
<?php endif; ?>

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
