<?php
/** @var array<string, mixed>  $state          Wizard state. */
/** @var array<string, string> $errors         Validation errors. */
/** @var array<string, mixed>  $workspace */
/** @var string                $csrf */
/** @var array{subtotal: float, tax: float, total: float, currency: string, line_count: int} $totals */
/** @var list<string>          $paymentModes */
/** @var array<string, string> $paymentLabels */
$title    = $title ?? 'New Appointment — Step 4';
ob_start();
$branchId    = (int) ($state['branch_id'] ?? 0);
$serviceLines = $state['service_lines'] ?? [];
$paymentState = $state['payment'] ?? [];
$selectedMode = (string) ($paymentState['mode'] ?? 'skip_payment');
$csrfName     = config('app.csrf_token_name', 'csrf_token');

if ($selectedMode === 'none') {
    $selectedMode = 'skip_payment';
}
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
    <span class="appt-wizard-steps__step">
      <a href="/appointments/wizard/step3?branch_id=<?= $branchId ?>">3. Customer</a>
    </span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step is-active">4. Payment</span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step">5. Review</span>
  </nav>

  <div class="appt-wizard-body">
    <h2 class="appt-wizard-body__title">Step 4 — Payment</h2>
    <p class="appt-wizard-body__intro">Select a payment method or choose to skip payment for now.</p>

    <?php if (!empty($errors)): ?>
    <ul class="form-errors appt-wizard-errors">
      <?php foreach ($errors as $err): ?>
      <li><?= htmlspecialchars((string)$err) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <section class="appt-wizard-review-section">
      <h3 class="appt-wizard-review-section__title">Order summary</h3>
      <table class="appt-wizard-review-table">
        <thead>
          <tr><th>Service</th><th>Date</th><th>Time</th><th>Staff</th><th>Duration</th><th>Price</th></tr>
        </thead>
        <tbody>
          <?php foreach ($serviceLines as $line): ?>
          <tr>
            <td><?= htmlspecialchars((string)($line['service_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($line['date'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($line['start_time'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($line['staff_name'] ?? '')) ?></td>
            <td><?= (int)($line['duration_minutes'] ?? 0) ?> min</td>
            <td><?= htmlspecialchars($totals['currency'] ?? 'GBP') ?> <?= number_format((float)($line['price_snapshot'] ?? 0), 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5" style="text-align:right"><strong>Sub-total</strong></td>
            <td><strong><?= htmlspecialchars($totals['currency'] ?? 'GBP') ?> <?= number_format($totals['subtotal'] ?? 0, 2) ?></strong></td>
          </tr>
          <tr>
            <td colspan="5" style="text-align:right">Tax / VAT</td>
            <td><?= htmlspecialchars($totals['currency'] ?? 'GBP') ?> <?= number_format($totals['tax'] ?? 0, 2) ?></td>
          </tr>
          <tr>
            <td colspan="5" style="text-align:right"><strong>Total due</strong></td>
            <td><strong><?= htmlspecialchars($totals['currency'] ?? 'GBP') ?> <?= number_format($totals['total'] ?? 0, 2) ?></strong></td>
          </tr>
        </tfoot>
      </table>
    </section>

    <form method="post" action="/appointments/wizard/step4?branch_id=<?= $branchId ?>" class="appt-wizard-form">
      <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">

      <fieldset class="appt-wizard-section">
        <legend class="appt-wizard-section__title">Payment method <span class="hint">*</span></legend>
        <?php foreach ($paymentModes as $modeValue): ?>
        <div class="form-row form-row--radio">
          <label>
            <input type="radio" name="payment_mode" value="<?= htmlspecialchars($modeValue) ?>"
              <?= $selectedMode === $modeValue ? 'checked' : '' ?>>
            <?= htmlspecialchars($paymentLabels[$modeValue] ?? $modeValue) ?>
          </label>
        </div>
        <?php endforeach; ?>
        <?php if (!empty($errors['payment_mode'])): ?>
        <span class="error"><?= htmlspecialchars($errors['payment_mode']) ?></span>
        <?php endif; ?>
      </fieldset>

      <div class="form-row" id="wiz-skip-reason-row"
        <?= $selectedMode !== 'skip_payment' ? 'hidden' : '' ?>>
        <label for="wiz-skip-reason">Reason for skipping (optional)</label>
        <input type="text" id="wiz-skip-reason" name="skip_reason"
          value="<?= htmlspecialchars((string)($paymentState['skip_reason'] ?? '')) ?>"
          placeholder="e.g. will pay on arrival">
      </div>

      <div class="form-row">
        <label>
          <input type="checkbox" name="hold_reservation" value="1"
            <?= !empty($paymentState['hold_reservation']) ? 'checked' : '' ?>>
          Hold reservation (for future deposit / hold integration)
        </label>
      </div>

      <div class="appt-wizard-actions">
        <a href="/appointments/wizard/step3?branch_id=<?= $branchId ?>" class="ds-btn ds-btn--ghost">← Back</a>
        <button type="submit" class="ds-btn ds-btn--primary">Continue to Review →</button>
        <button type="submit" class="appt-wizard-actions__cancel" formaction="/appointments/wizard/cancel" formmethod="post">Cancel</button>
      </div>
    </form>
  </div><!-- .appt-wizard-body -->
</div><!-- .appt-wizard-page -->

<script>
(() => {
  const radios   = document.querySelectorAll('[name="payment_mode"]');
  const skipRow  = document.getElementById('wiz-skip-reason-row');
  function applyMode() {
    const checked = document.querySelector('[name="payment_mode"]:checked');
    if (skipRow) skipRow.hidden = !checked || checked.value !== 'skip_payment';
  }
  radios.forEach(r => r.addEventListener('change', applyMode));
  applyMode();
})();
</script>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
