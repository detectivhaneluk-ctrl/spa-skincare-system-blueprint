<?php
$title = 'New Employee : Compensation and Benefits (Step 2 of 4)';

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

$staffId      = (int) ($staff['id'] ?? 0);
$displayName  = htmlspecialchars((string) ($staff['display_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$csrfName     = htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'), ENT_QUOTES, 'UTF-8');
$csrfVal      = htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8');

ob_start();
$teamWorkspaceActiveTab  = 'directory';
$teamWorkspaceShellTitle = 'Team';
require base_path('modules/staff/views/partials/team-workspace-shell.php');
?>

<!-- Step progress bar -->
<div class="staff-onboard-steps">
    <div class="staff-onboard-step staff-onboard-step--done">
        <span class="staff-onboard-step__num">&#10003;</span>
        <span class="staff-onboard-step__label">Employee Info</span>
    </div>
    <div class="staff-onboard-step staff-onboard-step--active">
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

<div class="staff-wizard-card">

    <!-- Card header -->
    <div class="staff-wizard-card__header">
        <div>
            <h1 class="staff-wizard-card__title">Compensation &amp; Benefits</h1>
            <p class="staff-wizard-card__sub">Step 2 of 4 &mdash; Setting up <strong><?= $displayName ?></strong></p>
        </div>
    </div>

    <?php if (!empty($flash['success'])): ?>
    <div class="staff-wizard-flash staff-wizard-flash--success" role="status">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars((string) $flash['success'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="staff-create-errors" role="alert">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>
            <strong>Please correct the following:</strong>
            <ul class="staff-create-errors__list">
                <?php foreach ($errors as $k => $e): ?>
                <?php if ($k === '_general' || is_string($e)): ?>
                <li><?= htmlspecialchars(is_string($e) ? $e : ($e['message'] ?? 'Invalid'), ENT_QUOTES, 'UTF-8') ?></li>
                <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <form method="post" action="/staff/<?= $staffId ?>/onboarding/step2" class="staff-create-form" novalidate>
        <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrfVal ?>">

        <!-- ── Section: Group ──────────────────────────────────────────── -->
        <div class="staff-create-section">
            <h3 class="staff-create-section__title">Staff Group</h3>
            <div class="staff-create-field <?= $hasError('primary_group_id') ? 'staff-create-field--error' : '' ?>">
                <label for="primary_group_id" class="staff-create-label">Group Assignment</label>
                <select id="primary_group_id" name="primary_group_id" class="staff-create-select">
                    <option value="">— No group assigned —</option>
                    <?php foreach ($groups as $g): ?>
                    <option value="<?= (int) $g['id'] ?>"
                        <?= (string) ($staff['primary_group_id'] ?? '') === (string) $g['id'] ? 'selected' : '' ?>
                    ><?= htmlspecialchars((string) $g['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($groups)): ?>
                <span class="staff-create-hint">No active groups configured. <a href="/staff/groups/admin/create" target="_blank" class="staff-create-hint-link">Create a group</a> first, then return here.</span>
                <?php endif; ?>
                <?php $showErr('primary_group_id'); ?>
            </div>
        </div>

        <!-- ── Section: Pay Type ──────────────────────────────────────── -->
        <div class="staff-create-section">
            <h3 class="staff-create-section__title">Pay Type — Services</h3>
            <div class="staff-create-field <?= $hasError('pay_type') ? 'staff-create-field--error' : '' ?>">
                <div class="staff-onboard-radio-stack">
                    <?php foreach ($payTypeLabels as $value => $label): ?>
                    <label class="staff-onboard-radio-option">
                        <input type="radio" name="pay_type" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                               <?= (($staff['pay_type'] ?? '') === $value) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php $showErr('pay_type'); ?>
            </div>
        </div>

        <!-- ── Section: Pay Type Classes/Workshops ────────────────────── -->
        <div class="staff-create-section">
            <h3 class="staff-create-section__title">Pay Type — Classes &amp; Workshops</h3>
            <div class="staff-create-field <?= $hasError('pay_type_classes') ? 'staff-create-field--error' : '' ?>">
                <div class="staff-onboard-radio-stack">
                    <?php foreach ($payTypeClassesLabels as $value => $label): ?>
                    <label class="staff-onboard-radio-option">
                        <input type="radio" name="pay_type_classes" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                               <?= (($staff['pay_type_classes'] ?? '') === $value) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php $showErr('pay_type_classes'); ?>
            </div>
        </div>

        <!-- ── Section: Pay Type Products ────────────────────────────── -->
        <div class="staff-create-section">
            <h3 class="staff-create-section__title">Pay Type — Products</h3>
            <div class="staff-create-field <?= $hasError('pay_type_products') ? 'staff-create-field--error' : '' ?>">
                <div class="staff-onboard-radio-stack">
                    <?php foreach ($payTypeProductsLabels as $value => $label): ?>
                    <label class="staff-onboard-radio-option">
                        <input type="radio" name="pay_type_products" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                               <?= (($staff['pay_type_products'] ?? '') === $value) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php $showErr('pay_type_products'); ?>
            </div>
        </div>

        <!-- ── Section: PTO & Benefits ───────────────────────────────── -->
        <div class="staff-create-section">
            <h3 class="staff-create-section__title">PTO &amp; Benefits</h3>

            <div class="staff-create-row-3">
                <div class="staff-create-field <?= $hasError('vacation_days') ? 'staff-create-field--error' : '' ?>">
                    <label for="vacation_days" class="staff-create-label staff-create-label--required">Vacation Days</label>
                    <input type="number" id="vacation_days" name="vacation_days" class="staff-create-input"
                           value="<?= $v('vacation_days') ?>" min="0" step="1" required>
                    <?php $showErr('vacation_days'); ?>
                </div>
                <div class="staff-create-field <?= $hasError('sick_days') ? 'staff-create-field--error' : '' ?>">
                    <label for="sick_days" class="staff-create-label staff-create-label--required">Sick Days</label>
                    <input type="number" id="sick_days" name="sick_days" class="staff-create-input"
                           value="<?= $v('sick_days') ?>" min="0" step="1" required>
                    <?php $showErr('sick_days'); ?>
                </div>
                <div class="staff-create-field <?= $hasError('personal_days') ? 'staff-create-field--error' : '' ?>">
                    <label for="personal_days" class="staff-create-label staff-create-label--required">Personal Days</label>
                    <input type="number" id="personal_days" name="personal_days" class="staff-create-input"
                           value="<?= $v('personal_days') ?>" min="0" step="1" required>
                    <?php $showErr('personal_days'); ?>
                </div>
            </div>

            <div class="staff-create-row-2" style="margin-top:0.75rem;">
                <div class="staff-create-field">
                    <label for="employee_number" class="staff-create-label">Employee ID</label>
                    <input type="text" id="employee_number" name="employee_number" class="staff-create-input"
                           value="<?= $v('employee_number') ?>" maxlength="100"
                           placeholder="e.g. EMP-001">
                    <span class="staff-create-hint">Business reference field, not a system ID.</span>
                </div>
                <div class="staff-create-field">
                    <label class="staff-create-label">Exemptions</label>
                    <div style="display:flex;flex-direction:column;gap:0.4rem;margin-top:0.1rem;">
                        <label class="staff-create-checkbox">
                            <input type="checkbox" name="has_dependents" value="1"
                                   <?= !empty($staff['has_dependents']) ? 'checked' : '' ?>>
                            <span>Has dependents</span>
                        </label>
                        <label class="staff-create-checkbox">
                            <input type="checkbox" name="is_exempt" value="1"
                                   <?= !empty($staff['is_exempt']) ? 'checked' : '' ?>>
                            <span>Exempt</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Actions ────────────────────────────────────────────────── -->
        <div class="staff-create-actions">
            <a href="/staff" class="staff-create-btn-cancel">Cancel</a>
            <a href="/staff/<?= $staffId ?>" class="staff-create-btn-cancel">View Record</a>
            <button type="submit" class="staff-create-btn-submit">
                Continue to Step 3
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
        </div>

    </form>
</div><!-- /.staff-wizard-card -->

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
