<?php
$title = 'New Employee : Select Services (Step 3 of 4)';

$staffId     = (int) ($staff['id'] ?? 0);
$displayName = htmlspecialchars((string) ($staff['display_name'] ?? ''), ENT_QUOTES, 'UTF-8');
$csrfVal     = htmlspecialchars((string) $csrf, ENT_QUOTES, 'UTF-8');

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
    <div class="staff-onboard-step staff-onboard-step--done">
        <span class="staff-onboard-step__num">&#10003;</span>
        <span class="staff-onboard-step__label">Compensation</span>
    </div>
    <div class="staff-onboard-step staff-onboard-step--active">
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
            <h1 class="staff-wizard-card__title">Services &amp; Treatments</h1>
            <p class="staff-wizard-card__sub">Step 3 of 4 &mdash; Setting up <strong><?= $displayName ?></strong></p>
        </div>
    </div>

    <?php if (!empty($flash['success'])): ?>
    <div class="staff-wizard-flash staff-wizard-flash--success" role="status">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars((string) $flash['success'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors['_general'])): ?>
    <div class="staff-create-errors" role="alert">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div><?= htmlspecialchars((string) $errors['_general'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php endif; ?>

    <form method="post" action="/staff/<?= $staffId ?>/onboarding/step3" id="step3-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $csrfVal ?>">

        <?php if (empty($serviceGroups)): ?>
        <!-- Empty state -->
        <div class="staff-wizard-empty">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" opacity=".3" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 12h6M12 9v6"/></svg>
            <p class="staff-wizard-empty__title">No services available</p>
            <p class="staff-wizard-empty__body">No services have been configured for this branch yet. <a href="/services/create" target="_blank" class="staff-create-hint-link">Add services</a> first, then return to complete this step.</p>
        </div>
        <?php else: ?>

        <!-- Toolbar: bulk select actions -->
        <div class="staff-svc-toolbar">
            <p class="staff-svc-toolbar__hint">Select which services this employee is trained and authorised to perform.</p>
            <div class="staff-svc-toolbar__actions">
                <button type="button" class="staff-svc-bulk-btn" id="btn-select-all">Select all</button>
                <button type="button" class="staff-svc-bulk-btn" id="btn-clear-all">Clear all</button>
            </div>
        </div>

        <!-- Service groups -->
        <div class="staff-svc-groups" id="service-groups">
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
                            <input type="checkbox" name="service_ids[]" value="<?= $svcId ?>"
                                   class="staff-svc-cb" <?= $isChecked ? 'checked' : '' ?>>
                            <span class="staff-svc-name"><?= htmlspecialchars((string) ($svc['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (!empty($svc['duration_minutes'])): ?>
                            <span class="staff-svc-meta"><?= (int) $svc['duration_minutes'] ?> min</span>
                            <?php endif; ?>
                            <?php if (isset($svc['price']) && $svc['price'] !== null && $svc['price'] !== ''): ?>
                            <span class="staff-svc-meta"><?= htmlspecialchars((string) $svc['price'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </label>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>

        <!-- Actions -->
        <div class="staff-create-actions">
            <a href="/staff/<?= $staffId ?>/onboarding/step2" class="staff-create-btn-cancel">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                Back
            </a>
            <a href="/staff" class="staff-create-btn-cancel">Cancel</a>
            <button type="submit" class="staff-create-btn-submit">
                Continue to Step 4
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
        </div>
    </form>
</div><!-- /.staff-wizard-card -->

<script>
(function () {
    var form = document.getElementById('step3-form');
    if (!form) return;
    var allCb = function () { return form.querySelectorAll('input[type="checkbox"][name="service_ids[]"]'); };

    function syncItemState(cb) {
        var item = cb.closest('.staff-svc-item');
        if (item) item.classList.toggle('staff-svc-item--checked', cb.checked);
    }

    allCb().forEach(function (cb) {
        cb.addEventListener('change', function () { syncItemState(cb); });
    });

    document.getElementById('btn-select-all').addEventListener('click', function () {
        allCb().forEach(function (cb) { cb.checked = true; syncItemState(cb); });
    });
    document.getElementById('btn-clear-all').addEventListener('click', function () {
        allCb().forEach(function (cb) { cb.checked = false; syncItemState(cb); });
    });

    form.querySelectorAll('.btn-group-select').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var group  = btn.closest('.staff-svc-group');
            var checks = group.querySelectorAll('input[type="checkbox"]');
            var select = btn.dataset.action === 'select';
            checks.forEach(function (cb) { cb.checked = select; syncItemState(cb); });
        });
    });
}());
</script>

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
