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

    <form method="post" action="/appointments/wizard/step1?branch_id=<?= $branchId ?>" class="appt-wizard-form appt-wizard-form--step1" id="wizard-step1-form">
      <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">

      <fieldset class="appt-wizard-section">
        <legend class="appt-wizard-section__title" id="wiz-legend-mode">Search mode</legend>
        <div class="appt-wizard-choice-group" role="radiogroup" aria-labelledby="wiz-legend-mode">
          <label class="appt-wizard-choice">
            <input type="radio" name="mode" value="service" <?= ($search['mode'] ?? 'service') === 'service' ? 'checked' : '' ?>>
            <span class="appt-wizard-choice__text">
              <span class="appt-wizard-choice__label">Service</span>
              <span class="appt-wizard-choice__hint">Book by service &amp; duration</span>
            </span>
          </label>
          <label class="appt-wizard-choice appt-wizard-choice--disabled" title="Package booking is not yet available">
            <input type="radio" name="mode" value="package" disabled tabindex="-1">
            <span class="appt-wizard-choice__text">
              <span class="appt-wizard-choice__label">Package</span>
              <span class="appt-wizard-choice__hint">Coming soon</span>
            </span>
          </label>
        </div>
      </fieldset>

      <fieldset class="appt-wizard-section">
        <legend class="appt-wizard-section__title" id="wiz-legend-service">Service</legend>
        <div class="form-row">
          <label for="wiz-category">Category <span class="hint">(optional filter)</span></label>
          <select id="wiz-category" name="category_id" class="appt-wizard-field">
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
          <select id="wiz-service" name="service_id" required class="appt-wizard-field">
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
            class="appt-wizard-field appt-wizard-field--narrow"
            value="<?= max(1, (int)($search['guests'] ?? 1)) ?>">
          <?php if (!empty($errors['guests'])): ?>
          <span class="error"><?= htmlspecialchars($errors['guests']) ?></span>
          <?php endif; ?>
        </div>
      </fieldset>

      <fieldset class="appt-wizard-section">
        <legend class="appt-wizard-section__title" id="wiz-legend-datetime">Date / time</legend>
        <div class="appt-wizard-choice-group appt-wizard-choice-group--date" role="radiogroup" aria-labelledby="wiz-legend-datetime">
          <?php $dateMode = $search['date_mode'] ?? 'exact'; ?>
          <label class="appt-wizard-choice">
            <input type="radio" name="date_mode" value="exact" <?= $dateMode === 'exact' ? 'checked' : '' ?>>
            <span class="appt-wizard-choice__text">
              <span class="appt-wizard-choice__label">Exact date</span>
              <span class="appt-wizard-choice__hint">Pick one day</span>
            </span>
          </label>
          <label class="appt-wizard-choice">
            <input type="radio" name="date_mode" value="first_available" <?= $dateMode === 'first_available' ? 'checked' : '' ?>>
            <span class="appt-wizard-choice__text">
              <span class="appt-wizard-choice__label">First available</span>
              <span class="appt-wizard-choice__hint">Soonest slot</span>
            </span>
          </label>
          <label class="appt-wizard-choice">
            <input type="radio" name="date_mode" value="range" <?= $dateMode === 'range' ? 'checked' : '' ?>>
            <span class="appt-wizard-choice__text">
              <span class="appt-wizard-choice__label">Date range</span>
              <span class="appt-wizard-choice__hint">Search a window</span>
            </span>
          </label>
        </div>
        <div class="form-row" id="wiz-date-exact-row">
          <label for="wiz-date">Date</label>
          <input type="date" id="wiz-date" name="date" class="appt-wizard-field"
            value="<?= htmlspecialchars((string)($continuation['date'] ?? $search['date'] ?? '')) ?>">
          <?php if (!empty($errors['date'])): ?>
          <span class="error"><?= htmlspecialchars($errors['date']) ?></span>
          <?php endif; ?>
        </div>
        <div class="appt-wizard-daterange" id="wiz-date-range-row" hidden>
          <div class="form-row appt-wizard-daterange__cell">
            <label for="wiz-date-from">From</label>
            <input type="date" id="wiz-date-from" name="date_from" class="appt-wizard-field"
              value="<?= htmlspecialchars((string)($search['date_from'] ?? '')) ?>">
          </div>
          <div class="form-row appt-wizard-daterange__cell">
            <label for="wiz-date-to">To</label>
            <input type="date" id="wiz-date-to" name="date_to" class="appt-wizard-field"
              value="<?= htmlspecialchars((string)($search['date_to'] ?? '')) ?>">
          </div>
          <?php if (!empty($errors['date_from']) || !empty($errors['date_to'])): ?>
          <span class="error"><?= htmlspecialchars($errors['date_from'] ?? $errors['date_to'] ?? '') ?></span>
          <?php endif; ?>
        </div>
      </fieldset>

      <fieldset class="appt-wizard-section">
        <legend class="appt-wizard-section__title" id="wiz-legend-filters">Filters <span class="hint">(optional)</span></legend>
        <div class="form-row">
          <label for="wiz-staff">Staff preference</label>
          <select id="wiz-staff" name="staff_id" class="appt-wizard-field">
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
          <select id="wiz-room" name="room_id" class="appt-wizard-field">
            <option value="">— Any room —</option>
            <?php foreach ($rooms as $rm): ?>
            <option value="<?= (int)($rm['id'] ?? 0) ?>"
              <?= ((int)($search['room_id'] ?? 0)) === (int)($rm['id'] ?? 0) ? 'selected' : '' ?>>
              <?= htmlspecialchars((string)($rm['name'] ?? '')) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row appt-wizard-checkbox-row">
          <label class="appt-wizard-checkbox">
            <input type="checkbox" name="include_freelancers" value="1"
              <?= !empty($search['include_freelancers']) ? 'checked' : '' ?>>
            <span class="appt-wizard-checkbox__box" aria-hidden="true"></span>
            <span class="appt-wizard-checkbox__label">Include freelance staff</span>
          </label>
        </div>
      </fieldset>

      <div class="appt-wizard-actions appt-wizard-actions--footer">
        <button type="submit" class="ds-btn ds-btn--primary">Search availability</button>
        <button type="submit" class="appt-wizard-actions__cancel" formaction="/appointments/wizard/cancel" formmethod="post">Cancel</button>
      </div>
    </form>

  </div><!-- .appt-wizard-body -->
