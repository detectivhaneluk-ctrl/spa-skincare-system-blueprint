<?php
$title = 'Add Waitlist Entry';
ob_start();
?>
<?php require base_path('modules/appointments/views/partials/workspace-shell.php'); ?>

<?php if (!empty($errors)): ?>
<ul class="form-errors">
    <?php foreach ($errors as $e): ?>
    <li><?= htmlspecialchars((string) $e) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="post" action="/appointments/waitlist" class="entity-form">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="form-row">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">—</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?= (int) $b['id'] ?>" <?= ((int) ($entry['branch_id'] ?? 0) === (int) $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="client_id">Client</label>
        <select id="client_id" name="client_id">
            <option value="">—</option>
            <?php foreach ($clients as $c): ?>
            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars(trim((string) $c['first_name'] . ' ' . (string) $c['last_name'])) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="service_id">Service</label>
        <select id="service_id" name="service_id">
            <option value="">—</option>
            <?php foreach ($services as $s): ?>
            <?php
            $svcDesc = trim((string)($s['description'] ?? ''));
            ?>
            <option value="<?= (int) $s['id'] ?>"<?php if ($svcDesc !== '') {
                echo ' title="' . htmlspecialchars($svcDesc, ENT_QUOTES, 'UTF-8') . '"';
            } ?>><?= htmlspecialchars((string) $s['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <span id="service-description-hint" class="hint" hidden></span>
    </div>
    <div class="form-row">
        <label for="preferred_staff_id">Preferred Staff</label>
        <select id="preferred_staff_id" name="preferred_staff_id">
            <option value="">—</option>
            <?php foreach ($staff as $st): ?>
            <option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars(trim((string) ($st['first_name'] ?? '') . ' ' . (string) ($st['last_name'] ?? ''))) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <label for="preferred_date">Preferred Date *</label>
        <input type="date" id="preferred_date" name="preferred_date" required value="<?= htmlspecialchars((string) ($entry['preferred_date'] ?? date('Y-m-d'))) ?>">
    </div>
    <div class="form-row">
        <label for="preferred_time_from">Preferred Time From</label>
        <input type="time" id="preferred_time_from" name="preferred_time_from">
    </div>
    <div class="form-row">
        <label for="preferred_time_to">Preferred Time To</label>
        <input type="time" id="preferred_time_to" name="preferred_time_to">
    </div>
    <div class="form-row">
        <label for="notes">Notes</label>
        <textarea id="notes" name="notes" rows="3"></textarea>
    </div>
    <div class="form-actions">
        <button type="submit">Create Waitlist Entry</button>
        <a href="/appointments/waitlist">Cancel</a>
    </div>
</form>
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
