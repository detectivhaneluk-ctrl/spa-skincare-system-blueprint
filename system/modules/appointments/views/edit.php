<?php
$title = 'Edit Appointment';
$flash = flash();
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$wBranch = isset($appointment['branch_id']) && $appointment['branch_id'] !== '' && $appointment['branch_id'] !== null
    ? (int) $appointment['branch_id']
    : null;
$wDate = isset($appointment['date']) && (string) $appointment['date'] !== '' ? (string) $appointment['date'] : null;
$listQ = $wBranch !== null ? '?' . http_build_query(['branch_id' => $wBranch]) : '';
$calQ = [];
if ($wBranch !== null) {
    $calQ['branch_id'] = $wBranch;
}
if ($wDate !== null) {
    $calQ['date'] = $wDate;
}
$calendarUrl = '/appointments/calendar/day' . ($calQ !== [] ? '?' . http_build_query($calQ) : '');
$createQ = [];
if ($wBranch !== null) {
    $createQ['branch_id'] = $wBranch;
}
if ($wDate !== null) {
    $createQ['date'] = $wDate;
}
$createUrl = '/appointments/create' . ($createQ !== [] ? '?' . http_build_query($createQ) : '');
$workspace['shell_modifier'] = 'workspace-shell--edit';
ob_start();
?>
<?php require base_path('modules/appointments/views/partials/workspace-shell.php'); ?>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<div class="appointments-edit-page">
<div class="appt-create-op-canvas appt-edit-op-canvas">
    <header class="appt-edit-page-head">
        <p class="appt-edit-page-head__id">Appointment #<?= (int) $appointment['id'] ?></p>
        <nav class="appt-edit-secondary-nav" aria-label="Related pages">
            <a href="/appointments/<?= (int) $appointment['id'] ?>" class="appt-edit-nav-link">View details</a>
            <span class="appt-edit-nav-sep" aria-hidden="true">·</span>
            <a href="<?= htmlspecialchars($calendarUrl) ?>" class="appt-edit-nav-link">Day calendar</a>
            <span class="appt-edit-nav-sep" aria-hidden="true">·</span>
            <a href="<?= htmlspecialchars('/appointments' . $listQ, ENT_QUOTES, 'UTF-8') ?>" class="appt-edit-nav-link">List</a>
        </nav>
    </header>
    <p class="appt-create-context appt-edit-lede"><strong>Edit booking</strong> — update scope, client, service, time, resources, status, and notes. Nothing is saved until you choose <strong>Update</strong>.</p>

<?php if (!empty($errors)): ?>
<ul class="form-errors appt-create-errors">
    <?php if (!empty($errors['_conflict'])): ?>
    <li class="error"><?= htmlspecialchars($errors['_conflict']) ?></li>
    <?php endif; ?>
    <?php if (!empty($errors['_general'])): ?>
    <li class="error"><?= htmlspecialchars($errors['_general']) ?></li>
    <?php endif; ?>
    <?php foreach ($errors as $k => $e): if (str_starts_with((string)$k, '_')) continue; ?>
    <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
