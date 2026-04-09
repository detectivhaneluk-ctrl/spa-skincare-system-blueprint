<?php
/** @var array<string, mixed>  $state   Wizard state. */
/** @var array<string, string> $errors  Validation errors. */
/** @var array<string, mixed>  $workspace */
/** @var string                $csrf */
$title    = $title ?? 'New Appointment — Step 3';
ob_start();
$branchId    = (int) ($state['branch_id'] ?? 0);
$clientState = $state['client'] ?? ['mode' => 'existing', 'client_id' => null, 'draft' => []];
$csrfName    = config('app.csrf_token_name', 'csrf_token');
$clientMode  = (string) ($clientState['mode'] ?? 'existing');
$draft       = $clientState['draft'] ?? [];
?>
<?php require base_path('modules/appointments/views/partials/workspace-shell.php'); ?>

<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars((string)$t) ?>"><?= htmlspecialchars((string)($flash[$t] ?? '')) ?></div>
<?php endif; ?>

<div class="appt-wizard-page">

  <nav class="appt-wizard-steps" aria-label="Booking steps">
    <span class="appt-wizard-steps__step">
      <a href="/appointments/wizard/step1?branch_id=<?= $branchId ?>">1. Search</a>
    </span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step">
      <a href="/appointments/wizard/step2?branch_id=<?= $branchId ?>">2. Select Slot</a>
    </span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step is-active">3. Customer</span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step">4. Payment</span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step">5. Review</span>
  </nav>

  <div class="appt-wizard-body">
    <h2 class="appt-wizard-body__title">Step 3 — Customer</h2>
    <p class="appt-wizard-body__intro">Find an existing client or enter details for a new one.</p>

    <?php if (!empty($errors)): ?>
    <ul class="form-errors appt-wizard-errors">
      <?php foreach ($errors as $err): ?>
      <li><?= htmlspecialchars((string)$err) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="post" action="/appointments/wizard/step3?branch_id=<?= $branchId ?>" class="appt-wizard-form" id="wizard-step3-form">
      <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">

      <fieldset class="appt-wizard-section">
        <legend class="appt-wizard-section__title">Client type</legend>
        <div class="form-row form-row--inline">
          <label>
            <input type="radio" name="client_mode" value="existing" id="wiz-mode-existing"
              <?= $clientMode === 'existing' ? 'checked' : '' ?>>
            Existing client
          </label>
          <label>
            <input type="radio" name="client_mode" value="new" id="wiz-mode-new"
              <?= $clientMode === 'new' ? 'checked' : '' ?>>
            New client
          </label>
        </div>
      </fieldset>

      <!-- Existing client panel -->
      <div id="wiz-existing-panel" <?= $clientMode !== 'existing' ? 'hidden' : '' ?>>
        <fieldset class="appt-wizard-section">
          <legend class="appt-wizard-section__title">Find existing client</legend>
          <div class="form-row">
            <label for="wiz-client-search">Search by name, phone, or email</label>
            <input type="search" id="wiz-client-search" placeholder="Start typing to search…" autocomplete="off">
            <div id="wiz-client-results" class="appt-wizard-client-results" hidden></div>
          </div>
          <div class="form-row">
            <label for="wiz-client-id">Selected client ID</label>
            <input type="number" id="wiz-client-id" name="client_id"
              value="<?= (int)($clientState['client_id'] ?? 0) ?: '' ?>"
              placeholder="Client ID (auto-filled by search above)">
            <span id="wiz-client-name" class="hint">
              <?php if ((int)($clientState['client_id'] ?? 0) > 0): ?>
                Client ID: <?= (int)$clientState['client_id'] ?> — verify in search
              <?php endif; ?>
            </span>
            <?php if (!empty($errors['client_id'])): ?>
            <span class="error"><?= htmlspecialchars($errors['client_id']) ?></span>
            <?php endif; ?>
          </div>
        </fieldset>
      </div>

      <!-- New client panel -->
      <div id="wiz-new-panel" <?= $clientMode !== 'new' ? 'hidden' : '' ?>>
        <fieldset class="appt-wizard-section">
          <legend class="appt-wizard-section__title">New client — required</legend>
          <div class="form-row form-row--split">
            <div>
              <label for="wiz-first-name">First name *</label>
              <input type="text" id="wiz-first-name" name="first_name"
                value="<?= htmlspecialchars((string)($draft['first_name'] ?? '')) ?>">
              <?php if (!empty($errors['first_name'])): ?>
              <span class="error"><?= htmlspecialchars($errors['first_name']) ?></span>
              <?php endif; ?>
            </div>
            <div>
              <label for="wiz-last-name">Last name *</label>
              <input type="text" id="wiz-last-name" name="last_name"
                value="<?= htmlspecialchars((string)($draft['last_name'] ?? '')) ?>">
              <?php if (!empty($errors['last_name'])): ?>
              <span class="error"><?= htmlspecialchars($errors['last_name']) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="form-row form-row--split">
            <div>
              <label for="wiz-phone">Phone</label>
              <input type="tel" id="wiz-phone" name="phone"
                value="<?= htmlspecialchars((string)($draft['phone'] ?? '')) ?>">
              <?php if (!empty($errors['phone'])): ?>
              <span class="error"><?= htmlspecialchars($errors['phone']) ?></span>
              <?php endif; ?>
            </div>
            <div>
              <label for="wiz-email">Email</label>
              <input type="email" id="wiz-email" name="email"
                value="<?= htmlspecialchars((string)($draft['email'] ?? '')) ?>">
              <?php if (!empty($errors['email'])): ?>
              <span class="error"><?= htmlspecialchars($errors['email']) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="form-row">
            <label>
              <input type="checkbox" name="receive_emails" value="1"
                <?= !empty($draft['receive_emails']) ? 'checked' : '' ?>>
              Receive booking emails
            </label>
          </div>
        </fieldset>

        <fieldset class="appt-wizard-section">
          <legend class="appt-wizard-section__title">New client — optional details</legend>
          <div class="form-row form-row--split">
            <div>
              <label for="wiz-gender">Gender</label>
              <select id="wiz-gender" name="gender">
                <option value="">—</option>
                <option value="female" <?= ($draft['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                <option value="male" <?= ($draft['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                <option value="other" <?= ($draft['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                <option value="prefer_not_to_say" <?= ($draft['gender'] ?? '') === 'prefer_not_to_say' ? 'selected' : '' ?>>Prefer not to say</option>
              </select>
            </div>
            <div>
              <label for="wiz-birth-date">Date of birth</label>
              <input type="date" id="wiz-birth-date" name="birth_date"
                value="<?= htmlspecialchars((string)($draft['birth_date'] ?? '')) ?>">
            </div>
          </div>
          <div class="form-row">
            <label for="wiz-address">Address</label>
            <input type="text" id="wiz-address" name="home_address_1"
              value="<?= htmlspecialchars((string)($draft['home_address_1'] ?? '')) ?>">
          </div>
          <div class="form-row form-row--split">
            <div>
              <label for="wiz-city">City</label>
              <input type="text" id="wiz-city" name="home_city"
                value="<?= htmlspecialchars((string)($draft['home_city'] ?? '')) ?>">
            </div>
            <div>
              <label for="wiz-postal">Postal / ZIP</label>
              <input type="text" id="wiz-postal" name="home_postal_code"
                value="<?= htmlspecialchars((string)($draft['home_postal_code'] ?? '')) ?>">
            </div>
          </div>
          <div class="form-row">
            <label for="wiz-country">Country</label>
            <input type="text" id="wiz-country" name="home_country"
              value="<?= htmlspecialchars((string)($draft['home_country'] ?? '')) ?>">
          </div>
          <div class="form-row">
            <label for="wiz-referral">How did you hear about us?</label>
            <input type="text" id="wiz-referral" name="referral_information"
              value="<?= htmlspecialchars((string)($draft['referral_information'] ?? '')) ?>">
          </div>
          <div class="form-row">
            <label for="wiz-origin">Customer origin / source</label>
            <input type="text" id="wiz-origin" name="customer_origin"
              value="<?= htmlspecialchars((string)($draft['customer_origin'] ?? '')) ?>">
          </div>
          <div class="form-row">
            <label>
              <input type="checkbox" name="marketing_opt_in" value="1"
                <?= !empty($draft['marketing_opt_in']) ? 'checked' : '' ?>>
              Marketing opt-in
            </label>
          </div>
        </fieldset>
      </div><!-- #wiz-new-panel -->

      <div class="appt-wizard-actions">
        <a href="/appointments/wizard/step2?branch_id=<?= $branchId ?>" class="ds-btn ds-btn--ghost">← Back</a>
        <button type="submit" class="ds-btn ds-btn--primary">Continue to Payment →</button>
        <button type="submit" class="appt-wizard-actions__cancel" formaction="/appointments/wizard/cancel" formmethod="post">Cancel</button>
      </div>
    </form>
  </div><!-- .appt-wizard-body -->
</div><!-- .appt-wizard-page -->

<script>
(() => {
  const modeExisting = document.getElementById('wiz-mode-existing');
  const modeNew      = document.getElementById('wiz-mode-new');
  const panelExist   = document.getElementById('wiz-existing-panel');
  const panelNew     = document.getElementById('wiz-new-panel');

  function applyMode() {
    const isExisting = modeExisting && modeExisting.checked;
    if (panelExist) panelExist.hidden = !isExisting;
    if (panelNew)   panelNew.hidden   = isExisting;
  }
  if (modeExisting) modeExisting.addEventListener('change', applyMode);
  if (modeNew)      modeNew.addEventListener('change', applyMode);
  applyMode();

  // AJAX client search.
  const searchInput  = document.getElementById('wiz-client-search');
  const resultsBox   = document.getElementById('wiz-client-results');
  const clientIdInput = document.getElementById('wiz-client-id');
  const clientNameEl  = document.getElementById('wiz-client-name');

  if (searchInput && resultsBox) {
    let timer = null;
    searchInput.addEventListener('input', () => {
      clearTimeout(timer);
      const q = searchInput.value.trim();
      if (q.length < 2) { resultsBox.hidden = true; return; }
      timer = setTimeout(async () => {
        try {
          const branchId = <?= $branchId ?>;
          const res = await fetch('/appointments/wizard/client-search?branch_id=' + branchId + '&q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
          });
          const data = await res.json();
          const clients = (data.data && data.data.clients) ? data.data.clients : [];
          if (!clients.length) {
            resultsBox.innerHTML = '<div class="appt-wizard-client-results__empty">No clients found.</div>';
          } else {
            resultsBox.innerHTML = clients.map(c => {
              const label = [c.name, c.email, c.phone].filter(Boolean).join(' · ');
              return `<button type="button" class="appt-wizard-client-results__item" data-id="${c.id}" data-name="${c.name}">${label}</button>`;
            }).join('');
            resultsBox.querySelectorAll('[data-id]').forEach(btn => {
              btn.addEventListener('click', () => {
                if (clientIdInput)  clientIdInput.value = btn.dataset.id;
                if (clientNameEl)   clientNameEl.textContent = btn.dataset.name;
                if (searchInput)    searchInput.value = btn.dataset.name;
                resultsBox.hidden = true;
              });
            });
          }
          resultsBox.hidden = false;
        } catch (e) {
          resultsBox.hidden = true;
        }
      }, 300);
    });

    document.addEventListener('click', e => {
      if (!resultsBox.contains(e.target) && e.target !== searchInput) {
        resultsBox.hidden = true;
      }
    });
  }
})();
</script>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
