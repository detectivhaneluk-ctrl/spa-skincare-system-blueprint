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
    'none'                    => 'None',
    'commission'              => 'Commission',
    'commission_by_sales_tier' => 'Commission by Sales Tier',
    'per_product_fee'         => 'Per Product Fee (specified for each product)',
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

ob_start();
?>
<div class="wizard-layout">

    <nav class="wizard-steps" aria-label="Onboarding steps">
        <ol class="wizard-steps__list">
            <li class="wizard-steps__item wizard-steps__item--done">
                <span class="wizard-steps__number">1</span>
                <span class="wizard-steps__label">Employee Info</span>
            </li>
            <li class="wizard-steps__item wizard-steps__item--active" aria-current="step">
                <span class="wizard-steps__number">2</span>
                <span class="wizard-steps__label">Compensation</span>
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
            <p class="wizard-body__subtitle">Compensation and Benefits &mdash; Step 2 of 4</p>
            <p class="wizard-body__context">
                Setting up:
                <strong><?= htmlspecialchars((string) ($staff['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
            </p>
        </header>

        <?php if (!empty($flash['success'])): ?>
        <div class="flash flash--success" role="status"><?= htmlspecialchars((string) $flash['success'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

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
            action="/staff/<?= (int) ($staff['id'] ?? 0) ?>/onboarding/step2"
            class="wizard-form"
            novalidate
        >
            <input
                type="hidden"
                name="<?= htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token'), ENT_QUOTES, 'UTF-8') ?>"
                value="<?= htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8') ?>"
            >

            <?php /* ── Group selection ── */ ?>
            <div class="wizard-form__section">
                <div class="form-row <?= $hasError('primary_group_id') ? 'form-row--error' : '' ?>">
                    <label for="primary_group_id" class="form-label">Group</label>
                    <select id="primary_group_id" name="primary_group_id" class="form-select">
                        <option value="">— No group assigned —</option>
                        <?php foreach ($groups as $g): ?>
                        <option
                            value="<?= (int) $g['id'] ?>"
                            <?= (string) ($staff['primary_group_id'] ?? '') === (string) $g['id'] ? 'selected' : '' ?>
                        ><?= htmlspecialchars((string) $g['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($groups)): ?>
                    <span class="form-hint">
                        No active groups configured.
                        <a href="/staff/groups/admin/create" target="_blank">Create a group</a> first,
                        then return here to assign one.
                    </span>
                    <?php endif; ?>
                    <?php $showErr('primary_group_id'); ?>
                </div>
            </div>

            <?php /* ── Pay Type ── */ ?>
            <div class="wizard-form__section">
                <fieldset class="form-fieldset <?= $hasError('pay_type') ? 'form-row--error' : '' ?>">
                    <legend class="form-label form-label--required">Pay Type</legend>
                    <div class="form-radio-stack">
                        <?php foreach ($payTypeLabels as $value => $label): ?>
                        <label class="form-radio-label">
                            <input
                                type="radio"
                                name="pay_type"
                                value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                                <?= (($staff['pay_type'] ?? '') === $value) ? 'checked' : '' ?>
                            >
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php $showErr('pay_type'); ?>
                </fieldset>
            </div>

            <?php /* ── Pay Type Classes/Workshops ── */ ?>
            <div class="wizard-form__section">
                <fieldset class="form-fieldset <?= $hasError('pay_type_classes') ? 'form-row--error' : '' ?>">
                    <legend class="form-label form-label--required">Pay Type — Classes / Workshops</legend>
                    <div class="form-radio-stack">
                        <?php foreach ($payTypeClassesLabels as $value => $label): ?>
                        <label class="form-radio-label">
                            <input
                                type="radio"
                                name="pay_type_classes"
                                value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                                <?= (($staff['pay_type_classes'] ?? '') === $value) ? 'checked' : '' ?>
                            >
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php $showErr('pay_type_classes'); ?>
                </fieldset>
            </div>

            <?php /* ── Pay Type Products ── */ ?>
            <div class="wizard-form__section">
                <fieldset class="form-fieldset <?= $hasError('pay_type_products') ? 'form-row--error' : '' ?>">
                    <legend class="form-label form-label--required">Pay Type — Products</legend>
                    <div class="form-radio-stack">
                        <?php foreach ($payTypeProductsLabels as $value => $label): ?>
                        <label class="form-radio-label">
                            <input
                                type="radio"
                                name="pay_type_products"
                                value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                                <?= (($staff['pay_type_products'] ?? '') === $value) ? 'checked' : '' ?>
                            >
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php $showErr('pay_type_products'); ?>
                </fieldset>
            </div>

            <?php /* ── PTO days ── */ ?>
            <div class="wizard-form__section wizard-form__section--inline">
                <div class="form-row <?= $hasError('vacation_days') ? 'form-row--error' : '' ?>">
                    <label for="vacation_days" class="form-label form-label--required">Vacation Days</label>
                    <input
                        type="number"
                        id="vacation_days"
                        name="vacation_days"
                        class="form-input form-input--narrow"
                        value="<?= $v('vacation_days') ?>"
                        min="0"
                        step="1"
                        required
                    >
                    <?php $showErr('vacation_days'); ?>
                </div>

                <div class="form-row <?= $hasError('sick_days') ? 'form-row--error' : '' ?>">
                    <label for="sick_days" class="form-label form-label--required">Sick Days</label>
                    <input
                        type="number"
                        id="sick_days"
                        name="sick_days"
                        class="form-input form-input--narrow"
                        value="<?= $v('sick_days') ?>"
                        min="0"
                        step="1"
                        required
                    >
                    <?php $showErr('sick_days'); ?>
                </div>

                <div class="form-row <?= $hasError('personal_days') ? 'form-row--error' : '' ?>">
                    <label for="personal_days" class="form-label form-label--required">Personal Days</label>
                    <input
                        type="number"
                        id="personal_days"
                        name="personal_days"
                        class="form-input form-input--narrow"
                        value="<?= $v('personal_days') ?>"
                        min="0"
                        step="1"
                        required
                    >
                    <?php $showErr('personal_days'); ?>
                </div>
            </div>

            <?php /* ── Employee ID ── */ ?>
            <div class="wizard-form__section">
                <div class="form-row">
                    <label for="employee_number" class="form-label">Employee ID</label>
                    <input
                        type="text"
                        id="employee_number"
                        name="employee_number"
                        class="form-input"
                        value="<?= $v('employee_number') ?>"
                        maxlength="100"
                        placeholder="Business identifier, e.g. EMP-001"
                    >
                    <span class="form-hint">This is a business reference field, not a system ID.</span>
                </div>
            </div>

            <?php /* ── Exemptions ── */ ?>
            <div class="wizard-form__section">
                <fieldset class="form-fieldset">
                    <legend class="form-label">Exemptions</legend>
                    <div class="form-checkbox-group">
                        <label class="form-checkbox-label">
                            <input
                                type="checkbox"
                                name="has_dependents"
                                value="1"
                                <?= !empty($staff['has_dependents']) ? 'checked' : '' ?>
                            >
                            Has dependents
                        </label>
                        <label class="form-checkbox-label">
                            <input
                                type="checkbox"
                                name="is_exempt"
                                value="1"
                                <?= !empty($staff['is_exempt']) ? 'checked' : '' ?>
                            >
                            Exempt
                        </label>
                    </div>
                </fieldset>
            </div>

            <?php /* ── Form actions ── */ ?>
            <div class="wizard-form__actions">
                <a href="/staff" class="btn btn--secondary">Cancel</a>
                <a href="/staff/<?= (int) ($staff['id'] ?? 0) ?>" class="btn btn--secondary">Back to Record</a>
                <button type="submit" class="btn btn--primary">Continue</button>
            </div>

        </form>
    </div>
</div>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
