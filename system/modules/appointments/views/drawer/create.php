<?php
$prefillTime = (string) ($appointment['prefill_time'] ?? '');
$selectedStartTime = (string) ($appointment['selected_start_time'] ?? '');
$prefillEndTime = (string) ($appointment['prefill_end_time'] ?? '');
$slotMinutes = max(5, (int) ($appointment['slot_minutes'] ?? 30));
$statusValue = (string) ($appointment['status'] ?? 'scheduled');

// Context bar display values (resolved from already-clicked slot)
$ctxStaffId = (int) ($appointment['staff_id'] ?? 0);
$ctxStaffName = '';
foreach ($staff as $st) {
    if ((int) ($st['id'] ?? 0) === $ctxStaffId) {
        $ctxStaffName = trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''));
        break;
    }
}
$ctxBranchId = (int) ($appointment['branch_id'] ?? 0);
$ctxBranchName = '';
$singleBranch = count($branches) === 1;
foreach ($branches as $b) {
    if ((int) ($b['id'] ?? 0) === $ctxBranchId) {
        $ctxBranchName = (string) ($b['name'] ?? '');
        break;
    }
}
$ctxDate = (string) ($appointment['date'] ?? '');
$ctxDateDisplay = '';
if ($ctxDate !== '') {
    $ts = strtotime($ctxDate);
    if ($ts !== false) {
        $ctxDateDisplay = date('D j M Y', $ts);
    }
}
// When staff_id is known, the drawer uses category-first scoped mode.
$staffScopedMode = $ctxStaffId > 0;
?>
<div class="drawer-workspace drawer-workspace--appointment-create" data-drawer-content-root data-drawer-title="New appointment" data-drawer-subtitle="" data-drawer-width="medium">

    <?php if (!empty($errors)): ?>
    <ul class="form-errors appt-create-errors">
        <?php if (!empty($errors['_conflict'])): ?><li class="error"><?= htmlspecialchars($errors['_conflict']) ?></li><?php endif; ?>
        <?php if (!empty($errors['_general'])): ?><li class="error"><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
        <?php foreach ($errors as $k => $e): if (str_starts_with((string) $k, '_')) { continue; } ?>
        <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="post" action="/appointments/create" class="entity-form appt-create-form" id="drawer-booking-form" data-drawer-submit data-drawer-dirty-track data-create-base-url="/appointments/create" data-prefill-time="<?= htmlspecialchars($prefillTime) ?>" data-slot-minutes="<?= (int) $slotMinutes ?>" data-prefill-end-time="<?= htmlspecialchars($prefillEndTime) ?>" data-staff-scoped="<?= $staffScopedMode ? '1' : '0' ?>" data-staff-services-url="/appointments/staff-services">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" id="selected_start_time" name="start_time" value="<?= htmlspecialchars($selectedStartTime) ?>">
        <input type="hidden" id="appointment_status" name="status" value="<?= htmlspecialchars($statusValue) ?>">
        <input type="hidden" id="branch_id" name="branch_id" value="<?= (int) $ctxBranchId ?>">
        <input type="hidden" id="date" name="date" value="<?= htmlspecialchars($ctxDate) ?>">
        <input type="hidden" id="staff_id" name="staff_id" value="<?= $ctxStaffId > 0 ? $ctxStaffId : '' ?>">

        <!-- Pinned slot context from calendar click -->
        <div class="appt-create-slot-summary appt-create-slot-summary--ctx" aria-label="Booking context">
            <div class="appt-create-slot-summary__item">
                <span class="appt-create-slot-summary__label">Date</span>
                <strong class="appt-create-slot-summary__value"><?= $ctxDateDisplay !== '' ? htmlspecialchars($ctxDateDisplay) : 'Not set' ?></strong>
            </div>
            <div class="appt-create-slot-summary__item">
                <span class="appt-create-slot-summary__label">Start</span>
                <strong class="appt-create-slot-summary__value" data-selected-slot-label><?= $prefillTime !== '' ? htmlspecialchars($prefillTime) : 'No slot' ?></strong>
            </div>
            <div class="appt-create-slot-summary__item">
                <span class="appt-create-slot-summary__label">Ends</span>
                <strong class="appt-create-slot-summary__value" data-estimated-end-label><?= $prefillEndTime !== '' ? htmlspecialchars($prefillEndTime) : '—' ?></strong>
            </div>
            <div class="appt-create-slot-summary__item">
                <span class="appt-create-slot-summary__label">Staff</span>
                <strong class="appt-create-slot-summary__value"><?= $ctxStaffName !== '' ? htmlspecialchars($ctxStaffName) : '—' ?></strong>
            </div>
            <?php if (!$singleBranch && $ctxBranchName !== ''): ?>
            <div class="appt-create-slot-summary__item">
                <span class="appt-create-slot-summary__label">Branch</span>
                <strong class="appt-create-slot-summary__value"><?= htmlspecialchars($ctxBranchName) ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <div class="appt-create-confirm-row">
            <label class="appt-create-confirm-toggle" for="appointment_status_confirmed" title="Mark the appointment as confirmed">
                <input type="checkbox" id="appointment_status_confirmed" value="1" <?= $statusValue === 'confirmed' ? 'checked' : '' ?>>
                <span class="appt-create-confirm-toggle__switch" aria-hidden="true"></span>
                <span class="appt-create-confirm-toggle__label">Mark as confirmed</span>
            </label>
        </div>

        <?php if ($staffScopedMode): ?>
        <!-- Staff-scoped mode: Category → Service flow -->
        <div class="form-row" id="category-field-row">
            <label for="service_category_id">Category <abbr title="required" class="appt-create-required-mark">*</abbr></label>
            <select id="service_category_id" name="_category_id" disabled>
                <option value="">Loading…</option>
            </select>
            <span id="category-hint" class="hint" hidden>Loading categories for <?= htmlspecialchars($ctxStaffName) ?>…</span>
        </div>
        <div class="form-row" id="service-field-row">
            <label for="service_id">Service <abbr title="required" class="appt-create-required-mark">*</abbr></label>
            <select id="service_id" name="service_id" required disabled>
                <option value="">— Choose category first —</option>
            </select>
            <span id="service-description-hint" class="hint" hidden></span>
        </div>
        <?php else: ?>
        <!-- Fallback (no staff context): show all services for branch -->
        <div class="form-row">
            <label for="service_id">Service <abbr title="required" class="appt-create-required-mark">*</abbr></label>
            <select id="service_id" name="service_id" required>
                <option value="">— Choose service —</option>
                <?php foreach ($services as $s): $svcDesc = trim((string) ($s['description'] ?? '')); ?>
                <option value="<?= (int) $s['id'] ?>" data-service-duration="<?= (int) ($s['duration_minutes'] ?? 0) ?>" <?= ((int) ($appointment['service_id'] ?? 0)) === (int) $s['id'] ? 'selected' : '' ?><?= $svcDesc !== '' ? ' title="' . htmlspecialchars($svcDesc, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($s['name']) ?> (<?= (int) ($s['duration_minutes'] ?? 0) ?> min)</option>
                <?php endforeach; ?>
            </select>
            <span id="service-description-hint" class="hint" hidden></span>
        </div>
        <?php endif; ?>

        <!-- Primary: Client (search-driven) -->
        <div class="appt-create-client-block">
            <div class="form-row appt-create-search-row">
                <label for="client-search">Client <abbr title="required" class="appt-create-required-mark">*</abbr></label>
                <input type="search" id="client-search" placeholder="Search by name, email, phone, or ID…" autocomplete="off">
                <div id="client-search-results" class="appt-create-search-results" hidden aria-live="polite"></div>
                <span class="hint" id="client-search-hint">Filter by ID, name, email, or phone.</span>
            </div>
            <select id="client_id" name="client_id" style="display:none" aria-hidden="true" tabindex="-1">
                <option value="">— Select client —</option>
                <?php foreach ($clients as $c): ?>
                <option value="<?= (int) $c['id'] ?>"
                        data-client-name="<?= htmlspecialchars(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                        data-client-email="<?= htmlspecialchars((string) ($c['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-client-phone="<?= htmlspecialchars((string) ($c['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-client-country="<?= htmlspecialchars((string) ($c['home_country'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-client-source="<?= htmlspecialchars((string) ($c['source'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        <?= ((int) ($appointment['client_id'] ?? 0)) === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="appt-create-slot-summary appt-create-client-chips" aria-live="polite">
                <div class="appt-create-slot-summary__item">
                    <span class="appt-create-slot-summary__label">Name</span>
                    <strong class="appt-create-slot-summary__value" data-client-detail="name">Select a client</strong>
                </div>
                <div class="appt-create-slot-summary__item">
                    <span class="appt-create-slot-summary__label">Phone</span>
                    <strong class="appt-create-slot-summary__value" data-client-detail="phone">—</strong>
                </div>
                <div class="appt-create-slot-summary__item">
                    <span class="appt-create-slot-summary__label">Email</span>
                    <strong class="appt-create-slot-summary__value" data-client-detail="email">—</strong>
                </div>
            </div>
        </div>

        <!-- Primary: Notes (optional) -->
        <div class="form-row">
            <label for="notes">Notes <span class="appt-create-optional-label">(optional)</span></label>
            <textarea id="notes" name="notes" rows="2"><?= htmlspecialchars($appointment['notes'] ?? '') ?></textarea>
        </div>

        <!-- Secondary: change time or add room (collapsed by default) -->
        <details class="appt-create-secondary">
            <summary class="appt-create-secondary__toggle">Change time or add room</summary>
            <div class="appt-create-secondary__body">
                <div class="form-row appt-create-row--inline">
                    <button type="button" id="load-slots-btn" class="appt-create-btn appt-create-btn--secondary">Load available slots</button>
                    <span id="slots-status" class="hint appt-create-slots-status"><?= $prefillTime !== '' ? 'Prefilled from calendar: ' . htmlspecialchars($prefillTime) : 'Select a different time.' ?></span>
                </div>
                <div class="form-row">
                    <div id="slots-container" class="slots-grid appt-create-slots-grid" role="group" aria-label="Available slots">
                        <span class="hint">Optional: load slots to pick another time.</span>
                    </div>
                </div>
                <?php if (!empty($rooms)): ?>
                <div class="form-row">
                    <label for="room_id">Room</label>
                    <select id="room_id" name="room_id">
                        <option value="">— None —</option>
                        <?php foreach ($rooms as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" <?= ((int) ($appointment['room_id'] ?? 0)) === (int) $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) ($r['name'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <input type="hidden" id="room_id" name="room_id" value="">
                <?php endif; ?>
            </div>
        </details>

        <div class="drawer-actions-row drawer-actions-row--appointment">
            <button type="submit" class="drawer-submit">Save appointment</button>
            <button type="button" class="drawer-secondary-btn" onclick="window.AppDrawer.close()">Cancel</button>
        </div>
    </form>
</div>
