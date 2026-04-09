<?php

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Permissions\PermissionService;

$title = $staff['display_name'];
$staffIsTrashed = (bool) ($staffIsTrashed ?? false);
$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$flash = flash();

$perm = Application::container()->get(PermissionService::class);
$authUser = Application::container()->get(AuthService::class)->user();
$uid = (int) ($authUser['id'] ?? 0);
$canViewPayrollCommissions = $authUser !== null && $perm->has($uid, 'payroll.view');

$stats = is_array($staffProfileStats ?? null) ? $staffProfileStats : [];
$appt = $stats['appointments'] ?? [];
$inv = $stats['invoice_revenue'] ?? [];
$comm = $stats['commission'] ?? [];
$successRate = $stats['success_rate_percent'] ?? null;
$photoUrl = isset($stats['photo_url']) && is_string($stats['photo_url']) && $stats['photo_url'] !== '' ? $stats['photo_url'] : null;

$enr = is_array($staffShowEnrichment ?? null) ? $staffShowEnrichment : [
    'branch_name' => null,
    'linked_user' => null,
    'primary_group_name' => null,
    'service_type_name' => null,
    'assigned_service_names' => [],
    'schedule_has_lunch' => false,
];
$scheduleHasLunch = !empty($enr['schedule_has_lunch']);

$formatMoneyScalar = static function (?float $amount, string $currency, bool $mixed): string {
    if ($mixed) {
        return '—';
    }
    if ($amount === null) {
        return '0.00' . ($currency !== '' ? ' ' . $currency : '');
    }
    return number_format($amount, 2, '.', ',') . ($currency !== '' ? ' ' . $currency : '');
};

if ($canViewPayrollCommissions) {
    $metricPrimaryLabel = 'Total earned';
    $metricPrimaryValue = $formatMoneyScalar(
        $comm['mixed_currency'] ?? false ? null : ($comm['scalar_total'] ?? 0.0),
        (string) ($comm['primary_currency'] ?? ''),
        (bool) ($comm['mixed_currency'] ?? false)
    );
    $metricPrimaryHint = 'payroll_commission_lines';
} else {
    $metricPrimaryLabel = 'Revenue';
    $metricPrimaryValue = $formatMoneyScalar(
        $inv['mixed_currency'] ?? false ? null : ($inv['scalar_total'] ?? 0.0),
        (string) ($inv['primary_currency'] ?? ''),
        (bool) ($inv['mixed_currency'] ?? false)
    );
    $metricPrimaryHint = 'appointment-linked invoices';
}

$firstAppt = $appt['first_appointment_at'] ?? null;
$firstApptLabelShort = '—';
if (is_string($firstAppt) && $firstAppt !== '') {
    try {
        $firstApptLabelShort = (new \DateTimeImmutable($firstAppt))->format('j M Y');
    } catch (\Exception) {
        $firstApptLabelShort = substr($firstAppt, 0, 10);
    }
}

$initials = '';
$fn = trim((string) ($staff['first_name'] ?? ''));
$ln = trim((string) ($staff['last_name'] ?? ''));
if ($fn !== '') {
    $initials .= mb_strtoupper(mb_substr($fn, 0, 1));
}
if ($ln !== '') {
    $initials .= mb_strtoupper(mb_substr($ln, 0, 1));
}
if ($initials === '') {
    $initials = '?';
}

$jobLine = trim((string) ($staff['job_title'] ?? ''));
$emailLine = trim((string) ($staff['email'] ?? ''));
$metaParts = array_filter([$jobLine !== '' ? $jobLine : null, $emailLine !== '' ? $emailLine : null]);
$metaLine = $metaParts !== [] ? implode(' · ', $metaParts) : '—';

