<?php
$title      = 'Edit Employee — ' . htmlspecialchars((string) ($staff['display_name'] ?? ($staff['first_name'] ?? 'Staff')), ENT_QUOTES, 'UTF-8');
$activeTab  = $activeTab ?? 'basic';
$staffId    = (int) ($staff['id'] ?? 0);
$isDrawer   = isset($_GET['drawer']) && $_GET['drawer'] === '1';

// Country list
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
    'none'                              => 'None',
    'flat_hourly'                       => 'Flat Hourly',
    'salary'                            => 'Salary',
    'commission'                        => 'Commission',
    'combination'                       => 'Combination',
    'per_service_fee'                   => 'Per Service Fee (specified by service)',
    'per_service_fee_with_bonus'        => 'Per Service Fee with Bonus (for employees with seniority)',
    'per_service_fee_by_employee'       => 'Per Service Fee by Employee',
    'service_commission_by_sales_tier'  => 'Service Commission by Sales Tier',
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

$displayOrder   = [1, 2, 3, 4, 5, 6, 0];
$dayLabels      = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
$defaultWorking = [1, 2, 3, 4, 5];

$v        = static function (string $key) use ($staff): string {
    return htmlspecialchars((string) ($staff[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
$hasError = static function (string $key) use ($errors): bool { return isset($errors[$key]); };
$showErr  = static function (string $key) use ($errors): void {
    if (isset($errors[$key])) {
        echo '<span class="form-field-error">' . htmlspecialchars((string) $errors[$key], ENT_QUOTES, 'UTF-8') . '</span>';
    }
};

$csrfName = htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'), ENT_QUOTES, 'UTF-8');
$csrfVal  = htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8');
$dname    = htmlspecialchars((string) ($staff['display_name'] ?? ($staff['first_name'] . ' ' . $staff['last_name'])), ENT_QUOTES, 'UTF-8');
$jobTitle = htmlspecialchars((string) ($staff['job_title'] ?? ''), ENT_QUOTES, 'UTF-8');

// Tab titles used in drawer header
$tabTitles = [
    'basic'        => 'Employee Info',
    'compensation' => 'Compensation',
    'services'     => 'Services',
    'schedule'     => 'Schedule',
    'history'      => 'History',
];
$drawerTitle    = $dname;
$drawerSubtitle = 'Edit — ' . ($tabTitles[$activeTab] ?? 'Profile');

if (!$isDrawer):
    ob_start();
    $teamWorkspaceActiveTab  = 'directory';
    $teamWorkspaceShellTitle = 'Team';
    require base_path('modules/staff/views/partials/team-workspace-shell.php');
endif;
?>
<div class="pedit-wrap"
    <?php if ($isDrawer): ?>
    data-drawer-content-root
    data-drawer-tabs
    data-drawer-title="<?= $drawerTitle ?>"
    data-drawer-subtitle="<?= htmlspecialchars($drawerSubtitle, ENT_QUOTES, 'UTF-8') ?>"
    data-drawer-width="wide"
    <?php endif; ?>
>

<?php if (!$isDrawer): ?>
<!-- Full-page header -->
<div class="pedit-page-header">
    <div>
        <h1 class="pedit-page-name"><?= $dname ?></h1>
        <?php if ($jobTitle): ?><p class="pedit-page-meta"><?= $jobTitle ?></p><?php endif; ?>
    </div>
    <div class="pedit-page-header__actions">
        <?php if (empty($staff['is_active'])): ?>
        <span class="stf-badge stf-badge--inactive">Inactive</span>
        <?php else: ?>
        <span class="stf-badge stf-badge--active">Active</span>
        <?php endif; ?>
        <a href="/staff/<?= $staffId ?>" class="staff-create-btn-cancel">&larr; Profile</a>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($flash['success'])): ?>
<div class="staff-wizard-flash staff-wizard-flash--success" role="status">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
    <?= htmlspecialchars((string) $flash['success'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>
<?php if (!empty($flash['error'])): ?>
<div class="staff-create-errors" role="alert">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div><?= htmlspecialchars((string) $flash['error'], ENT_QUOTES, 'UTF-8') ?></div>
</div>
<?php endif; ?>
<?php if (!empty($errors['_general'])): ?>
<div class="staff-create-errors" role="alert">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <div><?= htmlspecialchars((string) $errors['_general'], ENT_QUOTES, 'UTF-8') ?></div>
</div>
<?php endif; ?>

<!-- ── Tab nav ──────────────────────────────────────────────────────────── -->
<div class="pedit-tabs" role="tablist" aria-label="Profile sections">
    <?php
    $tabs = [
        'basic'        => 'Employee Info',
        'compensation' => 'Compensation',
        'services'     => 'Services',
        'schedule'     => 'Schedule',
        'history'      => 'History',
    ];
    foreach ($tabs as $tabKey => $tabLabel):
        $isActive = $activeTab === $tabKey;
    ?>
    <button type="button"
            class="pedit-tab<?= $isActive ? ' is-active' : '' ?>"
            role="tab"
            data-drawer-tab="<?= $tabKey ?>"
            <?= $isActive ? 'data-drawer-tab-default="1"' : '' ?>
            aria-selected="<?= $isActive ? 'true' : 'false' ?>">
        <?= $tabLabel ?>
    </button>
    <?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB: Employee Info + Compensation (one form — saves together)
     ══════════════════════════════════════════════════════════════════════ -->
<div class="pedit-panel" data-drawer-tab-panel="basic" <?= $activeTab !== 'basic' ? 'hidden' : '' ?>>
<form method="post" action="/staff/<?= $staffId ?><?= $isDrawer ? '?drawer=1' : '' ?>" class="pedit-form" enctype="multipart/form-data" novalidate data-drawer-submit>
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">

    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Identity</h3>
        <div class="staff-create-row-2">
            <div class="staff-create-field <?= $hasError('first_name') ? 'staff-create-field--error' : '' ?>">
                <label for="edit_first_name" class="staff-create-label staff-create-label--required">First Name</label>
                <input type="text" id="edit_first_name" name="first_name" class="staff-create-input" value="<?= $v('first_name') ?>" required>
                <?php $showErr('first_name'); ?>
            </div>
            <div class="staff-create-field <?= $hasError('last_name') ? 'staff-create-field--error' : '' ?>">
                <label for="edit_last_name" class="staff-create-label">Last Name</label>
                <input type="text" id="edit_last_name" name="last_name" class="staff-create-input" value="<?= $v('last_name') ?>">
                <?php $showErr('last_name'); ?>
            </div>
        </div>
        <div class="staff-create-field">
            <label for="edit_display_name" class="staff-create-label">Display Name</label>
            <input type="text" id="edit_display_name" name="display_name" class="staff-create-input" value="<?= $v('display_name') ?>" maxlength="200" placeholder="Defaults to First + Last">
        </div>
        <div class="staff-create-field <?= $hasError('gender') ? 'staff-create-field--error' : '' ?>">
            <label class="staff-create-label">Gender</label>
            <div class="staff-create-radio-group">
                <label class="staff-create-radio"><input type="radio" name="gender" value="male"   <?= ($staff['gender'] ?? '') === 'male'   ? 'checked' : '' ?>> <span>Male</span></label>
                <label class="staff-create-radio"><input type="radio" name="gender" value="female" <?= ($staff['gender'] ?? '') === 'female' ? 'checked' : '' ?>> <span>Female</span></label>
                <label class="staff-create-radio"><input type="radio" name="gender" value=""       <?= (($staff['gender'] ?? '') === '')    ? 'checked' : '' ?>> <span>Not specified</span></label>
            </div>
            <?php $showErr('gender'); ?>
        </div>
    </div>

    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Contact</h3>
        <div class="staff-create-field <?= $hasError('email') ? 'staff-create-field--error' : '' ?>">
            <label for="edit_email" class="staff-create-label">Email</label>
            <input type="email" id="edit_email" name="email" class="staff-create-input" value="<?= $v('email') ?>">
            <?php $showErr('email'); ?>
        </div>
        <div class="staff-create-row-2">
            <div class="staff-create-field">
                <label for="edit_home_phone" class="staff-create-label">Home Phone</label>
                <input type="tel" id="edit_home_phone" name="home_phone" class="staff-create-input" value="<?= $v('home_phone') ?>">
            </div>
            <div class="staff-create-field">
                <label for="edit_mobile_phone" class="staff-create-label">Mobile Phone</label>
                <input type="tel" id="edit_mobile_phone" name="mobile_phone" class="staff-create-input" value="<?= $v('mobile_phone') ?>">
            </div>
        </div>
        <div class="staff-create-field">
            <label class="staff-create-label">Preferred Phone</label>
            <div class="staff-create-radio-group">
                <label class="staff-create-radio"><input type="radio" name="preferred_phone" value="home"   <?= ($staff['preferred_phone'] ?? '') === 'home'   ? 'checked' : '' ?>> <span>Home</span></label>
                <label class="staff-create-radio"><input type="radio" name="preferred_phone" value="mobile" <?= ($staff['preferred_phone'] ?? '') === 'mobile' ? 'checked' : '' ?>> <span>Mobile</span></label>
            </div>
        </div>
        <label class="staff-create-checkbox">
            <input type="checkbox" name="sms_opt_in" value="1" <?= !empty($staff['sms_opt_in']) ? 'checked' : '' ?>>
            <span>SMS opt-in</span>
        </label>
        <div class="staff-create-field" style="margin-top:.75rem;">
            <label for="edit_street_1" class="staff-create-label">Street 1</label>
            <input type="text" id="edit_street_1" name="street_1" class="staff-create-input" value="<?= $v('street_1') ?>">
        </div>
        <div class="staff-create-field">
            <label for="edit_street_2" class="staff-create-label">Street 2</label>
            <input type="text" id="edit_street_2" name="street_2" class="staff-create-input" value="<?= $v('street_2') ?>">
        </div>
        <div class="staff-create-row-2">
            <div class="staff-create-field">
                <label for="edit_city" class="staff-create-label">City</label>
                <input type="text" id="edit_city" name="city" class="staff-create-input" value="<?= $v('city') ?>">
            </div>
            <div class="staff-create-field">
                <label for="edit_postal_code" class="staff-create-label">Postal Code</label>
                <input type="text" id="edit_postal_code" name="postal_code" class="staff-create-input" value="<?= $v('postal_code') ?>" maxlength="20">
            </div>
        </div>
        <div class="staff-create-field">
            <label for="edit_country" class="staff-create-label">Country</label>
            <select id="edit_country" name="country" class="staff-create-select">
                <?php foreach ($countries as $code => $label): ?>
                <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= ($staff['country'] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Employment</h3>
        <div class="staff-create-row-2">
            <div class="staff-create-field <?= $hasError('job_title') ? 'staff-create-field--error' : '' ?>">
                <label for="edit_job_title" class="staff-create-label">Job Title</label>
                <input type="text" id="edit_job_title" name="job_title" class="staff-create-input" value="<?= $v('job_title') ?>">
                <?php $showErr('job_title'); ?>
            </div>
            <div class="staff-create-field <?= $hasError('staff_type') ? 'staff-create-field--error' : '' ?>">
                <label for="edit_staff_type" class="staff-create-label">Type</label>
                <select id="edit_staff_type" name="staff_type" class="staff-create-select">
                    <option value="">— Select —</option>
                    <option value="freelancer" <?= ($staff['staff_type'] ?? '') === 'freelancer' ? 'selected' : '' ?>>Freelancer</option>
                    <option value="scheduled"  <?= ($staff['staff_type'] ?? '') === 'scheduled'  ? 'selected' : '' ?>>Scheduled</option>
                </select>
                <?php $showErr('staff_type'); ?>
            </div>
        </div>
        <div class="staff-create-row-2">
            <div class="staff-create-field">
                <label for="edit_license_number" class="staff-create-label">License #</label>
                <input type="text" id="edit_license_number" name="license_number" class="staff-create-input" value="<?= $v('license_number') ?>" maxlength="100">
            </div>
            <div class="staff-create-field">
                <label for="edit_license_expiration_date" class="staff-create-label">License Expiry</label>
                <input type="date" id="edit_license_expiration_date" name="license_expiration_date" class="staff-create-input" value="<?= $v('license_expiration_date') ?>">
            </div>
        </div>
        <div class="staff-create-row-2">
            <div class="staff-create-field">
                <label for="edit_max_appt" class="staff-create-label">Max Appointments / Day</label>
                <input type="number" id="edit_max_appt" name="max_appointments_per_day" class="staff-create-input" value="<?= $v('max_appointments_per_day') ?>" min="1" max="999" placeholder="Unspecified">
            </div>
            <div class="staff-create-field">
                <label for="edit_service_type_id" class="staff-create-label">Service Type</label>
                <select id="edit_service_type_id" name="service_type_id" class="staff-create-select">
                    <option value="">— Select —</option>
                    <?php foreach ($serviceTypes as $st): ?>
                    <option value="<?= (int) $st['id'] ?>" <?= (string) ($staff['service_type_id'] ?? '') === (string) $st['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $st['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:.75rem;">
            <label class="staff-create-checkbox">
                <input type="checkbox" name="is_active" value="1" <?= !empty($staff['is_active']) ? 'checked' : '' ?>>
                <span>Active</span>
                <span class="staff-create-hint staff-create-hint--inline">Inactive staff won't appear on calendar or booking.</span>
            </label>
            <label class="staff-create-checkbox">
                <input type="checkbox" name="specify_end_date" value="1" id="edit_specify_end_date"
                    <?= !empty($staff['employment_end_date']) ? 'checked' : '' ?>>
                <span>Specify Employment End Date</span>
            </label>
        </div>
        <div id="edit_end_date_row" class="staff-create-field" <?= empty($staff['employment_end_date']) ? 'hidden' : '' ?>>
            <label for="edit_employment_end_date" class="staff-create-label">Employment End Date</label>
            <input type="date" id="edit_employment_end_date" name="employment_end_date" class="staff-create-input" value="<?= $v('employment_end_date') ?>">
        </div>
    </div>

    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Profile &amp; Account</h3>
        <div class="staff-create-field">
            <label for="edit_profile_description" class="staff-create-label">Profile Description</label>
            <textarea id="edit_profile_description" name="profile_description" class="staff-create-textarea" rows="3"><?= $v('profile_description') ?></textarea>
        </div>
        <div class="staff-create-field">
            <label for="edit_employee_notes" class="staff-create-label">Internal Notes</label>
            <textarea id="edit_employee_notes" name="employee_notes" class="staff-create-textarea" rows="2"><?= $v('employee_notes') ?></textarea>
        </div>
        <div class="staff-create-field">
            <label for="edit_user_id" class="staff-create-label">Linked User Account</label>
            <select id="edit_user_id" name="user_id" class="staff-create-select">
                <option value="">— None —</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>" <?= ($staff['user_id'] ?? '') == $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name'] . ' (' . $u['email'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="staff-create-checkbox">
            <input type="checkbox" name="create_login_requested" value="1" <?= !empty($staff['create_login_requested']) ? 'checked' : '' ?>>
            <span>Create Login for this Employee</span>
        </label>
    </div>

    <div class="staff-create-actions">
        <a href="/staff/<?= $staffId ?>" class="staff-create-btn-cancel">Cancel</a>
        <button type="submit" name="_tab" value="basic" class="staff-create-btn-submit">Save Employee Info</button>
    </div>
</form>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB: Compensation (part of same form, separate panel for UX)
     ══════════════════════════════════════════════════════════════════════ -->
<div class="pedit-panel" data-drawer-tab-panel="compensation" <?= $activeTab !== 'compensation' ? 'hidden' : '' ?>>
<form method="post" action="/staff/<?= $staffId ?><?= $isDrawer ? '?drawer=1' : '' ?>" class="pedit-form" novalidate data-drawer-submit>
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">

    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Staff Group</h3>
        <div class="staff-create-field">
            <label for="edit_primary_group_id" class="staff-create-label">Group</label>
            <select id="edit_primary_group_id" name="primary_group_id" class="staff-create-select">
                <option value="">— No group assigned —</option>
                <?php foreach ($groups as $g): ?>
                <option value="<?= (int) $g['id'] ?>" <?= (string) ($staff['primary_group_id'] ?? '') === (string) $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $g['name'], ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Pay Type — Services</h3>
        <div class="staff-create-field <?= $hasError('pay_type') ? 'staff-create-field--error' : '' ?>">
            <div class="staff-onboard-radio-stack">
                <?php foreach ($payTypeLabels as $val => $lbl): ?>
                <label class="staff-onboard-radio-option">
                    <input type="radio" name="pay_type" value="<?= htmlspecialchars($val, ENT_QUOTES) ?>" <?= ($staff['pay_type'] ?? '') === $val ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php $showErr('pay_type'); ?>
        </div>
    </div>

    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Pay Type — Classes &amp; Workshops</h3>
        <div class="staff-create-field">
            <div class="staff-onboard-radio-stack">
                <?php foreach ($payTypeClassesLabels as $val => $lbl): ?>
                <label class="staff-onboard-radio-option">
                    <input type="radio" name="pay_type_classes" value="<?= htmlspecialchars($val, ENT_QUOTES) ?>" <?= ($staff['pay_type_classes'] ?? '') === $val ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="staff-create-section">
        <h3 class="staff-create-section__title">Pay Type — Products</h3>
        <div class="staff-create-field">
            <div class="staff-onboard-radio-stack">
                <?php foreach ($payTypeProductsLabels as $val => $lbl): ?>
                <label class="staff-onboard-radio-option">
                    <input type="radio" name="pay_type_products" value="<?= htmlspecialchars($val, ENT_QUOTES) ?>" <?= ($staff['pay_type_products'] ?? '') === $val ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="staff-create-section">
        <h3 class="staff-create-section__title">PTO &amp; Benefits</h3>
        <div class="staff-create-row-3">
            <div class="staff-create-field <?= $hasError('vacation_days') ? 'staff-create-field--error' : '' ?>">
                <label for="edit_vacation_days" class="staff-create-label">Vacation Days</label>
                <input type="number" id="edit_vacation_days" name="vacation_days" class="staff-create-input" value="<?= $v('vacation_days') ?>" min="0">
                <?php $showErr('vacation_days'); ?>
            </div>
            <div class="staff-create-field <?= $hasError('sick_days') ? 'staff-create-field--error' : '' ?>">
                <label for="edit_sick_days" class="staff-create-label">Sick Days</label>
                <input type="number" id="edit_sick_days" name="sick_days" class="staff-create-input" value="<?= $v('sick_days') ?>" min="0">
                <?php $showErr('sick_days'); ?>
            </div>
            <div class="staff-create-field <?= $hasError('personal_days') ? 'staff-create-field--error' : '' ?>">
                <label for="edit_personal_days" class="staff-create-label">Personal Days</label>
                <input type="number" id="edit_personal_days" name="personal_days" class="staff-create-input" value="<?= $v('personal_days') ?>" min="0">
                <?php $showErr('personal_days'); ?>
            </div>
        </div>
        <div class="staff-create-row-2" style="margin-top:.75rem;">
            <div class="staff-create-field">
                <label for="edit_employee_number" class="staff-create-label">Employee ID</label>
                <input type="text" id="edit_employee_number" name="employee_number" class="staff-create-input" value="<?= $v('employee_number') ?>" maxlength="100">
            </div>
            <div class="staff-create-field">
                <label class="staff-create-label">Exemptions</label>
                <div style="display:flex;flex-direction:column;gap:.35rem;margin-top:.1rem;">
                    <label class="staff-create-checkbox"><input type="checkbox" name="has_dependents" value="1" <?= !empty($staff['has_dependents']) ? 'checked' : '' ?>><span>Has Dependents</span></label>
                    <label class="staff-create-checkbox"><input type="checkbox" name="is_exempt" value="1" <?= !empty($staff['is_exempt']) ? 'checked' : '' ?>><span>Exempt</span></label>
                </div>
            </div>
        </div>
    </div>

    <div class="staff-create-actions">
        <a href="/staff/<?= $staffId ?>" class="staff-create-btn-cancel">Cancel</a>
        <button type="submit" name="_tab" value="compensation" class="staff-create-btn-submit">Save Compensation</button>
    </div>
</form>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB: Services
     ══════════════════════════════════════════════════════════════════════ -->
<div class="pedit-panel" data-drawer-tab-panel="services" <?= $activeTab !== 'services' ? 'hidden' : '' ?>>
<form method="post" action="/staff/<?= $staffId ?>/profile/services<?= $isDrawer ? '?drawer=1' : '' ?>" class="pedit-form" id="profile-services-form" novalidate data-drawer-submit>
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">

    <?php if (!empty($errors['_services_general'])): ?>
    <div class="staff-create-errors" role="alert">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div><?= htmlspecialchars((string) $errors['_services_general'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php endif; ?>

    <?php if (empty($serviceGroups)): ?>
    <div class="staff-wizard-empty">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" opacity=".3" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 12h6M12 9v6"/></svg>
        <p class="staff-wizard-empty__title">No services available</p>
        <p class="staff-wizard-empty__body">No services configured for this branch. <a href="/services/create" target="_blank" class="staff-create-hint-link">Add services</a> first.</p>
    </div>
    <?php else: ?>

    <div class="staff-svc-toolbar">
        <p class="staff-svc-toolbar__hint">Services this employee is authorised to perform. Changes affect appointment eligibility immediately.</p>
        <div class="staff-svc-toolbar__actions">
            <button type="button" class="staff-svc-bulk-btn" id="profile-select-all">Select all</button>
            <button type="button" class="staff-svc-bulk-btn" id="profile-clear-all">Clear all</button>
        </div>
    </div>

    <div class="staff-svc-groups">
        <?php foreach ($serviceGroups as $groupKey => $group): ?>
        <div class="staff-svc-group" data-group="<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>">
            <div class="staff-svc-group__header">
                <span class="staff-svc-group__name"><?= htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8') ?></span>
                <div class="staff-svc-group__actions">
                    <button type="button" class="staff-svc-group-btn btn-group-select" data-action="select">All</button>
                    <button type="button" class="staff-svc-group-btn btn-group-select" data-action="clear">None</button>
                </div>
            </div>
            <ul class="staff-svc-list">
                <?php foreach ($group['services'] as $svc): ?>
                <?php $svcId = (int) $svc['id']; $isChecked = isset($assignedIds[$svcId]); ?>
                <li class="staff-svc-item<?= $isChecked ? ' staff-svc-item--checked' : '' ?>">
                    <label class="staff-svc-label">
                        <input type="checkbox" name="service_ids[]" value="<?= $svcId ?>" class="staff-svc-cb" <?= $isChecked ? 'checked' : '' ?>>
                        <span class="staff-svc-name"><?= htmlspecialchars((string) ($svc['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (!empty($svc['duration_minutes'])): ?><span class="staff-svc-meta"><?= (int) $svc['duration_minutes'] ?> min</span><?php endif; ?>
                    </label>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <div class="staff-create-actions">
        <a href="/staff/<?= $staffId ?>" class="staff-create-btn-cancel">Cancel</a>
        <button type="submit" class="staff-create-btn-submit">Save Services</button>
    </div>
</form>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB: Schedule
     ══════════════════════════════════════════════════════════════════════ -->
<div class="pedit-panel" data-drawer-tab-panel="schedule" <?= $activeTab !== 'schedule' ? 'hidden' : '' ?>>
<form method="post" action="/staff/<?= $staffId ?>/profile/schedule<?= $isDrawer ? '?drawer=1' : '' ?>" class="pedit-form" id="profile-schedule-form" novalidate data-drawer-submit data-schedule-display-order="<?= htmlspecialchars(json_encode($displayOrder), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">

    <?php if (!empty($errors['_schedule_general'])): ?>
    <div class="staff-create-errors" role="alert">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div><?= htmlspecialchars((string) $errors['_schedule_general'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php endif; ?>

    <p class="staff-wizard-schedule-hint">Define normal recurring working hours. Changes affect availability calculations immediately.</p>

    <div class="staff-schedule-grid">
        <div class="staff-schedule-header" aria-hidden="true">
            <span class="ssg-col ssg-col--day">Day</span>
            <span class="ssg-col ssg-col--toggle">Working</span>
            <span class="ssg-col ssg-col--time">Start</span>
            <span class="ssg-col ssg-col--time">End</span>
            <span class="ssg-col ssg-col--time">Lunch Start</span>
            <span class="ssg-col ssg-col--time">Lunch End</span>
            <span class="ssg-col ssg-col--copy"></span>
        </div>

        <?php foreach ($displayOrder as $dow): ?>
        <?php
            $hasSavedRow = isset($schedule[$dow]);
            if ($isFirstVisit) {
                $isWorking  = in_array($dow, $defaultWorking, true);
                $startTime  = '09:00'; $endTime = '17:00';
                $lunchStart = $isWorking ? '12:00' : '';
                $lunchEnd   = $isWorking ? '13:00' : '';
            } else {
                $isWorking  = $hasSavedRow;
                $savedRow   = $schedule[$dow] ?? [];
                $startTime  = !empty($savedRow['start_time'])       ? substr($savedRow['start_time'], 0, 5)       : '09:00';
                $endTime    = !empty($savedRow['end_time'])         ? substr($savedRow['end_time'], 0, 5)         : '17:00';
                $lunchStart = !empty($savedRow['lunch_start_time']) ? substr($savedRow['lunch_start_time'], 0, 5) : '';
                $lunchEnd   = !empty($savedRow['lunch_end_time'])   ? substr($savedRow['lunch_end_time'], 0, 5)   : '';
            }
        ?>
        <div class="staff-schedule-row <?= $isWorking ? 'staff-schedule-row--on' : 'staff-schedule-row--off' ?>" data-dow="<?= $dow ?>">
            <span class="ssg-col ssg-col--day"><strong><?= htmlspecialchars($dayLabels[$dow]) ?></strong></span>
            <span class="ssg-col ssg-col--toggle">
                <label class="staff-schedule-toggle">
                    <input type="checkbox" name="schedule[<?= $dow ?>][is_working]" value="1" class="day-toggle" data-dow="<?= $dow ?>" <?= $isWorking ? 'checked' : '' ?>>
                    <span class="staff-schedule-toggle__track" aria-hidden="true"></span>
                    <span class="visually-hidden"><?= htmlspecialchars($dayLabels[$dow]) ?> working</span>
                </label>
            </span>
            <span class="ssg-col ssg-col--time"><input type="time" name="schedule[<?= $dow ?>][start_time]" value="<?= htmlspecialchars($startTime, ENT_QUOTES, 'UTF-8') ?>" class="staff-schedule-time day-time-input" <?= !$isWorking ? 'disabled' : '' ?> aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> work start"></span>
            <span class="ssg-col ssg-col--time"><input type="time" name="schedule[<?= $dow ?>][end_time]" value="<?= htmlspecialchars($endTime, ENT_QUOTES, 'UTF-8') ?>" class="staff-schedule-time day-time-input" <?= !$isWorking ? 'disabled' : '' ?> aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> work end"></span>
            <span class="ssg-col ssg-col--time"><input type="time" name="schedule[<?= $dow ?>][lunch_start_time]" value="<?= htmlspecialchars($lunchStart, ENT_QUOTES, 'UTF-8') ?>" class="staff-schedule-time day-time-input" <?= !$isWorking ? 'disabled' : '' ?> placeholder="--:--" aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> lunch start"></span>
            <span class="ssg-col ssg-col--time"><input type="time" name="schedule[<?= $dow ?>][lunch_end_time]" value="<?= htmlspecialchars($lunchEnd, ENT_QUOTES, 'UTF-8') ?>" class="staff-schedule-time day-time-input" <?= !$isWorking ? 'disabled' : '' ?> placeholder="--:--" aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> lunch end"></span>
            <span class="ssg-col ssg-col--copy">
                <button type="button" class="staff-schedule-copy btn-copy-prev" data-dow="<?= $dow ?>" title="Copy hours from previous day" <?= !$isWorking ? 'disabled' : '' ?>>Copy prev</button>
            </span>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="staff-create-actions" style="margin-top:1.5rem;">
        <a href="/staff/<?= $staffId ?>" class="staff-create-btn-cancel">Cancel</a>
        <button type="submit" class="staff-create-btn-submit">Save Schedule</button>
    </div>
</form>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB: History
     ══════════════════════════════════════════════════════════════════════ -->
<div class="pedit-panel" data-drawer-tab-panel="history" <?= $activeTab !== 'history' ? 'hidden' : '' ?>>
    <div style="padding:2.5rem 0;text-align:center;color:var(--ds-color-text-secondary,#86868b);">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" opacity=".3" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <p style="font-size:.875rem;margin:.75rem 0 0;">Audit history for this employee will appear here in a future release.</p>
    </div>
</div>

</div><!-- /.pedit-wrap -->

<script>
(function () {
    var wrap = document.querySelector('.pedit-wrap');
    if (!wrap) return;

    // ── Tab switching (full-page mode — drawer uses data-drawer-tabs built-in) ──
    // Only needed when NOT in drawer (drawer's initDrawerTabs handles it)
    var inDrawer = !!wrap.closest('#app-drawer-body');
    if (!inDrawer) {
        var tabs   = wrap.querySelectorAll('.pedit-tab');
        var panels = wrap.querySelectorAll('.pedit-panel');
        var urlTab = new URLSearchParams(window.location.search).get('tab') || '<?= htmlspecialchars($activeTab, ENT_QUOTES) ?>';

        function activateTab(name) {
            tabs.forEach(function (t) {
                var active = t.dataset.drawerTab === name;
                t.classList.toggle('is-active', active);
                t.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(function (p) {
                p.dataset.drawerTabPanel === name ? p.removeAttribute('hidden') : p.setAttribute('hidden', '');
            });
        }
        tabs.forEach(function (t) {
            t.addEventListener('click', function () {
                activateTab(t.dataset.drawerTab);
                var url = new URL(window.location.href);
                url.searchParams.set('tab', t.dataset.drawerTab);
                history.replaceState(null, '', url.toString());
            });
        });
        activateTab(urlTab);
    }

    // ── Employment end date toggle ──
    var cbEnd  = wrap.querySelector('#edit_specify_end_date');
    var rowEnd = wrap.querySelector('#edit_end_date_row');
    if (cbEnd && rowEnd) {
        cbEnd.addEventListener('change', function () {
            rowEnd.hidden = !cbEnd.checked;
        });
    }

    // ── Schedule: toggle + copy-prev ──
    var schedForm = wrap.querySelector('#profile-schedule-form');
    if (schedForm) {
        var dispOrder = <?= json_encode($displayOrder) ?>;
        function rowOf(dow) { return schedForm.querySelector('.staff-schedule-row[data-dow="' + dow + '"]'); }

        schedForm.querySelectorAll('.day-toggle').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var row = rowOf(cb.dataset.dow);
                var inputs = row.querySelectorAll('.day-time-input');
                var copyBtn = row.querySelector('.btn-copy-prev');
                var on = cb.checked;
                row.classList.toggle('staff-schedule-row--on', on);
                row.classList.toggle('staff-schedule-row--off', !on);
                inputs.forEach(function (i) { i.disabled = !on; });
                if (copyBtn) copyBtn.disabled = !on;
                if (on) {
                    var arr = Array.from(inputs);
                    if (arr[0] && !arr[0].value) arr[0].value = '09:00';
                    if (arr[1] && !arr[1].value) arr[1].value = '17:00';
                }
            });
        });

        schedForm.querySelectorAll('.btn-copy-prev').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var dow = parseInt(btn.dataset.dow, 10);
                var idx = dispOrder.indexOf(dow);
                if (idx <= 0) return;
                var prevRow = rowOf(dispOrder[idx - 1]);
                if (!prevRow || !prevRow.querySelector('.day-toggle').checked) return;
                var src = Array.from(prevRow.querySelectorAll('.day-time-input'));
                var dst = Array.from(rowOf(dow).querySelectorAll('.day-time-input'));
                src.forEach(function (s, i) { if (dst[i] && !dst[i].disabled) dst[i].value = s.value; });
            });
        });
    }

    // ── Services: select/clear all ──
    var svcsForm = wrap.querySelector('#profile-services-form');
    if (svcsForm) {
        var allCb = function () { return svcsForm.querySelectorAll('input[type="checkbox"][name="service_ids[]"]'); };
        function syncItem(cb) {
            var item = cb.closest('.staff-svc-item');
            if (item) item.classList.toggle('staff-svc-item--checked', cb.checked);
        }
        allCb().forEach(function (cb) { cb.addEventListener('change', function () { syncItem(cb); }); });

        var btnSel = wrap.querySelector('#profile-select-all');
        var btnClr = wrap.querySelector('#profile-clear-all');
        if (btnSel) btnSel.addEventListener('click', function () { allCb().forEach(function (c) { c.checked = true;  syncItem(c); }); });
        if (btnClr) btnClr.addEventListener('click', function () { allCb().forEach(function (c) { c.checked = false; syncItem(c); }); });

        svcsForm.querySelectorAll('.btn-group-select').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var grp = btn.closest('.staff-svc-group');
                var sel = btn.dataset.action === 'select';
                grp.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.checked = sel; syncItem(c); });
            });
        });
    }
}());
</script>

<?php if (!$isDrawer):
    $content = ob_get_clean();
    require shared_path('layout/base.php');
endif; ?>
