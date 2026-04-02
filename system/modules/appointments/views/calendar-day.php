<?php
$title = 'Appointment Day Calendar';
/** Binds calendar workspace flex/scroll layout without relying on :has() (older browsers / edge cases). */
$mainClass = trim((string) ($mainClass ?? '') . ' app-shell__main--calendar-workspace');
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$workspace['shell_modifier'] = 'workspace-shell--calendar';
$calDateRaw = $date ?? date('Y-m-d');
$calDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $calDateRaw) ? (string) $calDateRaw : date('Y-m-d');
ob_start();
?>
<div class="calendar-workspace" id="calendar-workspace-root" data-calendar-immersive-root>
<?php require base_path('modules/appointments/views/partials/workspace-shell.php'); ?>
<?php if (!empty($flash) && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>

<div class="appointments-workspace-page ds-page appts-calendar-page">
<div class="appts-calendar-body">
    <aside class="appts-calendar-rail appts-smart-calendar-rail" aria-label="Smart calendar">
        <div class="appts-cal-card" id="appts-cal-card" data-smart-calendar-root tabindex="0">
            <div class="appts-cal-card__top">
                <div class="appts-cal-card__mode" role="group" aria-label="Calendar view mode">
                    <button type="button" class="appts-cal-card__mode-btn appts-cal-card__mode-btn--active" id="appts-cal-mode-week" aria-pressed="true" data-cal-mode="week">Week</button>
                    <button type="button" class="appts-cal-card__mode-btn" id="appts-cal-mode-month" aria-pressed="false" data-cal-mode="month">Month</button>
                </div>
                <p class="appts-cal-card__title-month" id="appts-cal-context-month" aria-live="polite">—</p>
            </div>
            <p class="appts-cal-card__summary-status" id="appts-cal-summary-status" role="status" aria-live="polite" hidden></p>
            <div class="appts-cal-card__hero">
                <p class="appts-cal-card__hero-kicker" id="appts-cal-hero-kicker">Selected</p>
                <div class="appts-cal-card__hero-line">
                    <span class="appts-cal-card__hero-day" id="appts-cal-hero-day">—</span>
                    <span class="appts-cal-card__hero-weekday" id="appts-cal-hero-weekday"></span>
                </div>
            </div>
            <div class="appts-cal-card__nav appts-cal-card__nav--week" id="appts-cal-nav-week" role="group" aria-label="Change week">
                <button type="button" class="appts-cal-card__chev" id="appts-cal-prev-week" aria-label="Previous week">‹</button>
                <button type="button" class="appts-cal-card__today" id="appts-cal-today-week">Today</button>
                <button type="button" class="appts-cal-card__chev" id="appts-cal-next-week" aria-label="Next week">›</button>
            </div>
            <div class="appts-cal-card__nav appts-cal-card__nav--month is-cal-hidden" id="appts-cal-nav-month" role="group" aria-label="Change month">
                <button type="button" class="appts-cal-card__chev" id="appts-cal-prev-month" aria-label="Previous month">‹</button>
                <button type="button" class="appts-cal-card__today" id="appts-cal-today-month">Today</button>
                <button type="button" class="appts-cal-card__chev" id="appts-cal-next-month" aria-label="Next month">›</button>
            </div>
            <div class="appts-cal-card__body appts-cal-card__body--week" id="appts-cal-body-week">
                <div class="appts-cal-card__weekday-ribbon" aria-hidden="true">
                    <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
                </div>
                <div class="appts-cal-card__strip" id="appts-cal-strip" role="group" aria-label="Days this week"></div>
            </div>
            <div class="appts-cal-card__body appts-cal-card__body--month is-cal-hidden" id="appts-cal-body-month">
                <div class="appts-cal-month__dow" aria-hidden="true">
                    <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
                </div>
                <div class="appts-cal-month__grid" id="appts-cal-month-grid" role="group" aria-label="Month days"></div>
            </div>
        </div>
    </aside>
    <div class="appts-calendar-main">
        <div class="appts-command-strip" role="group" aria-label="Date, branch, and blocked time">
            <form method="get" action="/appointments/calendar/day" class="appts-command-strip__form" id="calendar-filter-form">
                <div class="appts-command-strip__fields">
                    <div class="appts-command-field appts-command-field--date-secondary">
                        <label class="appts-command-field__label" for="calendar-date">Go to date</label>
                        <input class="ds-input appts-command-field__control" type="date" id="calendar-date" name="date" value="<?= htmlspecialchars($calDate) ?>" required title="Jump to any date (syncs with calendar card)">
                    </div>
                    <div class="appts-command-field appts-command-field--grow">
                        <label class="appts-command-field__label" for="calendar-branch">Branch</label>
                        <?php if (count($branches) === 1): ?>
                        <span class="ds-input appts-command-field__control appts-command-field__control--locked"><?= htmlspecialchars($branches[0]['name']) ?></span>
                        <input type="hidden" id="calendar-branch" name="branch_id" value="<?= (int) $branches[0]['id'] ?>">
                        <?php else: ?>
                        <select class="ds-select appts-command-field__control" id="calendar-branch" name="branch_id">
                            <?php foreach ($branches as $b): ?>
                            <option value="<?= (int) $b['id'] ?>" <?= ((int)($branchId ?? 0) === (int)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
            <div class="appts-command-strip__actions">
                <?php if ($workspace['can_create'] ?? false): ?>
                <button type="button" class="ds-btn ds-btn--primary appts-command-strip__new-appt" data-calendar-new-appt>New appointment</button>
                <?php endif; ?>
                <button type="button" class="ds-btn ds-btn--secondary appts-immersive-exit" id="calendar-immersive-exit" data-calendar-immersive-exit hidden aria-hidden="true" aria-label="Restore full workspace header and navigation">Show chrome</button>
                <button type="button" class="ds-btn ds-btn--secondary" id="calendar-blocked-time-btn">Blocked time</button>
            </div>
        </div>
        <div class="appts-calendar-meta">
            <p class="appts-calendar-meta__hint">Staff columns · appointments and blocked time by length</p>
            <div id="calendar-status" class="appts-calendar-meta__status" role="status" aria-live="polite">Loading day calendar…</div>
        </div>
        <div id="calendar-branch-hours-indicator" class="appts-calendar-hours calendar-branch-hours-indicator" role="status" aria-live="polite"></div>
        <div class="appts-calendar-grid" id="appts-calendar-grid" data-branch-timezone="<?= htmlspecialchars($branchTimezone ?? 'UTC') ?>">
            <div id="calendar-day-wrap" class="calendar-day-wrap"></div>
        </div>
    </div>
</div>
</div>
</div>

<?php
$__apptsWeekSummaryJson = '';
if (!empty($calendarWeekSummaryBootstrap) && is_array($calendarWeekSummaryBootstrap)) {
    $__apptsWeekSummaryJson = json_encode($calendarWeekSummaryBootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($__apptsWeekSummaryJson === false) {
        $__apptsWeekSummaryJson = '';
    }
}
$__apptsMonthSummaryJson = '';
if (!empty($calendarMonthSummaryBootstrap) && is_array($calendarMonthSummaryBootstrap)) {
    $__apptsMonthSummaryJson = json_encode($calendarMonthSummaryBootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($__apptsMonthSummaryJson === false) {
        $__apptsMonthSummaryJson = '';
    }
}
?>
<?php if ($__apptsWeekSummaryJson !== ''): ?>
<script type="application/json" id="appts-calendar-week-summary-bootstrap"><?= $__apptsWeekSummaryJson ?></script>
<?php endif; ?>
<?php if ($__apptsMonthSummaryJson !== ''): ?>
<script type="application/json" id="appts-calendar-month-summary-bootstrap"><?= $__apptsMonthSummaryJson ?></script>
<?php endif; ?>

<script src="/assets/js/app-calendar-day.js" defer></script>
<script src="/assets/js/app-calendar-immersive.js" defer></script>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
?>
