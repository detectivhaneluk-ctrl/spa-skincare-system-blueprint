<?php
$title = 'New Employee : Default Schedule (Step 4 of 4)';

// Day order: Mon … Fri … Sat … Sun for natural display.
// day_of_week: 0=Sun, 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat
$displayOrder = [1, 2, 3, 4, 5, 6, 0];
$dayLabels    = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

// Default working days for first-visit (Mon–Fri on, Sat–Sun off)
$defaultWorking = [1, 2, 3, 4, 5];

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
            <li class="wizard-steps__item wizard-steps__item--done">
                <span class="wizard-steps__number">3</span>
                <span class="wizard-steps__label">Services</span>
            </li>
            <li class="wizard-steps__item wizard-steps__item--active" aria-current="step">
                <span class="wizard-steps__number">4</span>
                <span class="wizard-steps__label">Schedule</span>
            </li>
        </ol>
    </nav>

    <div class="wizard-body">
        <header class="wizard-body__header">
            <h1 class="wizard-body__title">New Employee</h1>
            <p class="wizard-body__subtitle">Default Schedule (Step 4 of 4)</p>
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

        <form method="post" action="/staff/<?= (int) ($staff['id'] ?? 0) ?>/onboarding/step4" class="entity-form" id="step4-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

            <div class="form-section">
                <div class="form-section__header">
                    <h2 class="form-section__title">Default Weekly Schedule</h2>
                    <p class="form-section__hint">
                        Set the employee's normal working hours for each day of the week.
                        Toggle a day on to mark it as a working day and set its hours.
                        Lunch times are optional — leave blank if no fixed lunch break applies.
                    </p>
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
                        // Determine if this day has a saved row (return visit) or use defaults (first visit)
                        $hasSavedRow  = isset($schedule[$dow]);
                        if ($isFirstVisit) {
                            $isWorking  = in_array($dow, $defaultWorking, true);
                            $startTime  = $isWorking ? '09:00' : '09:00';
                            $endTime    = $isWorking ? '17:00' : '17:00';
                            $lunchStart = $isWorking ? '12:00' : '12:00';
                            $lunchEnd   = $isWorking ? '13:00' : '13:00';
                        } else {
                            $isWorking  = $hasSavedRow;
                            $savedRow   = $schedule[$dow] ?? [];
                            $startTime  = !empty($savedRow['start_time'])  ? substr($savedRow['start_time'], 0, 5)  : '09:00';
                            $endTime    = !empty($savedRow['end_time'])    ? substr($savedRow['end_time'], 0, 5)    : '17:00';
                            $lunchStart = !empty($savedRow['lunch_start_time']) ? substr($savedRow['lunch_start_time'], 0, 5) : '';
                            $lunchEnd   = !empty($savedRow['lunch_end_time'])   ? substr($savedRow['lunch_end_time'], 0, 5)   : '';
                        }
                        $dayId = 'day-' . $dow;
                    ?>
                    <div class="schedule-row <?= $isWorking ? 'schedule-row--working' : 'schedule-row--off' ?>" data-dow="<?= $dow ?>">
                        <span class="sg-col sg-col--day">
                            <strong><?= htmlspecialchars($dayLabels[$dow]) ?></strong>
                        </span>
                        <span class="sg-col sg-col--toggle">
                            <label class="toggle-label">
                                <input
                                    type="checkbox"
                                    name="schedule[<?= $dow ?>][is_working]"
                                    value="1"
                                    class="day-toggle"
                                    data-dow="<?= $dow ?>"
                                    <?= $isWorking ? 'checked' : '' ?>
                                >
                                <span class="toggle-label__text sr-only"><?= htmlspecialchars($dayLabels[$dow]) ?> working</span>
                            </label>
                        </span>
                        <span class="sg-col sg-col--time">
                            <input
                                type="time"
                                name="schedule[<?= $dow ?>][start_time]"
                                value="<?= htmlspecialchars($startTime, ENT_QUOTES, 'UTF-8') ?>"
                                class="day-time-input"
                                <?= !$isWorking ? 'disabled' : '' ?>
                                aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> work start"
                            >
                        </span>
                        <span class="sg-col sg-col--time">
                            <input
                                type="time"
                                name="schedule[<?= $dow ?>][end_time]"
                                value="<?= htmlspecialchars($endTime, ENT_QUOTES, 'UTF-8') ?>"
                                class="day-time-input"
                                <?= !$isWorking ? 'disabled' : '' ?>
                                aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> work end"
                            >
                        </span>
                        <span class="sg-col sg-col--time">
                            <input
                                type="time"
                                name="schedule[<?= $dow ?>][lunch_start_time]"
                                value="<?= htmlspecialchars($lunchStart, ENT_QUOTES, 'UTF-8') ?>"
                                class="day-time-input"
                                <?= !$isWorking ? 'disabled' : '' ?>
                                aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> lunch start"
                                placeholder="--:--"
                            >
                        </span>
                        <span class="sg-col sg-col--time">
                            <input
                                type="time"
                                name="schedule[<?= $dow ?>][lunch_end_time]"
                                value="<?= htmlspecialchars($lunchEnd, ENT_QUOTES, 'UTF-8') ?>"
                                class="day-time-input"
                                <?= !$isWorking ? 'disabled' : '' ?>
                                aria-label="<?= htmlspecialchars($dayLabels[$dow]) ?> lunch end"
                                placeholder="--:--"
                            >
                        </span>
                        <span class="sg-col sg-col--copy">
                            <button
                                type="button"
                                class="btn btn--xs btn--ghost btn-copy-prev"
                                data-dow="<?= $dow ?>"
                                title="Copy from previous day"
                                <?= !$isWorking ? 'disabled' : '' ?>
                            >Copy prev</button>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-actions">
                <a href="/staff/<?= (int) ($staff['id'] ?? 0) ?>/onboarding/step3" class="btn btn--secondary">Back</a>
                <a href="/staff" class="btn btn--ghost">Cancel</a>
                <button type="submit" class="btn btn--primary">Finish &amp; Complete Setup</button>
            </div>
        </form>
    </div>
