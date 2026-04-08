<?php
$title = 'New Employee : Enter Employee Info (Step 1 of 4)';

// Country list — common defaults; no DB dependency needed for this lookup.
$countries = [
    '' => '— Select Country —',
    'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AR' => 'Argentina',
    'AU' => 'Australia', 'AT' => 'Austria', 'BE' => 'Belgium', 'BR' => 'Brazil',
    'CA' => 'Canada', 'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia',
    'HR' => 'Croatia', 'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'EG' => 'Egypt',
    'FI' => 'Finland', 'FR' => 'France', 'DE' => 'Germany', 'GH' => 'Ghana',
    'GR' => 'Greece', 'HU' => 'Hungary', 'IN' => 'India', 'ID' => 'Indonesia',
    'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy', 'JP' => 'Japan',
    'KE' => 'Kenya', 'MX' => 'Mexico', 'MA' => 'Morocco', 'NL' => 'Netherlands',
    'NZ' => 'New Zealand', 'NG' => 'Nigeria', 'NO' => 'Norway', 'PK' => 'Pakistan',
    'PE' => 'Peru', 'PH' => 'Philippines', 'PL' => 'Poland', 'PT' => 'Portugal',
    'RO' => 'Romania', 'RU' => 'Russia', 'SA' => 'Saudi Arabia', 'ZA' => 'South Africa',
    'ES' => 'Spain', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'TH' => 'Thailand',
    'TR' => 'Turkey', 'UA' => 'Ukraine', 'AE' => 'United Arab Emirates',
    'GB' => 'United Kingdom', 'US' => 'United States', 'VN' => 'Vietnam',
];

// $staff holds either [] (fresh form) or POST data on re-render after validation failure.
// $errors is an associative array keyed by field name.
// $serviceTypes is an array of ['id' => ..., 'name' => ...] rows.

