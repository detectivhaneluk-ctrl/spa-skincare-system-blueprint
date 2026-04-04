<?php
$title = 'Edit Employee — ' . htmlspecialchars((string) ($staff['display_name'] ?? ($staff['first_name'] ?? 'Staff')), ENT_QUOTES, 'UTF-8');

$activeTab = $activeTab ?? 'basic';

// Country list — mirrors create.php
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

$payTypeLabels = [
    'none'                             => 'None',
    'flat_hourly'                      => 'Flat Hourly',
    'salary'                           => 'Salary',
    'commission'                       => 'Commission',
    'combination'                      => 'Combination',
    'per_service_fee'                  => 'Per Service Fee (specified by service)',
    'per_service_fee_with_bonus'       => 'Per Service Fee with Bonus (for employees with seniority)',
    'per_service_fee_by_employee'      => 'Per Service Fee by Employee',
    'service_commission_by_sales_tier' => 'Service Commission by Sales Tier',
];

$payTypeClassesLabels = [
    'same_as_services'       => 'Same as Services',
    'commission_by_attendee' => 'Commission by Attendee',
];

$payTypeProductsLabels = [
    'none'                     => 'None',
    'commission'               => 'Commission',
    'commission_by_sales_tier' => 'Commission by Sales Tier',
    'per_product_fee'          => 'Per Product Fee (specified for each product)',
];

$displayOrder = [1, 2, 3, 4, 5, 6, 0];
$dayLabels    = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
$defaultWorking = [1, 2, 3, 4, 5];

