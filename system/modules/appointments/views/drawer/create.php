<?php
$prefillTime = (string) ($appointment['prefill_time'] ?? '');
$selectedStartTime = (string) ($appointment['selected_start_time'] ?? '');
$prefillEndTime = (string) ($appointment['prefill_end_time'] ?? '');
$slotMinutes = max(5, (int) ($appointment['slot_minutes'] ?? 30));
?>
<div class="drawer-workspace" data-drawer-content-root data-drawer-title="New appointment" data-drawer-subtitle="Calendar booking workspace" data-drawer-width="wide">
    <p class="drawer-workspace__intro">Keep the day calendar visible while you set scope, choose availability, and book the appointment.</p>

    <?php if (!empty($errors)): ?>
    <ul class="form-errors appt-create-errors">
        <?php if (!empty($errors['_conflict'])): ?><li class="error"><?= htmlspecialchars($errors['_conflict']) ?></li><?php endif; ?>
        <?php if (!empty($errors['_general'])): ?><li class="error"><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
        <?php foreach ($errors as $k => $e): if (str_starts_with((string) $k, '_')) { continue; } ?>
        <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="post" action="/appointments/create" class="entity-form appt-create-form" id="drawer-booking-form" data-drawer-submit data-drawer-dirty-track data-prefill-time="<?= htmlspecialchars($prefillTime) ?>" data-slot-minutes="<?= (int) $slotMinutes ?>" data-prefill-end-time="<?= htmlspecialchars($prefillEndTime) ?>">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" id="selected_start_time" name="start_time" value="<?= htmlspecialchars($selectedStartTime) ?>">

        <div class="drawer-card">
            <div class="drawer-toolbar">
                <div>
                    <h3 class="drawer-card__title">Scheduling first</h3>
                    <p class="drawer-card__sub">Date, staff, service, and slot selection stay at the top of the workflow.</p>
                </div>
                <span class="drawer-pill">Create mode</span>
            </div>
        </div>

        <div data-drawer-tabs>
            <div class="drawer-tabs" role="tablist" aria-label="New appointment sections">
                <button type="button" class="drawer-tab" data-drawer-tab="schedule" data-drawer-tab-default="1">Schedule</button>
                <button type="button" class="drawer-tab" data-drawer-tab="contact">Client details</button>
                <button type="button" class="drawer-tab" data-drawer-tab="payment">Payment</button>
            </div>

            <section data-drawer-tab-panel="schedule">
                <section class="appt-create-section appt-create-section--step" aria-labelledby="drawer-create-branch">
                    <h2 class="appt-create-section__title" id="drawer-create-branch"><span class="appt-create-section__step" aria-hidden="true">1</span> Branch and client</h2>
                    <div class="appt-create-section__body appt-create-section__body--split">
                        <div class="form-row">
                            <label for="branch_id">Branch</label>
                            <select id="branch_id" name="branch_id">
                                <option value="">—</option>
                                <?php foreach ($branches as $b): ?>
                                <option value="<?= (int) $b['id'] ?>" <?= ((int) ($appointment['branch_id'] ?? 0)) === (int) $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="client_id">Client *</label>
                            <select id="client_id" name="client_id" required>
                                <option value="">— Select client —</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"
                                        data-client-name="<?= htmlspecialchars(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                                        data-client-email="<?= htmlspecialchars((string) ($c['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-client-phone="<?= htmlspecialchars((string) ($c['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-client-country="<?= htmlspecialchars((string) ($c['home_country'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        data-client-source="<?= htmlspecialchars((string) ($c['source'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                        <?= ((int) ($appointment['client_id'] ?? 0)) === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim($c['first_name'] . ' ' . $c['last_name'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="appt-create-section appt-create-section--step" aria-labelledby="drawer-create-service">
                    <h2 class="appt-create-section__title" id="drawer-create-service"><span class="appt-create-section__step" aria-hidden="true">2</span> Service, date, staff</h2>
                    <div class="appt-create-section__body appt-create-section__body--split">
                        <div class="form-row">
                            <label for="service_id">Service *</label>
                            <select id="service_id" name="service_id" required>
                                <option value="">—</option>
                                <?php foreach ($services as $s): $svcDesc = trim((string) ($s['description'] ?? '')); ?>
                                <option value="<?= (int) $s['id'] ?>" data-service-duration="<?= (int) ($s['duration_minutes'] ?? 0) ?>" <?= ((int) ($appointment['service_id'] ?? 0)) === (int) $s['id'] ? 'selected' : '' ?><?= $svcDesc !== '' ? ' title="' . htmlspecialchars($svcDesc, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($s['name']) ?> (<?= (int) ($s['duration_minutes'] ?? 0) ?> min)</option>
                                <?php endforeach; ?>
                            </select>
                            <span id="service-description-hint" class="hint" hidden></span>
                        </div>
                        <div class="form-row">
                            <label for="date">Date *</label>
                            <input type="date" id="date" name="date" required value="<?= htmlspecialchars($appointment['date'] ?? '') ?>">
                        </div>
                        <div class="form-row">
                            <label for="staff_id">Staff *</label>
                            <select id="staff_id" name="staff_id" required>
                                <option value="">—</option>
                                <?php foreach ($staff as $st): ?>
                                <option value="<?= (int) $st['id'] ?>" <?= ((int) ($appointment['staff_id'] ?? 0)) === (int) $st['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="room_id">Room</label>
                            <select id="room_id" name="room_id">
                                <option value="">—</option>
                                <?php foreach ($rooms as $r): ?>
                                <option value="<?= (int) $r['id'] ?>" <?= ((int) ($appointment['room_id'] ?? 0)) === (int) $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) ($r['name'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="appt-create-section appt-create-section--step" aria-labelledby="drawer-create-availability">
                    <h2 class="appt-create-section__title" id="drawer-create-availability"><span class="appt-create-section__step" aria-hidden="true">3</span> Availability and notes</h2>
                    <div class="appt-create-section__body">
                        <div class="form-row appt-create-row--inline">
                            <button type="button" id="load-slots-btn" class="appt-create-btn appt-create-btn--secondary appt-create-btn--load">Load slots</button>
                            <span id="slots-status" class="hint appt-create-slots-status"><?= $prefillTime !== '' ? 'Prefilled from calendar: ' . htmlspecialchars($prefillTime) : '' ?></span>
                        </div>
                        <p class="drawer-selected-slot">Selected slot: <strong data-selected-slot-label><?= $selectedStartTime !== '' ? htmlspecialchars(str_replace(' ', ' at ', $selectedStartTime)) : 'No slot selected yet.' ?></strong></p>
                        <p class="drawer-selected-slot">Estimated end: <strong data-estimated-end-label><?= $prefillEndTime !== '' ? htmlspecialchars($prefillEndTime) : 'Pending service selection' ?></strong></p>
                        <div class="form-row appt-create-slot-decision">
                            <div class="appt-create-slot-decision__head">
                                <span class="appt-create-slot-decision__label">Choose a time</span>
                                <span class="appt-create-slot-decision__hint">The clicked calendar slot is already prefilled. Load slots only if you need to move it.</span>
                            </div>
                            <div id="slots-container" class="slots-grid appt-create-slots-grid" role="group" aria-label="Available slots">
                                <span class="hint">Load slots to review available times.</span>
                            </div>
                        </div>
                        <div class="form-row">
                            <label for="notes">Notes</label>
                            <textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($appointment['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                </section>
            </section>

            <section data-drawer-tab-panel="contact" hidden>
                <div class="drawer-card">
                    <h3 class="drawer-card__title">Client contact context</h3>
                    <dl class="drawer-detail-list">
                        <div><dt>Name</dt><dd data-client-detail="name">Select a client</dd></div>
                        <div><dt>Email</dt><dd data-client-detail="email">—</dd></div>
                        <div><dt>Country</dt><dd data-client-detail="country">—</dd></div>
                        <div><dt>Phone</dt><dd data-client-detail="phone">—</dd></div>
                        <div><dt>Source</dt><dd data-client-detail="source">Internal calendar</dd></div>
                    </dl>
                </div>
            </section>

            <section data-drawer-tab-panel="payment" hidden>
                <div class="drawer-card">
                    <h3 class="drawer-card__title">Payment handling</h3>
                    <p class="drawer-card__sub">Current booking flow does not collect payment inline at appointment creation.</p>
                    <p class="hint">This drawer keeps the payment section visible and honest: schedule first, then complete payment in the appropriate sales or follow-up flow after the appointment exists.</p>
                </div>
            </section>
        </div>

        <div class="drawer-actions-row">
            <button type="submit" class="drawer-submit">Save appointment</button>
            <button type="button" class="drawer-secondary-btn" onclick="window.AppDrawer.close()">Close</button>
        </div>
    </form>
</div>
