<?php
/** @var array<string, mixed> $state        Wizard state (branch_id, search). */
/** @var array<string, string> $errors       Validation errors keyed by field name. */
/** @var array<string, mixed> $workspace     Workspace shell context. */
/** @var list<array>          $categories    Service categories. */
/** @var list<array>          $services      All active services for this branch. */
/** @var list<array>          $staff         All active staff for this branch. */
/** @var list<array>          $rooms         All rooms for this branch. */
/** @var string               $branchName */
/** @var string               $csrf */
$title    = $title ?? 'New Appointment — Step 1';
ob_start();
$search   = $state['search'] ?? [];
$branchId = (int) ($state['branch_id'] ?? 0);
$csrfName = config('app.csrf_token_name', 'csrf_token');
?>
<?php require base_path('modules/appointments/views/partials/workspace-shell.php'); ?>

<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars((string)$t) ?>"><?= htmlspecialchars((string)($flash[$t] ?? '')) ?></div>
<?php endif; ?>

<div class="appt-wizard-page">

  <nav class="appt-wizard-steps" aria-label="Booking steps">
    <span class="appt-wizard-steps__step is-active">1. Search</span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step">2. Select Slot</span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step">3. Customer</span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step">4. Payment</span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step">5. Review</span>
  </nav>

  <div class="appt-wizard-body">
    <h2 class="appt-wizard-body__title">Step 1 — Availability Search</h2>
    <?php if (!empty($continuation)): ?>
    <div class="flash flash-info">
      <strong>Linked continuation search</strong> — searching for services after
      <?= htmlspecialchars((string)($continuation['after_time'] ?? '')) ?>
      on <?= htmlspecialchars((string)($continuation['date'] ?? '')) ?>.
      Your previous service lines are preserved.
      <a href="/appointments/wizard/step2?branch_id=<?= $branchId ?>">Cancel continuation</a>
    </div>
    <?php endif; ?>
    <p class="appt-wizard-body__intro">Choose a service, date, and optional filters, then click <strong>Search</strong>.</p>

    <?php if (!empty($errors)): ?>
    <ul class="form-errors appt-wizard-errors">
      <?php foreach ($errors as $err): ?>
      <li><?= htmlspecialchars((string)$err) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="post" action="/appointments/wizard/step1?branch_id=<?= $branchId ?>" class="appt-wizard-form" id="wizard-step1-form">
      <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">

      <fieldset class="appt-wizard-section">
        <legend class="appt-wizard-section__title">Search mode</legend>
        <div class="form-row form-row--inline">
          <label>
            <input type="radio" name="mode" value="service" <?= ($search['mode'] ?? 'service') === 'service' ? 'checked' : '' ?>>
            Service
          </label>
          <label style="color: #999; cursor: not-allowed;" title="Package booking is not yet available">
            <input type="radio" name="mode" value="package" disabled>
            Package <span class="hint">(not yet available — disabled)</span>
          </label>
        </div>
      </fieldset>

      <fieldset class="appt-wizard-section">
        <legend class="appt-wizard-section__title">Service</legend>
        <div class="form-row">
          <label for="wiz-category">Category <span class="hint">(optional filter)</span></label>
          <select id="wiz-category" name="category_id">
            <option value="">— All categories —</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)($cat['id'] ?? 0) ?>"
              <?= ((int)($search['category_id'] ?? 0)) === (int)($cat['id'] ?? 0) ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)($cat['name'] ?? '')) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label for="wiz-service">Service <span class="hint">*</span></label>
          <select id="wiz-service" name="service_id" required>
            <option value="">— Select service —</option>
            <?php foreach ($services as $svc): ?>
            <option value="<?= (int)($svc['id'] ?? 0) ?>"
              data-category-id="<?= (int)($svc['category_id'] ?? 0) ?>"
              <?= ((int)($search['service_id'] ?? 0)) === (int)($svc['id'] ?? 0) ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)($svc['name'] ?? '')) ?>
              (<?= (int)($svc['duration_minutes'] ?? 0) ?> min)
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['service_id'])): ?>
          <span class="error"><?= htmlspecialchars($errors['service_id']) ?></span>
          <?php endif; ?>
        </div>
        <div class="form-row">
          <label for="wiz-guests">Guests</label>
          <input type="number" id="wiz-guests" name="guests" min="1" max="20"
            value="<?= max(1, (int)($search['guests'] ?? 1)) ?>">
          <?php if (!empty($errors['guests'])): ?>
          <span class="error"><?= htmlspecialchars($errors['guests']) ?></span>
          <?php endif; ?>
        </div>
      </fieldset>

      <fieldset class="appt-wizard-section">
        <legend class="appt-wizard-section__title">Date / time</legend>
        <div class="form-row form-row--inline">
          <?php $dateMode = $search['date_mode'] ?? 'exact'; ?>
          <label>
            <input type="radio" name="date_mode" value="exact" <?= $dateMode === 'exact' ? 'checked' : '' ?>>
            Exact date
          </label>
          <label>
            <input type="radio" name="date_mode" value="first_available" <?= $dateMode === 'first_available' ? 'checked' : '' ?>>
            First available
          </label>
          <label>
            <input type="radio" name="date_mode" value="range" <?= $dateMode === 'range' ? 'checked' : '' ?>>
            Date range
          </label>
        </div>
        <div class="form-row" id="wiz-date-exact-row">
          <label for="wiz-date">Date</label>
          <input type="date" id="wiz-date" name="date"
            value="<?= htmlspecialchars((string)($continuation['date'] ?? $search['date'] ?? '')) ?>">
          <?php if (!empty($errors['date'])): ?>
          <span class="error"><?= htmlspecialchars($errors['date']) ?></span>
          <?php endif; ?>
        </div>
        <div class="form-row" id="wiz-date-range-row" hidden>
          <label for="wiz-date-from">From</label>
          <input type="date" id="wiz-date-from" name="date_from" value="<?= htmlspecialchars((string)($search['date_from'] ?? '')) ?>">
          <label for="wiz-date-to">To</label>
          <input type="date" id="wiz-date-to" name="date_to" value="<?= htmlspecialchars((string)($search['date_to'] ?? '')) ?>">
          <?php if (!empty($errors['date_from']) || !empty($errors['date_to'])): ?>
          <span class="error"><?= htmlspecialchars($errors['date_from'] ?? $errors['date_to'] ?? '') ?></span>
          <?php endif; ?>
        </div>
      </fieldset>

      <fieldset class="appt-wizard-section">
        <legend class="appt-wizard-section__title">Filters <span class="hint">(optional)</span></legend>
        <div class="form-row">
          <label for="wiz-staff">Staff preference</label>
          <select id="wiz-staff" name="staff_id">
            <option value="">— Any available staff —</option>
            <?php foreach ($staff as $st): ?>
            <option value="<?= (int)($st['id'] ?? 0) ?>"
              <?= ((int)($search['staff_id'] ?? 0)) === (int)($st['id'] ?? 0) ? 'selected' : '' ?>>
              <?= htmlspecialchars(trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''))) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label for="wiz-room">Room preference</label>
          <select id="wiz-room" name="room_id">
            <option value="">— Any room —</option>
            <?php foreach ($rooms as $rm): ?>
            <option value="<?= (int)($rm['id'] ?? 0) ?>"
              <?= ((int)($search['room_id'] ?? 0)) === (int)($rm['id'] ?? 0) ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)($rm['name'] ?? '')) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row">
          <label>
            <input type="checkbox" name="include_freelancers" value="1"
              <?= !empty($search['include_freelancers']) ? 'checked' : '' ?>>
            Include freelance staff
          </label>
        </div>
      </fieldset>

      <div class="appt-wizard-actions">
        <button type="submit" class="ds-btn ds-btn--primary">Search availability</button>
        <form method="post" action="/appointments/wizard/cancel" style="display:inline">
          <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">
          <button type="submit" class="ds-btn ds-btn--ghost">Cancel</button>
        </form>
      </div>
    </form>

  </div><!-- .appt-wizard-body -->
</div><!-- .appt-wizard-page -->

<script>
(() => {
  // Show/hide date fields based on date_mode radio selection.
  const radios    = document.querySelectorAll('[name="date_mode"]');
  const exactRow  = document.getElementById('wiz-date-exact-row');
  const rangeRow  = document.getElementById('wiz-date-range-row');

  function applyDateMode() {
    const mode = document.querySelector('[name="date_mode"]:checked')?.value || 'exact';
    if (exactRow) exactRow.hidden = (mode === 'range' || mode === 'first_available');
    if (rangeRow) rangeRow.hidden = (mode !== 'range');
  }

  radios.forEach(r => r.addEventListener('change', applyDateMode));
  applyDateMode();
})();
</script>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
