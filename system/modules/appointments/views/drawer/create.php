<?php
$prefillTime = (string) ($appointment['prefill_time'] ?? '');
$selectedStartTime = (string) ($appointment['selected_start_time'] ?? '');
$prefillEndTime = (string) ($appointment['prefill_end_time'] ?? '');
$slotMinutes = max(5, (int) ($appointment['slot_minutes'] ?? 30));
$singleBranch = count($branches) === 1;
$statusValue = (string) ($appointment['status'] ?? 'scheduled');
?>
<div class="drawer-workspace drawer-workspace--appointment-create" data-drawer-content-root data-drawer-title="New appointment" data-drawer-subtitle="Calendar booking workspace" data-drawer-width="medium">
    <p class="drawer-workspace__intro">Book the clicked calendar slot first. Load slots only if you want to move the appointment.</p>

    <?php if (!empty($errors)): ?>
    <ul class="form-errors appt-create-errors">
        <?php if (!empty($errors['_conflict'])): ?><li class="error"><?= htmlspecialchars($errors['_conflict']) ?></li><?php endif; ?>
        <?php if (!empty($errors['_general'])): ?><li class="error"><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
        <?php foreach ($errors as $k => $e): if (str_starts_with((string) $k, '_')) { continue; } ?>
        <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="post" action="/appointments/create" class="entity-form appt-create-form" id="drawer-booking-form" data-drawer-submit data-drawer-dirty-track data-create-base-url="/appointments/create" data-prefill-time="<?= htmlspecialchars($prefillTime) ?>" data-slot-minutes="<?= (int) $slotMinutes ?>" data-prefill-end-time="<?= htmlspecialchars($prefillEndTime) ?>">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" id="selected_start_time" name="start_time" value="<?= htmlspecialchars($selectedStartTime) ?>">
        <input type="hidden" id="appointment_status" name="status" value="<?= htmlspecialchars($statusValue) ?>">
        <?php if ($singleBranch): ?>
        <input type="hidden" id="branch_id" name="branch_id" value="<?= (int) $branches[0]['id'] ?>">
        <?php endif; ?>

        <div class="appt-create-tabs-shell" data-drawer-tabs>
            <div class="drawer-tabs appt-create-tabs" role="tablist" aria-label="New appointment sections">
                <button type="button" class="drawer-tab" data-drawer-tab="schedule" data-drawer-tab-default="1">Schedule</button>
                <button type="button" class="drawer-tab" data-drawer-tab="payment">Payment</button>
            </div>

            <section data-drawer-tab-panel="schedule">
                <div class="appt-create-stack">
                    <section class="appt-create-panel appt-create-panel--schedule">
                        <div class="appt-create-card__header">
                            <div>
                                <h3 class="appt-create-panel__title">Schedule</h3>
                                <p class="appt-create-panel__sub">Use the clicked time unless you load other slots.</p>
                            </div>
                            <div class="appt-create-card__controls">
                                <label class="appt-create-confirm-toggle" for="appointment_status_confirmed" title="Mark the appointment as confirmed">
                                    <input type="checkbox" id="appointment_status_confirmed" value="1" <?= $statusValue === 'confirmed' ? 'checked' : '' ?>>
                                    <span class="appt-create-confirm-toggle__switch" aria-hidden="true"></span>
                                    <span class="appt-create-confirm-toggle__label">Confirmed</span>
                                </label>
                            </div>
                        </div>

                        <div class="appt-create-slot-summary" aria-live="polite">
                            <div class="appt-create-slot-summary__item">
                                <span class="appt-create-slot-summary__label">Selected time</span>
                                <strong class="appt-create-slot-summary__value" data-selected-slot-label><?= $selectedStartTime !== '' ? htmlspecialchars(str_replace(' ', ' at ', $selectedStartTime)) : 'No slot selected yet.' ?></strong>
                            </div>
                            <div class="appt-create-slot-summary__item">
                                <span class="appt-create-slot-summary__label">Ends</span>
                                <strong class="appt-create-slot-summary__value" data-estimated-end-label><?= $prefillEndTime !== '' ? htmlspecialchars($prefillEndTime) : 'Pending service selection' ?></strong>
                            </div>
                        </div>

                        <div class="appt-create-mini-grid">
                            <?php if (!$singleBranch): ?>
                            <div class="form-row">
                                <label for="branch_id">Branch</label>
                                <select id="branch_id" name="branch_id" data-branch-select>
                                    <option value="">—</option>
                                    <?php foreach ($branches as $b): ?>
                                    <option value="<?= (int) $b['id'] ?>" <?= ((int) ($appointment['branch_id'] ?? 0)) === (int) $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

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

                        <div class="form-row appt-create-row--inline">
                            <button type="button" id="load-slots-btn" class="appt-create-btn appt-create-btn--secondary appt-create-btn--load">Load slots</button>
                            <span id="slots-status" class="hint appt-create-slots-status"><?= $prefillTime !== '' ? 'Prefilled from calendar: ' . htmlspecialchars($prefillTime) : 'Keep the clicked time unless you change it.' ?></span>
                        </div>
                        <div class="form-row appt-create-slot-decision">
                            <div id="slots-container" class="slots-grid appt-create-slots-grid" role="group" aria-label="Available slots">
                                <span class="hint">Optional: load slots to pick another time.</span>
                            </div>
                        </div>
                    </section>

                    <section class="appt-create-panel appt-create-panel--client">
                        <div class="appt-create-card__header">
                            <div>
                                <h3 class="appt-create-panel__title">Customer / contact</h3>
                                <p class="appt-create-panel__sub">Choose the canonical client record, then review the mirrored contact details.</p>
                            </div>
                        </div>

                        <div class="appt-create-client-block">
                            <div class="form-row appt-create-search-row">
                                <label for="client-search">Search clients</label>
                                <input type="search" id="client-search" placeholder="Start typing to filter clients…" autocomplete="off">
                                <div id="client-search-results" class="appt-create-search-results" hidden aria-live="polite"></div>
                                <span class="hint" id="client-search-hint">Filter by ID, name, email, or phone.</span>
                            </div>

                            <div class="form-row appt-create-client-select-row">
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
                                            <?= ((int) ($appointment['client_id'] ?? 0)) === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="appt-create-client-summary" aria-live="polite">
                                <dl class="drawer-detail-list appt-create-contact-list">
                                    <div><dt>Name</dt><dd data-client-detail="name">Select a client</dd></div>
                                    <div><dt>Email</dt><dd data-client-detail="email">—</dd></div>
                                    <div><dt>Country</dt><dd data-client-detail="country">—</dd></div>
                                    <div><dt>Phone</dt><dd data-client-detail="phone">—</dd></div>
                                    <div><dt>Source</dt><dd data-client-detail="source">—</dd></div>
                                </dl>
                            </div>
                        </div>

                        <div class="form-row">
                            <label for="notes">Notes</label>
                            <textarea id="notes" name="notes" rows="2"><?= htmlspecialchars($appointment['notes'] ?? '') ?></textarea>
                        </div>
                    </section>
                </div>
            </section>

            <section data-drawer-tab-panel="payment" hidden>
                <div class="appt-create-panel appt-create-panel--payment">
                    <h3 class="appt-create-panel__title">Payment handling</h3>
                    <p class="appt-create-panel__sub">This drawer does not collect payment inline.</p>
                    <p class="hint">Use the sales or follow-up flow after the appointment is created.</p>
                </div>
            </section>
        </div>

        <div class="drawer-actions-row drawer-actions-row--appointment">
            <button type="submit" class="drawer-submit">Save appointment</button>
            <button type="button" class="drawer-secondary-btn" onclick="window.AppDrawer.close()">Close</button>
        </div>
    </form>
</div>
