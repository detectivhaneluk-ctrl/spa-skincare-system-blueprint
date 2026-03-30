<section class="settings-establishment">
    <?php
    $storageReady = !empty($openingHoursStorageReady);
    $branchId = isset($openingHoursBranchId) && $openingHoursBranchId !== null ? (int) $openingHoursBranchId : null;
    $branchName = trim((string) ($openingHoursBranchName ?? ''));
    $dayLabels = is_array($openingHoursDayLabels ?? null) ? $openingHoursDayLabels : [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];
    $weeklyMap = is_array($openingHoursForm ?? null) ? $openingHoursForm : [];
    $timeOptions = ['' => 'Closed (no hours)'];
    for ($hour = 0; $hour < 24; $hour++) {
        foreach ([0, 30] as $minute) {
            $value = sprintf('%02d:%02d', $hour, $minute);
            $timeOptions[$value] = $value;
        }
    }
    ?>
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Opening Hours</h2>
        <p class="settings-establishment__lead">Set one recurring operating window per day for the active branch context.</p>
    </header>

    <?php if (!$storageReady): ?>
        <section class="settings-establishment-card">
            <h3 class="settings-establishment-card__title">Setup Required</h3>
            <p class="settings-establishment-card__help">Opening Hours is not available yet because the required database migration has not been applied.</p>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('overview')) ?>">Back to Overview</a>
            </div>
        </section>
    <?php elseif ($branchId === null): ?>
        <section class="settings-establishment-card">
            <h3 class="settings-establishment-card__title">No Branch Context</h3>
            <p class="settings-establishment-card__help">Opening Hours cannot be edited because no active branch context was resolved for this session.</p>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('overview')) ?>">Back to Overview</a>
            </div>
        </section>
    <?php else: ?>
        <form method="post" action="/settings" class="settings-form settings-establishment">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="section" value="establishment">
            <input type="hidden" name="screen" value="opening-hours">

            <section class="settings-establishment-card">
                <h3 class="settings-establishment-card__title">Weekly Operating Hours</h3>
                <p class="settings-establishment-card__help">
                    Branch context:
                    <?= htmlspecialchars($branchName !== '' ? $branchName : ('Branch #' . (string) $branchId)) ?>.
                    Leave both times blank to mark a day as closed.
                </p>
                <div class="settings-establishment-hours-table-wrap">
                    <table class="settings-establishment-hours-table">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Opening Time</th>
                                <th>Closing Time</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dayLabels as $day => $label): ?>
                                <?php
                                $row = $weeklyMap[$day] ?? ['start_time' => '', 'end_time' => ''];
                                $startValue = trim((string) ($row['start_time'] ?? ''));
                                $endValue = trim((string) ($row['end_time'] ?? ''));
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $label) ?></td>
                                    <td>
                                        <select name="opening_hours[<?= (int) $day ?>][start_time]" data-opening-select>
                                            <?php foreach ($timeOptions as $value => $optionLabel): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= $startValue === $value ? 'selected' : '' ?>><?= htmlspecialchars($optionLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="opening_hours[<?= (int) $day ?>][end_time]" data-closing-select>
                                            <?php foreach ($timeOptions as $value => $optionLabel): ?>
                                            <option value="<?= htmlspecialchars($value) ?>" <?= $endValue === $value ? 'selected' : '' ?>><?= htmlspecialchars($optionLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <?php if ((int) $day > 0): ?>
                                        <button type="button" class="settings-establishment-btn settings-establishment-btn--muted settings-establishment-btn--small" data-copy-previous>Copy previous day</button>
                                        <?php else: ?>
                                        —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="settings-establishment-card">
                <div class="settings-establishment-actions">
                    <button type="submit" class="settings-establishment-btn settings-establishment-btn--primary">Save Changes</button>
                    <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('overview')) ?>">Back to Overview</a>
                </div>
            </section>
        </form>
        <script>
            (() => {
                const rows = Array.from(document.querySelectorAll('.settings-establishment-hours-table tbody tr'));
                rows.forEach((row, idx) => {
                    const copyButton = row.querySelector('[data-copy-previous]');
                    if (!copyButton || idx === 0) {
                        return;
                    }
                    copyButton.addEventListener('click', () => {
                        const prevRow = rows[idx - 1];
                        if (!prevRow) {
                            return;
                        }
                        const prevOpen = prevRow.querySelector('[data-opening-select]');
                        const prevClose = prevRow.querySelector('[data-closing-select]');
                        const currentOpen = row.querySelector('[data-opening-select]');
                        const currentClose = row.querySelector('[data-closing-select]');
                        if (!prevOpen || !prevClose || !currentOpen || !currentClose) {
                            return;
                        }
                        currentOpen.value = prevOpen.value;
                        currentClose.value = prevClose.value;
                    });
                });
            })();
        </script>
    <?php endif; ?>
</section>
