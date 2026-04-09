<?php
/** @var array<string, mixed>  $state   Wizard state (branch_id, availability_results, service_lines). */
/** @var array<string, string> $errors  Validation errors. */
/** @var array<string, mixed>  $workspace */
/** @var string                $csrf */
$title    = $title ?? 'New Appointment — Step 2';
ob_start();
$branchId            = (int) ($state['branch_id'] ?? 0);
$availabilityResults = $state['availability_results'] ?? [];
$serviceLines        = $state['service_lines'] ?? [];
$csrfName            = config('app.csrf_token_name', 'csrf_token');
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
    <span class="appt-wizard-steps__step is-active">2. Select Slot</span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step">3. Customer</span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step">4. Payment</span>
    <span class="appt-wizard-steps__sep" aria-hidden="true">›</span>
    <span class="appt-wizard-steps__step">5. Review</span>
  </nav>

  <div class="appt-wizard-body">
    <h2 class="appt-wizard-body__title">Step 2 — Select Time Slot</h2>
    <p class="appt-wizard-body__intro">
      Choose one of the available slots below by clicking <strong>Select this time</strong>.
      You must select at least one slot before you can continue.
      <?php if (($state['booking_mode'] ?? 'standalone') === 'linked_chain'): ?>
      Multiple services are chained in sequence.
      <?php endif; ?>
    </p>
    <?php if (($state['booking_mode'] ?? 'standalone') === 'linked_chain'): ?>
    <div class="flash flash-info">
      <strong>Linked-chain mode active</strong> — additional services are chained in sequence after the first.
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <ul class="form-errors appt-wizard-errors">
      <?php foreach ($errors as $err): ?>
      <li><?= htmlspecialchars((string)$err) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if (!empty($serviceLines)): ?>
    <div class="appt-wizard-lines">
      <h3 class="appt-wizard-lines__title">Selected service(s)</h3>
      <form method="post" action="/appointments/wizard/step2?branch_id=<?= $branchId ?>">
        <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <table class="appt-wizard-lines__table">
          <thead>
            <tr>
              <th>#</th>
              <th>Service</th>
              <th>Date</th>
              <th>Time</th>
              <th>Staff</th>
              <th>Duration</th>
              <th>Lock</th>
              <th></th>
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
              <td><?= !empty($line['lock_to_staff']) ? 'Yes' : '—' ?></td>
              <td>
                <button type="submit" name="action" value="remove_<?= $idx ?>"
                  class="ds-btn ds-btn--ghost ds-btn--sm"
                  onclick="return confirm('Remove this service line?')">Remove</button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="appt-wizard-actions">
          <button type="submit" name="action" value="continue" class="ds-btn ds-btn--primary">Continue to Customer →</button>
          <button type="submit" name="action" value="add_linked" class="ds-btn ds-btn--toolbar"
            title="Search for a continuation service that starts after the last selected service ends">
            + Add Another Service (Linked)
          </button>
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div class="appt-wizard-availability">
      <h3 class="appt-wizard-availability__title">
        Available Slots (<?= count($availabilityResults) ?>)
        <span class="hint">— click <strong>Select this time</strong> to add a slot to your booking</span>
      </h3>

      <?php if (empty($availabilityResults)): ?>
      <p class="hint">No availability results. <a href="/appointments/wizard/step1?branch_id=<?= $branchId ?>">Go back to search</a>.</p>
      <?php else: ?>

      <?php if (empty($serviceLines)): ?>
      <div class="appt-wizard-notice appt-wizard-notice--required">
        <strong>No slot selected yet.</strong>
        You must select at least one time slot before you can continue.
        Click <strong>Select this time</strong> on a row below.
      </div>
      <?php endif; ?>

      <table class="appt-wizard-availability__table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Time</th>
            <th>Staff</th>
            <th>Service</th>
            <th>Duration</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($availabilityResults, 0, 60) as $result): ?>
          <?php
          $resultKey = htmlspecialchars((string)($result['result_key'] ?? ''));
          // Check if already selected as a service line.
          $alreadyAdded = false;
          foreach ($serviceLines as $line) {
              if ((string)($line['result_key'] ?? '') === (string)($result['result_key'] ?? '')) {
                  $alreadyAdded = true;
                  break;
              }
          }
          ?>
          <tr<?= $alreadyAdded ? ' class="appt-wizard-availability__row--added"' : '' ?>>
            <td><?= htmlspecialchars((string)($result['date'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($result['time'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($result['staff_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($result['service_name'] ?? '')) ?></td>
            <td><?= (int)($result['duration_minutes'] ?? 0) ?> min</td>
            <td>
              <?php if ($alreadyAdded): ?>
              <span class="hint">Selected</span>
              <?php else: ?>
              <form method="post" action="/appointments/wizard/step2/line?branch_id=<?= $branchId ?>" style="display:inline">
                <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="result_key" value="<?= $resultKey ?>">
                <label style="font-weight:normal;font-size:.85em">
                  <input type="checkbox" name="lock_to_staff" value="1"> Lock staff
                </label>
                <button type="submit" class="ds-btn ds-btn--toolbar ds-btn--sm">Select this time</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (count($availabilityResults) > 60): ?>
          <tr><td colspan="6" class="hint"><?= count($availabilityResults) - 60 ?> more slots not shown. Refine your search in step 1.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="appt-wizard-actions appt-wizard-actions--bottom">
      <a href="/appointments/wizard/step1?branch_id=<?= $branchId ?>" class="ds-btn ds-btn--ghost">← Back to Search</a>
      <?php if (!empty($serviceLines)): ?>
      <form method="post" action="/appointments/wizard/step2?branch_id=<?= $branchId ?>" style="display:inline">
        <input type="hidden" name="<?= htmlspecialchars($csrfName) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" name="action" value="continue" class="ds-btn ds-btn--primary">Continue to Customer →</button>
        <button type="submit" name="action" value="add_linked" class="ds-btn ds-btn--toolbar"
          title="Search for a continuation service that starts after the last selected service ends">
          + Add Another Service (Linked)
        </button>
      </form>
      <?php endif; ?>
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
