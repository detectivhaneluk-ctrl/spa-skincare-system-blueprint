<?php
/** @var array<string, mixed>  $state         Wizard state. */
/** @var array<string, string> $errors        Validation errors (from failed commit attempt). */
/** @var array<string, mixed>  $workspace */
/** @var string                $csrf */
/** @var array<string, string> $paymentLabels */
$title        = $title ?? 'New Appointment — Step 5 Review';
ob_start();
$branchId     = (int) ($state['branch_id'] ?? 0);
$serviceLines = $state['service_lines'] ?? [];
$client       = $state['client'] ?? [];
$payment      = $state['payment'] ?? [];
$bookingMode  = (string) ($state['booking_mode'] ?? 'standalone');
$csrfName     = config('app.csrf_token_name', 'csrf_token');
$clientMode   = (string) ($client['mode'] ?? 'existing');
$clientId     = (int) ($client['client_id'] ?? 0);
$draft        = $client['draft'] ?? [];
$paymentMode  = (string) ($payment['mode'] ?? '');
$paymentTotals = $payment['totals'] ?? ['subtotal' => 0.0, 'tax' => 0.0, 'total' => 0.0, 'currency' => 'GBP'];
$paymentLabel  = $paymentLabels[$paymentMode] ?? $paymentMode;
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
    <span class="appt-wizard-steps__step">
      <a href="/appointments/wizard/step4?branch_id=<?= $branchId ?>">4. Payment</a>
    </span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step is-active">5. Review</span>
  </nav>

  <div class="appt-wizard-body">
    <h2 class="appt-wizard-body__title">Step 5 — Review &amp; Confirm</h2>
    <p class="appt-wizard-body__intro">Please review every detail before confirming the booking.</p>

    <?php if (!empty($errors)): ?>
    <ul class="form-errors appt-wizard-errors">
      <?php foreach ($errors as $err): ?>
      <li><?= htmlspecialchars((string)$err) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <!-- Services Summary -->
    <section class="appt-wizard-review-section">
      <h3 class="appt-wizard-review-section__title">
        Service(s)
        <?php if ($bookingMode === 'linked_chain'): ?>
        <span class="hint">(linked chain)</span>
        <?php endif; ?>
      </h3>
      <?php if (empty($serviceLines)): ?>
      <p class="hint">No service lines selected. <a href="/appointments/wizard/step2?branch_id=<?= $branchId ?>">Go back</a>.</p>
      <?php else: ?>
      <table class="appt-wizard-review-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Service</th>
            <th>Date</th>
            <th>Time</th>
            <th>Staff</th>
            <th>Duration</th>
            <th>Price</th>
            <th>Chain</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($serviceLines as $idx => $line): ?>
          <tr>
            <td><?= $idx + 1 ?></td>
            <td><?= htmlspecialchars((string)($line['service_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($line['date'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($line['start_time'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($line['staff_name'] ?? '')) ?></td>
            <td><?= (int)($line['duration_minutes'] ?? 0) ?> min</td>
            <td><?= htmlspecialchars($paymentTotals['currency'] ?? 'GBP') ?> <?= number_format((float)($line['price_snapshot'] ?? 0), 2) ?></td>
            <td>
              <?php $pred = $line['predecessor_index'] ?? null; ?>
              <?= $pred !== null ? 'After #' . ($pred + 1) : '—' ?>
              <?php if (!empty($line['lock_to_staff'])): ?><span class="hint"> Lock</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="6" style="text-align:right"><strong>Sub-total</strong></td>
            <td><?= htmlspecialchars($paymentTotals['currency'] ?? 'GBP') ?> <?= number_format((float)($paymentTotals['subtotal'] ?? 0), 2) ?></td>
            <td></td>
          </tr>
          <tr>
            <td colspan="6" style="text-align:right"><strong>Total</strong></td>
            <td><strong><?= htmlspecialchars($paymentTotals['currency'] ?? 'GBP') ?> <?= number_format((float)($paymentTotals['total'] ?? 0), 2) ?></strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
      <?php endif; ?>
      <a href="/appointments/wizard/step2?branch_id=<?= $branchId ?>" class="appt-wizard-review-edit">Edit services</a>
    </section>

    <!-- Client Summary -->
    <section class="appt-wizard-review-section">
      <h3 class="appt-wizard-review-section__title">Client</h3>
      <?php if ($clientMode === 'existing'): ?>
        <p><?= $clientId > 0 ? 'Existing client (ID: ' . $clientId . ')' : '<span class="hint error">No client selected.</span>' ?></p>
      <?php else: ?>
        <dl class="appt-wizard-review-dl">
          <dt>Name</dt>
          <dd><?= htmlspecialchars(trim(($draft['first_name'] ?? '') . ' ' . ($draft['last_name'] ?? ''))) ?></dd>
          <?php if (!empty($draft['phone'])): ?><dt>Phone</dt><dd><?= htmlspecialchars((string)$draft['phone']) ?></dd><?php endif; ?>
          <?php if (!empty($draft['email'])): ?><dt>Email</dt><dd><?= htmlspecialchars((string)$draft['email']) ?></dd><?php endif; ?>
          <?php if (!empty($draft['gender'])): ?><dt>Gender</dt><dd><?= htmlspecialchars((string)$draft['gender']) ?></dd><?php endif; ?>
        </dl>
        <p class="hint">A new client record will be created on confirm.</p>
      <?php endif; ?>
      <a href="/appointments/wizard/step3?branch_id=<?= $branchId ?>" class="appt-wizard-review-edit">Edit client</a>
    </section>

    <!-- Payment Summary -->
    <section class="appt-wizard-review-section">
      <h3 class="appt-wizard-review-section__title">Payment</h3>
      <?php if ($paymentMode === '' || $paymentMode === 'none'): ?>
      <p class="error hint">Payment method not yet selected. <a href="/appointments/wizard/step4?branch_id=<?= $branchId ?>">Go to step 4</a>.</p>
      <?php else: ?>
      <dl class="appt-wizard-review-dl">
        <dt>Method</dt>
        <dd><?= htmlspecialchars($paymentLabel) ?></dd>
        <?php if ($paymentMode === 'skip_payment' && !empty($payment['skip_reason'])): ?>
        <dt>Skip reason</dt>
        <dd><?= htmlspecialchars((string)$payment['skip_reason']) ?></dd>
        <?php endif; ?>
        <dt>Total</dt>
        <dd><strong><?= htmlspecialchars($paymentTotals['currency'] ?? 'GBP') ?> <?= number_format((float)($paymentTotals['total'] ?? 0), 2) ?></strong></dd>
        <?php if (!empty($payment['hold_reservation'])): ?>
        <dt>Hold reservation</dt><dd>Yes</dd>
        <?php endif; ?>
      </dl>
      <?php endif; ?>
      <a href="/appointments/wizard/step4?branch_id=<?= $branchId ?>" class="appt-wizard-review-edit">Edit payment</a>
    </section>

    <!-- Confirm Actions -->
    <div class="appt-wizard-actions">
      <a href="/appointments/wizard/step4?branch_id=<?= $branchId ?>" class="ds-btn ds-btn--ghost">← Back to Payment</a>

      <form method="post" action="/appointments/wizard/commit?branch_id=<?= $branchId ?>" style="display:inline">
        <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="ds-btn ds-btn--primary"
          onclick="return confirm('Confirm and create this appointment?')">
          Confirm &amp; Book
        </button>
      </form>

      <form method="post" action="/appointments/wizard/cancel" style="display:inline">
        <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="appt-wizard-actions__cancel">Cancel</button>
      </form>
    </div>
  </div><!-- .appt-wizard-body -->
</div><!-- .appt-wizard-page -->
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
