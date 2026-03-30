<?php
$title = 'Appointment: ' . htmlspecialchars($appointment['display_summary'] ?? '');
$flash = flash();
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$wBranch = isset($appointment['branch_id']) && $appointment['branch_id'] !== '' && $appointment['branch_id'] !== null
    ? (int) $appointment['branch_id']
    : null;
$wDate = null;
if (!empty($appointment['start_at'])) {
    $t = strtotime((string) $appointment['start_at']);
    if ($t !== false) {
        $wDate = date('Y-m-d', $t);
    }
}
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
$waitlistQ = [];
if ($wBranch !== null) {
    $waitlistQ['branch_id'] = $wBranch;
}
if ($wDate !== null) {
    $waitlistQ['date'] = $wDate;
}
$waitlistUrl = '/appointments/waitlist' . ($waitlistQ !== [] ? '?' . http_build_query($waitlistQ) : '');
$workspace['active_tab'] = '';
$workspace['tabs'] = [
    ['id' => 'calendar', 'label' => 'Calendar', 'url' => $calendarUrl],
    ['id' => 'list', 'label' => 'List', 'url' => '/appointments' . $listQ],
    ['id' => 'new', 'label' => 'New Appointment', 'url' => $createUrl],
    ['id' => 'waitlist', 'label' => 'Waitlist', 'url' => $waitlistUrl],
];
$workspace['shell_modifier'] = 'workspace-shell--show';
$apptShowHeaderSubParts = ['Appointment #' . (int) ($appointment['id'] ?? 0)];
$dOnly = trim((string) ($appointment['display_date_only'] ?? ''));
$tRange = trim((string) ($appointment['display_time_range'] ?? ''));
if ($dOnly !== '') {
    $apptShowHeaderSubParts[] = $dOnly;
}
if ($tRange !== '') {
    $apptShowHeaderSubParts[] = $tRange;
}
$apptShowHeaderSubtitle = implode(' · ', $apptShowHeaderSubParts);
ob_start();
?>
<?php require base_path('modules/appointments/views/partials/workspace-shell.php'); ?>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<?php
$nsAlert = is_array($clientAppointmentSummary ?? null) ? ($clientAppointmentSummary['no_show_alert'] ?? null) : null;
$nsActive = is_array($nsAlert) && !empty($nsAlert['active']);
?>
<?php if ($nsActive): ?>
<div class="appt-show-no-show-alert" role="status">
    <strong>No-show alert</strong>
    <?= htmlspecialchars((string) ($nsAlert['message'] ?? 'Recorded no-shows meet or exceed the configured threshold.')) ?>
</div>
<?php endif; ?>

