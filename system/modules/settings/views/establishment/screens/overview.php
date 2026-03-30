<section class="settings-establishment">
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Establishment Overview</h2>
        <p class="settings-establishment__lead">Use this overview to access focused screens for establishment setup. Only one screen is shown at a time.</p>
    </header>

    <div class="settings-establishment-grid">
        <section class="settings-establishment-card settings-establishment-card--full">
            <h3 class="settings-establishment-card__title">Current Settings Summary</h3>
            <div class="settings-establishment-summary">
                <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Name</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($establishment['name'] ?? 'Not set')) ?></span></div>
                <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Phone</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($establishment['phone'] ?? 'Not set')) ?></span></div>
                <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Email</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($establishment['email'] ?? 'Not set')) ?></span></div>
                <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Address</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($establishment['address'] ?? 'Not set')) ?></span></div>
                <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Currency</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($establishment['currency'] ?? 'Not set')) ?></span></div>
                <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Time Zone</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($establishment['timezone'] ?? 'Not set')) ?></span></div>
                <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Language</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($establishment['language'] ?? 'Not set')) ?></span></div>
            </div>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('edit-overview')) ?>">Edit Establishment Overview</a>
            </div>
        </section>

        <section class="settings-establishment-card">
            <h3 class="settings-establishment-card__title">Primary Contact</h3>
            <p class="settings-establishment-card__help">Current primary contact channels are backed by establishment phone and email fields.</p>
            <div class="settings-establishment-summary">
                <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Phone</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($establishment['phone'] ?? 'Not set')) ?></span></div>
                <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Email</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($establishment['email'] ?? 'Not set')) ?></span></div>
            </div>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('edit-primary-contact')) ?>">Edit Primary Contact</a>
            </div>
        </section>

        <section class="settings-establishment-card">
            <h3 class="settings-establishment-card__title">Secondary Contact</h3>
            <?php
            $secondary = is_array($secondaryContact ?? null) ? $secondaryContact : [];
            $secondaryConfigured = false;
            foreach (['secondary_contact_first_name', 'secondary_contact_last_name', 'secondary_contact_phone', 'secondary_contact_email'] as $secondaryField) {
                if (trim((string) ($secondary[$secondaryField] ?? '')) !== '') {
                    $secondaryConfigured = true;
                    break;
                }
            }
            ?>
            <?php if ($secondaryContactBranchId === null): ?>
                <p class="settings-establishment-card__help">No active branch context is available for secondary contact settings.</p>
            <?php elseif (!$secondaryConfigured): ?>
                <p class="settings-establishment-card__help">No secondary contact configured for the active branch context.</p>
            <?php else: ?>
                <p class="settings-establishment-card__help">Secondary contact details for the active branch context.</p>
                <div class="settings-establishment-summary">
                    <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">First Name</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($secondary['secondary_contact_first_name'] ?? '')) ?></span></div>
                    <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Last Name</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($secondary['secondary_contact_last_name'] ?? '')) ?></span></div>
                    <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Phone</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($secondary['secondary_contact_phone'] ?? '')) ?></span></div>
                    <div class="settings-establishment-summary__row"><span class="settings-establishment-summary__key">Email</span><span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($secondary['secondary_contact_email'] ?? '')) ?></span></div>
                </div>
            <?php endif; ?>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn settings-establishment-btn--muted" href="<?= htmlspecialchars($establishmentUrl('edit-secondary-contact')) ?>">Edit Secondary Contact</a>
            </div>
        </section>

        <section class="settings-establishment-card">
            <h3 class="settings-establishment-card__title">Opening Hours</h3>
            <p class="settings-establishment-card__help">Recurring weekly operating hours for the active branch context.</p>
            <p class="settings-establishment-note">
                <?php if (empty($openingHoursStorageReady)): ?>
                    <?= htmlspecialchars('Setup required: apply migration 092_create_branch_operating_hours_table.sql to enable Opening Hours.') ?>
                <?php else: ?>
                    <?= htmlspecialchars((string) (($openingHoursSummary ?? '') !== '' ? $openingHoursSummary : 'No opening-hours data available for the active branch context.')) ?>
                <?php endif; ?>
            </p>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn settings-establishment-btn--muted" href="<?= htmlspecialchars($establishmentUrl('opening-hours')) ?>">Manage Opening Hours</a>
            </div>
        </section>

        <section class="settings-establishment-card">
            <h3 class="settings-establishment-card__title">Closure Dates</h3>
            <?php
            $closureStorageReady = !empty($closureDatesStorageReady);
            $closureBranchId = isset($closureDatesBranchId) && $closureDatesBranchId !== null ? (int) $closureDatesBranchId : null;
            $closureRows = is_array($closureDatesRows ?? null) ? $closureDatesRows : [];
            ?>
            <?php if (!$closureStorageReady): ?>
                <p class="settings-establishment-card__help">Setup required: apply migration 093_create_branch_closure_dates_table.sql to enable Closure Dates.</p>
            <?php elseif ($closureBranchId === null): ?>
                <p class="settings-establishment-card__help">No active branch context is available for closure-date management.</p>
            <?php elseif ($closureRows === []): ?>
                <p class="settings-establishment-card__help">No closure dates configured for the active branch context.</p>
            <?php else: ?>
                <p class="settings-establishment-card__help">Configured closure dates: <?= count($closureRows) ?>.</p>
                <div class="settings-establishment-summary">
                    <?php foreach (array_slice($closureRows, 0, 3) as $closureRow): ?>
                        <div class="settings-establishment-summary__row">
                            <span class="settings-establishment-summary__key"><?= htmlspecialchars((string) ($closureRow['closure_date'] ?? '')) ?></span>
                            <span class="settings-establishment-summary__value"><?= htmlspecialchars((string) ($closureRow['title'] ?? '')) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn settings-establishment-btn--muted" href="<?= htmlspecialchars($establishmentUrl('closure-dates')) ?>">Manage Closure Dates</a>
            </div>
        </section>
    </div>
</section>