$v = static function (string $key) use ($staff): string {
    return htmlspecialchars((string) ($staff[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
$hasError = static function (string $key) use ($errors): bool { return isset($errors[$key]); };
$showErr  = static function (string $key) use ($errors): void {
    if (isset($errors[$key])) {
        echo '<span class="form-field-error">' . htmlspecialchars((string) $errors[$key], ENT_QUOTES, 'UTF-8') . '</span>';
    }
};

ob_start();
?>
<div class="profile-editor">

    <header class="profile-editor__header">
        <div class="profile-editor__identity">
            <h1 class="profile-editor__name"><?= htmlspecialchars((string) ($staff['display_name'] ?? ($staff['first_name'] . ' ' . $staff['last_name'])), ENT_QUOTES, 'UTF-8') ?></h1>
            <span class="profile-editor__meta"><?= htmlspecialchars($staff['job_title'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
            <?php if (empty($staff['is_active'])): ?>
            <span class="badge badge--inactive">Inactive</span>
            <?php else: ?>
            <span class="badge badge--active">Active</span>
            <?php endif; ?>
        </div>
        <a href="/staff/<?= (int) $staff['id'] ?>" class="btn btn--ghost btn--sm">&larr; Back to profile</a>
    </header>

    <?php if (!empty($flash['success'])): ?>
    <div class="flash flash--success" role="status"><?= htmlspecialchars((string) $flash['success'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($flash['error'])): ?>
    <div class="flash flash--error" role="alert"><?= htmlspecialchars((string) $flash['error'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($errors['_general'])): ?>
    <div class="flash flash--error" role="alert"><?= htmlspecialchars((string) $errors['_general'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <nav class="profile-tabs" role="tablist" aria-label="Employee profile sections" id="profile-tab-nav">
        <button type="button" class="profile-tab" role="tab" data-tab="basic"        <?= $activeTab === 'basic'        ? 'aria-selected="true"' : 'aria-selected="false"' ?>>Employee Info</button>
        <button type="button" class="profile-tab" role="tab" data-tab="compensation" <?= $activeTab === 'compensation'  ? 'aria-selected="true"' : 'aria-selected="false"' ?>>Compensation</button>
        <button type="button" class="profile-tab" role="tab" data-tab="services"     <?= $activeTab === 'services'      ? 'aria-selected="true"' : 'aria-selected="false"' ?>>Services</button>
        <button type="button" class="profile-tab" role="tab" data-tab="schedule"     <?= $activeTab === 'schedule'      ? 'aria-selected="true"' : 'aria-selected="false"' ?>>Regular Schedule</button>
        <button type="button" class="profile-tab" role="tab" data-tab="history"      <?= $activeTab === 'history'       ? 'aria-selected="true"' : 'aria-selected="false"' ?>>History</button>
    </nav>

    <?php /* ─────────────────────────── TAB: EMPLOYEE INFO + COMPENSATION ─────────────── */ ?>
    <?php /* Both sections share one form so they save together, preventing field drift.    */ ?>
    <form
        method="post"
        action="/staff/<?= (int) $staff['id'] ?>"
        class="entity-form profile-form"
        enctype="multipart/form-data"
        id="profile-basic-form"
        novalidate
    >
        <input type="hidden" name="<?= htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8') ?>">

        <?php /* ── Employee Info tab panel ── */ ?>
        <div class="profile-panel" data-panel="basic" <?= $activeTab !== 'basic' ? 'hidden' : '' ?>>

            <div class="form-section">
                <div class="form-section__header">
                    <h2 class="form-section__title">Identity</h2>
                </div>
                <div class="form-row <?= $hasError('first_name') ? 'form-row--error' : '' ?>">
                    <label for="edit_first_name" class="form-label">First Name <span aria-hidden="true">*</span></label>
                    <input type="text" id="edit_first_name" name="first_name" class="form-input" value="<?= $v('first_name') ?>" required>
                    <?php $showErr('first_name'); ?>
                </div>
                <div class="form-row <?= $hasError('last_name') ? 'form-row--error' : '' ?>">
                    <label for="edit_last_name" class="form-label">Last Name</label>
                    <input type="text" id="edit_last_name" name="last_name" class="form-input" value="<?= $v('last_name') ?>">
                    <?php $showErr('last_name'); ?>
                </div>
                <div class="form-row <?= $hasError('display_name') ? 'form-row--error' : '' ?>">
                    <label for="edit_display_name" class="form-label">Display Name</label>
                    <input type="text" id="edit_display_name" name="display_name" class="form-input" value="<?= $v('display_name') ?>" maxlength="200" placeholder="Shown on calendar; defaults to first + last name">
                    <?php $showErr('display_name'); ?>
                </div>
                <div class="form-row <?= $hasError('gender') ? 'form-row--error' : '' ?>">
                    <span class="form-label">Gender</span>
                    <div class="radio-group">
                        <label><input type="radio" name="gender" value="male"   <?= ($staff['gender'] ?? '') === 'male'   ? 'checked' : '' ?>> Male</label>
                        <label><input type="radio" name="gender" value="female" <?= ($staff['gender'] ?? '') === 'female' ? 'checked' : '' ?>> Female</label>
                        <label><input type="radio" name="gender" value=""       <?= (($staff['gender'] ?? '') === '')    ? 'checked' : '' ?>> Not specified</label>
                    </div>
                    <?php $showErr('gender'); ?>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section__header">
                    <h2 class="form-section__title">Contact</h2>
                </div>
                <div class="form-row <?= $hasError('email') ? 'form-row--error' : '' ?>">
                    <label for="edit_email" class="form-label">Email</label>
                    <input type="email" id="edit_email" name="email" class="form-input" value="<?= $v('email') ?>">
                    <?php $showErr('email'); ?>
                </div>
                <div class="form-row">
                    <label for="edit_home_phone" class="form-label">Home Phone</label>
                    <input type="text" id="edit_home_phone" name="home_phone" class="form-input" value="<?= $v('home_phone') ?>">
                </div>
                <div class="form-row">
                    <label for="edit_mobile_phone" class="form-label">Mobile Phone</label>
                    <input type="text" id="edit_mobile_phone" name="mobile_phone" class="form-input" value="<?= $v('mobile_phone') ?>">
                </div>
                <div class="form-row">
                    <span class="form-label">Preferred Phone</span>
                    <div class="radio-group">
                        <label><input type="radio" name="preferred_phone" value="home"   <?= ($staff['preferred_phone'] ?? '') === 'home'   ? 'checked' : '' ?>> Home</label>
                        <label><input type="radio" name="preferred_phone" value="mobile" <?= ($staff['preferred_phone'] ?? '') === 'mobile' ? 'checked' : '' ?>> Mobile</label>
                    </div>
                </div>
                <div class="form-row">
                    <label><input type="checkbox" name="sms_opt_in" value="1" <?= !empty($staff['sms_opt_in']) ? 'checked' : '' ?>> SMS opt-in</label>
                </div>
                <div class="form-row">
                    <label for="edit_street_1" class="form-label">Street 1</label>
                    <input type="text" id="edit_street_1" name="street_1" class="form-input" value="<?= $v('street_1') ?>">
                </div>
                <div class="form-row">
                    <label for="edit_street_2" class="form-label">Street 2</label>
                    <input type="text" id="edit_street_2" name="street_2" class="form-input" value="<?= $v('street_2') ?>">
                </div>
                <div class="form-row">
                    <label for="edit_city" class="form-label">City</label>
                    <input type="text" id="edit_city" name="city" class="form-input" value="<?= $v('city') ?>">
                </div>
                <div class="form-row">
                    <label for="edit_postal_code" class="form-label">Postal Code</label>
                    <input type="text" id="edit_postal_code" name="postal_code" class="form-input" value="<?= $v('postal_code') ?>" maxlength="20">
                </div>
                <div class="form-row">
                    <label for="edit_country" class="form-label">Country</label>
                    <select id="edit_country" name="country" class="form-select">
                        <?php foreach ($countries as $code => $label): ?>
                        <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= ($staff['country'] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section__header">
                    <h2 class="form-section__title">Employment</h2>
                </div>
                <div class="form-row <?= $hasError('job_title') ? 'form-row--error' : '' ?>">
                    <label for="edit_job_title" class="form-label">Job Title</label>
                    <input type="text" id="edit_job_title" name="job_title" class="form-input" value="<?= $v('job_title') ?>">
                    <?php $showErr('job_title'); ?>
                </div>
                <div class="form-row <?= $hasError('staff_type') ? 'form-row--error' : '' ?>">
                    <label for="edit_staff_type" class="form-label">Type</label>
                    <select id="edit_staff_type" name="staff_type" class="form-select">
                        <option value="">— Select —</option>
                        <option value="freelancer" <?= ($staff['staff_type'] ?? '') === 'freelancer' ? 'selected' : '' ?>>Freelancer</option>
                        <option value="scheduled"  <?= ($staff['staff_type'] ?? '') === 'scheduled'  ? 'selected' : '' ?>>Scheduled</option>
                    </select>
                    <?php $showErr('staff_type'); ?>
                </div>
                <div class="form-row">
                    <label><input type="checkbox" name="is_active" value="1" <?= !empty($staff['is_active']) ? 'checked' : '' ?>> Active</label>
                    <span class="form-hint">Inactive staff will not appear on the calendar or in appointment booking.</span>
                </div>
                <div class="form-row">
                    <label>
                        <input type="checkbox" name="specify_end_date" value="1" id="edit_specify_end_date"
                            <?= !empty($staff['employment_end_date']) ? 'checked' : '' ?>>
                        Specify Employment End Date
                    </label>
                </div>
                <div class="form-row" id="edit_end_date_row" <?= empty($staff['employment_end_date']) ? 'style="display:none"' : '' ?>>
                    <label for="edit_employment_end_date" class="form-label">Employment End Date</label>
                    <input type="date" id="edit_employment_end_date" name="employment_end_date" class="form-input" value="<?= $v('employment_end_date') ?>">
                </div>
                <div class="form-row">
                    <label for="edit_max_appt" class="form-label">Max Appointments Per Day</label>
                    <input type="number" id="edit_max_appt" name="max_appointments_per_day" class="form-input" value="<?= $v('max_appointments_per_day') ?>" min="1" max="999" placeholder="Unspecified">
                </div>
                <div class="form-row">
                    <label for="edit_service_type_id" class="form-label">Service Type</label>
                    <select id="edit_service_type_id" name="service_type_id" class="form-select">
                        <option value="">— Select —</option>
                        <?php foreach ($serviceTypes as $st): ?>
                        <option value="<?= (int) $st['id'] ?>" <?= (string) ($staff['service_type_id'] ?? '') === (string) $st['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $st['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-section">
                <div class="form-section__header">
                    <h2 class="form-section__title">Professional Details</h2>
                </div>
                <div class="form-row">
                    <label for="edit_license_number" class="form-label">License #</label>
                    <input type="text" id="edit_license_number" name="license_number" class="form-input" value="<?= $v('license_number') ?>" maxlength="100">
                </div>
                <div class="form-row">
                    <label for="edit_license_expiration_date" class="form-label">License Expiration Date</label>
                    <input type="date" id="edit_license_expiration_date" name="license_expiration_date" class="form-input" value="<?= $v('license_expiration_date') ?>">
                </div>
                <div class="form-row">
                    <label for="edit_profile_description" class="form-label">Profile Description</label>
                    <textarea id="edit_profile_description" name="profile_description" class="form-textarea" rows="3"><?= $v('profile_description') ?></textarea>
                </div>
                <div class="form-row">
                    <label for="edit_employee_notes" class="form-label">Employee Notes</label>
                    <textarea id="edit_employee_notes" name="employee_notes" class="form-textarea" rows="3"><?= $v('employee_notes') ?></textarea>
                </div>
                <div class="form-row">
                    <label for="edit_user_id" class="form-label">Linked User Account</label>
                    <select id="edit_user_id" name="user_id" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= ($staff['user_id'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name'] . ' (' . $u['email'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label><input type="checkbox" name="create_login_requested" value="1" <?= !empty($staff['create_login_requested']) ? 'checked' : '' ?>> Create Login for this Employee</label>
                </div>
            </div>

            <div class="form-actions">
                <a href="/staff/<?= (int) $staff['id'] ?>" class="btn btn--ghost">Cancel</a>
                <button type="submit" name="_tab" value="basic" class="btn btn--primary">Save Employee Info</button>
            </div>
        </div>

        <?php /* ── Compensation tab panel ── */ ?>
        <div class="profile-panel" data-panel="compensation" <?= $activeTab !== 'compensation' ? 'hidden' : '' ?>>

            <div class="form-section">
                <div class="form-section__header">
                    <h2 class="form-section__title">Compensation and Benefits</h2>
                </div>

                <div class="form-row">
                    <label for="edit_primary_group_id" class="form-label">Group</label>
                    <select id="edit_primary_group_id" name="primary_group_id" class="form-select">
                        <option value="">— No group assigned —</option>
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= (int) $g['id'] ?>" <?= (string) ($staff['primary_group_id'] ?? '') === (string) $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $g['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <span class="form-label">Pay Type</span>
                    <div class="radio-group radio-group--stacked">
                        <?php foreach ($payTypeLabels as $ptVal => $ptLabel): ?>
                        <label><input type="radio" name="pay_type" value="<?= htmlspecialchars($ptVal, ENT_QUOTES) ?>" <?= ($staff['pay_type'] ?? '') === $ptVal ? 'checked' : '' ?>> <?= htmlspecialchars($ptLabel, ENT_QUOTES, 'UTF-8') ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-row">
                    <span class="form-label">Pay Type — Classes / Workshops</span>
                    <div class="radio-group">
                        <?php foreach ($payTypeClassesLabels as $ptcVal => $ptcLabel): ?>
                        <label><input type="radio" name="pay_type_classes" value="<?= htmlspecialchars($ptcVal, ENT_QUOTES) ?>" <?= ($staff['pay_type_classes'] ?? '') === $ptcVal ? 'checked' : '' ?>> <?= htmlspecialchars($ptcLabel, ENT_QUOTES, 'UTF-8') ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-row">
                    <span class="form-label">Pay Type — Products</span>
                    <div class="radio-group radio-group--stacked">
                        <?php foreach ($payTypeProductsLabels as $ptpVal => $ptpLabel): ?>
                        <label><input type="radio" name="pay_type_products" value="<?= htmlspecialchars($ptpVal, ENT_QUOTES) ?>" <?= ($staff['pay_type_products'] ?? '') === $ptpVal ? 'checked' : '' ?>> <?= htmlspecialchars($ptpLabel, ENT_QUOTES, 'UTF-8') ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-row <?= $hasError('vacation_days') ? 'form-row--error' : '' ?>">
                    <label for="edit_vacation_days" class="form-label">Vacation Days</label>
                    <input type="number" id="edit_vacation_days" name="vacation_days" class="form-input form-input--short" value="<?= $v('vacation_days') ?>" min="0">
                    <?php $showErr('vacation_days'); ?>
                </div>
                <div class="form-row <?= $hasError('sick_days') ? 'form-row--error' : '' ?>">
                    <label for="edit_sick_days" class="form-label">Sick Days</label>
                    <input type="number" id="edit_sick_days" name="sick_days" class="form-input form-input--short" value="<?= $v('sick_days') ?>" min="0">
                    <?php $showErr('sick_days'); ?>
                </div>
                <div class="form-row <?= $hasError('personal_days') ? 'form-row--error' : '' ?>">
                    <label for="edit_personal_days" class="form-label">Personal Days</label>
                    <input type="number" id="edit_personal_days" name="personal_days" class="form-input form-input--short" value="<?= $v('personal_days') ?>" min="0">
                    <?php $showErr('personal_days'); ?>
                </div>
                <div class="form-row">
                    <label for="edit_employee_number" class="form-label">Employee ID</label>
                    <input type="text" id="edit_employee_number" name="employee_number" class="form-input" value="<?= $v('employee_number') ?>" maxlength="100">
                </div>
                <div class="form-row">
                    <span class="form-label">Exemptions</span>
                    <div class="checkbox-group">
                        <label><input type="checkbox" name="has_dependents" value="1" <?= !empty($staff['has_dependents']) ? 'checked' : '' ?>> Has Dependents</label>
                        <label><input type="checkbox" name="is_exempt" value="1" <?= !empty($staff['is_exempt']) ? 'checked' : '' ?>> Exempt</label>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="/staff/<?= (int) $staff['id'] ?>" class="btn btn--ghost">Cancel</a>
                <button type="submit" name="_tab" value="compensation" class="btn btn--primary">Save Compensation</button>
            </div>
        </div>
    </form>

    <?php /* ─────────────────────────── TAB: SERVICES ─────────────────────────────────── */ ?>
    <div class="profile-panel" data-panel="services" <?= $activeTab !== 'services' ? 'hidden' : '' ?>>
        <form method="post" action="/staff/<?= (int) $staff['id'] ?>/profile/services" class="entity-form profile-form" id="profile-services-form">
            <input type="hidden" name="<?= htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8') ?>">

            <?php if (!empty($errors['_services_general'])): ?>
            <div class="flash flash--error" role="alert"><?= htmlspecialchars((string) $errors['_services_general'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="form-section">
                <div class="form-section__header">
                    <h2 class="form-section__title">Assigned Services</h2>
                    <p class="form-section__hint">Select which services this employee is authorised to perform. Changes affect appointment eligibility immediately.</p>
                </div>

                <?php if (empty($serviceGroups)): ?>
                <div class="wizard-placeholder">
                    <p class="wizard-placeholder__body">No services have been set up for this branch. <a href="/services/create">Add services</a> first.</p>
                </div>
                <?php else: ?>
                <div class="service-assignment-toolbar">
                    <button type="button" class="btn btn--sm btn--secondary" id="profile-select-all">Select All</button>
                    <button type="button" class="btn btn--sm btn--secondary" id="profile-clear-all">Clear All</button>
                </div>
                <div class="service-assignment-groups">
                    <?php foreach ($serviceGroups as $groupKey => $group): ?>
                    <div class="service-group" data-group="<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="service-group__header">
                            <h3 class="service-group__name"><?= htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <div class="service-group__actions">
                                <button type="button" class="btn btn--xs btn--ghost btn-group-select" data-action="select">Select all in group</button>
                                <button type="button" class="btn btn--xs btn--ghost btn-group-select" data-action="clear">Clear group</button>
                            </div>
                        </div>
                        <ul class="service-checklist">
                            <?php foreach ($group['services'] as $svc): ?>
                            <?php $svcId = (int) $svc['id']; ?>
                            <li class="service-checklist__item">
                                <label class="service-checklist__label">
                                    <input type="checkbox" name="service_ids[]" value="<?= $svcId ?>" class="service-checklist__checkbox" <?= isset($assignedIds[$svcId]) ? 'checked' : '' ?>>
                                    <span class="service-checklist__name"><?= htmlspecialchars((string) ($svc['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($svc['duration_minutes'])): ?><span class="service-checklist__meta"><?= (int) $svc['duration_minutes'] ?> min</span><?php endif; ?>
                                </label>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <a href="/staff/<?= (int) $staff['id'] ?>" class="btn btn--ghost">Cancel</a>
                <button type="submit" class="btn btn--primary">Save Services</button>
            </div>
        </form>
    </div>

    <?php /* ─────────────────────────── TAB: REGULAR SCHEDULE ──────────────────────────── */ ?>
    <div class="profile-panel" data-panel="schedule" <?= $activeTab !== 'schedule' ? 'hidden' : '' ?>>
        <form method="post" action="/staff/<?= (int) $staff['id'] ?>/profile/schedule" class="entity-form profile-form" id="profile-schedule-form">
            <input type="hidden" name="<?= htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8') ?>">

            <?php if (!empty($errors['_schedule_general'])): ?>
            <div class="flash flash--error" role="alert"><?= htmlspecialchars((string) $errors['_schedule_general'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="form-section">
                <div class="form-section__header">
                    <h2 class="form-section__title">Default Weekly Schedule</h2>
                    <p class="form-section__hint">Define the employee's normal recurring working hours. Changes affect availability calculations immediately.</p>
                </div>

                <div class="schedule-grid">
                    <div class="schedule-grid__header" aria-hidden="true">
                        <span class="sg-col sg-col--day">Day</span>
                        <span class="sg-col sg-col--toggle">Working</span>
                        <span class="sg-col sg-col--time">Work Start</span>
                        <span class="sg-col sg-col--time">Work End</span>
                        <span class="sg-col sg-col--time">Lunch Start</span>
                        <span class="sg-col sg-col--time">Lunch End</span>
                        <span class="sg-col sg-col--copy"></span>
                    </div>
                    <?php foreach ($displayOrder as $dow): ?>
                    <?php
                        $hasSavedRow = isset($schedule[$dow]);
                        if ($isFirstVisit) {
                            $isWorking  = in_array($dow, $defaultWorking, true);
                            $startTime  = '09:00';
                            $endTime    = '17:00';
                            $lunchStart = $isWorking ? '12:00' : '';
                            $lunchEnd   = $isWorking ? '13:00' : '';
                        } else {
                            $isWorking  = $hasSavedRow;
                            $savedRow   = $schedule[$dow] ?? [];
                            $startTime  = !empty($savedRow['start_time'])  ? substr($savedRow['start_time'], 0, 5)  : '09:00';
                            $endTime    = !empty($savedRow['end_time'])    ? substr($savedRow['end_time'], 0, 5)    : '17:00';
                            $lunchStart = !empty($savedRow['lunch_start_time']) ? substr($savedRow['lunch_start_time'], 0, 5) : '';
                            $lunchEnd   = !empty($savedRow['lunch_end_time'])   ? substr($savedRow['lunch_end_time'], 0, 5)   : '';
                        }
                    ?>
                    <div class="schedule-row <?= $isWorking ? 'schedule-row--working' : 'schedule-row--off' ?>" data-dow="<?= $dow ?>">
                        <span class="sg-col sg-col--day"><strong><?= htmlspecialchars($dayLabels[$dow]) ?></strong></span>
                        <span class="sg-col sg-col--toggle">
                            <label class="toggle-label">
                                <input type="checkbox" name="schedule[<?= $dow ?>][is_working]" value="1" class="day-toggle" data-dow="<?= $dow ?>" <?= $isWorking ? 'checked' : '' ?>>
                                <span class="sr-only"><?= htmlspecialchars($dayLabels[$dow]) ?> working</span>
                            </label>
                        </span>
                        <span class="sg-col sg-col--time"><input type="time" name="schedule[<?= $dow ?>][start_time]" value="<?= htmlspecialchars($startTime, ENT_QUOTES, 'UTF-8') ?>" class="day-time-input" <?= !$isWorking ? 'disabled' : '' ?> aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> work start"></span>
                        <span class="sg-col sg-col--time"><input type="time" name="schedule[<?= $dow ?>][end_time]" value="<?= htmlspecialchars($endTime, ENT_QUOTES, 'UTF-8') ?>" class="day-time-input" <?= !$isWorking ? 'disabled' : '' ?> aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> work end"></span>
                        <span class="sg-col sg-col--time"><input type="time" name="schedule[<?= $dow ?>][lunch_start_time]" value="<?= htmlspecialchars($lunchStart, ENT_QUOTES, 'UTF-8') ?>" class="day-time-input" <?= !$isWorking ? 'disabled' : '' ?> placeholder="--:--" aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> lunch start"></span>
                        <span class="sg-col sg-col--time"><input type="time" name="schedule[<?= $dow ?>][lunch_end_time]" value="<?= htmlspecialchars($lunchEnd, ENT_QUOTES, 'UTF-8') ?>" class="day-time-input" <?= !$isWorking ? 'disabled' : '' ?> placeholder="--:--" aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> lunch end"></span>
                        <span class="sg-col sg-col--copy">
                            <button type="button" class="btn btn--xs btn--ghost btn-copy-prev" data-dow="<?= $dow ?>" title="Copy from previous day" <?= !$isWorking ? 'disabled' : '' ?>>Copy prev</button>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-actions">
                <a href="/staff/<?= (int) $staff['id'] ?>" class="btn btn--ghost">Cancel</a>
                <button type="submit" class="btn btn--primary">Save Schedule</button>
            </div>
        </form>
    </div>

    <?php /* ─────────────────────────── TAB: HISTORY ────────────────────────────────────── */ ?>
    <div class="profile-panel" data-panel="history" <?= $activeTab !== 'history' ? 'hidden' : '' ?>>
        <div class="form-section">
            <div class="form-section__header">
                <h2 class="form-section__title">History</h2>
                <p class="form-section__hint">Audit history for this employee will appear here in a future release.</p>
            </div>
            <p style="color:#6b7280;font-size:.9rem;">No history entries available yet.</p>
        </div>
    </div>

</div>

<style>
.profile-editor { max-width: 900px; }
.profile-editor__header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.profile-editor__identity { display: flex; flex-direction: column; gap: .25rem; }
.profile-editor__name { font-size: 1.35rem; font-weight: 700; margin: 0; }
.profile-editor__meta { font-size: .875rem; color: #6b7280; }
.badge { display: inline-block; padding: .15rem .55rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
.badge--active { background: #dcfce7; color: #166534; }
.badge--inactive { background: #fee2e2; color: #991b1b; }

.profile-tabs { display: flex; gap: 0; border-bottom: 2px solid #e5e7eb; margin-bottom: 1.5rem; flex-wrap: wrap; }
.profile-tab { background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; padding: .6rem 1.1rem; cursor: pointer; font-size: .9rem; color: #6b7280; font-weight: 500; transition: color .15s, border-color .15s; }
.profile-tab:hover { color: #374151; }
.profile-tab[aria-selected="true"] { color: #4f46e5; border-bottom-color: #4f46e5; font-weight: 600; }

.profile-panel { animation: fadeIn .15s ease; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(3px); } to { opacity: 1; transform: none; } }
.profile-form .form-section { margin-bottom: 2rem; }
.profile-form .form-section__header { margin-bottom: 1rem; border-bottom: 1px solid #e5e7eb; padding-bottom: .5rem; }
.profile-form .form-section__title { font-size: 1rem; font-weight: 600; margin: 0; }
.profile-form .form-section__hint { font-size: .85rem; color: #6b7280; margin: .25rem 0 0; }
.form-input--short { max-width: 120px; }
.radio-group { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; }
.radio-group--stacked { flex-direction: column; gap: .4rem; }
.checkbox-group { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; }

/* Schedule grid — mirrors onboarding step 4 */
.schedule-grid { display: flex; flex-direction: column; border: 1px solid #e5e7eb; border-radius: .5rem; overflow: hidden; font-size: .875rem; }
.schedule-grid__header { display: grid; grid-template-columns: 130px 70px repeat(4, 1fr) 90px; gap: .5rem; align-items: center; padding: .5rem .75rem; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-weight: 600; font-size: .8rem; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
.schedule-row { display: grid; grid-template-columns: 130px 70px repeat(4, 1fr) 90px; gap: .5rem; align-items: center; padding: .5rem .75rem; border-bottom: 1px solid #f3f4f6; }
.schedule-row:last-child { border-bottom: none; }
.schedule-row--off { background: #fafafa; }
.schedule-row--off .day-time-input { opacity: .35; }
.sg-col { display: flex; align-items: center; }
.sg-col--toggle { justify-content: center; }
.day-time-input { width: 100%; padding: .3rem .4rem; border: 1px solid #d1d5db; border-radius: .35rem; font-size: .85rem; background: #fff; }
.day-time-input:disabled { background: #f9fafb; color: #9ca3af; cursor: not-allowed; }
.toggle-label { cursor: pointer; display: flex; align-items: center; justify-content: center; }
.toggle-label input { width: 1.1rem; height: 1.1rem; cursor: pointer; accent-color: #6366f1; }
.btn--xs { padding: .25rem .55rem; font-size: .75rem; }
.btn--ghost { background: transparent; border: 1px solid #d1d5db; color: #374151; }
.btn--ghost:hover:not(:disabled) { background: #f3f4f6; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; }

/* Service checklist — mirrors onboarding step 3 */
.service-assignment-toolbar { display: flex; gap: .5rem; margin-bottom: 1.25rem; }
.service-assignment-groups { display: flex; flex-direction: column; gap: 1.5rem; }
.service-group { border: 1px solid #e5e7eb; border-radius: .5rem; overflow: hidden; }
.service-group__header { display: flex; align-items: center; justify-content: space-between; padding: .625rem 1rem; background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
.service-group__name { font-size: .9375rem; font-weight: 600; margin: 0; }
.service-group__actions { display: flex; gap: .5rem; }
.service-checklist { list-style: none; margin: 0; padding: 0; }
.service-checklist__item { border-bottom: 1px solid #f3f4f6; }
.service-checklist__item:last-child { border-bottom: none; }
.service-checklist__label { display: flex; align-items: center; gap: .75rem; padding: .625rem 1rem; cursor: pointer; }
.service-checklist__label:hover { background: #f3f4f6; }
.service-checklist__checkbox { width: 1rem; height: 1rem; flex-shrink: 0; cursor: pointer; }
.service-checklist__name { flex: 1; font-size: .9rem; }
.service-checklist__meta { font-size: .8rem; color: #6b7280; }
</style>

<script>
(function () {
    // ── Tab switching ──
    var tabs   = document.querySelectorAll('.profile-tab');
    var panels = document.querySelectorAll('.profile-panel');
    var tabParam = new URLSearchParams(window.location.search).get('tab') || '<?= htmlspecialchars($activeTab, ENT_QUOTES, 'UTF-8') ?>';

    function activateTab(name) {
        tabs.forEach(function (t) {
            var active = t.dataset.tab === name;
            t.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach(function (p) {
            if (p.dataset.panel === name) {
                p.removeAttribute('hidden');
            } else {
                p.setAttribute('hidden', '');
            }
        });
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            var name = t.dataset.tab;
            activateTab(name);
            var url = new URL(window.location.href);
            url.searchParams.set('tab', name);
            history.replaceState(null, '', url.toString());
        });
    });

    activateTab(tabParam);

    // ── Employment end date toggle ──
    var cbEndDate = document.getElementById('edit_specify_end_date');
    var rowEndDate = document.getElementById('edit_end_date_row');
    if (cbEndDate && rowEndDate) {
        cbEndDate.addEventListener('change', function () {
            rowEndDate.style.display = cbEndDate.checked ? '' : 'none';
        });
    }

    // ── Schedule: day toggle ──
    var schedForm = document.getElementById('profile-schedule-form');
    if (schedForm) {
        var dispOrder = <?= json_encode($displayOrder) ?>;
        function rowOf(dow) { return schedForm.querySelector('.schedule-row[data-dow="' + dow + '"]'); }
        schedForm.querySelectorAll('.day-toggle').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var row = rowOf(cb.dataset.dow);
                var inputs = row.querySelectorAll('.day-time-input');
                var copyBtn = row.querySelector('.btn-copy-prev');
                if (cb.checked) {
                    row.classList.add('schedule-row--working'); row.classList.remove('schedule-row--off');
                    inputs.forEach(function (i) { i.disabled = false; });
                    if (copyBtn) copyBtn.disabled = false;
                } else {
                    row.classList.remove('schedule-row--working'); row.classList.add('schedule-row--off');
                    inputs.forEach(function (i) { i.disabled = true; });
                    if (copyBtn) copyBtn.disabled = true;
                }
            });
        });
        schedForm.querySelectorAll('.btn-copy-prev').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var dow = parseInt(btn.dataset.dow, 10);
                var idx = dispOrder.indexOf(dow);
                if (idx <= 0) return;
                var prevRow = rowOf(dispOrder[idx - 1]);
                if (!prevRow) return;
                var prevToggle = prevRow.querySelector('.day-toggle');
                if (!prevToggle || !prevToggle.checked) return;
                var src = Array.from(prevRow.querySelectorAll('.day-time-input'));
                var dst = Array.from(rowOf(dow).querySelectorAll('.day-time-input'));
                src.forEach(function (s, i) { if (dst[i] && !dst[i].disabled) dst[i].value = s.value; });
            });
        });
    }

    // ── Services: select/clear all ──
    var svcsForm = document.getElementById('profile-services-form');
    if (svcsForm) {
        var allCb = function () { return svcsForm.querySelectorAll('input[type="checkbox"][name="service_ids[]"]'); };
        var btnSel = document.getElementById('profile-select-all');
        var btnClr = document.getElementById('profile-clear-all');
        if (btnSel) btnSel.addEventListener('click', function () { allCb().forEach(function (c) { c.checked = true; }); });
        if (btnClr) btnClr.addEventListener('click', function () { allCb().forEach(function (c) { c.checked = false; }); });
        svcsForm.querySelectorAll('.btn-group-select').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var grp = btn.closest('.service-group');
                var sel = btn.dataset.action === 'select';
                grp.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.checked = sel; });
            });
        });
    }
}());
</script>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