$payTypeLabels = [
    'none' => 'None',
    'flat_hourly' => 'Flat hourly',
    'salary' => 'Salary',
    'commission' => 'Commission',
    'combination' => 'Combination',
    'per_service_fee' => 'Per service fee',
    'per_service_fee_with_bonus' => 'Per service fee with bonus',
    'per_service_fee_by_employee' => 'Per service fee by employee',
    'service_commission_by_sales_tier' => 'Service commission by sales tier',
];
$payTypeClassesLabels = [
    'same_as_services' => 'Same as services',
    'commission_by_attendee' => 'Commission by attendee',
];
$payTypeProductsLabels = [
    'none' => 'None',
    'commission' => 'Commission',
    'commission_by_sales_tier' => 'Commission by sales tier',
    'per_product_fee' => 'Per product fee',
];
$genderLabels = ['male' => 'Male', 'female' => 'Female'];
$staffTypeLabels = ['freelancer' => 'Freelancer', 'scheduled' => 'Scheduled'];
$preferredPhoneLabels = ['home' => 'Home', 'mobile' => 'Mobile'];

$stfTxt = static function ($v): string {
    $s = trim((string) ($v ?? ''));
    return $s !== '' ? htmlspecialchars($s, ENT_QUOTES, 'UTF-8') : '—';
};

$stfDate = static function ($v): string {
    if ($v === null || $v === '') {
        return '—';
    }
    try {
        return htmlspecialchars((new \DateTimeImmutable((string) $v))->format('j M Y'), ENT_QUOTES, 'UTF-8');
    } catch (\Exception) {
        return htmlspecialchars(substr((string) $v, 0, 10), ENT_QUOTES, 'UTF-8');
    }
};

$stfEnum = static function ($v, array $map): string {
    $k = strtolower(trim((string) ($v ?? '')));
    if ($k === '') {
        return '—';
    }
    return htmlspecialchars($map[$k] ?? $k, ENT_QUOTES, 'UTF-8');
};

$stfBool = static function ($v): string {
    return !empty($v) ? 'Yes' : 'No';
};

$stfPay = static function ($v, array $map): string {
    $k = strtolower(trim((string) ($v ?? '')));
    if ($k === '') {
        return '—';
    }
    return htmlspecialchars($map[$k] ?? str_replace('_', ' ', $k), ENT_QUOTES, 'UTF-8');
};

$onboardingStep = $staff['onboarding_step'] ?? null;
$onboardingLabel = '—';
if ($onboardingStep !== null && $onboardingStep !== '') {
    $os = (int) $onboardingStep;
    $onboardingLabel = $os >= 1 && $os <= 4 ? 'Step ' . $os . ' of 4' : (string) $os;
}

$countryNames = [
    'AF' => 'Afghanistan', 'AL' => 'Albania', 'AM' => 'Armenia', 'AU' => 'Australia', 'AT' => 'Austria',
    'BE' => 'Belgium', 'BR' => 'Brazil', 'CA' => 'Canada', 'FR' => 'France', 'DE' => 'Germany',
    'GR' => 'Greece', 'IN' => 'India', 'IT' => 'Italy', 'JP' => 'Japan', 'MX' => 'Mexico',
    'NL' => 'Netherlands', 'PL' => 'Poland', 'ES' => 'Spain', 'SE' => 'Sweden', 'CH' => 'Switzerland',
    'GB' => 'United Kingdom', 'US' => 'United States',
];
$cc = strtoupper(trim((string) ($staff['country'] ?? '')));
$countryDisplay = $cc !== '' ? ($countryNames[$cc] ?? $cc) : '';
$countryDisplayEsc = $countryDisplay !== '' ? htmlspecialchars($countryDisplay, ENT_QUOTES, 'UTF-8') : '—';

$staffId = (int) ($staff['id'] ?? 0);

ob_start();
$teamWorkspaceActiveTab = 'directory';
$teamWorkspaceShellTitle = 'Team';
require base_path('modules/staff/views/partials/team-workspace-shell.php');
?>
<div class="stf-show">
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<p class="stf-show-back"><a href="/staff" class="stf-name-link">← Staff directory</a></p>

