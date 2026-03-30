<section class="settings-establishment">
    <header class="settings-establishment__hero">
        <h2 class="settings-establishment__title">Edit Primary Contact</h2>
        <p class="settings-establishment__lead">Current primary contact channels are mapped to establishment phone and email.</p>
    </header>

    <form method="post" action="/settings" class="settings-form settings-establishment">
        <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="section" value="establishment">
        <input type="hidden" name="screen" value="edit-primary-contact">

        <section class="settings-establishment-card">
            <div class="settings-establishment-form-grid">
                <div class="setting-row"><label for="primary-contact-phone">Phone</label><input type="text" id="primary-contact-phone" name="settings[establishment.phone]" value="<?= htmlspecialchars((string) ($establishment['phone'] ?? '')) ?>"></div>
                <div class="setting-row"><label for="primary-contact-email">Email</label><input type="text" id="primary-contact-email" name="settings[establishment.email]" value="<?= htmlspecialchars((string) ($establishment['email'] ?? '')) ?>"></div>
            </div>
            <p class="settings-establishment-meta">First name and last name are not available as dedicated write-backed fields in the current settings backend.</p>
        </section>

        <section class="settings-establishment-card">
            <div class="settings-establishment-actions">
                <button type="submit" class="settings-establishment-btn settings-establishment-btn--primary">Save Changes</button>
                <a class="settings-establishment-btn" href="<?= htmlspecialchars($establishmentUrl('overview')) ?>">Back to Overview</a>
            </div>
        </section>
    </form>
</section>
