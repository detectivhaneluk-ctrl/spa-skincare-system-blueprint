<?php
$title = 'New Employee : Select Services (Step 3 of 4)';
ob_start();
?>
<div class="wizard-layout">

    <nav class="wizard-steps" aria-label="Onboarding steps">
        <ol class="wizard-steps__list">
            <li class="wizard-steps__item wizard-steps__item--done">
                <span class="wizard-steps__number">1</span>
                <span class="wizard-steps__label">Employee Info</span>
            </li>
            <li class="wizard-steps__item wizard-steps__item--done">
                <span class="wizard-steps__number">2</span>
                <span class="wizard-steps__label">Compensation</span>
            </li>
            <li class="wizard-steps__item wizard-steps__item--active" aria-current="step">
                <span class="wizard-steps__number">3</span>
                <span class="wizard-steps__label">Services</span>
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
            <p class="wizard-body__subtitle">Select Treatments / Services (Step 3 of 4)</p>
            <p class="wizard-body__context">
                Setting up:
                <strong><?= htmlspecialchars((string) ($staff['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
            </p>
        </header>

        <?php if (!empty($flash['success'])): ?>
        <div class="flash flash--success" role="status"><?= htmlspecialchars((string) $flash['success'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (!empty($errors['_general'])): ?>
        <div class="flash flash--error" role="alert"><?= htmlspecialchars((string) $errors['_general'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="post" action="/staff/<?= (int) ($staff['id'] ?? 0) ?>/onboarding/step3" class="entity-form" id="step3-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <?php if (empty($serviceGroups)): ?>
            <div class="wizard-placeholder">
                <div class="wizard-placeholder__icon" aria-hidden="true">&#9888;</div>
                <h2 class="wizard-placeholder__heading">No services available</h2>
                <p class="wizard-placeholder__body">
                    No services have been set up for this branch yet.
                    You can <a href="/services/create">add services</a> in the Services section,
                    then return to complete this step.
                </p>
                <p class="wizard-placeholder__body">
                    You may also skip this step now and assign services later from the employee record.
                </p>
            </div>
            <?php else: ?>

            <div class="form-section">
                <div class="form-section__header">
                    <h2 class="form-section__title">Select which services this employee can perform</h2>
                    <p class="form-section__hint">Check each service this employee is trained and authorised to perform. You can update these at any time from the employee record.</p>
                </div>

                <div class="service-assignment-toolbar">
                    <button type="button" class="btn btn--sm btn--secondary" id="btn-select-all">Select All</button>
                    <button type="button" class="btn btn--sm btn--secondary" id="btn-clear-all">Clear All</button>
                </div>

                <div class="service-assignment-groups" id="service-groups">
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
                            <?php $isChecked = isset($assignedIds[$svcId]); ?>
                            <li class="service-checklist__item">
                                <label class="service-checklist__label">
                                    <input
                                        type="checkbox"
                                        name="service_ids[]"
                                        value="<?= $svcId ?>"
                                        class="service-checklist__checkbox"
                                        <?= $isChecked ? 'checked' : '' ?>
                                    >
                                    <span class="service-checklist__name"><?= htmlspecialchars((string) ($svc['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($svc['duration_minutes'])): ?>
                                    <span class="service-checklist__meta"><?= (int) $svc['duration_minutes'] ?> min</span>
                                    <?php endif; ?>
                                    <?php if (isset($svc['price']) && $svc['price'] !== null && $svc['price'] !== ''): ?>
                                    <span class="service-checklist__meta"><?= htmlspecialchars((string) $svc['price'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </label>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php endif; ?>

            <div class="form-actions">
                <a href="/staff/<?= (int) ($staff['id'] ?? 0) ?>/onboarding/step2" class="btn btn--secondary">Back</a>
                <a href="/staff" class="btn btn--ghost">Cancel</a>
                <button type="submit" class="btn btn--primary">Continue</button>
            </div>
        </form>
    </div>
</div>

<style>
.service-assignment-toolbar {
    display: flex;
    gap: .5rem;
    margin-bottom: 1.25rem;
}
.service-assignment-groups {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
.service-group {
    border: 1px solid #e5e7eb;
    border-radius: .5rem;
    overflow: hidden;
}
.service-group__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .625rem 1rem;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}
.service-group__name {
    font-size: .9375rem;
    font-weight: 600;
    margin: 0;
}
.service-group__actions {
    display: flex;
    gap: .5rem;
}
.service-checklist {
    list-style: none;
    margin: 0;
    padding: 0;
}
.service-checklist__item {
    border-bottom: 1px solid #f3f4f6;
}
.service-checklist__item:last-child {
    border-bottom: none;
}
.service-checklist__label {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .625rem 1rem;
    cursor: pointer;
    transition: background .1s;
}
.service-checklist__label:hover {
    background: #f3f4f6;
}
.service-checklist__checkbox {
    width: 1rem;
    height: 1rem;
    flex-shrink: 0;
    cursor: pointer;
}
.service-checklist__name {
    flex: 1;
    font-size: .9rem;
}
.service-checklist__meta {
    font-size: .8rem;
    color: #6b7280;
    white-space: nowrap;
}
.btn--xs {
    padding: .2rem .5rem;
    font-size: .75rem;
}
.btn--ghost {
    background: transparent;
    border: 1px solid #d1d5db;
    color: #374151;
}
.btn--ghost:hover {
    background: #f3f4f6;
}
</style>

<script>
(function () {
    var form      = document.getElementById('step3-form');
    if (!form) return;
    var allCb = function () { return form.querySelectorAll('input[type="checkbox"][name="service_ids[]"]'); };

    document.getElementById('btn-select-all').addEventListener('click', function () {
        allCb().forEach(function (cb) { cb.checked = true; });
    });
    document.getElementById('btn-clear-all').addEventListener('click', function () {
        allCb().forEach(function (cb) { cb.checked = false; });
    });

    form.querySelectorAll('.btn-group-select').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var group  = btn.closest('.service-group');
            var checks = group.querySelectorAll('input[type="checkbox"]');
            var select = btn.dataset.action === 'select';
            checks.forEach(function (cb) { cb.checked = select; });
        });
    });
}());
</script>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
