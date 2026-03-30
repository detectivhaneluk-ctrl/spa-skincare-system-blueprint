<section class="settings-establishment">
    <?php
    $branchId = isset($secondaryContactBranchId) && $secondaryContactBranchId !== null ? (int) $secondaryContactBranchId : null;
    $branchName = trim((string) ($secondaryContactBranchName ?? ''));
    $values = is_array($secondaryContact ?? null) ? $secondaryContact : [];
    ?>
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Edit Secondary Contact</h2>
        <p class="settings-establishment__lead">Manage branch-scoped secondary contact details for escalation or backup communications.</p>
    </header>

    <?php if ($branchId === null): ?>
        <section class="settings-establishment-card">
            <h3 class="settings-establishment-card__title">No Branch Context</h3>
            <p class="settings-establishment-card__help">Secondary Contact cannot be edited because no active branch context was resolved for this session.</p>
            <div class="settings-establishment-actions">
                <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('overview')) ?>">Back to Overview</a>
            </div>
        </section>
    <?php else: ?>
        <form method="post" action="/settings" class="settings-form settings-establishment">
            <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="section" value="establishment">
            <input type="hidden" name="screen" value="edit-secondary-contact">

            <section class="settings-establishment-card">
                <p class="settings-establishment-card__help">Branch context: <?= htmlspecialchars($branchName !== '' ? $branchName : ('Branch #' . (string) $branchId)) ?>.</p>
                <div class="settings-establishment-form-grid">
                    <div class="setting-row"><label for="secondary-contact-first-name">First Name</label><input type="text" id="secondary-contact-first-name" name="secondary_contact_first_name" maxlength="100" value="<?= htmlspecialchars((string) ($values['secondary_contact_first_name'] ?? '')) ?>"></div>
                    <div class="setting-row"><label for="secondary-contact-last-name">Last Name</label><input type="text" id="secondary-contact-last-name" name="secondary_contact_last_name" maxlength="100" value="<?= htmlspecialchars((string) ($values['secondary_contact_last_name'] ?? '')) ?>"></div>
                    <div class="setting-row"><label for="secondary-contact-phone">Phone</label><input type="text" id="secondary-contact-phone" name="secondary_contact_phone" maxlength="50" value="<?= htmlspecialchars((string) ($values['secondary_contact_phone'] ?? '')) ?>"></div>
                    <div class="setting-row"><label for="secondary-contact-email">Email</label><input type="email" id="secondary-contact-email" name="secondary_contact_email" maxlength="255" value="<?= htmlspecialchars((string) ($values['secondary_contact_email'] ?? '')) ?>"></div>
                </div>
            </section>

            <section class="settings-establishment-card">
                <div class="settings-establishment-actions">
                    <button type="submit" class="settings-establishment-btn settings-establishment-btn--primary">Save Changes</button>
                    <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('overview')) ?>">Back to Overview</a>
                </div>
            </section>
        </form>
    <?php endif; ?>
</section>
