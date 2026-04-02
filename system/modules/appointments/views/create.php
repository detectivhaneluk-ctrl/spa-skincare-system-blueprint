<?php
$title = 'Add Appointment';
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$workspace['shell_modifier'] = 'workspace-shell--create';
ob_start();
?>
<?php require base_path('modules/appointments/views/partials/workspace-shell.php'); ?>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<div class="appointments-create-page">
<div class="appt-create-op-canvas">
<p class="appt-create-context"><strong>New booking</strong> — work top to bottom: scope → who → what &amp; when → availability → pick a time slot (tap a time to book).</p>

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
<form method="post" action="/appointments/create" class="entity-form appt-create-form" id="booking-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" id="selected_start_time" name="start_time" value="">

    <section class="appt-create-section appt-create-section--step" aria-labelledby="appt-create-sec-branch">
        <h2 class="appt-create-section__title" id="appt-create-sec-branch"><span class="appt-create-section__step" aria-hidden="true">1</span> Branch</h2>
        <div class="appt-create-section__body">
            <div class="form-row">
                <label for="branch_id">Branch</label>
                <?php if (count($branches) === 1): ?>
                <span class="ds-input form-control--locked"><?= htmlspecialchars($branches[0]['name']) ?></span>
                <input type="hidden" id="branch_id" name="branch_id" value="<?= (int) $branches[0]['id'] ?>">
                <?php else: ?>
                <select id="branch_id" name="branch_id">
                    <option value="">—</option>
                    <?php foreach ($branches as $b): ?>
                    <option value="<?= (int) $b['id'] ?>" <?= ((int)($appointment['branch_id'] ?? 0)) === (int)$b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="appt-create-section appt-create-section--step" aria-labelledby="appt-create-sec-client">
        <h2 class="appt-create-section__title" id="appt-create-sec-client"><span class="appt-create-section__step" aria-hidden="true">2</span> Client</h2>
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

    <section class="appt-create-section appt-create-section--step" aria-labelledby="appt-create-sec-service">
        <h2 class="appt-create-section__title" id="appt-create-sec-service"><span class="appt-create-section__step" aria-hidden="true">3</span> Service &amp; date</h2>
        <div class="appt-create-section__body appt-create-section__body--split">
            <div class="form-row">
                <label for="service_id">Service *</label>
                <select id="service_id" name="service_id" required>
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

    <section class="appt-create-section appt-create-section--step appt-create-section--booking-hub" aria-labelledby="appt-create-sec-resources">
        <h2 class="appt-create-section__title" id="appt-create-sec-resources"><span class="appt-create-section__step" aria-hidden="true">4</span> Staff &amp; availability</h2>
        <div class="appt-create-section__body">
            <div class="form-row">
                <label for="staff_id">Staff (optional for slots, required for booking)</label>
                <select id="staff_id" name="staff_id">
                    <option value="">—</option>
                    <?php foreach ($staff as $st): ?>
                    <option value="<?= (int) $st['id'] ?>" <?= ((int)($appointment['staff_id'] ?? 0)) === (int)$st['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="room_id">Room (optional)</label>
                <select id="room_id" name="room_id">
                    <option value="">—</option>
                    <?php foreach ($rooms as $r): ?>
                    <option value="<?= (int) $r['id'] ?>" <?= ((int)($appointment['room_id'] ?? 0)) === (int)$r['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) ($r['name'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">When set, slot search uses room occupancy (internal). The same room is saved on create-from-slot.</span>
            </div>
            <div class="form-row appt-create-row--inline">
                <button type="button" id="load-slots-btn" class="appt-create-btn appt-create-btn--secondary appt-create-btn--load">Load Slots</button>
                <span id="slots-status" class="hint appt-create-slots-status"></span>
            </div>
            <div class="form-row appt-create-slot-decision">
                <div class="appt-create-slot-decision__head">
                    <span class="appt-create-slot-decision__label">Choose a time</span>
                    <span class="appt-create-slot-decision__hint">Load slots, then click one time — the form submits when you pick a slot.</span>
                </div>
                <div id="slots-container" class="slots-grid appt-create-slots-grid" role="group" aria-label="Available slots">
                    <span class="hint">Select service and date, then load slots.</span>
                </div>
            </div>
        </div>
    </section>

    <section class="appt-create-section appt-create-section--step" aria-labelledby="appt-create-sec-notes">
        <h2 class="appt-create-section__title" id="appt-create-sec-notes"><span class="appt-create-section__step" aria-hidden="true">5</span> Notes</h2>
        <div class="appt-create-section__body">
            <div class="form-row">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($appointment['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </section>

    <div class="form-actions appt-create-actions appt-create-actions--booking">
        <p class="appt-create-actions__hint">Or use <strong>Create From Selected Slot</strong> after choosing a time in step 4 — slot click also submits.</p>
        <div class="appt-create-actions__row">
            <button type="submit" class="appt-create-submit">Create From Selected Slot</button>
            <a href="/appointments" class="appt-create-cancel">Cancel</a>
        </div>
    </div>
</form>
</div>
</div>
<script>
(() => {
  const form = document.getElementById('booking-form');
  const branchEl = document.getElementById('branch_id');
  const clientEl = document.getElementById('client_id');
  const serviceEl = document.getElementById('service_id');
  const dateEl = document.getElementById('date');
  const staffEl = document.getElementById('staff_id');
  const roomEl = document.getElementById('room_id');
  const startEl = document.getElementById('selected_start_time');
  const slotsWrap = document.getElementById('slots-container');
  const statusEl = document.getElementById('slots-status');
  const loadBtn = document.getElementById('load-slots-btn');
  const serviceHintEl = document.getElementById('service-description-hint');

  function updateServiceDescriptionHint() {
    const opt = serviceEl && serviceEl.options ? serviceEl.options[serviceEl.selectedIndex] : null;
    const hint = opt && typeof opt.title === 'string' ? opt.title.trim() : '';
    if (!serviceHintEl) return;
    if (hint) {
      serviceHintEl.textContent = hint;
      serviceHintEl.hidden = false;
      return;
    }
    serviceHintEl.textContent = '';
    serviceHintEl.hidden = true;
  }

  async function loadSlots() {
    const serviceId = serviceEl.value;
    const date = dateEl.value;
    if (!serviceId || !date) {
      statusEl.textContent = 'Select service and date first.';
      return;
    }
    statusEl.textContent = 'Loading...';
    slotsWrap.innerHTML = '';
    const params = new URLSearchParams();
    params.set('service_id', serviceId);
    params.set('date', date);
    if (staffEl.value) params.set('staff_id', staffEl.value);
    if (branchEl.value) params.set('branch_id', branchEl.value);
    if (roomEl && roomEl.value) params.set('room_id', roomEl.value);
    try {
      const res = await fetch('/appointments/slots?' + params.toString(), {headers: {'Accept': 'application/json'}});
      const payload = await res.json();
      if (!res.ok || !payload.success) {
        statusEl.textContent = payload.error || 'Failed to load slots.';
        return;
      }
      const slots = (payload.data && Array.isArray(payload.data.slots)) ? payload.data.slots : [];
      if (slots.length === 0) {
        slotsWrap.innerHTML = '<span class="hint">No slots available.</span>';
        statusEl.textContent = '';
        return;
      }
      statusEl.textContent = 'Click a slot to create appointment.';
      slots.forEach((slot) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'slot-btn';
        btn.textContent = slot;
        btn.addEventListener('click', () => {
          if (!clientEl.value) {
            statusEl.textContent = 'Select client first.';
            return;
          }
          if (!staffEl.value) {
            statusEl.textContent = 'Select staff before booking.';
            return;
          }
          startEl.value = date + ' ' + slot;
          form.submit();
        });
        slotsWrap.appendChild(btn);
      });
    } catch (e) {
      statusEl.textContent = 'Could not load slots.';
    }
  }

  loadBtn.addEventListener('click', loadSlots);
  form.addEventListener('submit', (e) => {
    if (!startEl.value) {
      e.preventDefault();
      statusEl.textContent = 'Select a slot button first.';
    }
  });
  [serviceEl, dateEl, staffEl, branchEl, roomEl].forEach((el) => {
    el.addEventListener('change', () => {
      statusEl.textContent = '';
      slotsWrap.innerHTML = '<span class="hint">Click "Load Slots" to refresh availability.</span>';
    });
  });
  serviceEl.addEventListener('change', updateServiceDescriptionHint);
  updateServiceDescriptionHint();
})();
</script>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
