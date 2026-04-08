<?php
$title = 'New Employee : Default Schedule (Step 4 of 4)';

// Day order: Mon … Sat … Sun for natural display.
// day_of_week: 0=Sun, 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat
$displayOrder   = [1, 2, 3, 4, 5, 6, 0];
$dayLabels      = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
$defaultWorking = [1, 2, 3, 4, 5];

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
    <div class="staff-onboard-step staff-onboard-step--done">
        <span class="staff-onboard-step__num">&#10003;</span>
        <span class="staff-onboard-step__label">Services</span>
    </div>
    <div class="staff-onboard-step staff-onboard-step--active">
        <span class="staff-onboard-step__num">4</span>
        <span class="staff-onboard-step__label">Schedule</span>
    </div>
</div>

<div class="staff-wizard-card">

    <!-- Card header -->
    <div class="staff-wizard-card__header">
        <div>
            <h1 class="staff-wizard-card__title">Default Weekly Schedule</h1>
            <p class="staff-wizard-card__sub">Step 4 of 4 &mdash; Setting up <strong><?= $displayName ?></strong></p>
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

    <p class="staff-wizard-schedule-hint">
        Toggle a day on to mark it as a working day and set hours. Lunch times are optional.
    </p>

    <form method="post" action="/staff/<?= $staffId ?>/onboarding/step4" id="step4-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= $csrfVal ?>">

        <!-- Schedule grid -->
        <div class="staff-schedule-grid">
            <!-- Header -->
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
                    $startTime  = '09:00';
                    $endTime    = '17:00';
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
                <span class="ssg-col ssg-col--day">
                    <strong><?= htmlspecialchars($dayLabels[$dow]) ?></strong>
                </span>
                <span class="ssg-col ssg-col--toggle">
                    <label class="staff-schedule-toggle">
                        <input
                            type="checkbox"
                            name="schedule[<?= $dow ?>][is_working]"
                            value="1"
                            class="day-toggle"
                            data-dow="<?= $dow ?>"
                            <?= $isWorking ? 'checked' : '' ?>
                        >
                        <span class="staff-schedule-toggle__track" aria-hidden="true"></span>
                        <span class="visually-hidden"><?= htmlspecialchars($dayLabels[$dow]) ?> working</span>
                    </label>
                </span>
                <span class="ssg-col ssg-col--time">
                    <input type="time" name="schedule[<?= $dow ?>][start_time]"
                           value="<?= htmlspecialchars($startTime, ENT_QUOTES, 'UTF-8') ?>"
                           class="staff-schedule-time day-time-input"
                           <?= !$isWorking ? 'disabled' : '' ?>
                           aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> work start">
                </span>
                <span class="ssg-col ssg-col--time">
                    <input type="time" name="schedule[<?= $dow ?>][end_time]"
                           value="<?= htmlspecialchars($endTime, ENT_QUOTES, 'UTF-8') ?>"
                           class="staff-schedule-time day-time-input"
                           <?= !$isWorking ? 'disabled' : '' ?>
                           aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> work end">
                </span>
                <span class="ssg-col ssg-col--time">
                    <input type="time" name="schedule[<?= $dow ?>][lunch_start_time]"
                           value="<?= htmlspecialchars($lunchStart, ENT_QUOTES, 'UTF-8') ?>"
                           class="staff-schedule-time day-time-input"
                           <?= !$isWorking ? 'disabled' : '' ?>
                           aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> lunch start"
                           placeholder="--:--">
                </span>
                <span class="ssg-col ssg-col--time">
                    <input type="time" name="schedule[<?= $dow ?>][lunch_end_time]"
                           value="<?= htmlspecialchars($lunchEnd, ENT_QUOTES, 'UTF-8') ?>"
                           class="staff-schedule-time day-time-input"
                           <?= !$isWorking ? 'disabled' : '' ?>
                           aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> lunch end"
                           placeholder="--:--">
                </span>
                <span class="ssg-col ssg-col--copy">
                    <button type="button" class="staff-schedule-copy btn-copy-prev"
                            data-dow="<?= $dow ?>"
                            title="Copy hours from previous day"
                            <?= !$isWorking ? 'disabled' : '' ?>>
                        Copy prev
                    </button>
                </span>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Actions -->
        <div class="staff-create-actions" style="margin-top:1.5rem;">
            <a href="/staff/<?= $staffId ?>/onboarding/step3" class="staff-create-btn-cancel">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
                Back
            </a>
            <a href="/staff" class="staff-create-btn-cancel">Cancel</a>
            <button type="submit" class="staff-create-btn-submit">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                Finish &amp; Complete Setup
            </button>
        </div>

    </form>
</div><!-- /.staff-wizard-card -->

<script>
(function () {
    var form = document.getElementById('step4-form');
    if (!form) return;

    var displayOrder = <?= json_encode($displayOrder) ?>;

    function rowOf(dow) {
        return form.querySelector('.staff-schedule-row[data-dow="' + dow + '"]');
    }

    function timeInputsOf(row) {
        return row.querySelectorAll('.day-time-input');
    }

    form.querySelectorAll('.day-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var row     = rowOf(cb.dataset.dow);
            var inputs  = timeInputsOf(row);
            var copyBtn = row.querySelector('.btn-copy-prev');
            var isOn    = cb.checked;
            row.classList.toggle('staff-schedule-row--on', isOn);
            row.classList.toggle('staff-schedule-row--off', !isOn);
            inputs.forEach(function (i) { i.disabled = !isOn; });
            if (copyBtn) copyBtn.disabled = !isOn;
        });
    });

    form.querySelectorAll('.btn-copy-prev').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var dow  = parseInt(btn.dataset.dow, 10);
            var idx  = displayOrder.indexOf(dow);
            if (idx <= 0) return;
            var prevRow = rowOf(displayOrder[idx - 1]);
            if (!prevRow) return;
            var prevToggle = prevRow.querySelector('.day-toggle');
            if (!prevToggle || !prevToggle.checked) return;
            var src = Array.from(prevRow.querySelectorAll('.day-time-input'));
            var dst = Array.from(rowOf(dow).querySelectorAll('.day-time-input'));
            src.forEach(function (s, i) {
                if (dst[i] && !dst[i].disabled) dst[i].value = s.value;
            });
        });
    });
}());
</script>

<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