$v = static function (string $key) use ($staff): string {
    return htmlspecialchars((string) ($staff[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};

$hasError = static function (string $key) use ($errors): bool {
    return isset($errors[$key]);
};

$showErr = static function (string $key) use ($errors): void {
    if (isset($errors[$key])) {
        echo '<span class="form-field-error">' . htmlspecialchars((string) $errors[$key], ENT_QUOTES, 'UTF-8') . '</span>';
    }
};

// Detect drawer context — drawer JS appends ?drawer=1 to fetch URL
$isDrawer = isset($_GET['drawer']) && $_GET['drawer'] === '1';

// ── CSRF ──────────────────────────────────────────────────────────────────
$csrfName = htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'), ENT_QUOTES, 'UTF-8');
$csrfVal  = htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8');

if (!$isDrawer):
    ob_start();
    $teamWorkspaceActiveTab  = 'directory';
    $teamWorkspaceShellTitle = 'Team';
    require base_path('modules/staff/views/partials/team-workspace-shell.php');
endif;

// ── Form body (shared between drawer and full-page render) ─────────────────
?>
<div
    class="staff-create-drawer-content"
    <?php if ($isDrawer): ?>
    data-drawer-content-root
    data-drawer-title="New Staff Member"
    data-drawer-subtitle="Step 1 of 4 — Employee Info"
    data-drawer-width="medium"
    <?php endif; ?>
>

<?php if (!$isDrawer): ?>
<!-- Full-page step navigator -->
<div class="staff-onboard-steps">
    <div class="staff-onboard-step staff-onboard-step--active">
        <span class="staff-onboard-step__num">1</span>
        <span class="staff-onboard-step__label">Employee Info</span>
    </div>
    <div class="staff-onboard-step">
        <span class="staff-onboard-step__num">2</span>
        <span class="staff-onboard-step__label">Compensation</span>
    </div>
    <div class="staff-onboard-step">
        <span class="staff-onboard-step__num">3</span>
        <span class="staff-onboard-step__label">Services</span>
    </div>
    <div class="staff-onboard-step">
        <span class="staff-onboard-step__num">4</span>
        <span class="staff-onboard-step__label">Schedule</span>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="staff-create-errors" role="alert">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div>
        <strong>Please correct the following:</strong>
        <ul class="staff-create-errors__list">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid'), ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<form
    method="post"
    action="/staff"
    enctype="multipart/form-data"
    class="staff-create-form"
    novalidate
>
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">

    <!-- ── Section: Identity ──────────────────────────────────────────────── -->
    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Identity</h3>

        <div class="staff-create-row-2">
            <div class="staff-create-field <?= $hasError('first_name') ? 'staff-create-field--error' : '' ?>">
                <label for="first_name" class="staff-create-label staff-create-label--required">First Name</label>
                <input type="text" id="first_name" name="first_name" class="staff-create-input"
                       value="<?= $v('first_name') ?>" maxlength="100" required autocomplete="given-name"
                       autofocus>
                <?php $showErr('first_name'); ?>
            </div>
            <div class="staff-create-field <?= $hasError('last_name') ? 'staff-create-field--error' : '' ?>">
                <label for="last_name" class="staff-create-label">Last Name</label>
                <input type="text" id="last_name" name="last_name" class="staff-create-input"
                       value="<?= $v('last_name') ?>" maxlength="100" autocomplete="family-name">
                <?php $showErr('last_name'); ?>
            </div>
        </div>

        <div class="staff-create-field">
            <label for="display_name" class="staff-create-label">Display Name</label>
            <input type="text" id="display_name" name="display_name" class="staff-create-input"
                   value="<?= $v('display_name') ?>" maxlength="200"
                   placeholder="Defaults to First + Last if left blank">
            <span class="staff-create-hint">Used on calendar, booking menu, and client-facing surfaces.</span>
        </div>

        <div class="staff-create-field <?= $hasError('gender') ? 'staff-create-field--error' : '' ?>">
            <label class="staff-create-label staff-create-label--required">Gender</label>
            <div class="staff-create-radio-group">
                <label class="staff-create-radio">
                    <input type="radio" name="gender" value="male"
                           <?= (($staff['gender'] ?? '') === 'male') ? 'checked' : '' ?>>
                    <span>Male</span>
                </label>
                <label class="staff-create-radio">
                    <input type="radio" name="gender" value="female"
                           <?= (($staff['gender'] ?? '') === 'female') ? 'checked' : '' ?>>
                    <span>Female</span>
                </label>
            </div>
            <?php $showErr('gender'); ?>
        </div>
    </div>

    <!-- ── Section: Login & Access ───────────────────────────────────────── -->
    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Login & Access</h3>

        <div class="staff-create-field <?= $hasError('email') ? 'staff-create-field--error' : '' ?>">
            <label for="email" class="staff-create-label staff-create-label--required">Email</label>
            <input type="email" id="email" name="email" class="staff-create-input"
                   value="<?= $v('email') ?>" maxlength="255" autocomplete="email">
            <?php $showErr('email'); ?>
        </div>

        <label class="staff-create-checkbox">
            <input type="checkbox" name="create_login_requested" value="1"
                   <?= !empty($staff['create_login_requested']) ? 'checked' : '' ?>>
            <span>Create login for this employee</span>
            <span class="staff-create-hint staff-create-hint--inline">(login setup will be completed in a later step)</span>
        </label>
    </div>

    <!-- ── Section: Role & Status ────────────────────────────────────────── -->
    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Role & Status</h3>

        <div class="staff-create-row-2">
            <div class="staff-create-field <?= $hasError('staff_type') ? 'staff-create-field--error' : '' ?>">
                <label for="staff_type" class="staff-create-label staff-create-label--required">Type</label>
                <select id="staff_type" name="staff_type" class="staff-create-select">
                    <option value="">— Select —</option>
                    <option value="freelancer" <?= (($staff['staff_type'] ?? '') === 'freelancer') ? 'selected' : '' ?>>Freelancer</option>
                    <option value="scheduled"  <?= (($staff['staff_type'] ?? '') === 'scheduled')  ? 'selected' : '' ?>>Scheduled</option>
                </select>
                <?php $showErr('staff_type'); ?>
            </div>

            <div class="staff-create-field <?= $hasError('status') ? 'staff-create-field--error' : '' ?>">
                <label for="status" class="staff-create-label staff-create-label--required">Status</label>
                <select id="status" name="status" class="staff-create-select">
                    <option value="">— Select —</option>
                    <option value="active"   <?= (($staff['status'] ?? '') === 'active')   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= (($staff['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                </select>
                <?php $showErr('status'); ?>
            </div>
        </div>

        <div class="staff-create-row-2">
            <div class="staff-create-field <?= $hasError('license_number') ? 'staff-create-field--error' : '' ?>">
                <label for="license_number" class="staff-create-label">License #</label>
                <input type="text" id="license_number" name="license_number" class="staff-create-input"
                       value="<?= $v('license_number') ?>" maxlength="100">
            </div>
            <div class="staff-create-field <?= $hasError('license_expiration_date') ? 'staff-create-field--error' : '' ?>">
                <label for="license_expiration_date" class="staff-create-label">License Expiry</label>
                <input type="date" id="license_expiration_date" name="license_expiration_date"
                       class="staff-create-input" value="<?= $v('license_expiration_date') ?>">
                <?php $showErr('license_expiration_date'); ?>
            </div>
        </div>

        <div class="staff-create-field">
            <label for="service_type_id" class="staff-create-label">Service Type</label>
            <select id="service_type_id" name="service_type_id" class="staff-create-select">
                <option value="">— None —</option>
                <?php foreach ($serviceTypes as $st): ?>
                <option value="<?= (int) $st['id'] ?>"
                    <?= (string) ($staff['service_type_id'] ?? '') === (string) $st['id'] ? 'selected' : '' ?>
                ><?= htmlspecialchars((string) $st['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($serviceTypes)): ?>
            <span class="staff-create-hint">No service types configured yet — can be set later.</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Section: Operational ──────────────────────────────────────────── -->
    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Operational</h3>

        <div class="staff-create-field <?= $hasError('max_appointments_per_day') ? 'staff-create-field--error' : '' ?>">
            <label for="max_appointments_per_day" class="staff-create-label">Max appointments per day</label>
            <select id="max_appointments_per_day" name="max_appointments_per_day" class="staff-create-select staff-create-select--narrow">
                <option value="">Unspecified</option>
                <?php for ($n = 1; $n <= 30; $n++): ?>
                <option value="<?= $n ?>" <?= (string) ($staff['max_appointments_per_day'] ?? '') === (string) $n ? 'selected' : '' ?>><?= $n ?></option>
                <?php endfor; ?>
            </select>
            <?php $showErr('max_appointments_per_day'); ?>
        </div>

        <label class="staff-create-checkbox">
            <input type="checkbox" id="specify_end_date" name="specify_end_date" value="1"
                   <?= !empty($staff['specify_end_date']) ? 'checked' : '' ?>
                   onchange="document.getElementById('staff-end-date-reveal').hidden = !this.checked">
            <span>Specify Employment End Date</span>
        </label>
        <div id="staff-end-date-reveal" class="staff-create-field <?= $hasError('employment_end_date') ? 'staff-create-field--error' : '' ?>"
             <?= empty($staff['specify_end_date']) ? 'hidden' : '' ?> style="margin-top:0.5rem;">
            <label for="employment_end_date" class="staff-create-label">Employment End Date</label>
            <input type="date" id="employment_end_date" name="employment_end_date"
                   class="staff-create-input" value="<?= $v('employment_end_date') ?>">
            <?php $showErr('employment_end_date'); ?>
        </div>
    </div>

    <!-- ── Section: Profile ──────────────────────────────────────────────── -->
    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Profile</h3>

        <div class="staff-create-row-2">
            <div class="staff-create-field <?= $hasError('photo') ? 'staff-create-field--error' : '' ?>">
                <label for="photo" class="staff-create-label">Photo</label>
                <input type="file" id="photo" name="photo" class="staff-create-file"
                       accept="image/jpeg,image/png,image/webp">
                <span class="staff-create-hint">JPG, PNG, WEBP</span>
                <?php $showErr('photo'); ?>
            </div>
            <div class="staff-create-field <?= $hasError('signature') ? 'staff-create-field--error' : '' ?>">
                <label for="signature" class="staff-create-label">Signature</label>
                <input type="file" id="signature" name="signature" class="staff-create-file"
                       accept="image/jpeg,image/png,image/webp">
                <span class="staff-create-hint">JPG, PNG, WEBP</span>
                <?php $showErr('signature'); ?>
            </div>
        </div>

        <div class="staff-create-field">
            <label for="profile_description" class="staff-create-label">Profile Description</label>
            <textarea id="profile_description" name="profile_description" class="staff-create-textarea" rows="3"><?= $v('profile_description') ?></textarea>
            <span class="staff-create-hint">Shown on the booking page for client-facing profiles.</span>
        </div>

        <div class="staff-create-field">
            <label for="employee_notes" class="staff-create-label">Internal Notes</label>
            <textarea id="employee_notes" name="employee_notes" class="staff-create-textarea" rows="2"><?= $v('employee_notes') ?></textarea>
            <span class="staff-create-hint">Admin-only. Not visible to clients.</span>
        </div>
    </div>

    <!-- ── Section: Contact ──────────────────────────────────────────────── -->
    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Contact Information</h3>

        <div class="staff-create-field">
            <label for="street_1" class="staff-create-label">Street 1</label>
            <input type="text" id="street_1" name="street_1" class="staff-create-input"
                   value="<?= $v('street_1') ?>" maxlength="200">
        </div>
        <div class="staff-create-field">
            <label for="street_2" class="staff-create-label">Street 2</label>
            <input type="text" id="street_2" name="street_2" class="staff-create-input"
                   value="<?= $v('street_2') ?>" maxlength="200">
        </div>

        <div class="staff-create-row-2">
            <div class="staff-create-field">
                <label for="city" class="staff-create-label">City</label>
                <input type="text" id="city" name="city" class="staff-create-input"
                       value="<?= $v('city') ?>" maxlength="100">
            </div>
            <div class="staff-create-field">
                <label for="postal_code" class="staff-create-label">Postal Code</label>
                <input type="text" id="postal_code" name="postal_code" class="staff-create-input"
                       value="<?= $v('postal_code') ?>" maxlength="20">
            </div>
        </div>

        <div class="staff-create-field">
            <label for="country" class="staff-create-label">Country</label>
            <select id="country" name="country" class="staff-create-select">
                <?php foreach ($countries as $code => $label): ?>
                <option value="<?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?>"
                    <?= ($staff['country'] ?? '') === $code ? 'selected' : '' ?>
                ><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="staff-create-row-2">
            <div class="staff-create-field">
                <label for="home_phone" class="staff-create-label">Home Phone</label>
                <input type="tel" id="home_phone" name="home_phone" class="staff-create-input"
                       value="<?= $v('home_phone') ?>" maxlength="50" autocomplete="home tel">
            </div>
            <div class="staff-create-field">
                <label for="mobile_phone" class="staff-create-label">Mobile Phone</label>
                <input type="tel" id="mobile_phone" name="mobile_phone" class="staff-create-input"
                       value="<?= $v('mobile_phone') ?>" maxlength="50" autocomplete="mobile tel">
            </div>
        </div>

        <div class="staff-create-field">
            <label class="staff-create-label">Preferred Phone</label>
            <div class="staff-create-radio-group">
                <label class="staff-create-radio">
                    <input type="radio" name="preferred_phone" value="home"
                           <?= (($staff['preferred_phone'] ?? '') === 'home') ? 'checked' : '' ?>>
                    <span>Home</span>
                </label>
                <label class="staff-create-radio">
                    <input type="radio" name="preferred_phone" value="mobile"
                           <?= (($staff['preferred_phone'] ?? '') === 'mobile') ? 'checked' : '' ?>>
                    <span>Mobile</span>
                </label>
            </div>
        </div>

        <label class="staff-create-checkbox">
            <input type="checkbox" name="sms_opt_in" value="1"
                   <?= !empty($staff['sms_opt_in']) ? 'checked' : '' ?>>
            <span>Allow SMS notifications</span>
            <span class="staff-create-hint staff-create-hint--inline">(requires system configuration)</span>
        </label>
    </div>

    <!-- ── Form actions ──────────────────────────────────────────────────── -->
    <div class="staff-create-actions">
        <a href="/staff" class="staff-create-btn-cancel">Cancel</a>
        <button type="submit" class="staff-create-btn-submit">
            Continue to Step 2
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
    </div>

</form>
</div><!-- /.staff-create-drawer-content -->

<?php if (!$isDrawer):
    $content = ob_get_clean();
    require shared_path('layout/base.php');
endif; ?>
