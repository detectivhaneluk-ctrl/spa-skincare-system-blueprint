<div class="drawer-workspace" data-drawer-content-root data-drawer-title="Blocked time" data-drawer-subtitle="<?= htmlspecialchars((string) $date) ?>" data-drawer-width="medium">
    <p class="drawer-workspace__intro">Create or remove blocked time without replacing the day calendar.</p>

    <div class="drawer-card">
        <h3 class="drawer-card__title">Add blocked slot</h3>
        <p class="drawer-card__sub">Uses the same branch and date context as the visible schedule.</p>
        <form method="post" action="/appointments/blocked-slots" class="entity-form calendar-blocked-form" data-drawer-submit data-drawer-dirty-track>
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <div class="calendar-blocked-form-grid">
                <div class="form-row">
                    <label for="blocked-branch-id">Branch</label>
                    <select id="blocked-branch-id" name="branch_id">
                        <option value="">—</option>
                        <?php foreach ($branches as $b): ?>
                        <option value="<?= (int) $b['id'] ?>" <?= ((int) ($branchId ?? 0)) === (int) $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="blocked-staff-id">Staff *</label>
                    <select id="blocked-staff-id" name="staff_id" required>
                        <option value="">—</option>
                        <?php foreach ($staffOptions as $st): ?>
                        <option value="<?= (int) $st['id'] ?>"><?= htmlspecialchars(trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? ''))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="blocked-title">Title / Reason *</label>
                    <input type="text" id="blocked-title" name="title" required maxlength="150" value="Blocked">
                </div>
                <div class="form-row">
                    <label for="blocked-date">Date *</label>
                    <input type="date" id="blocked-date" name="block_date" required value="<?= htmlspecialchars((string) $date) ?>">
                </div>
                <div class="form-row">
                    <label for="blocked-start">Start Time *</label>
                    <input type="time" id="blocked-start" name="start_time" required>
                </div>
                <div class="form-row">
                    <label for="blocked-end">End Time *</label>
                    <input type="time" id="blocked-end" name="end_time" required>
                </div>
                <div class="form-row calendar-blocked-field--full">
                    <label for="blocked-notes">Notes</label>
                    <textarea id="blocked-notes" name="notes" rows="2"></textarea>
                </div>
            </div>
            <div class="drawer-actions-row">
                <button type="submit" class="drawer-submit">Save blocked time</button>
                <button type="button" class="drawer-secondary-btn" onclick="window.AppDrawer.close()">Close</button>
            </div>
        </form>
    </div>

    <div class="drawer-card">
        <h3 class="drawer-card__title">Blocks for this day</h3>
        <?php if (empty($blockedSlots)): ?>
        <p class="hint">No blocked slots for the selected date and branch.</p>
        <?php else: ?>
        <table class="index-table calendar-blocked-table">
            <thead>
            <tr><th>Staff</th><th>Title</th><th>Time</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php foreach ($blockedSlots as $bs): ?>
            <tr>
                <td><?= htmlspecialchars(trim(($bs['staff_first_name'] ?? '') . ' ' . ($bs['staff_last_name'] ?? ''))) ?: '—' ?></td>
                <td><?= htmlspecialchars((string) ($bs['title'] ?? 'Blocked')) ?></td>
                <td><?= htmlspecialchars((string) ($bs['start_time'] ?? '')) ?> - <?= htmlspecialchars((string) ($bs['end_time'] ?? '')) ?></td>
                <td>
                    <form method="post" action="/appointments/blocked-slots/<?= (int) $bs['id'] ?>/delete" data-drawer-submit data-drawer-confirm="Delete blocked slot?">
                        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="date" value="<?= htmlspecialchars((string) $date) ?>">
                        <input type="hidden" name="branch_id" value="<?= htmlspecialchars((string) ($branchId ?? '')) ?>">
                        <button type="submit" class="drawer-secondary-btn drawer-danger-btn">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