</div><!-- .appt-wizard-page -->

<script>
(() => {
  // ── Category → Service filtering ───────────────────────────────────────────
  // When a category is selected, hide/disable services that do not belong to it.
  // When "All categories" (value="") is selected, show all services.
  // If the currently selected service is no longer valid after a category change,
  // it is reset to the placeholder so the user must make an explicit choice.
  const categorySelect = document.getElementById('wiz-category');
  const serviceSelect  = document.getElementById('wiz-service');

  function applyServiceFilter() {
    if (!categorySelect || !serviceSelect) { return; }
    const selectedCategoryId = parseInt(categorySelect.value || '0', 10);
    let currentSelectionStillValid = false;

    Array.from(serviceSelect.options).forEach((opt) => {
      if (!opt.value) {
        // Placeholder "— Select service —": always visible.
        opt.hidden   = false;
        opt.disabled = false;
        return;
      }
      const optCatId = parseInt(opt.dataset.categoryId || '0', 10);
      const visible  = (selectedCategoryId === 0) || (optCatId === selectedCategoryId);
      opt.hidden     = !visible;
      opt.disabled   = !visible;
      if (visible && opt.selected) {
        currentSelectionStillValid = true;
      }
    });

    // Reset to placeholder if the currently selected service is no longer
    // in the visible/valid set after a category change.
    if (!currentSelectionStillValid) {
      serviceSelect.value = '';
    }
  }

  if (categorySelect) {
    categorySelect.addEventListener('change', applyServiceFilter);
    // Apply immediately on page load to correct any pre-filled mismatch.
    applyServiceFilter();
  }

  // ── Date mode show/hide ─────────────────────────────────────────────────────
  const radios   = document.querySelectorAll('[name="date_mode"]');
  const exactRow = document.getElementById('wiz-date-exact-row');
  const rangeRow = document.getElementById('wiz-date-range-row');

  function applyDateMode() {
    const mode = document.querySelector('[name="date_mode"]:checked')?.value || 'exact';
    if (exactRow) { exactRow.hidden = (mode === 'range' || mode === 'first_available'); }
    if (rangeRow) { rangeRow.hidden = (mode !== 'range'); }
  }

  radios.forEach((r) => r.addEventListener('change', applyDateMode));
  applyDateMode();
})();
</script>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