</div>

<style>
.schedule-grid {
    display: flex;
    flex-direction: column;
    border: 1px solid #e5e7eb;
    border-radius: .5rem;
    overflow: hidden;
    font-size: .875rem;
}
.schedule-grid__header {
    display: grid;
    grid-template-columns: 130px 70px repeat(4, 1fr) 90px;
    gap: .5rem;
    align-items: center;
    padding: .5rem .75rem;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    font-weight: 600;
    font-size: .8rem;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.schedule-row {
    display: grid;
    grid-template-columns: 130px 70px repeat(4, 1fr) 90px;
    gap: .5rem;
    align-items: center;
    padding: .5rem .75rem;
    border-bottom: 1px solid #f3f4f6;
    transition: background .1s;
}
.schedule-row:last-child { border-bottom: none; }
.schedule-row--working { background: #fff; }
.schedule-row--off { background: #fafafa; }
.schedule-row--off .day-time-input { opacity: .35; }
.sg-col { display: flex; align-items: center; }
.sg-col--day { font-weight: 500; }
.sg-col--toggle { justify-content: center; }
.day-time-input {
    width: 100%;
    padding: .3rem .4rem;
    border: 1px solid #d1d5db;
    border-radius: .35rem;
    font-size: .85rem;
    background: #fff;
}
.day-time-input:disabled { background: #f9fafb; color: #9ca3af; cursor: not-allowed; }
.day-time-input:focus { outline: 2px solid #6366f1; outline-offset: 1px; }
.toggle-label { cursor: pointer; display: flex; align-items: center; justify-content: center; gap: .35rem; }
.toggle-label input { width: 1.1rem; height: 1.1rem; cursor: pointer; accent-color: #6366f1; }
.btn-copy-prev { white-space: nowrap; }
.btn--xs { padding: .25rem .55rem; font-size: .75rem; }
.btn--ghost {
    background: transparent;
    border: 1px solid #d1d5db;
    color: #374151;
}
.btn--ghost:hover:not(:disabled) { background: #f3f4f6; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; }

@media (max-width: 720px) {
    .schedule-grid__header { display: none; }
    .schedule-row {
        grid-template-columns: 1fr 1fr;
        grid-template-rows: auto auto auto;
        gap: .35rem;
        padding: .75rem;
    }
    .sg-col--day   { grid-column: 1; grid-row: 1; }
    .sg-col--toggle { grid-column: 2; grid-row: 1; justify-content: flex-end; }
    .sg-col--time  { grid-column: 1 / -1; }
    .sg-col--copy  { grid-column: 1 / -1; }
}
</style>

<script>
(function () {
    var form = document.getElementById('step4-form');
    if (!form) return;

    var displayOrder = <?= json_encode($displayOrder) ?>;

    function rowOf(dow) {
        return form.querySelector('.schedule-row[data-dow="' + dow + '"]');
    }
    function inputsOf(row) {
        return row.querySelectorAll('.day-time-input');
    }

    // Toggle working/off state on checkbox change
    form.querySelectorAll('.day-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            var dow    = cb.dataset.dow;
            var row    = rowOf(dow);
            var inputs = inputsOf(row);
            var copyBtn = row.querySelector('.btn-copy-prev');
            if (cb.checked) {
                row.classList.add('schedule-row--working');
                row.classList.remove('schedule-row--off');
                inputs.forEach(function (i) { i.disabled = false; });
                if (copyBtn) copyBtn.disabled = false;
            } else {
                row.classList.remove('schedule-row--working');
                row.classList.add('schedule-row--off');
                inputs.forEach(function (i) { i.disabled = true; });
                if (copyBtn) copyBtn.disabled = true;
            }
        });
    });

    // Copy previous day behavior
    form.querySelectorAll('.btn-copy-prev').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var dow = parseInt(btn.dataset.dow, 10);
            // Find the previous day in display order
            var idx = displayOrder.indexOf(dow);
            if (idx <= 0) return; // no previous day available
            var prevDow = displayOrder[idx - 1];
            var prevRow = rowOf(prevDow);
            if (!prevRow) return;
            var prevToggle = prevRow.querySelector('.day-toggle');
            if (!prevToggle || !prevToggle.checked) return; // previous day is off

            var srcInputs = Array.from(prevRow.querySelectorAll('.day-time-input'));
            var dstInputs = Array.from(rowOf(dow).querySelectorAll('.day-time-input'));
            // Copy values positionally (start, end, lunch_start, lunch_end)
            srcInputs.forEach(function (src, i) {
                if (dstInputs[i] && !dstInputs[i].disabled) {
                    dstInputs[i].value = src.value;
                }
            });
        });
    });
}());
</script>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
