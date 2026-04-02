(() => {
  const dateEl = document.getElementById('calendar-date');
  const calCard = document.getElementById('appts-cal-card');
  const calStrip = document.getElementById('appts-cal-strip');
  const calMonthGrid = document.getElementById('appts-cal-month-grid');
  const calContextMonth = document.getElementById('appts-cal-context-month');
  const calHeroDay = document.getElementById('appts-cal-hero-day');
  const calHeroWeekday = document.getElementById('appts-cal-hero-weekday');
  const calHeroKicker = document.getElementById('appts-cal-hero-kicker');
  const calModeWeek = document.getElementById('appts-cal-mode-week');
  const calModeMonth = document.getElementById('appts-cal-mode-month');
  const calNavWeek = document.getElementById('appts-cal-nav-week');
  const calNavMonth = document.getElementById('appts-cal-nav-month');
  const calBodyWeek = document.getElementById('appts-cal-body-week');
  const calBodyMonth = document.getElementById('appts-cal-body-month');
  const calPrevWeek = document.getElementById('appts-cal-prev-week');
  const calNextWeek = document.getElementById('appts-cal-next-week');
  const calPrevMonth = document.getElementById('appts-cal-prev-month');
  const calNextMonth = document.getElementById('appts-cal-next-month');
  const calTodayWeek = document.getElementById('appts-cal-today-week');
  const calTodayMonth = document.getElementById('appts-cal-today-month');
  const branchEl = document.getElementById('calendar-branch');
  const statusEl = document.getElementById('calendar-status');
  const branchHoursIndicatorEl = document.getElementById('calendar-branch-hours-indicator');
  const wrap = document.getElementById('calendar-day-wrap');
  const newAppointmentBtns = document.querySelectorAll('[data-calendar-new-appt]');
  const blockedTimeBtn = document.getElementById('calendar-blocked-time-btn');
  const summaryRailEl = document.getElementById('appts-cal-summary-status');
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
   * Prevents the first time label from being clipped by the sticky header вЂ” a translateY(-50%) at top:0
   * would otherwise overlap the header's background. The body height is also extended by this amount.
   */
  const GRID_TOP_INSET_PX = 10;
  let selectedSlot = null;
  /** AbortController for the in-flight /calendar/day fetch. Cancelled when a newer load() starts. */
  let currentLoadAbort = null;
  /** AbortController for GET /calendar/week-summary (stale-response safe). */
  let weekSummaryAbort = null;
  /** Last applied week summary payload; null until bootstrap or fetch. */
  let latestWeekSummary = null;
  /** AbortController for GET /calendar/month-summary. */
  let monthSummaryAbort = null;
  let latestMonthSummary = null;
  let weekSummaryErrorText = '';
  let monthSummaryErrorText = '';

  if (!dateEl || !branchEl || !wrap || !statusEl) {
    return;
  }

  function refreshSummaryRailVisible() {
    if (!summaryRailEl) return;
    const msg = calendarMode === 'week' ? weekSummaryErrorText : monthSummaryErrorText;
    if (!msg) {
      summaryRailEl.textContent = '';
      summaryRailEl.hidden = true;
    } else {
      summaryRailEl.textContent = msg;
      summaryRailEl.hidden = false;
    }
  }

  function calendarDayCanonicalLocation() {
    const q = currentCalendarQuery();
    const suffix = q.toString();
    return '/appointments/calendar/day' + (suffix !== '' ? '?' + suffix : '');
  }

  function currentBrowserLocationKey() {
    return window.location.pathname + (window.location.search || '');
  }

  function replaceCalendarHistoryCanonical() {
    if (!window.history || typeof window.history.replaceState !== 'function') {
      return;
    }
    window.history.replaceState({ apptsCal: 1 }, '', calendarDayCanonicalLocation());
  }

  function pushCalendarHistoryIfChanged() {
    if (!window.history || typeof window.history.pushState !== 'function') {
      return;
    }
    const href = calendarDayCanonicalLocation();
    if (currentBrowserLocationKey() === href) {
      return;
    }
    window.history.pushState({ apptsCal: 1 }, '', href);
  }

  const CAL_MODE_KEY = 'appts_cal_card_mode';
  let calendarMode = (function () {
    try {
      const s = sessionStorage.getItem(CAL_MODE_KEY);
      return s === 'month' ? 'month' : 'week';
    } catch (e) {
      return 'week';
    }
  })();

  function setCalendarMode(mode) {
    if (mode !== 'week' && mode !== 'month') return;
    calendarMode = mode;
    try {
      sessionStorage.setItem(CAL_MODE_KEY, mode);
    } catch (e) { /* ignore */ }
    syncModeChrome();
    renderSmartCard();
    refreshSummaryRailVisible();
  }

  function syncModeChrome() {
    const isWeek = calendarMode === 'week';
    if (calModeWeek && calModeMonth) {
      calModeWeek.classList.toggle('appts-cal-card__mode-btn--active', isWeek);
      calModeWeek.setAttribute('aria-pressed', isWeek ? 'true' : 'false');
      calModeMonth.classList.toggle('appts-cal-card__mode-btn--active', !isWeek);
      calModeMonth.setAttribute('aria-pressed', isWeek ? 'false' : 'true');
    }
    calNavWeek?.classList.toggle('is-cal-hidden', !isWeek);
    calNavMonth?.classList.toggle('is-cal-hidden', isWeek);
    calBodyWeek?.classList.toggle('is-cal-hidden', !isWeek);
    calBodyMonth?.classList.toggle('is-cal-hidden', isWeek);
  }

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

  /** Gregorian date-only arithmetic (UTC components) вЂ” weekday is global for Y-M-D. */
  function shiftIsoDate(iso, deltaDays) {
    const parts = String(iso || '').split('-');
    if (parts.length !== 3) return iso;
    const y = parseInt(parts[0], 10);
    const mo = parseInt(parts[1], 10);
    const d = parseInt(parts[2], 10);
    if (!Number.isFinite(y) || !Number.isFinite(mo) || !Number.isFinite(d)) return iso;
    const t = Date.UTC(y, mo - 1, d) + deltaDays * 86400000;
    const x = new Date(t);
    return x.getUTCFullYear() + '-' + String(x.getUTCMonth() + 1).padStart(2, '0') + '-' + String(x.getUTCDate()).padStart(2, '0');
  }

  /** Monday = 0 вЂ¦ Sunday = 6 (ISO week aligned). */
  function mondayOffsetFromIso(iso) {
    const parts = String(iso || '').split('-');
    if (parts.length !== 3) return 0;
    const y = parseInt(parts[0], 10);
    const mo = parseInt(parts[1], 10);
    const d = parseInt(parts[2], 10);
    const sun0 = new Date(Date.UTC(y, mo - 1, d)).getUTCDay();
    return sun0 === 0 ? 6 : sun0 - 1;
  }

  function weekStartMondayIso(iso) {
    const off = mondayOffsetFromIso(iso);
    return shiftIsoDate(iso, -off);
  }

  function visibleMonthFromDateEl() {
    const cur = String(dateEl && dateEl.value ? dateEl.value : '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(cur)) return null;
    const y = parseInt(cur.slice(0, 4), 10);
    const m = parseInt(cur.slice(5, 7), 10);
    if (!Number.isFinite(y) || !Number.isFinite(m)) return null;
    return { y, m };
  }

  function addMonthsIso(isoDate, deltaM) {
    const parts = String(isoDate || '').split('-');
    if (parts.length !== 3) return isoDate;
    const y = parseInt(parts[0], 10);
    const m = parseInt(parts[1], 10);
    const d = parseInt(parts[2], 10);
    if (!Number.isFinite(y) || !Number.isFinite(m) || !Number.isFinite(d)) return isoDate;
    const first = new Date(Date.UTC(y, m - 1 + deltaM, 1));
    const ny = first.getUTCFullYear();
    const nm = first.getUTCMonth();
    const lastD = new Date(Date.UTC(ny, nm + 1, 0)).getUTCDate();
    const nd = Math.min(d, lastD);
    const out = new Date(Date.UTC(ny, nm, nd));
    return out.getUTCFullYear() + '-' + String(out.getUTCMonth() + 1).padStart(2, '0') + '-' + String(out.getUTCDate()).padStart(2, '0');
  }

  function daysInMonthUtc(y, m) {
    return new Date(Date.UTC(y, m, 0)).getUTCDate();
  }

  function ymFirstIso(y, m) {
    return y + '-' + String(m).padStart(2, '0') + '-01';
  }

  function scrollStripToSelected() {
    if (!calStrip) return;
    const sel = calStrip.querySelector('.appts-cal-card__dow--selected');
    if (!sel) return;
    let reduced = false;
    try {
      reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) { reduced = false; }
    sel.scrollIntoView({ block: 'nearest', inline: 'center', behavior: reduced ? 'auto' : 'smooth' });
  }

  function clearWeekSummaryDecorations() {
    if (!calStrip) return;
    calStrip.querySelectorAll('.appts-cal-card__dow').forEach((el) => {
      el.classList.remove(
        'appts-cal-card__dow--closed',
        'appts-cal-card__dow--has-appts',
        'appts-cal-card__dow--has-blocked',
        'appts-cal-card__dow--busy-steady',
        'appts-cal-card__dow--busy-heavy',
        'appts-cal-card__dow--past',
        'appts-cal-card__dow--future'
      );
      el.querySelectorAll('.appts-cal-card__dow-count').forEach((n) => n.remove());
    });
  }

  function clearMonthGridDecorations() {
    if (!calMonthGrid) return;
    calMonthGrid.querySelectorAll('.appts-cal-month__cell--day').forEach((el) => {
      el.classList.remove(
        'appts-cal-month__cell--closed',
        'appts-cal-month__cell--has-appts',
        'appts-cal-month__cell--has-blocked',
        'appts-cal-month__cell--busy-steady',
        'appts-cal-month__cell--busy-heavy',
        'appts-cal-month__cell--past',
        'appts-cal-month__cell--future'
      );
      el.querySelectorAll('.appts-cal-month__cell-count').forEach((n) => n.remove());
    });
  }

  function applyWeekSummaryPayload(payload) {
    if (!payload || typeof payload !== 'object' || !payload.week_summary_contract || !calStrip || !branchEl || !dateEl) {
      return;
    }
    const bid = parseInt(String(branchEl.value || '0'), 10) || 0;
    if ((Number(payload.branch_id) || 0) !== bid) {
      return;
    }
    const cur = String(dateEl.value || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(cur)) return;
    const wk = payload.week;
    if (!wk || String(wk.week_start) !== weekStartMondayIso(cur)) {
      clearWeekSummaryDecorations();
      latestWeekSummary = null;
      return;
    }
    latestWeekSummary = payload;
    clearWeekSummaryDecorations();
    const byDate = {};
    const list = Array.isArray(payload.days) ? payload.days : [];
    for (let i = 0; i < list.length; i++) {
      const row = list[i];
      if (row && row.date) byDate[row.date] = row;
    }
    calStrip.querySelectorAll('.appts-cal-card__dow').forEach((btn) => {
      const iso = btn.dataset.date;
      const row = byDate[iso];
      if (!row) return;
      if (row.branch_closed) btn.classList.add('appts-cal-card__dow--closed');
      const ac = Number(row.appointment_count) || 0;
      if (ac > 0) {
        btn.classList.add('appts-cal-card__dow--has-appts');
        const span = document.createElement('span');
        span.className = 'appts-cal-card__dow-count';
        span.textContent = ac > 99 ? '99+' : String(ac);
        span.setAttribute('aria-label', ac + ' appointments');
        btn.appendChild(span);
      }
      if (row.has_blocked) btn.classList.add('appts-cal-card__dow--has-blocked');
      if (row.busy_level === 'steady') btn.classList.add('appts-cal-card__dow--busy-steady');
      if (row.busy_level === 'heavy') btn.classList.add('appts-cal-card__dow--busy-heavy');
      if (row.is_past) btn.classList.add('appts-cal-card__dow--past');
      if (row.is_future) btn.classList.add('appts-cal-card__dow--future');
    });
  }

  async function loadWeekSummary() {
    if (!dateEl) return;
    const cur = String(dateEl.value || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(cur)) return;
    if (weekSummaryAbort) weekSummaryAbort.abort();
    weekSummaryAbort = new AbortController();
    const params = new URLSearchParams();
    params.set('date', cur);
    if (branchEl && branchEl.value) params.set('branch_id', branchEl.value);
    try {
      const res = await fetch('/calendar/week-summary?' + params.toString(), {
        headers: { Accept: 'application/json' },
        signal: weekSummaryAbort.signal,
      });
      let payload;
      try {
        payload = await res.json();
      } catch (parseErr) {
        weekSummaryErrorText = 'Week summary: invalid response.';
        clearWeekSummaryDecorations();
        latestWeekSummary = null;
        refreshSummaryRailVisible();
        return;
      }
      const err = payload && typeof payload === 'object' ? payload.error : undefined;
      const errMsg = typeof err === 'string' ? err : err && typeof err === 'object' && typeof err.message === 'string' ? err.message : null;
      if (!res.ok || errMsg) {
        weekSummaryErrorText = 'Week summary: ' + (errMsg || 'could not load.');
        clearWeekSummaryDecorations();
        latestWeekSummary = null;
        refreshSummaryRailVisible();
        return;
      }
      if (payload && payload.week_summary_contract) {
        weekSummaryErrorText = '';
        refreshSummaryRailVisible();
        applyWeekSummaryPayload(payload);
      } else {
        weekSummaryErrorText = 'Week summary: unexpected response.';
        clearWeekSummaryDecorations();
        latestWeekSummary = null;
        refreshSummaryRailVisible();
      }
    } catch (e) {
      if (e && e.name === 'AbortError') return;
      weekSummaryErrorText = 'Week summary: network error.';
      clearWeekSummaryDecorations();
      latestWeekSummary = null;
      refreshSummaryRailVisible();
    }
  }

  function applyMonthSummaryPayload(payload) {
    if (!payload || typeof payload !== 'object' || !payload.month_summary_contract || !calMonthGrid || !branchEl || !dateEl) {
      return;
    }
    const bid = parseInt(String(branchEl.value || '0'), 10) || 0;
    if ((Number(payload.branch_id) || 0) !== bid) {
      return;
    }
    const vm = visibleMonthFromDateEl();
    if (!vm || !payload.month) return;
    const py = Number(payload.month.year);
    const pm = Number(payload.month.month);
    if (py !== vm.y || pm !== vm.m) {
      clearMonthGridDecorations();
      latestMonthSummary = null;
      return;
    }
    latestMonthSummary = payload;
    clearMonthGridDecorations();
    const byDate = {};
    const list = Array.isArray(payload.days) ? payload.days : [];
    for (let i = 0; i < list.length; i++) {
      const row = list[i];
      if (row && row.date) byDate[row.date] = row;
    }
    calMonthGrid.querySelectorAll('.appts-cal-month__cell--day').forEach((btn) => {
      const iso = btn.dataset.date;
      const row = byDate[iso];
      if (!row) return;
      if (row.branch_closed) btn.classList.add('appts-cal-month__cell--closed');
      const ac = Number(row.appointment_count) || 0;
      if (ac > 0) {
        btn.classList.add('appts-cal-month__cell--has-appts');
        const span = document.createElement('span');
        span.className = 'appts-cal-month__cell-count';
        span.textContent = ac > 9 ? '9+' : String(ac);
        span.setAttribute('aria-label', ac + ' appointments');
        btn.appendChild(span);
      }
      if (row.has_blocked) btn.classList.add('appts-cal-month__cell--has-blocked');
      if (row.busy_level === 'steady') btn.classList.add('appts-cal-month__cell--busy-steady');
      if (row.busy_level === 'heavy') btn.classList.add('appts-cal-month__cell--busy-heavy');
      if (row.is_past) btn.classList.add('appts-cal-month__cell--past');
      if (row.is_future) btn.classList.add('appts-cal-month__cell--future');
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
      let payload;
      try {
        payload = await res.json();
      } catch (parseErr) {
        monthSummaryErrorText = 'Month summary: invalid response.';
        clearMonthGridDecorations();
        latestMonthSummary = null;
        refreshSummaryRailVisible();
        return;
      }
      const err = payload && typeof payload === 'object' ? payload.error : undefined;
      const errMsg = typeof err === 'string' ? err : err && typeof err === 'object' && typeof err.message === 'string' ? err.message : null;
      if (!res.ok || errMsg) {
        monthSummaryErrorText = 'Month summary: ' + (errMsg || 'could not load.');
        clearMonthGridDecorations();
        latestMonthSummary = null;
        refreshSummaryRailVisible();
        return;
      }
      if (payload && payload.month_summary_contract) {
        monthSummaryErrorText = '';
        refreshSummaryRailVisible();
        applyMonthSummaryPayload(payload);
      } else {
        monthSummaryErrorText = 'Month summary: unexpected response.';
        clearMonthGridDecorations();
        latestMonthSummary = null;
        refreshSummaryRailVisible();
      }
    } catch (e) {
      if (e && e.name === 'AbortError') return;
      monthSummaryErrorText = 'Month summary: network error.';
      clearMonthGridDecorations();
      latestMonthSummary = null;
      refreshSummaryRailVisible();
    }
  }

  function updateRailDayMeta(vm, apptCount) {
    if (!vm) return;
    if (calendarMode === 'week' && latestWeekSummary) return;
    if (calendarMode === 'month' && latestMonthSummary) return;
    const closedCls = calendarMode === 'week' ? 'appts-cal-card__dow--closed' : 'appts-cal-month__cell--closed';
    const apptCls = calendarMode === 'week' ? 'appts-cal-card__dow--has-appts' : 'appts-cal-month__cell--has-appts';
    const sel = calendarMode === 'week'
      ? calStrip?.querySelector('.appts-cal-card__dow--selected')
      : calMonthGrid?.querySelector('.appts-cal-month__cell--selected');
    if (!sel) return;
    sel.classList.remove(closedCls, apptCls);
    const closed = (vm.branchHours && vm.branchHours.isClosedDay)
      || (vm.closureDate && vm.closureDate.active);
    if (closed) sel.classList.add(closedCls);
    if (apptCount > 0) sel.classList.add(apptCls);
  }

  function updateHero() {
    if (!calHeroDay || !calHeroWeekday || !calContextMonth || !dateEl) return;
    const cur = String(dateEl.value || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(cur)) return;
    const todayStr = getBranchNow().dateStr;
    const y = parseInt(cur.slice(0, 4), 10);
    const mo = parseInt(cur.slice(5, 7), 10);
    const dayNum = parseInt(cur.slice(8, 10), 10);
    const refUtc = new Date(Date.UTC(y, mo - 1, dayNum));
    calContextMonth.textContent = refUtc.toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
    calHeroDay.textContent = String(dayNum);
    calHeroWeekday.textContent = refUtc.toLocaleDateString(undefined, { weekday: 'long', timeZone: 'UTC' });
    if (calHeroKicker) {
      calHeroKicker.textContent = cur === todayStr ? 'Today' : 'Selected';
    }
  }

  function pickDateAndReload(iso) {
    if (!dateEl || dateEl.value === iso) return;
    selectedSlot = null;
    nowLineScrolled = false;
    dateEl.value = iso;
    renderSmartCard();
    pushCalendarHistoryIfChanged();
    load();
  }

  function renderWeekStrip() {
    if (!calStrip || !dateEl) return;
    const cur = String(dateEl.value || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(cur)) return;
    const todayStr = getBranchNow().dateStr;
    const ws = weekStartMondayIso(cur);
    calStrip.innerHTML = '';
    calStrip.setAttribute('aria-label', 'Week of ' + ws);
    for (let i = 0; i < 7; i++) {
      const iso = shiftIsoDate(ws, i);
      const py = parseInt(iso.slice(0, 4), 10);
      const pm = parseInt(iso.slice(5, 7), 10);
      const pd = parseInt(iso.slice(8, 10), 10);
      const cellUtc = new Date(Date.UTC(py, pm - 1, pd));
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'appts-cal-card__dow';
      btn.dataset.date = iso;
      btn.setAttribute('aria-label', cellUtc.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' }));
      if (iso === cur) {
        btn.classList.add('appts-cal-card__dow--selected');
        btn.setAttribute('aria-current', 'date');
      }
      if (iso === todayStr) btn.classList.add('appts-cal-card__dow--today');
      const num = document.createElement('span');
      num.className = 'appts-cal-card__dow-num';
      num.textContent = String(pd);
      const dot = document.createElement('span');
      dot.className = 'appts-cal-card__dow-dot';
      dot.setAttribute('aria-hidden', 'true');
      btn.appendChild(num);
      btn.appendChild(dot);
      btn.addEventListener('click', () => pickDateAndReload(iso));
      calStrip.appendChild(btn);
    }
    const bootEl = document.getElementById('appts-calendar-week-summary-bootstrap');
    if (bootEl && bootEl.textContent) {
      try {
        const boot = JSON.parse(bootEl.textContent);
        if (boot && boot.week_summary_contract) {
          weekSummaryErrorText = '';
          refreshSummaryRailVisible();
          applyWeekSummaryPayload(boot);
        } else {
          weekSummaryErrorText = 'Week summary: incomplete data from server.';
          clearWeekSummaryDecorations();
          latestWeekSummary = null;
          refreshSummaryRailVisible();
        }
      } catch (e) {
        weekSummaryErrorText = 'Week summary: could not read server data.';
        clearWeekSummaryDecorations();
        latestWeekSummary = null;
        refreshSummaryRailVisible();
      }
      bootEl.remove();
    } else if (latestWeekSummary) {
      applyWeekSummaryPayload(latestWeekSummary);
    }
    requestAnimationFrame(scrollStripToSelected);
    loadWeekSummary();
  }

  function renderMonthGrid() {
    if (!calMonthGrid || !dateEl) return;
    const cur = String(dateEl.value || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(cur)) return;
    const vm = visibleMonthFromDateEl();
    if (!vm) return;
    const todayStr = getBranchNow().dateStr;
    const y = vm.y;
    const m = vm.m;
    const last = daysInMonthUtc(y, m);
    const firstIso = ymFirstIso(y, m);
    const pad = mondayOffsetFromIso(firstIso);
    const cells = pad + last;
    const rows = Math.ceil(cells / 7);
    const total = rows * 7;
    calMonthGrid.innerHTML = '';
    calMonthGrid.setAttribute('aria-label', calContextMonth ? calContextMonth.textContent : 'Month');
    for (let i = 0; i < total; i++) {
      const dayIx = i - pad + 1;
      if (i < pad || dayIx > last) {
        const padEl = document.createElement('div');
        padEl.className = 'appts-cal-month__cell appts-cal-month__cell--pad';
        padEl.setAttribute('aria-hidden', 'true');
        calMonthGrid.appendChild(padEl);
        continue;
      }
      const iso = y + '-' + String(m).padStart(2, '0') + '-' + String(dayIx).padStart(2, '0');
      const cellUtc = new Date(Date.UTC(y, m - 1, dayIx));
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'appts-cal-month__cell appts-cal-month__cell--day';
      btn.dataset.date = iso;
      btn.setAttribute('aria-label', cellUtc.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' }));
      if (iso === cur) {
        btn.classList.add('appts-cal-month__cell--selected');
        btn.setAttribute('aria-current', 'date');
      }
      if (iso === todayStr) btn.classList.add('appts-cal-month__cell--today');
      const num = document.createElement('span');
      num.className = 'appts-cal-month__cell-num';
      num.textContent = String(dayIx);
      const dot = document.createElement('span');
      dot.className = 'appts-cal-month__cell-dot';
      dot.setAttribute('aria-hidden', 'true');
      btn.appendChild(num);
      btn.appendChild(dot);
      btn.addEventListener('click', () => pickDateAndReload(iso));
      calMonthGrid.appendChild(btn);
    }
    const bootM = document.getElementById('appts-calendar-month-summary-bootstrap');
    if (bootM && bootM.textContent) {
      try {
        const boot = JSON.parse(bootM.textContent);
        if (boot && boot.month_summary_contract) {
          monthSummaryErrorText = '';
          refreshSummaryRailVisible();
          applyMonthSummaryPayload(boot);
        } else {
          monthSummaryErrorText = 'Month summary: incomplete data from server.';
          clearMonthGridDecorations();
          latestMonthSummary = null;
          refreshSummaryRailVisible();
        }
      } catch (e) {
        monthSummaryErrorText = 'Month summary: could not read server data.';
        clearMonthGridDecorations();
        latestMonthSummary = null;
        refreshSummaryRailVisible();
      }
      bootM.remove();
    } else if (latestMonthSummary) {
      applyMonthSummaryPayload(latestMonthSummary);
    }
    loadMonthSummary();
  }

  function renderSmartCard() {
    updateHero();
    syncModeChrome();
    if (calendarMode === 'week') {
      renderWeekStrip();
    } else {
      renderMonthGrid();
    }
  }

  function shiftCalendarWeek(deltaWeeks) {
    const cur = dateEl.value;
    if (!cur) return;
    selectedSlot = null;
    nowLineScrolled = false;
    dateEl.value = shiftIsoDate(cur, deltaWeeks * 7);
    renderSmartCard();
    pushCalendarHistoryIfChanged();
    load();
  }

  function shiftCalendarMonth(deltaM) {
    const cur = dateEl.value;
    if (!cur) return;
    selectedSlot = null;
    nowLineScrolled = false;
    dateEl.value = addMonthsIso(cur, deltaM);
    renderSmartCard();
    pushCalendarHistoryIfChanged();
    load();
  }

  function goToBranchToday() {
    const t = getBranchNow().dateStr;
    if (!t || !/^\d{4}-\d{2}-\d{2}$/.test(t)) return;
    if (dateEl.value === t) {
      renderSmartCard();
      return;
    }
    selectedSlot = null;
    nowLineScrolled = false;
    dateEl.value = t;
    renderSmartCard();
    pushCalendarHistoryIfChanged();
    load();
  }

  function refreshCalendarSummaries() {
    if (calendarMode === 'week') {
      loadWeekSummary();
    } else {
      loadMonthSummary();
    }
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
        hoverLabel.textContent = col.label + ' В· ' + snapped;
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
          if (statusEl) statusEl.textContent = '';
          return;
        }
        if (statusEl) {
          statusEl.textContent = 'Opening new appointment for ' + col.label + ' at ' + selectedSlot.time + '.';
        }
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
    statusEl.textContent = 'Loading day calendarвЂ¦';
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
        clearWeekSummaryDecorations();
        latestWeekSummary = null;
        clearMonthGridDecorations();
        latestMonthSummary = null;
        weekSummaryErrorText = '';
        monthSummaryErrorText = '';
        refreshSummaryRailVisible();
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
      clearWeekSummaryDecorations();
      latestWeekSummary = null;
      clearMonthGridDecorations();
      latestMonthSummary = null;
      weekSummaryErrorText = '';
      monthSummaryErrorText = '';
      refreshSummaryRailVisible();
    }
  }

  const filterForm = document.getElementById('calendar-filter-form');
  if (filterForm) filterForm.addEventListener('submit', (e) => {
    e.preventDefault();
    renderSmartCard();
    pushCalendarHistoryIfChanged();
    load();
  });
  dateEl.addEventListener('change', () => {
    selectedSlot = null;
    nowLineScrolled = false;
    renderSmartCard();
    pushCalendarHistoryIfChanged();
    load();
  });
  branchEl.addEventListener('change', () => {
    selectedSlot = null;
    nowLineScrolled = false;
    pushCalendarHistoryIfChanged();
    renderSmartCard();
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
  if (blockedTimeBtn) {
    blockedTimeBtn.addEventListener('click', async () => {
      await openDrawerUrl(buildBlockedTimeUrl());
    });
  }
  window.addEventListener('app:appointments-calendar-refresh', () => {
    refreshCalendarSummaries();
    load();
  });

  if (calModeWeek) {
    calModeWeek.addEventListener('click', () => setCalendarMode('week'));
  }
  if (calModeMonth) {
    calModeMonth.addEventListener('click', () => setCalendarMode('month'));
  }
  if (calPrevWeek) {
    calPrevWeek.addEventListener('click', () => shiftCalendarWeek(-1));
  }
  if (calNextWeek) {
    calNextWeek.addEventListener('click', () => shiftCalendarWeek(1));
  }
  if (calPrevMonth) {
    calPrevMonth.addEventListener('click', () => shiftCalendarMonth(-1));
  }
  if (calNextMonth) {
    calNextMonth.addEventListener('click', () => shiftCalendarMonth(1));
  }
  if (calTodayWeek) {
    calTodayWeek.addEventListener('click', () => goToBranchToday());
  }
  if (calTodayMonth) {
    calTodayMonth.addEventListener('click', () => goToBranchToday());
  }

  if (calCard) {
    calCard.addEventListener('keydown', (e) => {
      if (!dateEl || !/^\d{4}-\d{2}-\d{2}$/.test(String(dateEl.value || ''))) return;
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        selectedSlot = null;
        nowLineScrolled = false;
        dateEl.value = shiftIsoDate(dateEl.value, -1);
        renderSmartCard();
        pushCalendarHistoryIfChanged();
        load();
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        selectedSlot = null;
        nowLineScrolled = false;
        dateEl.value = shiftIsoDate(dateEl.value, 1);
        renderSmartCard();
        pushCalendarHistoryIfChanged();
        load();
      }
    });
  }

  window.addEventListener('popstate', () => {
    if (window.location.pathname !== '/appointments/calendar/day') {
      return;
    }
    try {
      if (window.AppDrawer && typeof window.AppDrawer.close === 'function') {
        window.AppDrawer.close(true);
      }
    } catch (err) { /* ignore */ }
    const q = new URLSearchParams(window.location.search);
    const d = q.get('date');
    const b = q.get('branch_id');
    if (d && /^\d{4}-\d{2}-\d{2}$/.test(d)) {
      dateEl.value = d;
    }
    if (b != null && b !== '') {
      const bs = String(b);
      if ([...branchEl.options].some((opt) => opt.value === bs)) {
        branchEl.value = bs;
      }
    }
    selectedSlot = null;
    nowLineScrolled = false;
    renderSmartCard();
    load();
  });

  replaceCalendarHistoryCanonical();

  renderSmartCard();
  load();
})();