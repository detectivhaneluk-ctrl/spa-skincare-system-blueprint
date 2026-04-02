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
    <aside class="appts-calendar-rail appts-smart-calendar-rail" aria-label="Smart calendar navigation">
        <div class="appts-month-day-rail appts-smart-calendar" id="appts-month-day-rail" data-smart-calendar-root>
            <div class="appts-month-day-rail__toolbar appts-smart-calendar__toolbar">
                <p class="appts-month-day-rail__month-line appts-smart-calendar__month-label" id="appts-rail-month-label">Month</p>
                <div class="appts-month-day-rail__month-cluster" role="group" aria-label="Change month">
                    <button type="button" class="appts-month-day-rail__icon-btn" id="appts-rail-prev-month" aria-label="Previous month">‹</button>
                    <button type="button" class="appts-month-day-rail__icon-btn" id="appts-rail-next-month" aria-label="Next month">›</button>
                </div>
                <button type="button" class="ds-btn ds-btn--secondary appts-month-day-rail__today-btn" id="appts-rail-today">Today</button>
            </div>
            <div class="appts-month-day-rail__scroller" id="appts-rail-scroller">
                <div class="appts-month-day-rail__days" id="appts-rail-days" role="group" aria-label="Days in month"></div>
            </div>
        </div>
    </aside>
    <div class="appts-calendar-main">
        <div class="appts-command-strip" role="group" aria-label="Date, branch, and blocked time">
            <form method="get" action="/appointments/calendar/day" class="appts-command-strip__form" id="calendar-filter-form">
                <div class="appts-command-strip__fields">
                    <div class="appts-command-field appts-command-field--date-secondary">
                        <label class="appts-command-field__label" for="calendar-date">Go to date</label>
                        <input class="ds-input appts-command-field__control" type="date" id="calendar-date" name="date" value="<?= htmlspecialchars($calDate) ?>" required title="Jump to any date (same as left rail)">
                    </div>
                    <div class="appts-command-field appts-command-field--grow">
                        <label class="appts-command-field__label" for="calendar-branch">Branch</label>
                        <select class="ds-select appts-command-field__control" id="calendar-branch" name="branch_id">
                            <?php foreach ($branches as $b): ?>
                            <option value="<?= (int) $b['id'] ?>" <?= ((int)($branchId ?? 0) === (int)$b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </form>
            <div class="appts-command-strip__actions">
                <button type="button" class="ds-btn ds-btn--primary appts-command-strip__new-appt" data-calendar-new-appt>New appointment</button>
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
$__apptsMonthSummaryJson = '';
if (!empty($calendarMonthSummaryBootstrap) && is_array($calendarMonthSummaryBootstrap)) {
    $__apptsMonthSummaryJson = json_encode($calendarMonthSummaryBootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($__apptsMonthSummaryJson === false) {
        $__apptsMonthSummaryJson = '';
    }
}
?>
<?php if ($__apptsMonthSummaryJson !== ''): ?>
<script type="application/json" id="appts-calendar-month-summary-bootstrap"><?= $__apptsMonthSummaryJson ?></script>
<?php endif; ?>

<script>
(() => {
  const dateEl = document.getElementById('calendar-date');
  const railScroller = document.getElementById('appts-rail-scroller');
  const railDays = document.getElementById('appts-rail-days');
  const railMonthLabel = document.getElementById('appts-rail-month-label');
  const railPrevMonth = document.getElementById('appts-rail-prev-month');
  const railNextMonth = document.getElementById('appts-rail-next-month');
  const railTodayBtn = document.getElementById('appts-rail-today');
  const branchEl = document.getElementById('calendar-branch');
  const statusEl = document.getElementById('calendar-status');
  const branchHoursIndicatorEl = document.getElementById('calendar-branch-hours-indicator');
  const wrap = document.getElementById('calendar-day-wrap');
  const newAppointmentBtns = document.querySelectorAll('[data-calendar-new-appt]');
  const blockedTimeBtn = document.getElementById('calendar-blocked-time-btn');
  const PIXELS_PER_MINUTE = 1.4;
  const MIN_BLOCK_HEIGHT = 20;
  const MAX_TITLE_LENGTH = 48;
  const MAX_META_LENGTH = 56;
  /** Calendar grid / snap / hover / grid lines (from API time_grid.slot_minutes; fallback quarter-hour). */
  const GRID_STEP_FALLBACK_MINUTES = 15;
  /** Default booking length for create drawer prefill (start = clicked slot; end = start + this). Not grid step. */
  const DEFAULT_BOOKING_DURATION_MINUTES = 30;
  /** Branch-effective IANA timezone, validated client-side. Falls back to 'UTC' if Intl rejects the identifier. */
  const BRANCH_TIMEZONE = (() => {
    const raw = document.getElementById('appts-calendar-grid')?.dataset?.branchTimezone || 'UTC';
    try { new Intl.DateTimeFormat([], { timeZone: raw }); return raw; } catch (e) { return 'UTC'; }
  })();
  /**
   * Top inset added to all vertical pixel positions (labels, grid lines, blocks, now-line).
   * Prevents the first time label from being clipped by the sticky header — a translateY(-50%) at top:0
   * would otherwise overlap the header's background. The body height is also extended by this amount.
   */
  const GRID_TOP_INSET_PX = 10;
  let selectedSlot = null;
  /** AbortController for the in-flight /calendar/day fetch. Cancelled when a newer load() starts. */
  let currentLoadAbort = null;
  /** AbortController for GET /calendar/month-summary (stale-response safe). */
  let monthSummaryAbort = null;
  /** Last applied month summary payload (branch + visible month); null until bootstrap or fetch. */
  let latestMonthSummary = null;
  /** now-line: current grid vm reference; null when no calendar is rendered. */
  let nowLineVm = null;
  /** now-line: setInterval id; cleared whenever the grid is destroyed or re-rendered. */
  let nowLineTimer = null;
  /** now-line: true after first auto-scroll on today; reset when date or branch changes explicitly. */
  let nowLineScrolled = false;
  function currentCalendarQuery() {
    const params = new URLSearchParams();
    params.set('date', dateEl.value);
    if (branchEl.value) params.set('branch_id', branchEl.value);
    return params;
  }

  /**
   * Returns branch-local current time as { minutes, dateStr } using Intl.DateTimeFormat.
   * Falls back to browser-local time if Intl fails (should not happen; BRANCH_TIMEZONE is pre-validated).
   */
  function getBranchNow() {
    const now = new Date();
    try {
      const parts = new Intl.DateTimeFormat([], {
        timeZone: BRANCH_TIMEZONE,
        hour: '2-digit', minute: '2-digit',
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour12: false,
      }).formatToParts(now);
      let h = 0, m = 0, y = '', mo = '', d = '';
      for (const p of parts) {
        if (p.type === 'hour')   h  = parseInt(p.value, 10) || 0;
        if (p.type === 'minute') m  = parseInt(p.value, 10) || 0;
        if (p.type === 'year')   y  = p.value;
        if (p.type === 'month')  mo = p.value;
        if (p.type === 'day')    d  = p.value;
      }
      return { minutes: h * 60 + m, dateStr: y + '-' + mo + '-' + d };
    } catch (e) {
      return {
        minutes: now.getHours() * 60 + now.getMinutes(),
        dateStr: now.toISOString().slice(0, 10),
      };
    }
  }

  function addMonthsIso(isoDate, deltaM) {
    const parts = String(isoDate || '').split('-');
    if (parts.length !== 3) return isoDate;
    const y = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    const d = parseInt(parts[2], 10);
    if (!Number.isFinite(y) || !Number.isFinite(m) || !Number.isFinite(d)) return isoDate;
    const first = new Date(y, m - 1 + deltaM, 1);
    const ny = first.getFullYear();
    const nm = first.getMonth();
    const lastD = new Date(ny, nm + 1, 0).getDate();
    const nd = Math.min(d, lastD);
    const out = new Date(ny, nm, nd);
    return out.getFullYear() + '-' + String(out.getMonth() + 1).padStart(2, '0') + '-' + String(out.getDate()).padStart(2, '0');
  }

  function countAppointmentsInPayload(payload) {
    const g = payload && payload.appointments_by_staff;
    if (!g || typeof g !== 'object') return 0;
    let n = 0;
    for (const k of Object.keys(g)) {
      const arr = g[k];
      if (Array.isArray(arr)) n += arr.length;
    }
    return n;
  }

  function scrollRailToSelected() {
    if (!railScroller || !railDays) return;
    const sel = railDays.querySelector('.appts-month-day-rail__day--selected');
    if (!sel) return;
    let reduced = false;
    try {
      reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) { reduced = false; }
    sel.scrollIntoView({ block: 'center', inline: 'nearest', behavior: reduced ? 'auto' : 'smooth' });
  }

  function clearMonthSummaryDecorations() {
    if (!railDays) return;
    railDays.querySelectorAll('.appts-month-day-rail__day').forEach((el) => {
      el.classList.remove(
        'appts-month-day-rail__day--closed',
        'appts-month-day-rail__day--has-appts',
        'appts-month-day-rail__day--has-blocked',
        'appts-month-day-rail__day--busy-steady',
        'appts-month-day-rail__day--busy-heavy',
        'appts-month-day-rail__day--past',
        'appts-month-day-rail__day--future'
      );
      el.querySelectorAll('.appts-month-day-rail__day-count').forEach((n) => n.remove());
    });
  }

  function visibleMonthFromDateEl() {
    const cur = String(dateEl && dateEl.value ? dateEl.value : '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(cur)) return null;
    const y = parseInt(cur.slice(0, 4), 10);
    const m = parseInt(cur.slice(5, 7), 10);
    if (!Number.isFinite(y) || !Number.isFinite(m)) return null;
    return { y, m };
  }

  function applyMonthSummaryPayload(payload) {
    if (!payload || typeof payload !== 'object' || !payload.month_summary_contract || !railDays || !branchEl) {
      return;
    }
    const bid = parseInt(String(branchEl.value || '0'), 10) || 0;
    if ((Number(payload.branch_id) || 0) !== bid) {
      return;
    }
    const vm = visibleMonthFromDateEl();
    if (!vm || !payload.month) {
      return;
    }
    const py = Number(payload.month.year);
    const pm = Number(payload.month.month);
    if (py !== vm.y || pm !== vm.m) {
      clearMonthSummaryDecorations();
      latestMonthSummary = null;
      return;
    }
    latestMonthSummary = payload;
    clearMonthSummaryDecorations();
    const byDate = {};
    const list = Array.isArray(payload.days) ? payload.days : [];
    for (let i = 0; i < list.length; i++) {
      const row = list[i];
      if (row && row.date) byDate[row.date] = row;
    }
    railDays.querySelectorAll('.appts-month-day-rail__day').forEach((btn) => {
      const iso = btn.dataset.date;
      const row = byDate[iso];
      if (!row) return;
      if (row.branch_closed) btn.classList.add('appts-month-day-rail__day--closed');
      const ac = Number(row.appointment_count) || 0;
      if (ac > 0) {
        btn.classList.add('appts-month-day-rail__day--has-appts');
        const span = document.createElement('span');
        span.className = 'appts-month-day-rail__day-count';
        span.textContent = ac > 99 ? '99+' : String(ac);
        span.setAttribute('aria-label', ac + ' appointments');
        btn.appendChild(span);
      }
      if (row.has_blocked) btn.classList.add('appts-month-day-rail__day--has-blocked');
      if (row.busy_level === 'steady') btn.classList.add('appts-month-day-rail__day--busy-steady');
      if (row.busy_level === 'heavy') btn.classList.add('appts-month-day-rail__day--busy-heavy');
      if (row.is_past) btn.classList.add('appts-month-day-rail__day--past');
      if (row.is_future) btn.classList.add('appts-month-day-rail__day--future');
    });
  }

  async function loadMonthSummary() {
    const vm = visibleMonthFromDateEl();
    if (!vm || !dateEl) return;
    if (monthSummaryAbort) monthSummaryAbort.abort();
    monthSummaryAbort = new AbortController();
    const params = new URLSearchParams();
    params.set('year', String(vm.y));
    params.set('month', String(vm.m));
    params.set('date', String(dateEl.value || '').trim());
    if (branchEl && branchEl.value) params.set('branch_id', branchEl.value);
    try {
      const res = await fetch('/calendar/month-summary?' + params.toString(), {
        headers: { Accept: 'application/json' },
        signal: monthSummaryAbort.signal,
      });
      const payload = await res.json();
      const err = payload && typeof payload === 'object' ? payload.error : undefined;
      const errMsg = typeof err === 'string' ? err : err && typeof err === 'object' && typeof err.message === 'string' ? err.message : null;
      if (!res.ok || errMsg) {
        return;
      }
      if (payload && payload.month_summary_contract) {
        applyMonthSummaryPayload(payload);
      }
    } catch (e) {
      if (e && e.name === 'AbortError') return;
    }
  }

  function updateRailDayMeta(vm, apptCount) {
    if (latestMonthSummary || !railDays || !vm) return;
    const sel = railDays.querySelector('.appts-month-day-rail__day--selected');
    if (!sel) return;
    sel.classList.remove('appts-month-day-rail__day--closed', 'appts-month-day-rail__day--has-appts');
    const closed = (vm.branchHours && vm.branchHours.isClosedDay)
      || (vm.closureDate && vm.closureDate.active);
    if (closed) sel.classList.add('appts-month-day-rail__day--closed');
    if (apptCount > 0) sel.classList.add('appts-month-day-rail__day--has-appts');
  }

  function renderMonthRail() {
    if (!railDays || !railMonthLabel || !dateEl) return;
    const cur = String(dateEl.value || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(cur)) return;
    const y = parseInt(cur.slice(0, 4), 10);
    const mo = parseInt(cur.slice(5, 7), 10);
    if (!Number.isFinite(y) || !Number.isFinite(mo)) return;
    const first = new Date(y, mo - 1, 1);
    railMonthLabel.textContent = first.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    const lastDay = new Date(y, mo, 0).getDate();
    const todayStr = getBranchNow().dateStr;
    railDays.innerHTML = '';
    railDays.setAttribute('aria-label', 'Days in ' + first.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }));

    for (let d = 1; d <= lastDay; d++) {
      const iso = y + '-' + String(mo).padStart(2, '0') + '-' + String(d).padStart(2, '0');
      const dt = new Date(y, mo - 1, d);
      const narrow = dt.toLocaleDateString(undefined, { weekday: 'narrow' });
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'appts-month-day-rail__day';
      btn.dataset.date = iso;
      const longLabel = dt.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
      btn.setAttribute('aria-label', longLabel);
      if (iso === cur) {
        btn.classList.add('appts-month-day-rail__day--selected');
        btn.setAttribute('aria-current', 'date');
      }
      if (iso === todayStr) {
        btn.classList.add('appts-month-day-rail__day--today');
      }
      const wEl = document.createElement('span');
      wEl.className = 'appts-month-day-rail__day-wd';
      wEl.textContent = narrow;
      const nEl = document.createElement('span');
      nEl.className = 'appts-month-day-rail__day-num';
      nEl.textContent = String(d);
      const main = document.createElement('span');
      main.className = 'appts-month-day-rail__day-main';
      main.appendChild(wEl);
      main.appendChild(nEl);
      const dot = document.createElement('span');
      dot.className = 'appts-month-day-rail__day-dot';
      dot.setAttribute('aria-hidden', 'true');
      btn.appendChild(main);
      btn.appendChild(dot);
      btn.addEventListener('click', () => {
        if (dateEl.value === iso) return;
        selectedSlot = null;
        nowLineScrolled = false;
        dateEl.value = iso;
        renderMonthRail();
        syncCalendarUrl();
        load();
      });
      railDays.appendChild(btn);
    }
    const bootEl = document.getElementById('appts-calendar-month-summary-bootstrap');
    if (bootEl && bootEl.textContent) {
      try {
        const boot = JSON.parse(bootEl.textContent);
        if (boot && boot.month_summary_contract) {
          applyMonthSummaryPayload(boot);
        }
      } catch (e) { /* ignore */ }
      bootEl.remove();
    } else if (latestMonthSummary) {
      applyMonthSummaryPayload(latestMonthSummary);
    }
    requestAnimationFrame(scrollRailToSelected);
    loadMonthSummary();
  }

  function shiftCalendarMonth(deltaM) {
    const cur = dateEl.value;
    if (!cur) return;
    const next = addMonthsIso(cur, deltaM);
    selectedSlot = null;
    nowLineScrolled = false;
    dateEl.value = next;
    renderMonthRail();
    syncCalendarUrl();
    load();
  }

  function goToBranchToday() {
    const t = getBranchNow().dateStr;
    if (!t || !/^\d{4}-\d{2}-\d{2}$/.test(t)) return;
    if (dateEl.value === t) return;
    selectedSlot = null;
    nowLineScrolled = false;
    dateEl.value = t;
    renderMonthRail();
    syncCalendarUrl();
    load();
  }

  /** Remove now-line DOM elements and cancel the update timer. */
  function destroyNowLine() {
    if (nowLineTimer) { clearInterval(nowLineTimer); nowLineTimer = null; }
    nowLineVm = null;
    document.getElementById('ops-now-line-indicator')?.remove();
    document.getElementById('ops-now-label-indicator')?.remove();
  }

  /** Reposition (or hide) the now-line based on current branch-local time vs selected date. */
  function positionNowLine() {
    const line  = document.getElementById('ops-now-line-indicator');
    const label = document.getElementById('ops-now-label-indicator');
    if (!nowLineVm || !line || !label) return;
    const { minutes: nowMinutes, dateStr: nowDate } = getBranchNow();
    const isToday = dateEl.value === nowDate;
    const inRange = nowMinutes >= nowLineVm.start && nowMinutes <= nowLineVm.end;
    if (!isToday || !inRange) {
      line.hidden = true;
      label.hidden = true;
      return;
    }
    const topPx = (nowMinutes - nowLineVm.start) * PIXELS_PER_MINUTE + GRID_TOP_INSET_PX;
    line.style.top  = topPx + 'px';
    label.style.top = topPx + 'px';
    label.textContent = fmtTime(nowMinutes);
    line.hidden  = false;
    label.hidden = false;
  }

  /**
   * Create now-line and now-label DOM elements, position them, and start the 30-second update timer.
   * Also performs one-time auto-scroll near current time if today and not yet scrolled.
   */
  function initNowLine(vm) {
    destroyNowLine();
    nowLineVm = vm;
    const calBody = wrap.querySelector('.ops-calendar-body');
    if (!calBody) return;

    const line = document.createElement('div');
    line.id = 'ops-now-line-indicator';
    line.className = 'ops-now-line';
    line.hidden = true;
    line.setAttribute('aria-hidden', 'true');
    calBody.appendChild(line);

    // Label placed in .ops-calendar-body so it covers the time-axis column with its own background,
    // replacing the nearby time label instead of competing with it.
    const label = document.createElement('div');
    label.id = 'ops-now-label-indicator';
    label.className = 'ops-now-label';
    label.hidden = true;
    label.setAttribute('aria-hidden', 'true');
    calBody.appendChild(label);

    positionNowLine();

    if (!nowLineScrolled) {
      const { minutes: nowMinutes, dateStr: nowDate } = getBranchNow();
      const gridEl = document.getElementById('appts-calendar-grid');
      if (dateEl.value === nowDate && nowMinutes >= vm.start && nowMinutes <= vm.end && gridEl) {
        const topPx = (nowMinutes - vm.start) * PIXELS_PER_MINUTE + GRID_TOP_INSET_PX;
        const scrollTarget = Math.max(0, topPx - gridEl.clientHeight * 0.25);
        nowLineScrolled = true;
        setTimeout(() => gridEl.scrollTo({ top: scrollTarget, behavior: 'smooth' }), 100);
      }
    }

    nowLineTimer = setInterval(positionNowLine, 30_000);
  }

  function syncCalendarUrl() {
    if (!window.history || typeof window.history.replaceState !== 'function') {
      return;
    }
    const q = currentCalendarQuery();
    const suffix = q.toString();
    const path = '/appointments/calendar/day' + (suffix !== '' ? '?' + suffix : '');
    window.history.replaceState(null, '', path);
  }

  function openDrawerUrl(url) {
    if (window.AppDrawer && typeof window.AppDrawer.openUrl === 'function') {
      return window.AppDrawer.openUrl(url);
    }
    window.location.href = url;
    return Promise.resolve(true);
  }

  function buildNewAppointmentUrl() {
    const params = currentCalendarQuery();
    if (selectedSlot && selectedSlot.staffId) {
      params.set('staff_id', String(selectedSlot.staffId));
    }
    if (selectedSlot && selectedSlot.staffLabel) {
      params.set('staff_label', selectedSlot.staffLabel);
    }
    if (selectedSlot && selectedSlot.time) {
      params.set('time', selectedSlot.time);
    }
    const duration = selectedSlot && selectedSlot.bookingDurationMinutes != null
      ? Number(selectedSlot.bookingDurationMinutes)
      : DEFAULT_BOOKING_DURATION_MINUTES;
    if (Number.isFinite(duration) && duration > 0) {
      params.set('slot_minutes', String(duration));
    }
    return '/appointments/create?' + params.toString();
  }

  function buildBlockedTimeUrl() {
    return '/appointments/blocked-slots/panel?' + currentCalendarQuery().toString();
  }

  function snapTimeFromTop(offsetPx, dayStart, step) {
    const rawMinutes = dayStart + Math.max(0, Math.round((offsetPx - GRID_TOP_INSET_PX) / PIXELS_PER_MINUTE));
    const snapped = Math.round(rawMinutes / step) * step;
    return fmtTime(snapped);
  }

  function toMinutes(hhmm) {
    const [h, m] = String(hhmm || '00:00').split(':').map(Number);
    if (!Number.isFinite(h) || !Number.isFinite(m)) return 0;
    return (h * 60) + m;
  }

  function minutesFromDateTime(dt) {
    const hhmm = String(dt || '').slice(11, 16);
    return toMinutes(hhmm);
  }

  function safeLabel(text, maxLen) {
    const s = text != null ? String(text).trim() : '';
    if (maxLen && s.length > maxLen) return s.slice(0, maxLen) + '\u2026';
    return s;
  }

  function blockPlacement(startMins, endMins, dayStart, dayEnd, step) {
    const start = Number(startMins);
    const end = Number(endMins);
    if (!Number.isFinite(start) || !Number.isFinite(end)) return null;
    const safeEnd = dayEnd > dayStart ? dayEnd : dayStart + step;
    const clampedStart = Math.max(dayStart, Math.min(start, safeEnd));
    const clampedEnd = Math.max(dayStart, Math.min(end, safeEnd));
    if (clampedEnd <= clampedStart) return null;
    const top = Math.max(0, (clampedStart - dayStart) * PIXELS_PER_MINUTE);
    const height = Math.max(MIN_BLOCK_HEIGHT, (clampedEnd - clampedStart) * PIXELS_PER_MINUTE);
    return { top: Number(top) || 0, height: Number(height) || MIN_BLOCK_HEIGHT };
  }

  function fmtTime(totalMinutes) {
    const safe = Math.max(0, Math.floor(totalMinutes));
    const hh = String(Math.floor(safe / 60)).padStart(2, '0');
    const mm = String(safe % 60).padStart(2, '0');
    return hh + ':' + mm;
  }

  function fmtFromDt(dt) {
    const t = String(dt || '').slice(11, 16);
    return /^\d{2}:\d{2}$/.test(t) ? t : '';
  }

  function timeRangeLabel(startDt, endDt) {
    const a = fmtFromDt(startDt);
    const b = fmtFromDt(endDt);
    if (a && b) return a + '\u2013' + b;
    return a || b || '';
  }

  function buildTimeMarks(startMins, endMins, stepMin) {
    const marks = [];
    for (let cur = startMins; cur <= endMins; cur += stepMin) {
      marks.push(cur);
    }
    return marks;
  }

  function buildCalendarViewModel(payload) {
    if (!payload || typeof payload !== 'object') payload = {};
    const staff = Array.isArray(payload.staff) ? payload.staff : [];
    const grouped = payload.appointments_by_staff && typeof payload.appointments_by_staff === 'object' ? payload.appointments_by_staff : {};
    const calDisp = payload.appointment_calendar_display && typeof payload.appointment_calendar_display === 'object' ? payload.appointment_calendar_display : {};
    const LABEL_MODES = new Set(['client_and_service', 'service_and_client', 'service_only', 'client_only']);
    const serviceLabelMode = LABEL_MODES.has(calDisp.label_mode) ? calDisp.label_mode : 'client_and_service';
    const seriesLabelMode = LABEL_MODES.has(calDisp.series_label_mode) ? calDisp.series_label_mode : serviceLabelMode;
    const blocked = payload.blocked_by_staff && typeof payload.blocked_by_staff === 'object' ? payload.blocked_by_staff : {};
    const grid = payload.time_grid && typeof payload.time_grid === 'object' ? payload.time_grid : {};
    const branchHours = payload.branch_operating_hours && typeof payload.branch_operating_hours === 'object'
      ? payload.branch_operating_hours
      : {};
    const closureDate = payload.closure_date && typeof payload.closure_date === 'object'
      ? payload.closure_date
      : {};
    const rawGridStep = Number(grid.slot_minutes);
    const step = Number.isFinite(rawGridStep) && rawGridStep > 0 ? rawGridStep : GRID_STEP_FALLBACK_MINUTES;
    const dayStart = toMinutes(grid.day_start || '09:00');
    const dayEnd = toMinutes(grid.day_end || '18:00');
    const safeEnd = dayEnd > dayStart ? dayEnd : dayStart + step;
    const range = safeEnd - dayStart;

    const columns = staff.map((s) => {
      const sid = String(s.id);
      const appts = Array.isArray(grouped[sid]) ? grouped[sid] : [];
      const blocks = Array.isArray(blocked[sid]) ? blocked[sid] : [];
      const items = [];

      appts.forEach((a) => {
        const start = minutesFromDateTime(a.start_at);
        const end = minutesFromDateTime(a.end_at);
        const placement = blockPlacement(start, end, dayStart, dayEnd, step);
        if (!placement) return;
        const seriesId = a.series_id != null && String(a.series_id).trim() !== '' ? Number(a.series_id) : 0;
        const isSeriesLinked = seriesId > 0;
        const showStartTime = isSeriesLinked
          ? calDisp.series_show_start_time !== false
          : calDisp.show_start_time !== false;
        const labelMode = isSeriesLinked ? seriesLabelMode : serviceLabelMode;
        const serviceLine = safeLabel(a.service_name || 'Service', MAX_META_LENGTH);
        const clientLine = safeLabel(a.client_name || ('Appointment #' + (a.id ?? '')), MAX_TITLE_LENGTH) || safeLabel('Appointment', MAX_TITLE_LENGTH);
        let labelPrimary = clientLine;
        let metaLine = serviceLine;
        if (labelMode === 'client_and_service') {
          labelPrimary = clientLine;
          metaLine = serviceLine;
        } else if (labelMode === 'service_and_client') {
          labelPrimary = serviceLine;
          metaLine = clientLine;
        } else if (labelMode === 'service_only') {
          labelPrimary = serviceLine;
          metaLine = '';
        } else if (labelMode === 'client_only') {
          labelPrimary = clientLine;
          metaLine = '';
        }
        const endOnly = fmtFromDt(a.end_at);
        const timeLabel = showStartTime
          ? timeRangeLabel(a.start_at, a.end_at)
          : (endOnly ? ('Ends ' + endOnly) : '');
        const statusLine = safeLabel(a.status || 'scheduled', 32);
        const prebooked = !!(a.display_flags && a.display_flags.prebooked);
        const ns = a.client_no_show_alert && typeof a.client_no_show_alert === 'object' ? a.client_no_show_alert : null;
        const noShowAlert = !!(ns && ns.active);
        const noShowTitle = noShowAlert && ns.message ? String(ns.message) : '';
        items.push({
          kind: 'appointment',
          id: Number(a.id || 0),
          top: placement.top,
          height: placement.height,
          timeLabel,
          title: labelPrimary,
          meta: metaLine,
          statusLabel: statusLine,
          prebooked,
          noShowAlert,
          noShowTitle,
          link: '/appointments/' + (a.id ?? '')
        });
      });

      blocks.forEach((b) => {
        const start = minutesFromDateTime(b.start_at);
        const end = minutesFromDateTime(b.end_at);
        const placement = blockPlacement(start, end, dayStart, dayEnd, step);
        if (!placement) return;
        const labelPrimary = safeLabel(b.title || 'Blocked', MAX_TITLE_LENGTH) || safeLabel('Blocked', MAX_TITLE_LENGTH);
        const labelMeta = safeLabel(b.notes, MAX_META_LENGTH);
        items.push({
          kind: 'blocked',
          id: Number(b.id || 0),
          top: placement.top,
          height: placement.height,
          timeLabel: timeRangeLabel(b.start_at, b.end_at),
          title: labelPrimary,
          meta: labelMeta,
          link: null
        });
      });

      return {
        id: Number(s.id || 0),
        label: ((s.first_name || '') + ' ' + (s.last_name || '')).trim() || ('Staff #' + s.id),
        items
      };
    });

    return {
      columns,
      start: dayStart,
      end: safeEnd,
      step,
      marks: buildTimeMarks(dayStart, safeEnd, step),
      height: range * PIXELS_PER_MINUTE + GRID_TOP_INSET_PX,
      branchHours: {
        available: !!branchHours.branch_hours_available,
        isClosedDay: !!branchHours.is_closed_day,
        openTime: typeof branchHours.open_time === 'string' ? branchHours.open_time : null,
        closeTime: typeof branchHours.close_time === 'string' ? branchHours.close_time : null,
        outOfHoursAppointments: Number(branchHours.out_of_hours_appointments || 0)
      },
      closureDate: {
        storageReady: !!closureDate.storage_ready,
        active: !!closureDate.active,
        title: closureDate.title ? String(closureDate.title) : null,
        notes: closureDate.notes ? String(closureDate.notes) : null,
        recordsVisibleCount: Number(closureDate.records_visible_count || 0)
      }
    };
  }

  function renderCalendar(payload) {
    wrap.innerHTML = '';
    const apptCount = countAppointmentsInPayload(payload);
    const vm = buildCalendarViewModel(payload);
    renderBranchHoursIndicator(vm.branchHours, vm.closureDate);
    if (!vm.columns.length) {
      wrap.innerHTML = '<p class="calendar-empty-hint">No active staff for this branch and date.</p>';
      destroyNowLine();
      updateRailDayMeta(vm, apptCount);
      window.dispatchEvent(new CustomEvent('calendar-workspace:grid-updated'));
      return;
    }

    const root = document.createElement('div');
    root.className = 'ops-calendar';

    const head = document.createElement('div');
    head.className = 'ops-calendar-head';
    const headTime = document.createElement('div');
    headTime.className = 'ops-time-head';
    headTime.textContent = 'Time';
    head.appendChild(headTime);
    vm.columns.forEach((col) => {
      const h = document.createElement('div');
      h.className = 'ops-staff-head';
      const inner = document.createElement('div');
      inner.className = 'ops-staff-head-inner';
      const name = document.createElement('div');
      name.className = 'ops-staff-head-name';
      name.textContent = col.label;
      inner.appendChild(name);
      h.appendChild(inner);
      head.appendChild(h);
    });
    root.appendChild(head);

    const body = document.createElement('div');
    body.className = 'ops-calendar-body';
    body.style.height = vm.height + 'px';

    const labelsCol = document.createElement('div');
    labelsCol.className = 'ops-time-labels';
    vm.marks.forEach((mark) => {
      const row = document.createElement('div');
      row.className = 'ops-time-label';
      row.style.top = ((mark - vm.start) * PIXELS_PER_MINUTE + GRID_TOP_INSET_PX) + 'px';
      const mod = ((mark % 60) + 60) % 60;
      if (mod === 0) {
        row.classList.add('ops-time-label--hour');
        row.textContent = fmtTime(mark);
      } else if (mod === 30) {
        row.classList.add('ops-time-label--half');
        row.textContent = ':30';
      } else {
        row.classList.add('ops-time-label--micro');
      }
      labelsCol.appendChild(row);
    });
    body.appendChild(labelsCol);

    const laneWrap = document.createElement('div');
    laneWrap.className = 'ops-lanes';
    vm.columns.forEach((col) => {
      const lane = document.createElement('div');
      lane.className = 'ops-lane';
      lane.setAttribute('role', 'presentation');
      lane.dataset.staffId = String(col.id);
      const hoverPreview = document.createElement('div');
      hoverPreview.className = 'ops-slot-preview';
      hoverPreview.hidden = true;
      const hoverLabel = document.createElement('div');
      hoverLabel.className = 'ops-slot-preview__label';
      hoverPreview.appendChild(hoverLabel);
      lane.appendChild(hoverPreview);

      vm.marks.forEach((mark) => {
        const line = document.createElement('div');
        line.className = 'ops-grid-line';
        line.style.top = ((mark - vm.start) * PIXELS_PER_MINUTE + GRID_TOP_INSET_PX) + 'px';
        lane.appendChild(line);
      });

      const envelope = branchEnvelopeForLane(vm.branchHours, vm.start, vm.end);
      if (envelope !== null) {
        if (envelope.beforeHeight > 0) {
          const before = document.createElement('div');
          before.className = 'ops-lane-offhours ops-lane-offhours--before';
          before.style.top = GRID_TOP_INSET_PX + 'px';
          before.style.height = envelope.beforeHeight + 'px';
          lane.appendChild(before);
        }
        if (envelope.afterHeight > 0) {
          const after = document.createElement('div');
          after.className = 'ops-lane-offhours ops-lane-offhours--after';
          after.style.top = (envelope.afterTop + GRID_TOP_INSET_PX) + 'px';
          after.style.height = envelope.afterHeight + 'px';
          lane.appendChild(after);
        }
      }

      col.items
        .sort((a, b) => {
          const byTop = (Number(a.top) || 0) - (Number(b.top) || 0);
          if (byTop !== 0) return byTop;
          const kindOrder = (a.kind === 'blocked' ? 1 : 0) - (b.kind === 'blocked' ? 1 : 0);
          if (kindOrder !== 0) return kindOrder;
          return (Number(a.id) || 0) - (Number(b.id) || 0);
        })
        .forEach((item) => {
          const topPx = Math.max(0, Number(item.top) || 0);
          const heightPx = Math.max(MIN_BLOCK_HEIGHT, Number(item.height) || MIN_BLOCK_HEIGHT);
          const isDrawerBlock = !!item.link || item.kind === 'blocked';
          const block = document.createElement(isDrawerBlock ? 'a' : 'div');
          block.className = 'ops-block ' + (item.kind === 'blocked' ? 'ops-block-blocked' : 'ops-block-appt');
          if (item.kind === 'appointment' && item.prebooked) {
            block.classList.add('ops-block-appt--prebooked');
          }
          if (item.kind === 'appointment' && item.noShowAlert) {
            block.classList.add('ops-block-appt--no-show-alert');
            if (item.noShowTitle) {
              block.setAttribute('title', item.noShowTitle);
            }
          }
          block.setAttribute('data-block-type', item.kind === 'blocked' ? 'blocked' : 'appointment');
          if (item.statusLabel) {
            block.setAttribute('data-status', String(item.statusLabel).toLowerCase().replace(/\s+/g, '-').slice(0, 40));
          }
          block.style.top = (topPx + GRID_TOP_INSET_PX) + 'px';
          block.style.height = heightPx + 'px';
          if (item.link) {
            block.href = item.link;
            block.dataset.drawerUrl = item.link;
          } else if (item.kind === 'blocked') {
            block.href = buildBlockedTimeUrl();
            block.dataset.drawerUrl = buildBlockedTimeUrl();
          }
          if (item.timeLabel) {
            const timeEl = document.createElement('div');
            timeEl.className = 'ops-block-time';
            timeEl.textContent = item.timeLabel;
            block.appendChild(timeEl);
          }
          if (item.kind === 'blocked') {
            const kindEl = document.createElement('div');
            kindEl.className = 'ops-block-kind';
            kindEl.textContent = 'Blocked';
            block.appendChild(kindEl);
          }
          const ttl = document.createElement('div');
          ttl.className = 'ops-block-title';
          ttl.textContent = safeLabel(item.title, MAX_TITLE_LENGTH) || (item.kind === 'blocked' ? 'Blocked' : 'Appointment');
          block.appendChild(ttl);
          if (item.meta) {
            const meta = document.createElement('div');
            meta.className = 'ops-block-meta';
            meta.textContent = safeLabel(item.meta, MAX_META_LENGTH);
            block.appendChild(meta);
          }
          if (item.kind === 'appointment' && item.statusLabel) {
            const st = document.createElement('div');
            st.className = 'ops-block-status';
            st.textContent = safeLabel(item.statusLabel, 32);
            block.appendChild(st);
          }
          lane.appendChild(block);
        });

      lane.addEventListener('mousemove', (e) => {
        if (e.target.closest('.ops-block')) {
          hoverPreview.hidden = true;
          return;
        }
        const rect = lane.getBoundingClientRect();
        const offsetY = e.clientY - rect.top;
        const snapped = snapTimeFromTop(offsetY, vm.start, vm.step);
        const topPx = Math.max(GRID_TOP_INSET_PX, (toMinutes(snapped) - vm.start) * PIXELS_PER_MINUTE + GRID_TOP_INSET_PX);
        hoverPreview.hidden = false;
        hoverPreview.style.top = topPx + 'px';
        hoverLabel.textContent = col.label + ' · ' + snapped;
      });

      lane.addEventListener('mouseleave', () => {
        hoverPreview.hidden = true;
      });

      lane.addEventListener('click', async (e) => {
        if (e.target.closest('.ops-block')) return;
        e.preventDefault();
        e.stopPropagation();
        const rect = lane.getBoundingClientRect();
        const offsetY = e.clientY - rect.top;
        selectedSlot = {
          staffId: col.id,
          staffLabel: col.label,
          time: snapTimeFromTop(offsetY, vm.start, vm.step),
          bookingDurationMinutes: DEFAULT_BOOKING_DURATION_MINUTES,
        };
        const url = buildNewAppointmentUrl();
        const started = await openDrawerUrl(url);
        if (!started) {
          statusEl.textContent = '';
          return;
        }
        statusEl.textContent = 'Opening new appointment for ' + col.label + ' at ' + selectedSlot.time + '.';
      });

      laneWrap.appendChild(lane);
    });

    body.appendChild(laneWrap);
    root.appendChild(body);
    wrap.appendChild(root);
    initNowLine(vm);
    updateRailDayMeta(vm, apptCount);
    window.dispatchEvent(new CustomEvent('calendar-workspace:grid-updated'));
  }

  function renderBranchHoursIndicator(meta, closureMeta) {
    if (!branchHoursIndicatorEl) return;
    const closureStorageReady = !!(closureMeta && closureMeta.storageReady);
    const closureActive = !!(closureMeta && closureMeta.active);
    const closureTitle = closureMeta && closureMeta.title ? String(closureMeta.title).trim() : '';
    const closureNotes = closureMeta && closureMeta.notes ? String(closureMeta.notes).trim() : '';
    const closureVisibleRecords = Number(closureMeta && closureMeta.recordsVisibleCount ? closureMeta.recordsVisibleCount : 0);

    if (!closureStorageReady) {
      branchHoursIndicatorEl.textContent = 'Closure-date storage is not available yet. Calendar uses existing operating-hours data only.';
      branchHoursIndicatorEl.className = 'appts-calendar-hours calendar-branch-hours-indicator calendar-branch-hours-indicator--missing';
      return;
    }

    if (closureActive) {
      const titlePart = closureTitle !== '' ? (' ' + closureTitle + '.') : '';
      const notesPart = closureNotes !== '' ? (' ' + closureNotes) : '';
      const anomalyPart = closureVisibleRecords > 0
        ? (' ' + closureVisibleRecords + ' existing record(s) are still visible for review.')
        : '';
      branchHoursIndicatorEl.textContent = 'Closed day (closure date).' + titlePart + notesPart + anomalyPart;
      branchHoursIndicatorEl.className = 'appts-calendar-hours calendar-branch-hours-indicator calendar-branch-hours-indicator--closed';
      return;
    }

    const available = !!(meta && meta.available);
    const isClosed = !!(meta && meta.isClosedDay);
    const openTime = meta && meta.openTime ? String(meta.openTime).slice(0, 5) : '';
    const closeTime = meta && meta.closeTime ? String(meta.closeTime).slice(0, 5) : '';
    const anomalies = Number(meta && meta.outOfHoursAppointments ? meta.outOfHoursAppointments : 0);
    if (!available) {
      branchHoursIndicatorEl.textContent = 'Opening hours not configured for this branch/day.';
      branchHoursIndicatorEl.className = 'appts-calendar-hours calendar-branch-hours-indicator calendar-branch-hours-indicator--missing';
      return;
    }
    if (isClosed) {
      branchHoursIndicatorEl.textContent = anomalies > 0
        ? ('Closed today. ' + anomalies + ' existing appointment(s) found on a closed day.')
        : 'Closed today.';
      branchHoursIndicatorEl.className = 'appts-calendar-hours calendar-branch-hours-indicator calendar-branch-hours-indicator--closed';
      return;
    }
    const base = (openTime && closeTime)
      ? ('Branch hours: ' + openTime + '-' + closeTime)
      : 'Opening hours not configured for this branch/day.';
    const suffix = anomalies > 0 ? (' | ' + anomalies + ' appointment(s) outside branch hours.') : '';
    branchHoursIndicatorEl.textContent = base + suffix;
    branchHoursIndicatorEl.className = 'appts-calendar-hours calendar-branch-hours-indicator calendar-branch-hours-indicator--open';
  }

  function branchEnvelopeForLane(meta, dayStart, dayEnd) {
    if (!meta || !meta.available) return null;
    const range = Math.max(1, (dayEnd - dayStart));
    if (meta.isClosedDay) {
      return {
        beforeHeight: range * PIXELS_PER_MINUTE,
        afterTop: range * PIXELS_PER_MINUTE,
        afterHeight: 0
      };
    }
    const open = toMinutes(meta.openTime || '');
    const close = toMinutes(meta.closeTime || '');
    if (!Number.isFinite(open) || !Number.isFinite(close) || close <= open) {
      return null;
    }
    const openClamped = Math.max(dayStart, Math.min(open, dayEnd));
    const closeClamped = Math.max(dayStart, Math.min(close, dayEnd));
    return {
      beforeHeight: Math.max(0, (openClamped - dayStart) * PIXELS_PER_MINUTE),
      afterTop: Math.max(0, (closeClamped - dayStart) * PIXELS_PER_MINUTE),
      afterHeight: Math.max(0, (dayEnd - closeClamped) * PIXELS_PER_MINUTE)
    };
  }

  async function load() {
    const date = dateEl.value;
    if (!date) return;
    const params = new URLSearchParams();
    params.set('date', date);
    if (branchEl.value) params.set('branch_id', branchEl.value);
    statusEl.textContent = 'Loading day calendar…';
    destroyNowLine();
    if (currentLoadAbort) {
      currentLoadAbort.abort();
    }
    const abortCtrl = new AbortController();
    currentLoadAbort = abortCtrl;
    try {
      const res = await fetch('/calendar/day?' + params.toString(), {
        headers: {'Accept': 'application/json'},
        signal: abortCtrl.signal,
      });
      const payload = await res.json();
      // BKM-008: success payloads include contract fields only; errors may be a string (422) or
      // { message } (auth/HTTP JSON). Avoid truthy non-string `error` and property access on non-objects.
      const payloadError = payload && typeof payload === 'object' ? payload.error : undefined;
      const errMsg =
        typeof payloadError === 'string'
          ? payloadError
          : payloadError && typeof payloadError === 'object' && typeof payloadError.message === 'string'
            ? payloadError.message
            : null;
      if (!res.ok || errMsg) {
        statusEl.textContent = errMsg || 'Failed to load calendar.';
        wrap.innerHTML = '';
        clearMonthSummaryDecorations();
        latestMonthSummary = null;
        return;
      }
      statusEl.textContent = '';
      renderCalendar(payload);
    } catch (e) {
      if (e && e.name === 'AbortError') {
        return;
      }
      statusEl.textContent = 'Could not load calendar data.';
      wrap.innerHTML = '';
      clearMonthSummaryDecorations();
      latestMonthSummary = null;
    }
  }

  const filterForm = document.getElementById('calendar-filter-form');
  filterForm.addEventListener('submit', (e) => {
    e.preventDefault();
    renderMonthRail();
    syncCalendarUrl();
    load();
  });
  dateEl.addEventListener('change', () => {
    selectedSlot = null;
    nowLineScrolled = false;
    renderMonthRail();
    syncCalendarUrl();
    load();
  });
  branchEl.addEventListener('change', () => {
    selectedSlot = null;
    nowLineScrolled = false;
    syncCalendarUrl();
    loadMonthSummary();
    load();
  });

  newAppointmentBtns.forEach((btn) => btn.addEventListener('click', async () => {
    if (!selectedSlot) {
      selectedSlot = {
        staffId: null,
        time: '09:00',
        bookingDurationMinutes: DEFAULT_BOOKING_DURATION_MINUTES,
      };
    }
    await openDrawerUrl(buildNewAppointmentUrl());
  }));
  blockedTimeBtn.addEventListener('click', async () => {
    await openDrawerUrl(buildBlockedTimeUrl());
  });
  window.addEventListener('app:appointments-calendar-refresh', () => {
    loadMonthSummary();
    load();
  });

  if (railPrevMonth) {
    railPrevMonth.addEventListener('click', () => shiftCalendarMonth(-1));
  }
  if (railNextMonth) {
    railNextMonth.addEventListener('click', () => shiftCalendarMonth(1));
  }
  if (railTodayBtn) {
    railTodayBtn.addEventListener('click', () => goToBranchToday());
  }

  renderMonthRail();
  load();
})();
</script>
<script src="/assets/js/app-calendar-immersive.js" defer></script>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
?>
