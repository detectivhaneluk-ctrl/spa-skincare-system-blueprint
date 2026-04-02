<?php
$nsAlert = is_array($clientAppointmentSummary ?? null) ? ($clientAppointmentSummary['no_show_alert'] ?? null) : null;
$nsActive = is_array($nsAlert) && !empty($nsAlert['active']);
?>
<div class="drawer-workspace" data-drawer-content-root data-drawer-title="<?= htmlspecialchars((string) ($appointment['display_summary'] ?? 'Appointment')) ?>" data-drawer-subtitle="Appointment #<?= (int) ($appointment['id'] ?? 0) ?>" data-drawer-width="wide">
    <?php if ($nsActive): ?>
    <div class="appt-show-no-show-alert" role="status">
        <strong>No-show alert</strong>
        <?= htmlspecialchars((string) ($nsAlert['message'] ?? 'Recorded no-shows meet or exceed the configured threshold.')) ?>
    </div>
    <?php endif; ?>

    <div class="drawer-card">
        <div class="drawer-toolbar">
            <div>
                <h3 class="drawer-card__title"><?= htmlspecialchars((string) ($appointment['display_summary'] ?? 'Appointment')) ?></h3>
                <p class="drawer-card__sub"><?= htmlspecialchars((string) (($appointment['display_date_only'] ?? '') . ' ' . ($appointment['display_time_range'] ?? ''))) ?></p>
            </div>
            <div class="drawer-actions-row">
                <a href="/appointments/<?= (int) $appointment['id'] ?>/edit" class="drawer-link-btn" data-drawer-url>Edit</a>
                <?php if (!empty($appointment['can_mark_checked_in'])): ?>
                <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>/check-in" data-drawer-submit>
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <button type="submit" class="drawer-secondary-btn">Check in</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div data-drawer-tabs>
        <div class="drawer-tabs" role="tablist" aria-label="Appointment drawer sections">
            <button type="button" class="drawer-tab" data-drawer-tab="details" data-drawer-tab-default="1">Details</button>
            <button type="button" class="drawer-tab" data-drawer-tab="actions">Actions</button>
            <button type="button" class="drawer-tab" data-drawer-tab="payment">Payment</button>
            <button type="button" class="drawer-tab" data-drawer-tab="history">History</button>
        </div>

        <section data-drawer-tab-panel="details">
            <div class="drawer-card">
                <dl class="drawer-detail-list">
                    <div><dt>Client</dt><dd><?= htmlspecialchars(trim(($appointment['client_first_name'] ?? '') . ' ' . ($appointment['client_last_name'] ?? ''))) ?></dd></div>
                    <div><dt>Service</dt><dd><?= htmlspecialchars((string) ($appointment['service_name'] ?? '—')) ?></dd></div>
                    <div><dt>Staff</dt><dd><?= htmlspecialchars(trim(($appointment['staff_first_name'] ?? '') . ' ' . ($appointment['staff_last_name'] ?? ''))) ?: '—' ?></dd></div>
                    <div><dt>Room</dt><dd><?= htmlspecialchars((string) ($appointment['room_name'] ?? '—')) ?></dd></div>
                    <div><dt>Start</dt><dd><?= htmlspecialchars((string) ($appointment['display_start_at'] ?? '—')) ?></dd></div>
                    <div><dt>End</dt><dd><?= htmlspecialchars((string) ($appointment['display_end_at'] ?? '—')) ?></dd></div>
                    <div><dt>Status</dt><dd><?= htmlspecialchars((string) ($appointment['status_label'] ?? '—')) ?></dd></div>
                    <div><dt>Checked in</dt><dd><?= !empty($appointment['checked_in_display']) ? htmlspecialchars((string) $appointment['checked_in_display']) : '—' ?></dd></div>
                    <div><dt>Notes</dt><dd><?= nl2br(htmlspecialchars((string) ($appointment['notes'] ?? ''))) ?: '—' ?></dd></div>
                </dl>
            </div>
        </section>

        <section data-drawer-tab-panel="actions" hidden>
            <div class="drawer-card">
                <h3 class="drawer-card__title">Reschedule</h3>
                <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>/reschedule" class="entity-form" data-drawer-submit data-drawer-dirty-track>
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="expected_current_start_at" value="<?= htmlspecialchars((string) ($appointment['start_at'] ?? '')) ?>">
                    <div class="form-row">
                        <label for="reschedule_start_time">New start *</label>
                        <input type="datetime-local" id="reschedule_start_time" name="start_time" required value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime((string) ($appointment['start_at'] ?? 'now')))) ?>">
                    </div>
                    <div class="form-row">
                        <label for="reschedule_staff_id">Staff</label>
                        <select id="reschedule_staff_id" name="staff_id">
                            <option value="">Keep current staff</option>
                            <?php foreach ($staffOptions as $st): ?>
                            <option value="<?= (int) $st['id'] ?>" <?= ((int) ($appointment['staff_id'] ?? 0)) === (int) $st['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="reschedule_notes">Notes</label>
                        <textarea id="reschedule_notes" name="notes" rows="2"></textarea>
                    </div>
                    <button type="submit" class="drawer-submit">Reschedule</button>
                </form>
            </div>

            <div class="drawer-card">
                <h3 class="drawer-card__title">Status</h3>
                <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>/status" class="entity-form" data-drawer-submit data-drawer-dirty-track>
                    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                    <div class="form-row">
                        <label for="next_status">Update status *</label>
                        <select id="next_status" name="status" required>
                            <option value="">Select status</option>
                            <?php foreach (['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'] as $stVal): ?>
                            <option value="<?= htmlspecialchars($stVal) ?>"><?= htmlspecialchars((string) ($appointment['status_select_labels'][$stVal] ?? $stVal)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="status_notes">Notes</label>
                        <textarea id="status_notes" name="notes" rows="2"></textarea>
                    </div>
                    <button type="submit" class="drawer-submit">Update status</button>
                </form>
            </div>

            <div class="drawer-card">
                <h3 class="drawer-card__title">Destructive actions</h3>
                <div class="drawer-actions-row">
                    <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>/cancel" data-drawer-submit data-drawer-confirm="Cancel this appointment?">
                        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                        <button type="submit" class="drawer-secondary-btn">Cancel appointment</button>
                    </form>
                    <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>/delete" data-drawer-submit data-drawer-confirm="Delete this appointment?">
                        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                        <button type="submit" class="drawer-secondary-btn drawer-danger-btn">Delete appointment</button>
                    </form>
                </div>
            </div>
        </section>

        <section data-drawer-tab-panel="payment" hidden>
            <div class="drawer-card">
                <h3 class="drawer-card__title">Payment and package context</h3>
                <dl class="drawer-detail-list">
                    <div><dt>Name</dt><dd><?= htmlspecialchars(trim(($appointment['client_first_name'] ?? '') . ' ' . ($appointment['client_last_name'] ?? ''))) ?: '—' ?></dd></div>
                    <div><dt>Email</dt><dd><?= htmlspecialchars((string) ($appointment['client_email'] ?? '—')) ?></dd></div>
                    <div><dt>Country</dt><dd><?= htmlspecialchars((string) ($appointment['client_country'] ?? '—')) ?></dd></div>
                    <div><dt>Phone</dt><dd><?= htmlspecialchars((string) ($appointment['client_phone'] ?? '—')) ?></dd></div>
                    <div><dt>Source</dt><dd><?= htmlspecialchars((string) (($appointment['client_source'] ?? '') !== '' ? $appointment['client_source'] : 'internal_calendar')) ?></dd></div>
                </dl>
                <p class="hint">Package consumption remains available from the history/workflow path below. Inline payment collection is not part of the current appointment detail contract.</p>
            </div>
        </section>

        <section data-drawer-tab-panel="history" hidden>
            <div class="drawer-card">
                <h3 class="drawer-card__title">Package consumption</h3>
                <?php if (empty($packageConsumptions)): ?>
                <p class="hint">No package consumption has been recorded for this appointment yet.</p>
                <?php else: ?>
                <table class="index-table appt-show-history-table">
                    <thead>
                    <tr><th>Usage ID</th><th>Package</th><th>Qty</th><th>Remaining</th><th>Created</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($packageConsumptions as $c): ?>
                    <tr>
                        <td><?= (int) $c['usage_id'] ?></td>
                        <td><?= htmlspecialchars((string) $c['package_name']) ?></td>
                        <td><?= (int) $c['quantity'] ?></td>
                        <td><?= (int) $c['remaining_after'] ?></td>
                        <td><?= htmlspecialchars((string) $c['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>