<form method="post" action="/appointments/<?= (int) $appointment['id'] ?>" class="entity-form appt-create-form appt-edit-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">

    <div class="appt-edit-form-sections">
    <section class="appt-create-section" aria-labelledby="appt-edit-sec-branch">
        <h2 class="appt-create-section__title" id="appt-edit-sec-branch">Branch</h2>
        <div class="appt-create-section__body">
            <div class="form-row">
                <label for="branch_id">Branch</label>
                <select id="branch_id" name="branch_id">
                    <option value="">—</option>
                    <?php foreach ($branches as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= ((int)($appointment['branch_id'] ?? 0)) === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </section>

    <section class="appt-create-section" aria-labelledby="appt-edit-sec-client">
        <h2 class="appt-create-section__title" id="appt-edit-sec-client">Client</h2>
        <div class="appt-create-section__body">
            <div class="form-row">
                <label for="client_id">Client *</label>
                <select id="client_id" name="client_id" required>
                    <option value="">— Select client —</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= ((int)($appointment['client_id'] ?? 0)) === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim($c['first_name'] . ' ' . $c['last_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['client_id'])): ?><span class="error"><?= htmlspecialchars($errors['client_id']) ?></span><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="appt-create-section" aria-labelledby="appt-edit-sec-service">
        <h2 class="appt-create-section__title" id="appt-edit-sec-service">Service &amp; date</h2>
        <div class="appt-create-section__body appt-create-section__body--split">
            <div class="form-row">
                <label for="service_id">Service</label>
                <select id="service_id" name="service_id">
                    <option value="">—</option>
                    <?php foreach ($services as $s): ?>
                    <?php
                    $svcDesc = trim((string)($s['description'] ?? ''));
                    ?>
                    <option value="<?= (int) $s['id'] ?>" <?= ((int)($appointment['service_id'] ?? 0)) === (int)$s['id'] ? 'selected' : '' ?><?php if ($svcDesc !== '') {
                        echo ' title="' . htmlspecialchars($svcDesc, ENT_QUOTES, 'UTF-8') . '"';
                    } ?>><?= htmlspecialchars($s['name']) ?> (<?= (int)($s['duration_minutes'] ?? 0) ?> min)</option>
                    <?php endforeach; ?>
                </select>
                <span id="service-description-hint" class="hint" hidden></span>
            </div>
            <div class="form-row">
                <label for="date">Date *</label>
                <input type="date" id="date" name="date" required value="<?= htmlspecialchars($appointment['date'] ?? '') ?>">
            </div>
        </div>
    </section>

    <section class="appt-create-section" aria-labelledby="appt-edit-sec-time">
        <h2 class="appt-create-section__title" id="appt-edit-sec-time">Time</h2>
        <div class="appt-create-section__body">
            <div class="form-row">
                <label for="start_time">Start time *</label>
                <input type="time" id="start_time" name="start_time" required value="<?= htmlspecialchars($appointment['start_time'] ?? '') ?>">
                <?php if (!empty($errors['start_time'])): ?><span class="error"><?= htmlspecialchars($errors['start_time']) ?></span><?php endif; ?>
            </div>
            <div class="form-row">
                <label for="end_time">End time</label>
                <input type="time" id="end_time" name="end_time" value="<?= htmlspecialchars($appointment['end_time'] ?? '') ?>">
                <span class="hint">Manual override allowed. If blank with service selected, auto-calculated from duration.</span>
                <?php if (!empty($errors['end_time'])): ?><span class="error"><?= htmlspecialchars($errors['end_time']) ?></span><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="appt-create-section" aria-labelledby="appt-edit-sec-resources">
        <h2 class="appt-create-section__title" id="appt-edit-sec-resources">Staff &amp; room</h2>
        <div class="appt-create-section__body appt-create-section__body--split">
            <div class="form-row">
                <label for="staff_id">Staff</label>
                <select id="staff_id" name="staff_id">
                    <option value="">—</option>
                    <?php foreach ($staff as $st): ?>
                    <option value="<?= (int) $st['id'] ?>" <?= ((int)($appointment['staff_id'] ?? 0)) === (int)$st['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="room_id">Room</label>
                <select id="room_id" name="room_id">
                    <option value="">—</option>
                    <?php foreach ($rooms as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= ((int)($appointment['room_id'] ?? 0)) === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </section>

    <section class="appt-create-section" aria-labelledby="appt-edit-sec-status">
        <h2 class="appt-create-section__title" id="appt-edit-sec-status">Status &amp; notes</h2>
        <div class="appt-create-section__body">
            <div class="form-row">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="scheduled" <?= ($appointment['status'] ?? '') === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                    <option value="confirmed" <?= ($appointment['status'] ?? '') === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="in_progress" <?= ($appointment['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>In progress</option>
                    <option value="completed" <?= ($appointment['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= ($appointment['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    <option value="no_show" <?= ($appointment['status'] ?? '') === 'no_show' ? 'selected' : '' ?>>No show</option>
                </select>
            </div>
            <div class="form-row">
                <label for="appt-notes">Notes</label>
                <textarea id="appt-notes" name="notes" rows="3"><?= htmlspecialchars($appointment['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </section>

    <?php require base_path('modules/appointments/views/partials/appointment-calendar-meta-fields.php'); ?>
    </div>

    <div class="form-actions appt-create-actions appt-edit-actions">
        <div class="appt-edit-actions__primary">
            <button type="submit" class="appt-create-submit">Update</button>
            <a href="/appointments/<?= (int) $appointment['id'] ?>" class="appt-create-cancel">Cancel</a>
        </div>
        <p class="appt-edit-actions__hint">Discard changes any time by leaving this page or using Cancel (returns to the appointment view).</p>
    </div>
</form>
</div>
</div>
<script>
(() => {
  const serviceEl = document.getElementById('service_id');
  const serviceHintEl = document.getElementById('service-description-hint');
  if (!serviceEl || !serviceHintEl) return;

  const updateServiceDescriptionHint = () => {
    const opt = serviceEl.options[serviceEl.selectedIndex];
    const hint = opt && typeof opt.title === 'string' ? opt.title.trim() : '';
    if (hint) {
      serviceHintEl.textContent = hint;
      serviceHintEl.hidden = false;
      return;
    }
    serviceHintEl.textContent = '';
    serviceHintEl.hidden = true;
  };

  serviceEl.addEventListener('change', updateServiceDescriptionHint);
  updateServiceDescriptionHint();
})();
</script>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