<div class="appointments-show-page">
<div class="appt-show-op-canvas appt-show-page-canvas">
    <div class="appt-show-page-head">
        <header class="appt-show-header">
            <div class="appt-show-header__text">
                <h2 class="appt-show-title"><?= htmlspecialchars($appointment['display_summary'] ?? '') ?></h2>
                <p class="appt-show-sub"><?= htmlspecialchars($apptShowHeaderSubtitle) ?></p>
            </div>
        </header>
        <nav class="appt-show-secondary-nav" aria-label="Related pages">
            <a href="<?= htmlspecialchars($calendarUrl) ?>" class="appt-show-nav-link">Day calendar</a>
            <span class="appt-show-nav-sep" aria-hidden="true">·</span>
            <a href="<?= htmlspecialchars('/appointments' . $listQ, ENT_QUOTES, 'UTF-8') ?>" class="appt-show-nav-link">List</a>
            <span class="appt-show-nav-sep" aria-hidden="true">·</span>
            <a href="<?= htmlspecialchars($createUrl, ENT_QUOTES, 'UTF-8') ?>" class="appt-show-nav-link">New Appointment</a>
            <span class="appt-show-nav-sep" aria-hidden="true">·</span>
            <a href="/appointments/<?= (int) ($appointment['id'] ?? 0) ?>/print" class="appt-show-nav-link">Printable summary</a>
        </nav>
    </div>

    <div class="appt-show-toolbar-bar">
    <div class="appt-show-toolbar" role="toolbar" aria-label="Appointment actions">
        <a href="/appointments/<?= (int) $appointment['id'] ?>/edit" class="appt-show-toolbar__link appt-show-toolbar__link--primary">Edit</a>
        <?php if (!empty($appointment['can_mark_checked_in'])): ?>
        <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>/check-in" class="appt-show-toolbar__form">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="appt-show-toolbar__btn">Check in</button>
        </form>
        <?php endif; ?>
        <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>/cancel" class="appt-show-toolbar__form" onsubmit="return confirm('Cancel this appointment?')">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="appt-show-toolbar__btn">Cancel Appointment</button>
        </form>
        <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>/delete" class="appt-show-toolbar__form" onsubmit="return confirm('Delete this appointment?')">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <button type="submit" class="appt-show-toolbar__btn appt-show-toolbar__btn--danger">Delete</button>
        </form>
    </div>
    </div>

    <section class="appt-create-section appt-show-section appt-show-section--details" aria-labelledby="appt-show-details-heading">
        <h2 class="appt-create-section__title" id="appt-show-details-heading">Appointment details</h2>
        <div class="appt-create-section__body">
            <div class="appt-show-details-card">
            <dl class="appt-show-details">
                <div class="appt-show-details__row">
                    <dt class="appt-show-details__term">Client</dt>
                    <dd class="appt-show-details__def"><a href="/clients/<?= (int) $appointment['client_id'] ?>"><?= htmlspecialchars(trim(($appointment['client_first_name'] ?? '') . ' ' . ($appointment['client_last_name'] ?? ''))) ?></a></dd>
                </div>
                <div class="appt-show-details__row">
                    <dt class="appt-show-details__term">Service</dt>
                    <dd class="appt-show-details__def"><?= htmlspecialchars($appointment['service_name'] ?? '—') ?></dd>
                </div>
                <div class="appt-show-details__row">
                    <dt class="appt-show-details__term">Staff</dt>
                    <dd class="appt-show-details__def"><?= htmlspecialchars(trim(($appointment['staff_first_name'] ?? '') . ' ' . ($appointment['staff_last_name'] ?? ''))) ?: '—' ?></dd>
                </div>
                <div class="appt-show-details__row">
                    <dt class="appt-show-details__term">Room</dt>
                    <dd class="appt-show-details__def"><?= htmlspecialchars($appointment['room_name'] ?? '—') ?></dd>
                </div>
                <div class="appt-show-details__row">
                    <dt class="appt-show-details__term">Start</dt>
                    <dd class="appt-show-details__def"><?= htmlspecialchars(($appointment['display_start_at'] ?? '') !== '' ? (string) $appointment['display_start_at'] : '—') ?></dd>
                </div>
                <div class="appt-show-details__row">
                    <dt class="appt-show-details__term">End</dt>
                    <dd class="appt-show-details__def"><?= htmlspecialchars(($appointment['display_end_at'] ?? '') !== '' ? (string) $appointment['display_end_at'] : '—') ?></dd>
                </div>
                <div class="appt-show-details__row">
                    <dt class="appt-show-details__term">Status</dt>
                    <dd class="appt-show-details__def appt-show-details__def--status"><span class="appt-show-status-line"><?= htmlspecialchars($appointment['status_label'] ?? '—') ?></span></dd>
                </div>
                <div class="appt-show-details__row">
                    <dt class="appt-show-details__term">Checked in</dt>
                    <dd class="appt-show-details__def"><?= !empty($appointment['checked_in_display']) ? htmlspecialchars((string) $appointment['checked_in_display']) : '—' ?></dd>
                </div>
                <div class="appt-show-details__row appt-show-details__row--notes">
                    <dt class="appt-show-details__term">Notes</dt>
                    <dd class="appt-show-details__def"><?= nl2br(htmlspecialchars($appointment['notes'] ?? '')) ?: '—' ?></dd>
                </div>
            </dl>
            </div>
        </div>
    </section>

    <div class="appt-show-form-stack">
    <section class="appt-create-section appt-show-section" aria-labelledby="appt-show-reschedule-heading">
        <h2 class="appt-create-section__title" id="appt-show-reschedule-heading">Reschedule</h2>
        <div class="appt-create-section__body">
            <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>/reschedule" class="entity-form appt-create-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <div class="form-row">
                    <label for="reschedule_start_time">Reschedule Start *</label>
                    <input type="datetime-local" id="reschedule_start_time" name="start_time" required value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime((string)($appointment['start_at'] ?? 'now')))) ?>">
                </div>
                <div class="form-row">
                    <label for="reschedule_staff_id">Staff (optional override)</label>
                    <select id="reschedule_staff_id" name="staff_id">
                        <option value="">Keep current staff</option>
                        <?php foreach ($staffOptions as $st): ?>
                        <option value="<?= (int) $st['id'] ?>" <?= ((int) ($appointment['staff_id'] ?? 0) === (int) $st['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars(trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''))) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="reschedule_notes">Reschedule Notes</label>
                    <textarea id="reschedule_notes" name="notes" rows="2"></textarea>
                </div>
                <div class="form-actions appt-create-actions"><button type="submit" class="appt-create-submit">Reschedule</button></div>
            </form>
        </div>
    </section>

    <section class="appt-create-section" aria-labelledby="appt-show-status-heading">
        <h2 class="appt-create-section__title" id="appt-show-status-heading">Update status</h2>
        <div class="appt-create-section__body">
            <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>/status" class="entity-form appt-create-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <div class="form-row">
                    <label for="next_status">Update Status *</label>
                    <select id="next_status" name="status" required>
                        <option value="">Select status</option>
                        <?php
                        $sl = $appointment['status_select_labels'] ?? [];
                        foreach (['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'] as $stVal):
                            $stLab = (string) ($sl[$stVal] ?? $stVal);
                        ?>
                        <option value="<?= htmlspecialchars($stVal) ?>"><?= htmlspecialchars($stLab) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="status_notes">Status Notes</label>
                    <textarea id="status_notes" name="notes" rows="2"></textarea>
                </div>
                <div class="form-actions appt-create-actions"><button type="submit" class="appt-create-submit">Update Status</button></div>
            </form>
        </div>
    </section>

    <section class="appt-create-section" aria-labelledby="appt-show-package-heading">
        <h2 class="appt-create-section__title" id="appt-show-package-heading">Package consumption</h2>
        <div class="appt-create-section__body">
<?php if (($appointment['status'] ?? '') !== 'completed'): ?>
            <p class="hint appt-show-hint">Appointment must be in <strong>completed</strong> status to consume package sessions.</p>
<?php elseif (empty($appointment['client_id'])): ?>
            <p class="hint appt-show-hint">Appointment has no client; package consumption is unavailable.</p>
<?php else: ?>
    <?php if (empty($eligiblePackages)): ?>
            <p class="hint appt-show-hint">No eligible active client packages with remaining sessions for this appointment context.</p>
    <?php else: ?>
            <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>/consume-package" class="entity-form appt-create-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <div class="form-row">
                    <label for="client_package_id">Client Package *</label>
                    <select id="client_package_id" name="client_package_id" required>
                        <option value="">Select package</option>
                        <?php foreach ($eligiblePackages as $pkg): ?>
                        <option value="<?= (int) $pkg['client_package_id'] ?>">
                            #<?= (int) $pkg['client_package_id'] ?> · <?= htmlspecialchars($pkg['package_name']) ?> · remaining <?= (int) $pkg['remaining_sessions'] ?> · <?= $pkg['branch_id'] ? ('branch #' . (int) $pkg['branch_id']) : 'global' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row"><label for="quantity">Quantity *</label><input type="number" min="1" step="1" id="quantity" name="quantity" value="1" required></div>
                <div class="form-row"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" placeholder="Optional appointment/package note"></textarea></div>
                <div class="form-actions appt-create-actions"><button type="submit" class="appt-create-submit">Consume Package Session(s)</button></div>
            </form>
    <?php endif; ?>
<?php endif; ?>
        </div>
    </section>
    </div>

<?php if (!empty($packageConsumptions)): ?>
    <section class="appt-create-section appt-show-section appt-show-section--history" aria-labelledby="appt-show-history-heading">
        <h2 class="appt-create-section__title" id="appt-show-history-heading">Consumption history (this appointment)</h2>
        <div class="appt-create-section__body appt-show-history-body">
            <div class="appt-show-history-scroll" role="region" aria-label="Package consumption history" tabindex="0">
            <table class="index-table appt-show-history-table">
                <thead>
                <tr>
                    <th scope="col">Usage ID</th>
                    <th scope="col">Client Package</th>
                    <th scope="col">Package</th>
                    <th scope="col">Qty</th>
                    <th scope="col">Remaining After</th>
                    <th scope="col">Created</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($packageConsumptions as $c): ?>
                <tr>
                    <td><?= (int) $c['usage_id'] ?></td>
                    <td>#<?= (int) $c['client_package_id'] ?></td>
                    <td><?= htmlspecialchars($c['package_name']) ?></td>
                    <td><?= (int) $c['quantity'] ?></td>
                    <td><?= (int) $c['remaining_after'] ?></td>
                    <td><?= htmlspecialchars($c['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </section>
<?php endif; ?>

    <footer class="appt-show-page-footer">
        <p class="appt-show-back"><a href="/appointments" class="appt-show-back__link">← Back to list</a></p>
    </footer>
</div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
