<?php
$title = 'Appointment Day Calendar';
/** Binds calendar workspace flex/scroll layout without relying on :has() (older browsers / edge cases). */
$mainClass = trim((string) ($mainClass ?? '') . ' app-shell__main--calendar-workspace');
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$workspace['shell_modifier'] = 'workspace-shell--calendar';
$calDateRaw = $date ?? date('Y-m-d');
$calDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $calDateRaw) ? (string) $calDateRaw : date('Y-m-d');
$calDateDisplay = $calDate;
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $calDate, $calDateM)) {
    $calDateDisplay = $calDateM[3] . '.' . $calDateM[2] . '.' . $calDateM[1];
}
$calBadgeLegendItems = \Modules\Appointments\Services\CalendarBadgeRegistry::legendItemsImplemented();
ob_start();
?>
<div class="calendar-workspace" id="calendar-workspace-root" data-calendar-immersive-root>
<?php require base_path('modules/appointments/views/partials/calendar-badge-sprite.php'); ?>
<?php require base_path('modules/appointments/views/partials/calendar-toolbar-bootstrap-sprite.php'); ?>
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
                    <button type="button" class="appts-cal-card__mode-btn" id="appts-cal-mode-two-months" aria-pressed="false" data-cal-mode="two-months">2 Months</button>
                </div>
            </div>
            <div class="appts-cal-card__header-row">
                <p class="appts-cal-card__title-month" id="appts-cal-context-month" aria-live="polite">—</p>
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
            </div>
            <p class="appts-cal-card__summary-status" id="appts-cal-summary-status" role="status" aria-live="polite" hidden></p>
            <div class="appts-cal-card__hero" aria-hidden="true">
                <p class="appts-cal-card__hero-kicker" id="appts-cal-hero-kicker">Selected</p>
                <div class="appts-cal-card__hero-line">
                    <span class="appts-cal-card__hero-day" id="appts-cal-hero-day">—</span>
                    <span class="appts-cal-card__hero-weekday" id="appts-cal-hero-weekday"></span>
                </div>
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
            <div class="appts-cal-card__body appts-cal-card__body--two-months is-cal-hidden" id="appts-cal-body-two-months">
                <div class="appts-cal-month__dow" aria-hidden="true">
                    <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
                </div>
                <div class="appts-cal-two-months" id="appts-cal-two-months" role="group" aria-label="Two months">
                    <section class="appts-cal-two-months__month">
                        <p class="appts-cal-two-months__label" id="appts-cal-two-months-label-1" aria-live="polite">—</p>
                        <div class="appts-cal-month__grid appts-cal-month__grid--two" id="appts-cal-two-months-grid-1" role="group" aria-label="Current month days"></div>
                    </section>
                    <section class="appts-cal-two-months__month">
                        <p class="appts-cal-two-months__label" id="appts-cal-two-months-label-2" aria-live="polite">—</p>
                        <div class="appts-cal-month__grid appts-cal-month__grid--two" id="appts-cal-two-months-grid-2" role="group" aria-label="Next month days"></div>
                    </section>
                </div>
            </div>
        </div>
        <div class="cal-tools-panel" id="cal-tools-panel" aria-label="Calendar tools panel">
            <nav class="cal-tools-tabs" role="tablist" aria-label="Calendar tools">
                <button type="button" class="cal-tools-tab cal-tools-tab--active" role="tab"
                        data-tools-tab="waitlist" aria-selected="true" aria-controls="cal-tools-waitlist"
                        title="Waitlist">
                    <svg class="cal-tools-tab__ic cal-tools-tab__ic--bi" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                        <use href="#bi-list-task"/>
                    </svg>
                    <span id="cal-tools-waitlist-badge" class="cal-tools-badge" hidden></span>
                </button>
                <button type="button" class="cal-tools-tab" role="tab"
                        data-tools-tab="checkin" aria-selected="false" aria-controls="cal-tools-checkin"
                        title="Check-in today">
                    <svg class="cal-tools-tab__ic cal-tools-tab__ic--bi" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                        <use href="#bi-check-circle"/>
                    </svg>
                    <span id="cal-tools-checkin-badge" class="cal-tools-badge" hidden></span>
                </button>
                <button type="button" class="cal-tools-tab" role="tab"
                        data-tools-tab="legend" aria-selected="false" aria-controls="cal-tools-legend"
                        title="Legend">
                    <svg class="cal-tools-tab__ic cal-tools-tab__ic--bi" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                        <use href="#bi-info-circle"/>
                    </svg>
                </button>
                <button type="button" class="cal-tools-tab" role="tab"
                        data-tools-tab="clipboard" aria-selected="false" aria-controls="cal-tools-clipboard"
                        title="Clipboard (held appointments)">
                    <svg class="cal-tools-tab__ic cal-tools-tab__ic--bi" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                        <use href="#bi-clipboard"/>
                    </svg>
                    <span id="cal-tools-clipboard-badge" class="cal-tools-badge" hidden></span>
                </button>
            </nav>
            <div class="cal-tools-body">
                <div id="cal-tools-waitlist" class="cal-tools-pane cal-tools-pane--active" role="tabpanel" tabindex="0">
                    <p class="cal-tools-hint">Loading…</p>
                </div>
                <div id="cal-tools-checkin" class="cal-tools-pane" role="tabpanel" tabindex="0" hidden>
                    <p class="cal-tools-hint">Loading…</p>
                </div>
                <div id="cal-tools-legend" class="cal-tools-pane" role="tabpanel" tabindex="0" hidden>
                    <ul class="cal-legend-list">
                        <li class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot--scheduled"></span>Scheduled</li>
                        <li class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot--confirmed"></span>Confirmed</li>
                        <li class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot--in-progress"></span>In Progress</li>
                        <li class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot--completed"></span>Completed</li>
                        <li class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot--cancelled"></span>Cancelled</li>
                        <li class="cal-legend-item"><span class="cal-legend-dot cal-legend-dot--no-show"></span>No Show</li>
                        <li class="cal-legend-item cal-legend-item--sep"></li>
                        <li class="cal-legend-item"><span class="cal-legend-stripe"></span>Blocked Time</li>
                        <?php if (!empty($calBadgeLegendItems)): ?>
                        <li class="cal-legend-item cal-legend-item--sep"></li>
                        <li class="cal-legend-item cal-legend-subhead" aria-hidden="true">Appointment tags</li>
                        <?php foreach ($calBadgeLegendItems as $ble): ?>
                        <li class="cal-legend-item cal-legend-item--badge">
                            <svg class="cal-legend-badge-ic" width="14" height="14" aria-hidden="true" style="color:var(--<?= htmlspecialchars((string) ($ble['color_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)">
                                <use href="#<?= htmlspecialchars((string) ($ble['icon_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"/>
                            </svg>
                            <span><?= htmlspecialchars((string) ($ble['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                        </li>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
                <div id="cal-tools-clipboard" class="cal-tools-pane" role="tabpanel" tabindex="0" hidden>
                    <p class="cal-tools-hint" id="cal-clipboard-empty-hint">Right-click any appointment and select <strong>"Hold on Clipboard"</strong> to park it here while you find a new slot.</p>
                    <div id="cal-clipboard-items" class="cal-clipboard-list" hidden></div>
                    <button type="button" class="cal-clipboard-clear-btn" id="cal-clipboard-clear" hidden title="Remove all from clipboard">Clear all</button>
                </div>
            </div>
        </div>
    </aside>
    <div class="appts-calendar-main">
        <section class="appts-calendar-control-surface appts-calendar-control-surface--premium" aria-label="Calendar filters and display tools">
        <form method="get" action="/appointments/calendar/day" id="calendar-filter-form" class="appts-cal-filter-sync-form" aria-hidden="true" tabindex="-1">
            <input type="hidden" name="date" id="calendar-date" value="<?= htmlspecialchars($calDate) ?>">
            <?php if (count($branches) === 1): ?>
            <input type="hidden" name="branch_id" id="calendar-branch" value="<?= (int) $branches[0]['id'] ?>">
            <?php endif; ?>
        </form>
        <div class="appts-command-strip appts-command-strip--premium" role="group" aria-label="Date, branch, tools, and blocked time">
            <div class="appts-command-strip__lead">
                <button type="button" class="appts-cal-toolbar-date-heading" id="calendar-toolbar-date-focus" aria-label="Focus mini calendar in sidebar">
                    <span class="appts-cal-toolbar-date-heading__text" id="calendar-toolbar-date-label"><?= htmlspecialchars($calDateDisplay) ?></span>
                </button>
                <div class="appts-cal-toolbar-day-nav" role="group" aria-label="Previous or next day">
                    <button type="button" class="appts-cal-toolbar-day-nav__btn" id="calendar-toolbar-prev-day" aria-label="Previous day" title="Previous day">
                        <svg class="appts-cal-toolbar-day-nav__ic" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><use href="#bi-chevron-left"/></svg>
                    </button>
                    <button type="button" class="appts-cal-toolbar-day-nav__btn" id="calendar-toolbar-next-day" aria-label="Next day" title="Next day">
                        <svg class="appts-cal-toolbar-day-nav__ic" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><use href="#bi-chevron-right"/></svg>
                    </button>
                </div>
                <button type="button" class="appts-cal-toolbar-today-btn" id="calendar-toolbar-today-btn" aria-label="Jump to today">Today</button>
                <span class="appts-cal-toolbar-divider" aria-hidden="true"></span>
                <div class="appts-cal-toolbar-branch-pill-wrap">
                    <?php if (count($branches) === 1): ?>
                    <span class="appts-cal-toolbar-branch-pill appts-cal-toolbar-branch-pill--static" title="Active branch">
                        <span class="appts-cal-toolbar-branch-pill__dot" aria-hidden="true"></span>
                        <span class="appts-cal-toolbar-branch-pill__name"><?= htmlspecialchars($branches[0]['name']) ?></span>
                    </span>
                    <?php else: ?>
                    <label class="visually-hidden" for="calendar-branch">Branch</label>
                    <div class="appts-cal-toolbar-branch-pill">
                        <span class="appts-cal-toolbar-branch-pill__dot" aria-hidden="true"></span>
                        <select class="appts-cal-toolbar-branch-select" id="calendar-branch" name="branch_id" form="calendar-filter-form" title="Switch branch">
                            <?php foreach ($branches as $b): ?>
                            <option value="<?= (int) $b['id'] ?>" <?= ((int)($branchId ?? 0) === (int)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <svg class="appts-cal-toolbar-branch-pill__chev" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><use href="#bi-chevron-down"/></svg>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="appts-command-strip__trail">
            <div class="appts-command-strip__actions appts-command-strip__actions--premium">
                <?php if ($workspace['can_create'] ?? false): ?>
                <button type="button" class="ds-btn ds-btn--primary appts-command-strip__new-appt" data-calendar-new-appt>New appointment</button>
                <?php endif; ?>
                <button type="button" class="ds-btn ds-btn--secondary appts-immersive-exit" id="calendar-immersive-exit" data-calendar-immersive-exit hidden aria-hidden="true" aria-label="Restore full workspace header and navigation">Show chrome</button>
            </div>
            <div class="appts-cal-context-anchor" id="cal-toolbar-context-anchor">
                <button type="button" class="appts-cal-toolbar-ghost-btn" id="calendar-blocked-time-btn">
                    <svg class="appts-cal-toolbar-ghost-btn__ic" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><use href="#bi-slash-circle"/></svg>
                    <span>Blocked time</span>
                </button>
                <div class="appts-cal-tools-cluster" id="cal-toolbar-tools-cluster">
                <div id="calendar-toolbar-context" class="appts-cal-toolbar__context" aria-label="Column visibility summary"></div>
                <div class="appts-cal-tools-dropdown">
                <button type="button" class="appts-cal-toolbar-ghost-btn appts-cal-tools-toggle" id="cal-toolbar-tools-toggle" aria-expanded="false" aria-controls="cal-toolbar-tools-panel" aria-haspopup="true">
                    <svg class="appts-cal-toolbar-ghost-btn__ic appts-cal-tools-toggle__icon appts-cal-toolbar__icon appts-cal-toolbar__icon--bi" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><use href="#bi-sliders"/></svg>
                    <span class="appts-cal-tools-toggle__label" id="cal-toolbar-tools-toggle-text">Tools</span>
                </button>
                <div id="cal-toolbar-tools-panel" class="appts-cal-tools-panel" role="region" aria-labelledby="cal-toolbar-tools-toggle-text" hidden>
                    <div class="appts-cal-tools-menu appts-calendar-toolbar" id="appts-calendar-toolbar" role="toolbar" aria-label="Calendar display tools">
                        <div class="appts-cal-tools-menu__header" id="cal-toolbar-tools-menu-heading">Menu</div>
                        <div class="appts-cal-tools-menu__group" role="group" aria-label="Calendar">
                            <button type="button" class="appts-cal-tools-menu__item" id="cal-toolbar-refresh" aria-label="Refresh calendar">
                                <span class="appts-cal-tools-menu__ic" aria-hidden="true"><svg class="appts-cal-toolbar__icon appts-cal-toolbar__icon--bi" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" focusable="false"><use href="#bi-arrow-clockwise"/></svg></span>
                                <span class="appts-cal-tools-menu__label">Refresh</span>
                            </button>
                        </div>
                        <hr class="appts-cal-tools-menu__sep" aria-hidden="true" />
                        <div class="appts-cal-tools-menu__group" role="group" aria-label="View controls">
            <div class="appts-cal-toolbar__slot appts-cal-toolbar__slot--tools-menu">
                <button type="button" class="appts-cal-tools-menu__item appts-cal-toolbar__btn" id="cal-toolbar-zoom" aria-expanded="false" aria-haspopup="dialog" aria-controls="cal-toolbar-zoom-pop" aria-label="Zoom">
                    <span class="appts-cal-tools-menu__ic" aria-hidden="true"><svg class="appts-cal-toolbar__icon appts-cal-toolbar__icon--bi" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" focusable="false"><use href="#bi-zoom-in"/></svg></span>
                    <span class="appts-cal-tools-menu__label appts-cal-toolbar__btn-text">Zoom</span>
                </button>
                <div class="appts-cal-toolbar__popover appts-cal-toolbar__popover--zoom" id="cal-toolbar-zoom-pop" hidden aria-hidden="true" role="dialog" aria-modal="false" aria-label="Zoom settings">
                    <div class="appts-cal-zoom__group">
                        <div class="appts-cal-zoom__header">
                            <label class="appts-cal-zoom__label" for="cal-toolbar-col-slider">Column width</label>
                            <div class="appts-cal-zoom__value-row">
                                <span class="appts-cal-zoom__value" id="cal-toolbar-col-value" aria-live="polite">160px</span>
                                <button type="button" class="appts-cal-zoom__reset" id="cal-toolbar-col-reset" aria-label="Reset column width to default" title="Reset to default">&#8635;</button>
                            </div>
                        </div>
                        <input type="range" id="cal-toolbar-col-slider" class="appts-cal-zoom__range" min="96" max="420" value="160" />
                        <div class="appts-cal-zoom__hints"><span>Narrow</span><span>Wide</span></div>
                    </div>
                    <hr class="appts-cal-zoom__sep" aria-hidden="true" />
                    <div class="appts-cal-zoom__group">
                        <div class="appts-cal-zoom__header">
                            <label class="appts-cal-zoom__label" for="cal-toolbar-zoom-slider">Time zoom</label>
                            <div class="appts-cal-zoom__value-row">
                                <span class="appts-cal-zoom__value" id="cal-toolbar-zoom-value" aria-live="polite">100%</span>
                                <button type="button" class="appts-cal-zoom__reset" id="cal-toolbar-zoom-reset" aria-label="Reset time zoom to default" title="Reset to default">&#8635;</button>
                            </div>
                        </div>
                        <input type="range" id="cal-toolbar-zoom-slider" class="appts-cal-zoom__range" min="25" max="200" value="100" />
                        <div class="appts-cal-zoom__hints"><span>Compact</span><span>Expanded</span></div>
                        <div class="appts-cal-zoom__presets" role="group" aria-label="Time zoom presets">
                            <button type="button" class="appts-cal-zoom__preset" data-zoom-preset="50">Compact</button>
                            <button type="button" class="appts-cal-zoom__preset" data-zoom-preset="100">Normal</button>
                            <button type="button" class="appts-cal-zoom__preset" data-zoom-preset="150">Large</button>
                            <button type="button" class="appts-cal-zoom__preset" id="cal-toolbar-zoom-fit">Fit</button>
                        </div>
                    </div>
                    <hr class="appts-cal-zoom__sep" aria-hidden="true" />
                    <label class="appts-cal-zoom__check">
                        <input type="checkbox" id="cal-toolbar-in-progress" class="appts-cal-zoom__checkbox" checked />
                        <span class="appts-cal-zoom__check-text">Show in-progress appointments</span>
                    </label>
                </div>
            </div>
            <div class="appts-cal-toolbar__slot appts-cal-toolbar__slot--tools-menu">
                <button type="button" class="appts-cal-tools-menu__item appts-cal-toolbar__btn" id="cal-toolbar-views" aria-expanded="false" aria-haspopup="dialog" aria-controls="cal-toolbar-views-pop" aria-label="Saved views">
                    <span class="appts-cal-tools-menu__ic" aria-hidden="true"><svg class="appts-cal-toolbar__icon appts-cal-toolbar__icon--bi" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" focusable="false"><use href="#bi-bookmarks"/></svg></span>
                    <span class="appts-cal-tools-menu__label appts-cal-toolbar__btn-text">Views</span>
                </button>
                <div class="appts-cal-toolbar__popover" id="cal-toolbar-views-pop" hidden aria-hidden="true" role="dialog" aria-modal="false" aria-label="Saved views">
                    <ul class="appts-cal-toolbar__views-list" id="cal-toolbar-views-list"></ul>
                    <button type="button" class="appts-cal-toolbar__menu-item" id="cal-toolbar-view-save">Save current view as…</button>
                    <button type="button" class="appts-cal-toolbar__menu-item" id="cal-toolbar-view-default">Set current view as default</button>
                    <button type="button" class="appts-cal-toolbar__menu-item appts-cal-toolbar__menu-item--danger" id="cal-toolbar-view-delete">Delete current view</button>
                    <p class="appts-cal-toolbar__inline-error visually-hidden" id="cal-toolbar-views-error" role="status" aria-live="polite"></p>
                </div>
            </div>
                        </div>
                        <hr class="appts-cal-tools-menu__sep" aria-hidden="true" />
                        <div class="appts-cal-tools-menu__group" role="group" aria-label="Staff and waitlist">
            <div class="appts-cal-toolbar__slot appts-cal-toolbar__slot--tools-menu">
                <button type="button" class="appts-cal-tools-menu__item appts-cal-toolbar__btn" id="cal-toolbar-staff" aria-expanded="false" aria-haspopup="dialog" aria-controls="cal-toolbar-staff-pop" aria-label="Staff columns">
                    <span class="appts-cal-tools-menu__ic" aria-hidden="true"><svg class="appts-cal-toolbar__icon appts-cal-toolbar__icon--bi" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" focusable="false"><use href="#bi-people"/></svg></span>
                    <span class="appts-cal-tools-menu__label appts-cal-toolbar__btn-text">Staff</span>
                </button>
                <div class="appts-cal-toolbar__popover appts-cal-toolbar__popover--wide" id="cal-toolbar-staff-pop" hidden aria-hidden="true" role="dialog" aria-modal="false" aria-label="Staff visibility">
                    <div id="cal-toolbar-staff-fields" class="appts-cal-staff-modal__grid"></div>
                    <div class="appts-cal-toolbar__footer">
                        <button type="button" class="ds-btn ds-btn--ghost" id="cal-toolbar-staff-all">Select all</button>
                        <button type="button" class="ds-btn ds-btn--ghost" id="cal-toolbar-staff-none">Deselect all</button>
                        <button type="button" class="ds-btn ds-btn--ghost" id="cal-toolbar-staff-cancel">Cancel</button>
                        <button type="button" class="ds-btn ds-btn--primary" id="cal-toolbar-staff-apply">Apply</button>
                    </div>
                </div>
            </div>
            <button type="button" class="appts-cal-tools-menu__item" id="cal-toolbar-folder" aria-label="Open waitlist panel">
                <span class="appts-cal-tools-menu__ic" aria-hidden="true"><svg class="appts-cal-toolbar__icon appts-cal-toolbar__icon--bi" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" focusable="false"><use href="#bi-folder2-open"/></svg></span>
                <span class="appts-cal-tools-menu__label">Waitlist</span>
            </button>
                        </div>
                        <hr class="appts-cal-tools-menu__sep" aria-hidden="true" />
                        <div class="appts-cal-tools-menu__group" role="group" aria-label="Print">
            <div class="appts-cal-toolbar__slot appts-cal-toolbar__slot--tools-menu">
                <button type="button" class="appts-cal-tools-menu__item appts-cal-toolbar__btn" id="cal-toolbar-print" aria-expanded="false" aria-haspopup="dialog" aria-controls="cal-toolbar-print-pop" aria-label="Print">
                    <span class="appts-cal-tools-menu__ic" aria-hidden="true"><svg class="appts-cal-toolbar__icon appts-cal-toolbar__icon--bi" width="18" height="18" viewBox="0 0 16 16" fill="currentColor" focusable="false"><use href="#bi-printer"/></svg></span>
                    <span class="appts-cal-tools-menu__label appts-cal-toolbar__btn-text">Print</span>
                </button>
                <div class="appts-cal-toolbar__popover" id="cal-toolbar-print-pop" hidden aria-hidden="true" role="dialog" aria-modal="false" aria-label="Print">
                    <a href="#" class="appts-cal-toolbar__menu-item" data-cal-print="calendar">Print calendar</a>
                    <a href="#" class="appts-cal-toolbar__menu-item" data-cal-print="planning">Print planning</a>
                    <a href="#" class="appts-cal-toolbar__menu-item" data-cal-print="appointments">Print appointments</a>
                    <a href="#" class="appts-cal-toolbar__menu-item" data-cal-print="itineraries">Print client itineraries</a>
                </div>
            </div>
                        </div>
                    </div>
                </div>
                </div>
            </div>
            </div>
            </div>
        </div>
        </section>
        <div class="appts-cal-dialog-backdrop" id="cal-toolbar-dialog-backdrop" hidden></div>
        <div class="appts-cal-dialog" id="cal-toolbar-save-dialog" role="dialog" aria-modal="true" aria-labelledby="cal-toolbar-save-title" hidden>
            <h3 class="appts-cal-dialog__title" id="cal-toolbar-save-title">Save current view</h3>
            <label class="appts-cal-dialog__label" for="cal-toolbar-save-name">View name</label>
            <input class="ds-input appts-cal-dialog__input" type="text" id="cal-toolbar-save-name" maxlength="120" autocomplete="off" />
            <p class="appts-cal-dialog__error visually-hidden" id="cal-toolbar-save-error" role="alert"></p>
            <div class="appts-cal-dialog__actions">
                <button type="button" class="ds-btn ds-btn--ghost" id="cal-toolbar-save-cancel">Cancel</button>
                <button type="button" class="ds-btn ds-btn--primary" id="cal-toolbar-save-confirm">Save view</button>
            </div>
        </div>
        <div class="appts-cal-dialog" id="cal-toolbar-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="cal-toolbar-delete-title" hidden>
            <h3 class="appts-cal-dialog__title" id="cal-toolbar-delete-title">Delete saved view?</h3>
            <p class="appts-cal-dialog__text">This action cannot be undone.</p>
            <p class="appts-cal-dialog__error visually-hidden" id="cal-toolbar-delete-error" role="alert"></p>
            <div class="appts-cal-dialog__actions">
                <button type="button" class="ds-btn ds-btn--ghost" id="cal-toolbar-delete-cancel">Cancel</button>
                <button type="button" class="ds-btn ds-btn--danger" id="cal-toolbar-delete-confirm">Delete view</button>
            </div>
        </div>
        <div id="calendar-prefs-alert" class="appts-calendar-prefs-alert" role="alert" hidden></div>
        <div class="appts-calendar-meta">
            <div id="calendar-status" class="appts-calendar-meta__status" role="status" aria-live="polite">Loading day calendar…</div>
        </div>
        <div id="calendar-branch-hours-indicator" class="appts-calendar-hours calendar-branch-hours-indicator" role="status" aria-live="polite"></div>
        <div class="appts-calendar-grid" id="appts-calendar-grid"
             data-branch-timezone="<?= htmlspecialchars($branchTimezone ?? 'UTC') ?>"
             data-csrf="<?= htmlspecialchars($csrf) ?>"
             data-csrf-name="<?= htmlspecialchars((string) config('app.csrf_token_name', 'csrf_token')) ?>"
             data-cal-cap-sales-create="<?= !empty($workspace['sales_create']) ? '1' : '0' ?>"
             data-cal-cap-sales-pay="<?= !empty($workspace['sales_pay']) ? '1' : '0' ?>"
             data-cal-cap-sales-view="<?= !empty($workspace['sales_view']) ? '1' : '0' ?>"
             data-cal-cap-appointments-create="<?= !empty($workspace['appointments_create']) ? '1' : '0' ?>">
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
<?php
$__calUiBootstrapJson = '';
if (!empty($calendarUiPageBootstrap) && is_array($calendarUiPageBootstrap)) {
    $__calUiBootstrapJson = json_encode($calendarUiPageBootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($__calUiBootstrapJson === false) {
        $__calUiBootstrapJson = '';
    }
}
?>
<?php if ($__calUiBootstrapJson !== ''): ?>
<script type="application/json" id="appts-calendar-ui-bootstrap"><?= $__calUiBootstrapJson ?></script>
<?php endif; ?>

<script src="/assets/js/app-calendar-day.js" defer></script>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
?>
