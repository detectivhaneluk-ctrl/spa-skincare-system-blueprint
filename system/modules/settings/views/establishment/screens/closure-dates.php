<section class="settings-establishment">
    <?php
    $storageReady = !empty($closureDatesStorageReady);
    $branchId = isset($closureDatesBranchId) && $closureDatesBranchId !== null ? (int) $closureDatesBranchId : null;
    $branchName = trim((string) ($closureDatesBranchName ?? ''));
    $rows = is_array($closureDatesRows ?? null) ? $closureDatesRows : [];
    $flashBag = is_array($flash ?? null) ? $flash : [];
    $old = is_array($flashBag['closure_dates_old'] ?? null) ? $flashBag['closure_dates_old'] : [];
    $oldAction = (string) ($old['action'] ?? '');
    $oldId = (int) ($old['closure_id'] ?? 0);
    ?>
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Closure Dates</h2>
        <p class="settings-establishment__lead">Manage full-day branch closure dates. These dates are establishment/branch-level and do not reuse staff blocked slots.</p>
    </header>

    <?php if (!$storageReady): ?>
        <section class="settings-establishment-card">
            <h3 class="settings-establishment-card__title">Setup Required</h3>
            <p class="settings-establishment-card__help">Closure Dates is not available yet because the required database migration has not been applied.</p>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('overview')) ?>">Back to Overview</a>
            </div>
        </section>
    <?php elseif ($branchId === null): ?>
        <section class="settings-establishment-card">
            <h3 class="settings-establishment-card__title">No Branch Context</h3>
            <p class="settings-establishment-card__help">Closure Dates cannot be edited because no active branch context was resolved for this session.</p>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('overview')) ?>">Back to Overview</a>
            </div>
        </section>
    <?php else: ?>
        <form method="post" action="/settings" class="settings-form settings-establishment">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="section" value="establishment">
            <input type="hidden" name="screen" value="closure-dates">
            <input type="hidden" name="closure_dates_action" value="create">
            <section class="settings-establishment-card">
                <h3 class="settings-establishment-card__title">Add Closure Date</h3>
                <p class="settings-establishment-card__help">Branch context: <?= htmlspecialchars($branchName !== '' ? $branchName : ('Branch #' . (string) $branchId)) ?>.</p>
                <div class="settings-establishment-form-grid">
                    <div class="setting-row">
                        <label for="closure-date-create">Date</label>
                        <input id="closure-date-create" type="date" name="closure_date" required value="<?= htmlspecialchars($oldAction === 'create' ? (string) ($old['closure_date'] ?? '') : '') ?>">
                    </div>
                    <div class="setting-row">
                        <label for="closure-title-create">Title</label>
                        <input id="closure-title-create" type="text" name="title" required maxlength="150" placeholder="Public Holiday" value="<?= htmlspecialchars($oldAction === 'create' ? (string) ($old['title'] ?? '') : '') ?>">
                    </div>
                    <div class="setting-row setting-row--full">
                        <label for="closure-notes-create">Notes</label>
                        <input id="closure-notes-create" type="text" name="notes" placeholder="Optional details" value="<?= htmlspecialchars($oldAction === 'create' ? (string) ($old['notes'] ?? '') : '') ?>">
                    </div>
                </div>
                <div class="settings-establishment-actions">
                    <button type="submit" class="settings-establishment-btn settings-establishment-btn--primary">Add Closure Date</button>
                    <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('overview')) ?>">Back to Overview</a>
                </div>
            </section>
        </form>

        <section class="settings-establishment-card">
            <h3 class="settings-establishment-card__title">Existing Closure Dates</h3>
            <p class="settings-establishment-card__help">One row represents one full-day closure for the active branch.</p>
            <?php if ($rows === []): ?>
                <p class="settings-establishment-note">No closure dates configured yet.</p>
            <?php else: ?>
                <div class="settings-establishment-hours-table-wrap">
                    <table class="settings-establishment-hours-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Title</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $rid = (int) ($row['id'] ?? 0);
                                $editMode = $oldAction === 'update' && $oldId === $rid;
                                $dateValue = $editMode ? (string) ($old['closure_date'] ?? '') : (string) ($row['closure_date'] ?? '');
                                $titleValue = $editMode ? (string) ($old['title'] ?? '') : (string) ($row['title'] ?? '');
                                $notesValue = $editMode ? (string) ($old['notes'] ?? '') : (string) (($row['notes'] ?? '') ?: '');
                                ?>
                                <tr>
                                    <td>
                                        <input type="date" form="closure-update-<?= $rid ?>" name="closure_date" value="<?= htmlspecialchars($dateValue) ?>" required>
                                    </td>
                                    <td>
                                        <input type="text" form="closure-update-<?= $rid ?>" name="title" value="<?= htmlspecialchars($titleValue) ?>" maxlength="150" required>
                                    </td>
                                    <td>
                                        <input type="text" form="closure-update-<?= $rid ?>" name="notes" value="<?= htmlspecialchars($notesValue) ?>" placeholder="Optional details">
                                    </td>
                                    <td>
                                        <div class="settings-establishment-actions">
                                            <form id="closure-update-<?= $rid ?>" method="post" action="/settings">
                                                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="section" value="establishment">
                                                <input type="hidden" name="screen" value="closure-dates">
                                                <input type="hidden" name="closure_dates_action" value="update">
                                                <input type="hidden" name="closure_id" value="<?= $rid ?>">
                                                <button type="submit" class="settings-establishment-btn settings-establishment-btn--small">Save</button>
                                            </form>
                                            <form method="post" action="/settings" onsubmit="return confirm('Delete this closure date?');">
                                                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                                                <input type="hidden" name="section" value="establishment">
                                                <input type="hidden" name="screen" value="closure-dates">
                                                <input type="hidden" name="closure_dates_action" value="delete">
                                                <input type="hidden" name="closure_id" value="<?= $rid ?>">
                                                <button type="submit" class="settings-establishment-btn settings-establishment-btn--muted settings-establishment-btn--small">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
