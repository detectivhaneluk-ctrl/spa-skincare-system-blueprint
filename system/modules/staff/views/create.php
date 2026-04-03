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

ob_start();
?>
<div class="wizard-layout">

    <?php /* Step indicator */ ?>
    <nav class="wizard-steps" aria-label="Onboarding steps">
        <ol class="wizard-steps__list">
            <li class="wizard-steps__item wizard-steps__item--active" aria-current="step">
                <span class="wizard-steps__number">1</span>
                <span class="wizard-steps__label">Employee Info</span>
            </li>
            <li class="wizard-steps__item wizard-steps__item--pending">
                <span class="wizard-steps__number">2</span>
                <span class="wizard-steps__label">Step 2</span>
            </li>
            <li class="wizard-steps__item wizard-steps__item--pending">
                <span class="wizard-steps__number">3</span>
                <span class="wizard-steps__label">Step 3</span>
            </li>
            <li class="wizard-steps__item wizard-steps__item--pending">
                <span class="wizard-steps__number">4</span>
                <span class="wizard-steps__label">Step 4</span>
            </li>
        </ol>
    </nav>

    <div class="wizard-body">
        <header class="wizard-body__header">
            <h1 class="wizard-body__title">New Employee</h1>
            <p class="wizard-body__subtitle">Enter Employee Info &mdash; Step 1 of 4</p>
        </header>

        <?php if (!empty($errors)): ?>
        <div class="form-errors" role="alert">
            <strong>Please correct the following:</strong>
            <ul>
                <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid'), ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form
            method="post"
            action="/staff"
            enctype="multipart/form-data"
            class="wizard-form"
            novalidate
        >
            <input
                type="hidden"
                name="<?= htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'), ENT_QUOTES, 'UTF-8') ?>"
                value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8') ?>"
            >

            <?php /* ── Name block ── */ ?>
            <div class="wizard-form__section">
                <div class="form-row <?= $hasError('first_name') ? 'form-row--error' : '' ?>">
                    <label for="first_name" class="form-label form-label--required">First Name</label>
                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        class="form-input"
                        value="<?= $v('first_name') ?>"
                        maxlength="100"
                        required
                        autocomplete="given-name"
                    >
                    <?php $showErr('first_name'); ?>
                </div>

                <div class="form-row <?= $hasError('last_name') ? 'form-row--error' : '' ?>">
                    <label for="last_name" class="form-label">Last Name</label>
                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        class="form-input"
                        value="<?= $v('last_name') ?>"
                        maxlength="100"
                        autocomplete="family-name"
                    >
                    <?php $showErr('last_name'); ?>
                </div>

                <div class="form-row">
                    <label for="display_name" class="form-label">Display Name</label>
                    <input
                        type="text"
                        id="display_name"
                        name="display_name"
                        class="form-input"
                        value="<?= $v('display_name') ?>"
                        maxlength="200"
                        placeholder="Defaults to First + Last if left blank"
                    >
                </div>
            </div>

            <?php /* ── Gender ── */ ?>
            <div class="wizard-form__section">
                <fieldset class="form-fieldset <?= $hasError('gender') ? 'form-row--error' : '' ?>">
                    <legend class="form-label form-label--required">Gender</legend>
                    <div class="form-radio-group">
                        <label class="form-radio-label">
                            <input
                                type="radio"
                                name="gender"
                                value="male"
                                <?= (($staff['gender'] ?? '') === 'male') ? 'checked' : '' ?>
                            > Male
                        </label>
                        <label class="form-radio-label">
                            <input
                                type="radio"
                                name="gender"
                                value="female"
                                <?= (($staff['gender'] ?? '') === 'female') ? 'checked' : '' ?>
                            > Female
                        </label>
                    </div>
                    <?php $showErr('gender'); ?>
                </fieldset>
            </div>

            <?php /* ── Email + Login ── */ ?>
            <div class="wizard-form__section">
                <div class="form-row <?= $hasError('email') ? 'form-row--error' : '' ?>">
                    <label for="email" class="form-label form-label--required">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-input"
                        value="<?= $v('email') ?>"
                        maxlength="255"
                        autocomplete="email"
                    >
                    <?php $showErr('email'); ?>
                </div>

                <div class="form-row">
                    <label class="form-checkbox-label">
                        <input
                            type="checkbox"
                            name="create_login_requested"
                            value="1"
                            <?= !empty($staff['create_login_requested']) ? 'checked' : '' ?>
                        >
                        Create login for this employee
                        <span class="form-hint">(login setup will be completed in a later step)</span>
                    </label>
                </div>
            </div>

            <?php /* ── Type + Status ── */ ?>
            <div class="wizard-form__section wizard-form__section--inline">
                <div class="form-row <?= $hasError('staff_type') ? 'form-row--error' : '' ?>">
                    <label for="staff_type" class="form-label form-label--required">Type</label>
                    <select id="staff_type" name="staff_type" class="form-select">
                        <option value="">— Select —</option>
                        <option value="freelancer"  <?= (($staff['staff_type'] ?? '') === 'freelancer')  ? 'selected' : '' ?>>Freelancer</option>
                        <option value="scheduled"   <?= (($staff['staff_type'] ?? '') === 'scheduled')   ? 'selected' : '' ?>>Scheduled</option>
                    </select>
                    <?php $showErr('staff_type'); ?>
                </div>

                <div class="form-row <?= $hasError('status') ? 'form-row--error' : '' ?>">
                    <label for="status" class="form-label form-label--required">Status</label>
                    <select id="status" name="status" class="form-select">
                        <option value="">— Select —</option>
                        <option value="active"   <?= (($staff['status'] ?? '') === 'active')   ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= (($staff['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <?php $showErr('status'); ?>
                </div>
            </div>

            <?php /* ── Employment end date ── */ ?>
            <div class="wizard-form__section">
                <div class="form-row">
                    <label class="form-checkbox-label">
                        <input
                            type="checkbox"
                            id="specify_end_date"
                            name="specify_end_date"
                            value="1"
                            <?= !empty($staff['specify_end_date']) ? 'checked' : '' ?>
                            onchange="document.getElementById('end-date-reveal').hidden = !this.checked"
                        >
                        Specify Employment End Date
                    </label>
                </div>
                <div id="end-date-reveal" class="form-row <?= $hasError('employment_end_date') ? 'form-row--error' : '' ?>" <?= empty($staff['specify_end_date']) ? 'hidden' : '' ?>>
                    <label for="employment_end_date" class="form-label">Employment End Date</label>
                    <input
                        type="date"
                        id="employment_end_date"
                        name="employment_end_date"
                        class="form-input form-input--date"
                        value="<?= $v('employment_end_date') ?>"
                    >
                    <?php $showErr('employment_end_date'); ?>
                </div>
            </div>

            <?php /* ── Max appointments ── */ ?>
            <div class="wizard-form__section">
                <div class="form-row <?= $hasError('max_appointments_per_day') ? 'form-row--error' : '' ?>">
                    <label for="max_appointments_per_day" class="form-label">Max # of appointments staff member can have per day</label>
                    <select id="max_appointments_per_day" name="max_appointments_per_day" class="form-select">
                        <option value="">Unspecified</option>
                        <?php for ($n = 1; $n <= 30; $n++): ?>
                        <option value="<?= $n ?>" <?= (string) ($staff['max_appointments_per_day'] ?? '') === (string) $n ? 'selected' : '' ?>><?= $n ?></option>
                        <?php endfor; ?>
                    </select>
                    <?php $showErr('max_appointments_per_day'); ?>
                </div>
            </div>

            <?php /* ── Photo + Signature uploads ── */ ?>
            <div class="wizard-form__section">
                <div class="form-row <?= $hasError('photo') ? 'form-row--error' : '' ?>">
                    <label for="photo" class="form-label">Photo</label>
                    <input
                        type="file"
                        id="photo"
                        name="photo"
                        class="form-input-file"
                        accept="image/jpeg,image/png,image/webp"
                    >
                    <span class="form-hint">Accepted formats: JPG, PNG, WEBP. Max size determined by server settings.</span>
                    <?php $showErr('photo'); ?>
                </div>

                <div class="form-row <?= $hasError('signature') ? 'form-row--error' : '' ?>">
                    <label for="signature" class="form-label">Signature</label>
                    <input
                        type="file"
                        id="signature"
                        name="signature"
                        class="form-input-file"
                        accept="image/jpeg,image/png,image/webp"
                    >
                    <span class="form-hint">Accepted formats: JPG, PNG, WEBP.</span>
                    <?php $showErr('signature'); ?>
                </div>
            </div>

            <?php /* ── Profile description + notes ── */ ?>
            <div class="wizard-form__section">
                <div class="form-row">
                    <label for="profile_description" class="form-label">Profile Description</label>
                    <textarea id="profile_description" name="profile_description" class="form-textarea" rows="4"><?= $v('profile_description') ?></textarea>
                </div>

                <div class="form-row">
                    <label for="employee_notes" class="form-label">Employee Notes</label>
                    <textarea id="employee_notes" name="employee_notes" class="form-textarea" rows="4"><?= $v('employee_notes') ?></textarea>
                </div>
            </div>

            <?php /* ── License ── */ ?>
            <div class="wizard-form__section wizard-form__section--inline">
                <div class="form-row">
                    <label for="license_number" class="form-label">License #</label>
                    <input
                        type="text"
                        id="license_number"
                        name="license_number"
                        class="form-input"
                        value="<?= $v('license_number') ?>"
                        maxlength="100"
                    >
                </div>

                <div class="form-row <?= $hasError('license_expiration_date') ? 'form-row--error' : '' ?>">
                    <label for="license_expiration_date" class="form-label">License Expiration Date</label>
                    <input
                        type="date"
                        id="license_expiration_date"
                        name="license_expiration_date"
                        class="form-input form-input--date"
                        value="<?= $v('license_expiration_date') ?>"
                    >
                    <?php $showErr('license_expiration_date'); ?>
                </div>
            </div>

            <?php /* ── Service Type ── */ ?>
            <div class="wizard-form__section">
                <div class="form-row">
                    <label for="service_type_id" class="form-label">Service Type</label>
                    <select id="service_type_id" name="service_type_id" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($serviceTypes as $st): ?>
                        <option
                            value="<?= (int) $st['id'] ?>"
                            <?= (string) ($staff['service_type_id'] ?? '') === (string) $st['id'] ? 'selected' : '' ?>
                        ><?= htmlspecialchars((string) $st['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($serviceTypes)): ?>
                    <span class="form-hint">No service types configured yet. This can be set up later.</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php /* ── Contact Information ── */ ?>
            <div class="wizard-form__section wizard-form__section--group">
                <h2 class="wizard-form__section-heading">Contact Information</h2>

                <div class="form-row">
                    <label for="street_1" class="form-label">Street 1</label>
                    <input type="text" id="street_1" name="street_1" class="form-input" value="<?= $v('street_1') ?>" maxlength="200">
                </div>

                <div class="form-row">
                    <label for="street_2" class="form-label">Street 2</label>
                    <input type="text" id="street_2" name="street_2" class="form-input" value="<?= $v('street_2') ?>" maxlength="200">
                </div>

                <div class="wizard-form__section--inline">
                    <div class="form-row">
                        <label for="city" class="form-label">City</label>
                        <input type="text" id="city" name="city" class="form-input" value="<?= $v('city') ?>" maxlength="100">
                    </div>

                    <div class="form-row">
                        <label for="postal_code" class="form-label">Postal Code</label>
                        <input type="text" id="postal_code" name="postal_code" class="form-input" value="<?= $v('postal_code') ?>" maxlength="20">
                    </div>
                </div>

                <div class="form-row">
                    <label for="country" class="form-label">Country</label>
                    <select id="country" name="country" class="form-select">
                        <?php foreach ($countries as $code => $label): ?>
                        <option
                            value="<?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?>"
                            <?= ($staff['country'] ?? '') === $code ? 'selected' : '' ?>
                        ><?= htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wizard-form__section--inline">
                    <div class="form-row">
                        <label for="home_phone" class="form-label">Home Phone</label>
                        <input type="tel" id="home_phone" name="home_phone" class="form-input" value="<?= $v('home_phone') ?>" maxlength="50" autocomplete="home tel">
                    </div>

                    <div class="form-row">
                        <label for="mobile_phone" class="form-label">Mobile Phone</label>
                        <input type="tel" id="mobile_phone" name="mobile_phone" class="form-input" value="<?= $v('mobile_phone') ?>" maxlength="50" autocomplete="mobile tel">
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Preferred Phone</label>
                    <div class="form-radio-group">
                        <label class="form-radio-label">
                            <input
                                type="radio"
                                name="preferred_phone"
                                value="home"
                                <?= (($staff['preferred_phone'] ?? '') === 'home') ? 'checked' : '' ?>
                            > Home
                        </label>
                        <label class="form-radio-label">
                            <input
                                type="radio"
                                name="preferred_phone"
                                value="mobile"
                                <?= (($staff['preferred_phone'] ?? '') === 'mobile') ? 'checked' : '' ?>
                            > Mobile
                        </label>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-checkbox-label">
                        <input
                            type="checkbox"
                            name="sms_opt_in"
                            value="1"
                            <?= !empty($staff['sms_opt_in']) ? 'checked' : '' ?>
                        >
                        Allow SMS notifications
                        <span class="form-hint">(SMS delivery requires system configuration)</span>
                    </label>
                </div>
            </div>

            <?php /* ── Form actions ── */ ?>
            <div class="wizard-form__actions">
                <a href="/staff" class="btn btn--secondary">Cancel</a>
                <button type="submit" class="btn btn--primary">Continue</button>
            </div>

        </form>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
