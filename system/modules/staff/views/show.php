<?php
$title = $staff['display_name'];
$staffIsTrashed = (bool) ($staffIsTrashed ?? false);
$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$flash = flash();
ob_start();
?>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<h1><?= htmlspecialchars($staff['display_name']) ?></h1>
<?php if ($staffIsTrashed): ?>
<p><span class="badge badge-muted">In Trash</span></p>
<?php endif; ?>
<div class="entity-actions">
    <?php if (!$staffIsTrashed): ?>
    <a href="/staff/<?= (int) $staff['id'] ?>/edit">Edit</a>
    <form method="post" action="/staff/<?= (int) $staff['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Move this staff member to Trash?')">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit">Trash</button>
    </form>
    <?php else: ?>
    <form method="post" action="/staff/<?= (int) $staff['id'] ?>/restore" style="display:inline">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn">Restore</button>
    </form>
    <form method="post" action="/staff/<?= (int) $staff['id'] ?>/permanent-delete" style="display:inline" onsubmit="return confirm('Permanently delete this staff member? This cannot be undone.')">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn" style="color:#dc2626;">Delete permanently</button>
    </form>
    <?php endif; ?>
</div>
<dl class="entity-detail">
    <dt>First name</dt><dd><?= htmlspecialchars($staff['first_name']) ?></dd>
    <dt>Last name</dt><dd><?= htmlspecialchars($staff['last_name']) ?></dd>
    <dt>Job Title</dt><dd><?= htmlspecialchars($staff['job_title'] ?? '—') ?></dd>
    <dt>Phone</dt><dd><?= htmlspecialchars($staff['phone'] ?? '—') ?></dd>
    <dt>Email</dt><dd><?= htmlspecialchars($staff['email'] ?? '—') ?></dd>
    <dt>Active</dt><dd><?= $staff['is_active'] ? 'Yes' : 'No' ?></dd>
</dl>

<h2>Weekly schedule</h2>
<?php if ($staffIsTrashed): ?>
<p class="hint">This profile is in Trash — schedule changes are disabled until restored.</p>
<?php else: ?>
<p class="hint">Recurring working hours by day (0=Sun … 6=Sat). Used for availability and calendar.</p>
<?php endif; ?>
<table class="index-table">
    <thead><tr><th>Day</th><th>Start</th><th>End</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($schedules as $s): ?>
    <tr>
        <td><?= htmlspecialchars($dayNames[$s['day_of_week']] ?? (string) $s['day_of_week']) ?></td>
        <td><?= htmlspecialchars(substr($s['start_time'], 0, 5)) ?></td>
        <td><?= htmlspecialchars(substr($s['end_time'], 0, 5)) ?></td>
        <td>
            <?php if (!$staffIsTrashed): ?>
            <form method="post" action="/staff/<?= (int) $staff['id'] ?>/schedules/<?= (int) $s['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this schedule entry?')">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit">Remove</button>
            </form>
            <?php else: ?>—<?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($schedules)): ?>
    <tr><td colspan="4"><span class="hint">No schedule entries. Add one below.</span></td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php if (!$staffIsTrashed): ?>
<form method="post" action="/staff/<?= (int) $staff['id'] ?>/schedules" class="entity-form" style="margin-top:0.5em;">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <select name="day_of_week" required>
        <?php foreach ($dayNames as $dow => $name): ?>
        <option value="<?= $dow ?>"><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="time" name="start_time" required step="300">
    <input type="time" name="end_time" required step="300">
    <button type="submit">Add schedule</button>
</form>
<?php endif; ?>

<h2>Breaks</h2>
<?php if (!$staffIsTrashed): ?>
<p class="hint">Recurring breaks (e.g. lunch) by day. Reduce available slots within working hours.</p>
<?php endif; ?>
<table class="index-table">
    <thead><tr><th>Day</th><th>Start</th><th>End</th><th>Title</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($breaks as $b): ?>
    <tr>
        <td><?= htmlspecialchars($dayNames[$b['day_of_week']] ?? (string) $b['day_of_week']) ?></td>
        <td><?= htmlspecialchars(substr($b['start_time'], 0, 5)) ?></td>
        <td><?= htmlspecialchars(substr($b['end_time'], 0, 5)) ?></td>
        <td><?= htmlspecialchars($b['title'] ?? '—') ?></td>
        <td>
            <?php if (!$staffIsTrashed): ?>
            <form method="post" action="/staff/<?= (int) $staff['id'] ?>/breaks/<?= (int) $b['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this break?')">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit">Remove</button>
            </form>
            <?php else: ?>—<?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($breaks)): ?>
    <tr><td colspan="5"><span class="hint">No breaks. Add one below.</span></td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php if (!$staffIsTrashed): ?>
<form method="post" action="/staff/<?= (int) $staff['id'] ?>/breaks" class="entity-form" style="margin-top:0.5em;">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <select name="day_of_week" required>
        <?php foreach ($dayNames as $dow => $name): ?>
        <option value="<?= $dow ?>"><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
    </select>
    <input type="time" name="start_time" required step="300">
    <input type="time" name="end_time" required step="300">
    <input type="text" name="title" placeholder="e.g. Lunch" maxlength="100">
    <button type="submit">Add break</button>
</form>
<?php endif; ?>

<p><a href="/staff">← Back to list</a><?php if ($staffIsTrashed): ?> · <a href="/staff?status=trash">View Trash</a><?php endif; ?></p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
