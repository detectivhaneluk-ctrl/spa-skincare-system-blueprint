<div class="drawer-workspace" data-drawer-content-root data-drawer-title="Edit appointment" data-drawer-subtitle="Update without leaving the calendar" data-drawer-width="wide">
    <p class="drawer-workspace__intro">Adjust the booking details here, then return to the same day calendar context with a refreshed schedule.</p>

    <?php if (!empty($errors)): ?>
    <ul class="form-errors appt-create-errors">
        <?php if (!empty($errors['_conflict'])): ?><li class="error"><?= htmlspecialchars($errors['_conflict']) ?></li><?php endif; ?>
        <?php if (!empty($errors['_general'])): ?><li class="error"><?= htmlspecialchars($errors['_general']) ?></li><?php endif; ?>
        <?php foreach ($errors as $k => $e): if (str_starts_with((string) $k, '_')) { continue; } ?>
        <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid')) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="post" action="/appointments/<?= (int) $appointment['id'] ?>" class="entity-form appt-create-form appt-edit-form" data-drawer-submit data-drawer-dirty-track>
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">

        <div class="drawer-card">
            <div class="drawer-toolbar">
                <div>
                    <h3 class="drawer-card__title">Appointment #<?= (int) $appointment['id'] ?></h3>
                    <p class="drawer-card__sub">Edit the appointment without losing the day schedule behind the drawer.</p>
                </div>
                <a href="/appointments/<?= (int) $appointment['id'] ?>" class="drawer-link-btn" data-drawer-url>Back to details</a>
            </div>
        </div>

        <div class="appt-edit-form-sections">
            <section class="appt-create-section" aria-labelledby="drawer-edit-branch">
                <h2 class="appt-create-section__title" id="drawer-edit-branch">Branch and client</h2>
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
                            <option value="<?= (int) $c['id'] ?>" <?= ((int) ($appointment['client_id'] ?? 0)) === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim($c['first_name'] . ' ' . $c['last_name'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>

            <section class="appt-create-section" aria-labelledby="drawer-edit-service">
                <h2 class="appt-create-section__title" id="drawer-edit-service">Service and schedule</h2>
                <div class="appt-create-section__body appt-create-section__body--split">
                    <div class="form-row">
                        <label for="service_id">Service</label>
                        <select id="service_id" name="service_id">
                            <option value="">—</option>
                            <?php foreach ($services as $s): $svcDesc = trim((string) ($s['description'] ?? '')); ?>
                            <option value="<?= (int) $s['id'] ?>" <?= ((int) ($appointment['service_id'] ?? 0)) === (int) $s['id'] ? 'selected' : '' ?><?= $svcDesc !== '' ? ' title="' . htmlspecialchars($svcDesc, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($s['name']) ?> (<?= (int) ($s['duration_minutes'] ?? 0) ?> min)</option>
                            <?php endforeach; ?>
                        </select>
                        <span id="service-description-hint" class="hint" hidden></span>
                    </div>
                    <div class="form-row">
                        <label for="date">Date *</label>
                        <input type="date" id="date" name="date" required value="<?= htmlspecialchars($appointment['date'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <label for="start_time">Start time *</label>
                        <input type="time" id="start_time" name="start_time" required value="<?= htmlspecialchars($appointment['start_time'] ?? '') ?>">
                    </div>
                    <div class="form-row">
                        <label for="end_time">End time</label>
                        <input type="time" id="end_time" name="end_time" value="<?= htmlspecialchars($appointment['end_time'] ?? '') ?>">
                    </div>
                </div>
            </section>

            <section class="appt-create-section" aria-labelledby="drawer-edit-resources">
                <h2 class="appt-create-section__title" id="drawer-edit-resources">Resources and status</h2>
                <div class="appt-create-section__body appt-create-section__body--split">
                    <div class="form-row">
                        <label for="staff_id">Staff</label>
                        <select id="staff_id" name="staff_id">
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
                    <div class="form-row">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <?php foreach (['scheduled' => 'Scheduled', 'confirmed' => 'Confirmed', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No show'] as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= ($appointment['status'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="3"><?= htmlspecialchars($appointment['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </section>
        </div>

        <div class="drawer-actions-row">
            <button type="submit" class="drawer-submit">Update appointment</button>
            <a href="/appointments/<?= (int) $appointment['id'] ?>" class="drawer-secondary-btn" data-drawer-url>Cancel</a>
        </div>
    </form>
</div>