<section class="stf-show-panel stf-show-panel--intro" aria-labelledby="stf-show-heading">
    <div class="stf-show-intro">
        <div class="stf-show-intro__avatar" aria-hidden="true">
            <?php if ($photoUrl !== null): ?>
            <img src="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" width="72" height="72" loading="lazy" decoding="async">
            <?php else: ?>
            <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </div>
        <div class="stf-show-intro__main">
            <div class="stf-show-intro__head">
                <h1 id="stf-show-heading" class="stf-show-title"><?= htmlspecialchars($staff['display_name'], ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if ($staffIsTrashed): ?>
                <span class="badge badge-muted">In Trash</span>
                <?php else: ?>
                <span class="stf-status <?= !empty($staff['is_active']) ? 'stf-status--active' : 'stf-status--inactive' ?>"><?= !empty($staff['is_active']) ? 'Active' : 'Inactive' ?></span>
                <?php endif; ?>
            </div>
            <p class="stf-show-meta"><?= htmlspecialchars($metaLine, ENT_QUOTES, 'UTF-8') ?></p>
            <p class="stf-show-idline"><span class="stf-show-idline__label">Staff ID</span> <?= $staffId ?></p>
        </div>
        <div class="stf-show-actions">
            <?php if (!$staffIsTrashed): ?>
            <a href="/staff/<?= $staffId ?>/edit" data-drawer-url="/staff/<?= $staffId ?>/edit" class="stf-create-btn">Edit profile</a>
            <a href="/staff/<?= $staffId ?>/edit?tab=schedule" data-drawer-url="/staff/<?= $staffId ?>/edit?tab=schedule" class="staff-schedule-edit-link" title="Edit weekly schedule">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Schedule
            </a>
            <form method="post" action="/staff/<?= $staffId ?>/delete" class="stf-act-form" onsubmit="return confirm('Move this staff member to Trash?')">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="stf-act stf-act--trash">Trash</button>
            </form>
            <?php else: ?>
            <form method="post" action="/staff/<?= $staffId ?>/restore" class="stf-act-form">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="stf-act stf-act--restore">Restore</button>
            </form>
            <form method="post" action="/staff/<?= $staffId ?>/permanent-delete" class="stf-act-form" onsubmit="return confirm('Permanently delete this staff member? This cannot be undone.')">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="stf-act stf-act--delete">Delete permanently</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="stf-show-panel" aria-labelledby="stf-show-performance-heading">
    <h2 id="stf-show-performance-heading" class="stf-show-section-title">Performance</h2>
    <p class="stf-show-section-lead">Organization- and branch-scoped. Money totals use one currency unless mixed, then shown as an em dash.</p>
    <div class="stf-show-stats" role="list">
        <div class="stf-show-stat" role="listitem">
            <span class="stf-show-stat__label">First booking</span>
            <span class="stf-show-stat__value"><?= htmlspecialchars($firstApptLabelShort, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="stf-show-stat" role="listitem">
            <span class="stf-show-stat__label"><?= htmlspecialchars($metricPrimaryLabel, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="stf-show-stat__value" title="<?= htmlspecialchars($metricPrimaryHint, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($metricPrimaryValue, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="stf-show-stat" role="listitem">
            <span class="stf-show-stat__label">Clients served</span>
            <span class="stf-show-stat__value"><?= (int) ($appt['clients_distinct'] ?? 0) ?></span>
        </div>
        <div class="stf-show-stat" role="listitem">
            <span class="stf-show-stat__label">Success rate</span>
            <span class="stf-show-stat__value"><?= $successRate !== null ? htmlspecialchars((string) $successRate, ENT_QUOTES, 'UTF-8') . '%' : '—' ?></span>
        </div>
    </div>
    <h3 class="stf-show-subhead">Activity breakdown</h3>
    <dl class="stf-show-dl">
        <div class="stf-show-dl__row"><dt>Total appointments</dt><dd><?= (int) ($appt['appointments_total'] ?? 0) ?></dd></div>
        <div class="stf-show-dl__row"><dt>Completed</dt><dd><?= (int) ($appt['appointments_completed'] ?? 0) ?></dd></div>
        <div class="stf-show-dl__row"><dt>No-show</dt><dd><?= (int) ($appt['appointments_no_show'] ?? 0) ?></dd></div>
        <div class="stf-show-dl__row"><dt>Success rate</dt><dd><?= $successRate !== null ? htmlspecialchars((string) $successRate, ENT_QUOTES, 'UTF-8') . '% (completed vs no-show)' : '—' ?></dd></div>
        <?php if ($canViewPayrollCommissions): ?>
        <div class="stf-show-dl__row"><dt>Revenue (linked invoices)</dt><dd><?= htmlspecialchars($formatMoneyScalar($inv['mixed_currency'] ?? false ? null : ($inv['scalar_total'] ?? 0.0), (string) ($inv['primary_currency'] ?? ''), (bool) ($inv['mixed_currency'] ?? false)), ENT_QUOTES, 'UTF-8') ?></dd></div>
        <?php endif; ?>
    </dl>
</section>

<section class="stf-show-panel" aria-labelledby="stf-show-person-heading">
    <h2 id="stf-show-person-heading" class="stf-show-section-title">Person &amp; workplace</h2>
    <p class="stf-show-section-lead">Identity, role, and where this record sits in your org.</p>
    <div class="stf-show-grid">
        <div class="stf-show-grid__col">
            <h3 class="stf-show-subhead stf-show-subhead--in-grid">Identity</h3>
            <dl class="stf-show-dl">
                <div class="stf-show-dl__row"><dt>Display name</dt><dd><?= $stfTxt($staff['display_name'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>First name</dt><dd><?= $stfTxt($staff['first_name'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>Last name</dt><dd><?= $stfTxt($staff['last_name'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>Gender</dt><dd><?= $stfEnum($staff['gender'] ?? '', $genderLabels) ?></dd></div>
                <div class="stf-show-dl__row"><dt>Staff type</dt><dd><?= $stfEnum($staff['staff_type'] ?? '', $staffTypeLabels) ?></dd></div>
                <div class="stf-show-dl__row"><dt>Employee number</dt><dd><?= $stfTxt($staff['employee_number'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>Onboarding</dt><dd><?= htmlspecialchars($onboardingLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
            </dl>
        </div>
        <div class="stf-show-grid__col">
            <h3 class="stf-show-subhead stf-show-subhead--in-grid">Workplace</h3>
            <dl class="stf-show-dl">
                <div class="stf-show-dl__row"><dt>Branch</dt><dd><?= $enr['branch_name'] !== null && $enr['branch_name'] !== '' ? htmlspecialchars($enr['branch_name'], ENT_QUOTES, 'UTF-8') : '—' ?></dd></div>
                <div class="stf-show-dl__row"><dt>Job title</dt><dd><?= $stfTxt($staff['job_title'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>Primary group</dt><dd><?= $enr['primary_group_name'] !== null && $enr['primary_group_name'] !== '' ? htmlspecialchars($enr['primary_group_name'], ENT_QUOTES, 'UTF-8') : '—' ?></dd></div>
                <div class="stf-show-dl__row"><dt>Login user</dt><dd><?php
                    $lu = $enr['linked_user'] ?? null;
                    if (is_array($lu) && (($lu['name'] ?? '') !== '' || ($lu['email'] ?? '') !== '')) {
                        $nm = htmlspecialchars(trim((string) ($lu['name'] ?? '')), ENT_QUOTES, 'UTF-8');
                        $em = trim((string) ($lu['email'] ?? ''));
                        if ($em !== '') {
                            echo $nm !== '' ? $nm . ' ' : '';
                            echo '<a href="mailto:' . htmlspecialchars($em, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($em, ENT_QUOTES, 'UTF-8') . '</a>';
                        } else {
                            echo $nm !== '' ? $nm : '—';
                        }
                    } else {
                        echo '—';
                    }
?></dd></div>
                <div class="stf-show-dl__row"><dt>Service specialty</dt><dd><?= $enr['service_type_name'] !== null && $enr['service_type_name'] !== '' ? htmlspecialchars($enr['service_type_name'], ENT_QUOTES, 'UTF-8') : '—' ?></dd></div>
                <div class="stf-show-dl__row"><dt>Directory status</dt><dd><?= $stfBool($staff['is_active'] ?? 0) ?></dd></div>
            </dl>
        </div>
    </div>
</section>

<section class="stf-show-panel" aria-labelledby="stf-show-contact-heading">
    <h2 id="stf-show-contact-heading" class="stf-show-section-title">Contact &amp; address</h2>
    <div class="stf-show-grid">
        <div class="stf-show-grid__col">
            <h3 class="stf-show-subhead stf-show-subhead--in-grid">Contact</h3>
            <dl class="stf-show-dl">
                <div class="stf-show-dl__row"><dt>Email</dt><dd><?php $em0 = trim((string) ($staff['email'] ?? '')); echo $em0 !== '' ? '<a href="mailto:' . htmlspecialchars($em0, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($em0, ENT_QUOTES, 'UTF-8') . '</a>' : '—'; ?></dd></div>
                <div class="stf-show-dl__row"><dt>Phone (directory)</dt><dd><?= $stfTxt($staff['phone'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>Home phone</dt><dd><?= $stfTxt($staff['home_phone'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>Mobile phone</dt><dd><?= $stfTxt($staff['mobile_phone'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>Preferred phone</dt><dd><?= $stfEnum($staff['preferred_phone'] ?? '', $preferredPhoneLabels) ?></dd></div>
                <div class="stf-show-dl__row"><dt>SMS opt-in</dt><dd><?= $stfBool($staff['sms_opt_in'] ?? 0) ?></dd></div>
            </dl>
        </div>
        <div class="stf-show-grid__col">
            <h3 class="stf-show-subhead stf-show-subhead--in-grid">Address</h3>
            <dl class="stf-show-dl">
                <div class="stf-show-dl__row"><dt>Street</dt><dd><?= $stfTxt($staff['street_1'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>Street line 2</dt><dd><?= $stfTxt($staff['street_2'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>City</dt><dd><?= $stfTxt($staff['city'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>Postal code</dt><dd><?= $stfTxt($staff['postal_code'] ?? '') ?></dd></div>
                <div class="stf-show-dl__row"><dt>Country</dt><dd><?= $countryDisplayEsc ?></dd></div>
            </dl>
        </div>
    </div>
</section>

<section class="stf-show-panel" aria-labelledby="stf-show-services-heading">
    <h2 id="stf-show-services-heading" class="stf-show-section-title">Services &amp; compliance</h2>
    <h3 class="stf-show-subhead">Assigned services</h3>
    <?php
    $svcNames = $enr['assigned_service_names'] ?? [];
    if (!is_array($svcNames)) {
        $svcNames = [];
    }
?>
    <?php if ($svcNames === []): ?>
    <p class="stf-show-empty-inline">No services linked. Assign from <a href="/staff/<?= $staffId ?>/edit?tab=services" data-drawer-url="/staff/<?= $staffId ?>/edit?tab=services">Edit profile → Services</a>.</p>
    <?php else: ?>
    <ul class="stf-show-chip-list" role="list">
        <?php foreach ($svcNames as $sn): ?>
        <li class="stf-show-chip"><?= htmlspecialchars((string) $sn, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <h3 class="stf-show-subhead">Credentials</h3>
    <dl class="stf-show-dl">
        <div class="stf-show-dl__row"><dt>License number</dt><dd><?= $stfTxt($staff['license_number'] ?? '') ?></dd></div>
        <div class="stf-show-dl__row"><dt>License expiration</dt><dd><?= $stfDate($staff['license_expiration_date'] ?? null) ?></dd></div>
    </dl>
</section>

<section class="stf-show-panel" aria-labelledby="stf-show-ops-heading">
    <h2 id="stf-show-ops-heading" class="stf-show-section-title">Scheduling rules</h2>
    <dl class="stf-show-dl">
        <div class="stf-show-dl__row"><dt>Max appointments / day</dt><dd><?php
            $mad = $staff['max_appointments_per_day'] ?? null;
            echo ($mad !== null && $mad !== '') ? htmlspecialchars((string) (int) $mad, ENT_QUOTES, 'UTF-8') : '—';
?></dd></div>
        <div class="stf-show-dl__row"><dt>Employment end</dt><dd><?= $stfDate($staff['employment_end_date'] ?? null) ?></dd></div>
        <div class="stf-show-dl__row"><dt>Create login requested</dt><dd><?= $stfBool($staff['create_login_requested'] ?? 0) ?></dd></div>
    </dl>
</section>

<section class="stf-show-panel" aria-labelledby="stf-show-comp-heading">
    <h2 id="stf-show-comp-heading" class="stf-show-section-title">Compensation &amp; benefits</h2>
    <p class="stf-show-section-lead">Mirrors payroll / HR setup. Edit under Edit profile → Compensation.</p>
    <div class="stf-show-grid stf-show-grid--3">
        <div class="stf-show-grid__col">
            <h3 class="stf-show-subhead stf-show-subhead--in-grid">Pay</h3>
            <dl class="stf-show-dl">
                <div class="stf-show-dl__row"><dt>Pay type</dt><dd><?= $stfPay($staff['pay_type'] ?? '', $payTypeLabels) ?></dd></div>
                <div class="stf-show-dl__row"><dt>Classes</dt><dd><?= $stfPay($staff['pay_type_classes'] ?? '', $payTypeClassesLabels) ?></dd></div>
                <div class="stf-show-dl__row"><dt>Products</dt><dd><?= $stfPay($staff['pay_type_products'] ?? '', $payTypeProductsLabels) ?></dd></div>
            </dl>
        </div>
        <div class="stf-show-grid__col">
            <h3 class="stf-show-subhead stf-show-subhead--in-grid">Time off (days)</h3>
            <dl class="stf-show-dl">
                <div class="stf-show-dl__row"><dt>Vacation</dt><dd><?php $v = $staff['vacation_days'] ?? null; echo ($v !== null && $v !== '') ? htmlspecialchars((string) (int) $v, ENT_QUOTES, 'UTF-8') : '—'; ?></dd></div>
                <div class="stf-show-dl__row"><dt>Sick</dt><dd><?php $v = $staff['sick_days'] ?? null; echo ($v !== null && $v !== '') ? htmlspecialchars((string) (int) $v, ENT_QUOTES, 'UTF-8') : '—'; ?></dd></div>
                <div class="stf-show-dl__row"><dt>Personal</dt><dd><?php $v = $staff['personal_days'] ?? null; echo ($v !== null && $v !== '') ? htmlspecialchars((string) (int) $v, ENT_QUOTES, 'UTF-8') : '—'; ?></dd></div>
            </dl>
        </div>
        <div class="stf-show-grid__col">
            <h3 class="stf-show-subhead stf-show-subhead--in-grid">Classification</h3>
            <dl class="stf-show-dl">
                <div class="stf-show-dl__row"><dt>Exempt</dt><dd><?= $stfBool($staff['is_exempt'] ?? 0) ?></dd></div>
                <div class="stf-show-dl__row"><dt>Has dependents</dt><dd><?= $stfBool($staff['has_dependents'] ?? 0) ?></dd></div>
            </dl>
        </div>
    </div>
</section>

<?php
$profDesc = trim((string) ($staff['profile_description'] ?? ''));
$empNotes = trim((string) ($staff['employee_notes'] ?? ''));
?>
<section class="stf-show-panel" aria-labelledby="stf-show-notes-heading">
    <h2 id="stf-show-notes-heading" class="stf-show-section-title">Profile &amp; internal notes</h2>
    <h3 class="stf-show-subhead">Public profile text</h3>
    <?php if ($profDesc !== ''): ?>
    <div class="stf-show-prose"><?= nl2br(htmlspecialchars($profDesc, ENT_QUOTES, 'UTF-8')) ?></div>
    <?php else: ?>
    <p class="stf-show-empty-inline">No profile description. Add one in <a href="/staff/<?= $staffId ?>/edit" data-drawer-url="/staff/<?= $staffId ?>/edit">Edit profile</a>.</p>
    <?php endif; ?>
    <h3 class="stf-show-subhead">Internal notes</h3>
    <?php if ($empNotes !== ''): ?>
    <div class="stf-show-notes-block"><?= nl2br(htmlspecialchars($empNotes, ENT_QUOTES, 'UTF-8')) ?></div>
    <?php else: ?>
    <p class="stf-show-empty-inline">No internal notes.</p>
    <?php endif; ?>
</section>

<section class="stf-show-panel stf-show-panel--muted" aria-labelledby="stf-show-record-heading">
    <h2 id="stf-show-record-heading" class="stf-show-section-title">Record</h2>
    <dl class="stf-show-dl stf-show-dl--compact">
        <div class="stf-show-dl__row"><dt>Staff ID</dt><dd><?= $staffId ?></dd></div>
        <div class="stf-show-dl__row"><dt>Created</dt><dd><?= $stfDate($staff['created_at'] ?? null) ?></dd></div>
        <div class="stf-show-dl__row"><dt>Updated</dt><dd><?= $stfDate($staff['updated_at'] ?? null) ?></dd></div>
    </dl>
</section>

<section class="stf-show-panel" id="stf-show-schedule">
    <h2 class="stf-show-section-title">Weekly schedule</h2>
<?php if ($staffIsTrashed): ?>
    <p class="stf-show-section-lead">This profile is in Trash — schedule changes are disabled until restored.</p>
<?php else: ?>
    <p class="stf-show-section-lead">Recurring working hours (0 = Sun … 6 = Sat). Lunch windows apply inside each day when set.</p>
<?php endif; ?>
    <div class="stf-table-wrap stf-show-table-wrap">
<table class="index-table stf-show-table">
    <thead><tr>
        <th>Day</th>
        <th>Start</th>
        <th>End</th>
        <?php if ($scheduleHasLunch): ?><th>Lunch start</th><th>Lunch end</th><?php endif; ?>
        <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($schedules as $s): ?>
    <tr>
        <td><?= htmlspecialchars($dayNames[$s['day_of_week']] ?? (string) $s['day_of_week'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars(substr((string) $s['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars(substr((string) $s['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
        <?php if ($scheduleHasLunch): ?>
        <td><?php $x = $s['lunch_start_time'] ?? null; echo $x !== null && $x !== '' ? htmlspecialchars(substr((string) $x, 0, 5), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
        <td><?php $x = $s['lunch_end_time'] ?? null; echo $x !== null && $x !== '' ? htmlspecialchars(substr((string) $x, 0, 5), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
        <?php endif; ?>
        <td>
            <?php if (!$staffIsTrashed): ?>
            <form method="post" action="/staff/<?= $staffId ?>/schedules/<?= (int) $s['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this schedule entry?')">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="stf-act stf-act--trash">Remove</button>
            </form>
            <?php else: ?>—<?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($schedules)): ?>
    <tr class="stf-show-table-empty"><td colspan="<?= $scheduleHasLunch ? 6 : 4 ?>">No recurring hours yet. Use the form below to add a weekly block.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
    </div>
<?php if (!$staffIsTrashed): ?>
<form method="post" action="/staff/<?= $staffId ?>/schedules" class="stf-show-add-form" aria-label="Add weekly schedule row">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="stf-show-add-fields">
        <div class="stf-show-add-field">
            <label for="stf-sch-day">Day</label>
            <select id="stf-sch-day" name="day_of_week" required>
                <?php foreach ($dayNames as $dow => $name): ?>
                <option value="<?= $dow ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="stf-show-add-field">
            <label for="stf-sch-start">Start</label>
            <input id="stf-sch-start" type="time" name="start_time" required step="300">
        </div>
        <div class="stf-show-add-field">
            <label for="stf-sch-end">End</label>
            <input id="stf-sch-end" type="time" name="end_time" required step="300">
        </div>
    </div>
    <button type="submit" class="stf-show-add-submit">Add schedule</button>
</form>
<?php endif; ?>
</section>

<section class="stf-show-panel">
    <h2 class="stf-show-section-title">Breaks</h2>
<?php if (!$staffIsTrashed): ?>
    <p class="stf-show-section-lead">Recurring breaks (e.g. lunch) by day. Reduces available slots within working hours.</p>
<?php endif; ?>
    <div class="stf-table-wrap stf-show-table-wrap">
<table class="index-table stf-show-table">
    <thead><tr><th>Day</th><th>Start</th><th>End</th><th>Title</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($breaks as $b): ?>
    <tr>
        <td><?= htmlspecialchars($dayNames[$b['day_of_week']] ?? (string) $b['day_of_week'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars(substr((string) $b['start_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars(substr((string) $b['end_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars((string) ($b['title'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
        <td>
            <?php if (!$staffIsTrashed): ?>
            <form method="post" action="/staff/<?= $staffId ?>/breaks/<?= (int) $b['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Remove this break?')">
                <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <button type="submit" class="stf-act stf-act--trash">Remove</button>
            </form>
            <?php else: ?>—<?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($breaks)): ?>
    <tr class="stf-show-table-empty"><td colspan="5">No breaks yet. Add lunch or other blocks below.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
    </div>
<?php if (!$staffIsTrashed): ?>
<form method="post" action="/staff/<?= $staffId ?>/breaks" class="stf-show-add-form" aria-label="Add recurring break">
    <input type="hidden" name="<?= htmlspecialchars(config('app.csrf_token_name', 'csrf_token')) ?>" value="<?= htmlspecialchars($csrf) ?>">
    <div class="stf-show-add-fields">
        <div class="stf-show-add-field">
            <label for="stf-brk-day">Day</label>
            <select id="stf-brk-day" name="day_of_week" required>
                <?php foreach ($dayNames as $dow => $name): ?>
                <option value="<?= $dow ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="stf-show-add-field">
            <label for="stf-brk-start">Start</label>
            <input id="stf-brk-start" type="time" name="start_time" required step="300">
        </div>
        <div class="stf-show-add-field">
            <label for="stf-brk-end">End</label>
            <input id="stf-brk-end" type="time" name="end_time" required step="300">
        </div>
        <div class="stf-show-add-field stf-show-add-field--grow">
            <label for="stf-brk-title">Title</label>
            <input id="stf-brk-title" type="text" name="title" placeholder="e.g. Lunch" maxlength="100">
        </div>
    </div>
    <button type="submit" class="stf-show-add-submit">Add break</button>
</form>
<?php endif; ?>
</section>

<p class="stf-show-footer"><a href="/staff" class="stf-name-link">← Back to list</a><?php if ($staffIsTrashed): ?> · <a href="/staff?status=trash" class="stf-name-link">View Trash</a><?php endif; ?></p>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
