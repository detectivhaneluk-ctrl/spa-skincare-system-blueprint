(() => {
  const dateEl = document.getElementById('calendar-date');
  const calendarToolbarDateLabel = document.getElementById('calendar-toolbar-date-label');
  const calendarToolbarDateFocus = document.getElementById('calendar-toolbar-date-focus');
  const calendarToolbarPrevDay = document.getElementById('calendar-toolbar-prev-day');
  const calendarToolbarNextDay = document.getElementById('calendar-toolbar-next-day');
  const calCard = document.getElementById('appts-cal-card');
  const calStrip = document.getElementById('appts-cal-strip');
  const calMonthGrid = document.getElementById('appts-cal-month-grid');
  const calTwoMonthsGrid1 = document.getElementById('appts-cal-two-months-grid-1');
  const calTwoMonthsGrid2 = document.getElementById('appts-cal-two-months-grid-2');
  const calTwoMonthsLabel1 = document.getElementById('appts-cal-two-months-label-1');
  const calTwoMonthsLabel2 = document.getElementById('appts-cal-two-months-label-2');
  const calContextMonth = document.getElementById('appts-cal-context-month');
  const calHeroDay = document.getElementById('appts-cal-hero-day');
  const calHeroWeekday = document.getElementById('appts-cal-hero-weekday');
  const calHeroKicker = document.getElementById('appts-cal-hero-kicker');
  const calModeWeek = document.getElementById('appts-cal-mode-week');
  const calModeMonth = document.getElementById('appts-cal-mode-month');
  const calModeTwoMonths = document.getElementById('appts-cal-mode-two-months');
  const calNavWeek = document.getElementById('appts-cal-nav-week');
  const calNavMonth = document.getElementById('appts-cal-nav-month');
  const calBodyWeek = document.getElementById('appts-cal-body-week');
  const calBodyMonth = document.getElementById('appts-cal-body-month');
  const calBodyTwoMonths = document.getElementById('appts-cal-body-two-months');
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
  const calendarHorizontalNavControls = document.getElementById('calendar-staff-pan-controls');
  const calendarHorizontalNavPrev = document.getElementById('calendar-staff-pan-prev');
  const calendarHorizontalNavNext = document.getElementById('calendar-staff-pan-next');
  const summaryRailEl = document.getElementById('appts-cal-summary-status');
  const BASE_PIXELS_PER_MINUTE = 1.4;
  /** Canonical min/max time zoom — must match CalendarToolbarUiService + calendar-day.php range input. */
  const MIN_TIME_ZOOM_PERCENT = 25;
  const MAX_TIME_ZOOM_PERCENT = 200;
  /**
   * Vertical scroll: #appts-calendar-grid. Horizontal pan: .ops-calendar-head-scroll + .ops-calendar-lanes-scroll
   * (scrollLeft kept in sync in JS) so the 84px time gutter never uses position:sticky — labels and grid lines
   * stay aligned while scrolling vertically.
   * Bounded viewport via layout + syncCalendarViewportHeight. Implicit fit may shrink time zoom
   * to fit the workday but must not use zoom bumps to manufacture overflow; Tools ▸ Fit is explicit zoom only.
   */
  let timeZoomPercent = 100;
  let columnWidthPx = 160;
  /** How many staff columns should fill the visible scroll area. null = legacy free-px mode. */
  let staffColumnsPerView = 2;
  let showInProgressAppointments = true;
  let staffOrderScheduledIds = [];
  let staffOrderFreelancerIds = [];
  let lastCalendarPayload = null;
  let activeSavedViewId = null;
  let calendarToolbarSaveTimer = null;
  /** Counts consecutive save failures for the silent-retry logic. */
  let calendarPrefsSaveFailCount = 0;
  /** True while re-rendering after auto viewport time-zoom fit (avoids nested fit). */
  let inWorkdayFitRender = false;
  /** After explicit Tools > Fit: block implicit tryFit from ResizeObserver / delayed schedule (avoids staged second shrink). */
  let implicitWorkdayFitSuppressedUntil = 0;
  /**
   * When true, skip auto "fit workday" zoom — either a DB prefs row exists or we just saved successfully.
   * Prevents load/resize from overwriting the user's chosen column width / time zoom.
   */
  let calendarPrefsPersistedFromServer = false;
  /** When true, POST /calendar/ui-preferences is disabled (migration missing or PERSISTENCE_UNAVAILABLE) — no save/retry churn. */
  let calendarPersistenceWriteDisabled = false;
  /** After a successful GET /calendar/ui-preferences for this branch_id; used to avoid carrying persisted flag across branches. */
  let lastUiPrefsBranchId = null;
  /**
   * True when last bootstrap apply used `default_view_config` (no per-branch prefs row).
   * Implicit viewport try-fit must not override that authoritative layout on the same load.
   */
  let appliedDefaultViewConfigFromBootstrap = false;
  /** Hidden staff ids deferred until /calendar/day staff[] exists (same-branch validation). */
  let pendingDeferredHiddenStaffIds = null;
  /** Current day-calendar horizontal scrollports after the latest render. */
  let calendarHorizontalHeadScrollEl = null;
  let calendarHorizontalLanesScrollEl = null;
  let calendarHorizontalColumnGeometry = [];
  let calendarHorizontalNavStateRaf = null;
  let calendarHorizontalNavAnimationRaf = null;
  let calendarOverlayHeadEl = null;
  let calendarOverlayHeadScrollEl = null;
  /**
   * Per-tab: user moved time zoom (or applied a saved view) for this branch — block auto-fit until DB row exists.
   * Survives refresh within the session; keyed by branch in sessionStorage.
   */
  function calendarAutofitTimeZoomLockStorageKey() {
    const b = branchEl && branchEl.value ? String(branchEl.value) : '0';
    return 'appts_cal_autofit_time_zoom_lock_' + b;
  }
  function isCalendarAutofitTimeZoomLocked() {
    try {
      return sessionStorage.getItem(calendarAutofitTimeZoomLockStorageKey()) === '1';
    } catch (_e) {
      return false;
    }
  }
  function setCalendarAutofitTimeZoomLocked() {
    try {
      sessionStorage.setItem(calendarAutofitTimeZoomLockStorageKey(), '1');
    } catch (_e) { /* private mode / quota */ }
  }
  function clearCalendarAutofitTimeZoomLock() {
    try {
      sessionStorage.removeItem(calendarAutofitTimeZoomLockStorageKey());
    } catch (_e) { /* ignore */ }
  }

  function getPixelsPerMinute() {
    return BASE_PIXELS_PER_MINUTE * (timeZoomPercent / 100);
  }
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
   * Vertical padding inside the day body (px): breathing room before the first slot and after the last,
   * so hour labels are not flush with the grid edges. Also keeps the first label clear of the sticky header
   * (labels use translateY(-50%)).
   */
  const GRID_TOP_INSET_PX = 20;
  const GRID_BOTTOM_INSET_PX = 20;
  const GRID_VERTICAL_INSETS_PX = GRID_TOP_INSET_PX + GRID_BOTTOM_INSET_PX;
  /** Viewport clearance from #appts-calendar-grid top — below this, flip time bubble under the + chip. */
  const SLOT_PREVIEW_FLIP_CLEARANCE_PX = 100;

  function clearSlotPreviewViewportPin(p) {
    if (!(p instanceof HTMLElement)) return;
    p.style.position = '';
    p.style.left = '';
    p.style.width = '';
    p.style.top = '';
    p.style.right = '';
  }

  /**
   * Pin slot preview to viewport coords so it stacks above .ops-now-line (a sibling of .ops-calendar-lanes-scroll).
   * Clamps horizontal extent to #appts-calendar-grid so the bubble does not spill past the calendar surface.
   */
  function positionSlotPreviewInViewport(hoverPreview, lane, topPx) {
    const laneRect = lane.getBoundingClientRect();
    const gridEl = document.getElementById('appts-calendar-grid');
    const gridR = gridEl ? gridEl.getBoundingClientRect() : null;
    let left = laneRect.left;
    let width = laneRect.width;
    const top = laneRect.top + topPx;
    if (gridR && Number.isFinite(left) && Number.isFinite(width)) {
      const pad = 6;
      const gLeft = gridR.left + pad;
      const gRight = gridR.right - pad;
      if (left + width > gRight) {
        left = Math.max(gLeft, gRight - width);
      }
      if (left < gLeft) {
        left = gLeft;
      }
      if (left + width > gRight) {
        width = Math.max(48, gRight - left);
      }
    }
    hoverPreview.style.position = 'fixed';
    hoverPreview.style.left = Math.round(left) + 'px';
    hoverPreview.style.width = Math.round(Math.max(48, width)) + 'px';
    hoverPreview.style.top = Math.round(top) + 'px';
    hoverPreview.style.right = 'auto';
  }

  function refreshSlotPreviewFlip(hoverPreview, lane, topPx) {
    const rect = lane.getBoundingClientRect();
    const snapViewportY = rect.top + topPx;
    const vScroll = getCalendarVerticalScrollEl();
    const gridEl = document.getElementById('appts-calendar-grid');
    const gridViewportTop = vScroll
      ? vScroll.getBoundingClientRect().top
      : (gridEl ? gridEl.getBoundingClientRect().top : 0);
    if (snapViewportY - gridViewportTop < SLOT_PREVIEW_FLIP_CLEARANCE_PX) {
      hoverPreview.classList.add('is-flipped');
    } else {
      hoverPreview.classList.remove('is-flipped');
    }
  }

  /** While hover is open, grid scroll changes viewport Y — dismiss the preview rather than
   *  trying to track a stale position (mouseleave may not fire when content scrolls under cursor). */
  function dismissAllActiveSlotPreviews() {
    document.querySelectorAll('.ops-slot-preview.is-active').forEach((p) => {
      p.classList.remove('is-active', 'is-flipped');
      clearSlotPreviewViewportPin(p);
    });
  }

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
  let twoMonthsSummaryErrorText = '';
  let twoMonthsSummarySeq = 0;
  /** Frees the browser tab loading indicator if the server never responds. */
  const CALENDAR_FETCH_TIMEOUT_MS = 25000;
  function bindAbortDeadline(abortController, ms) {
    const id = setTimeout(() => {
      try {
        abortController.abort(new DOMException('Request timed out.', 'TimeoutError'));
      } catch (_) {
        abortController.abort();
      }
    }, ms);
    abortController.signal.addEventListener('abort', () => clearTimeout(id));
    return id;
  }

  function normalizeCalendarCapabilities(raw) {
    const r = raw && typeof raw === 'object' ? raw : {};
    return {
      move_preview: !!r.move_preview,
      sales_create: !!r.sales_create,
      sales_pay: !!r.sales_pay,
      sales_view: !!r.sales_view,
      appointments_create: !!r.appointments_create,
    };
  }

  function readBootstrapCalendarCapabilities() {
    const grid = document.getElementById('appts-calendar-grid');
    return normalizeCalendarCapabilities({
      sales_create: grid?.dataset.calCapSalesCreate === '1',
      sales_pay: grid?.dataset.calCapSalesPay === '1',
      sales_view: grid?.dataset.calCapSalesView === '1',
      appointments_create: grid?.dataset.calCapAppointmentsCreate === '1',
    });
  }

  let calendarCapabilities = readBootstrapCalendarCapabilities();

  if (!dateEl || !branchEl || !wrap || !statusEl) {
    return;
  }

  function readCalendarUiPageBootstrapScript() {
    const el = document.getElementById('appts-calendar-ui-bootstrap');
    if (!el || !el.textContent) return null;
    try {
      const d = JSON.parse(el.textContent);
      return d && typeof d === 'object' ? d : null;
    } catch (_e) {
      return null;
    }
  }

  /** Apply server-first-paint bundle (HTML) before any fetch; GET /calendar/ui-preferences reconciles later. */
  function applyCalendarUiPageBootstrapIfPresent() {
    const data = readCalendarUiPageBootstrapScript();
    if (!data) return;
    const st = data.calendar_ui_storage;
    if (st && st.preferences_table_ready === false) {
      calendarPersistenceWriteDisabled = true;
    } else {
      calendarPersistenceWriteDisabled = false;
    }
    calendarPrefsPersistedFromServer = Boolean(data.preferences_persisted);
    applyCalendarUiBootstrap(data);
    if (pendingDeferredHiddenStaffIds !== null) {
      setHiddenStaffIds(new Set(pendingDeferredHiddenStaffIds));
      pendingDeferredHiddenStaffIds = null;
    }
  }

  function clearCalendarPrefsAlert() {
    const el = document.getElementById('calendar-prefs-alert');
    if (!el) return;
    if (el._prefsDismissTimer) { clearTimeout(el._prefsDismissTimer); el._prefsDismissTimer = null; }
    el.innerHTML = '';
    el.hidden = true;
  }

  function showCalendarPrefsAlert(message) {
    const el = document.getElementById('calendar-prefs-alert');
    if (!el) return;
    const t = String(message || '').trim();
    const msg = t !== '' ? t : 'Unable to save calendar preferences.';
    const isSessionExpired = msg.toLowerCase().includes('session expired');
    el.innerHTML = '';

    const span = document.createElement('span');
    span.className = 'appts-calendar-prefs-alert__msg';
    span.textContent = msg;
    el.appendChild(span);

    if (!isSessionExpired) {
      // Retry button — Google-style recovery action
      const retryBtn = document.createElement('button');
      retryBtn.type = 'button';
      retryBtn.className = 'appts-calendar-prefs-alert__retry';
      retryBtn.textContent = 'Retry';
      retryBtn.addEventListener('click', () => {
        calendarPrefsSaveFailCount = 0;
        clearCalendarPrefsAlert();
        void persistCalendarPrefs();
      });
      el.appendChild(retryBtn);
    }

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'appts-calendar-prefs-alert__close';
    closeBtn.setAttribute('aria-label', 'Dismiss');
    closeBtn.textContent = '\u00d7';
    closeBtn.addEventListener('click', clearCalendarPrefsAlert);
    el.appendChild(closeBtn);

    el.hidden = false;
    if (el._prefsDismissTimer) clearTimeout(el._prefsDismissTimer);
    el._prefsDismissTimer = setTimeout(clearCalendarPrefsAlert, 10000);
  }

  function refreshSummaryRailVisible() {
    if (!summaryRailEl) return;
    let msg = '';
    if (calendarMode === 'week') msg = weekSummaryErrorText;
    else if (calendarMode === 'month') msg = monthSummaryErrorText;
    else msg = twoMonthsSummaryErrorText;
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
      if (s === 'month' || s === 'two-months') return s;
      return 'week';
    } catch (e) {
      return 'week';
    }
  })();

  function setCalendarMode(mode) {
    if (mode !== 'week' && mode !== 'month' && mode !== 'two-months') return;
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
    const isMonth = calendarMode === 'month';
    const isTwoMonths = calendarMode === 'two-months';
    if (calModeWeek && calModeMonth && calModeTwoMonths) {
      calModeWeek.classList.toggle('appts-cal-card__mode-btn--active', isWeek);
      calModeWeek.setAttribute('aria-pressed', isWeek ? 'true' : 'false');
      calModeMonth.classList.toggle('appts-cal-card__mode-btn--active', isMonth);
      calModeMonth.setAttribute('aria-pressed', isMonth ? 'true' : 'false');
      calModeTwoMonths.classList.toggle('appts-cal-card__mode-btn--active', isTwoMonths);
      calModeTwoMonths.setAttribute('aria-pressed', isTwoMonths ? 'true' : 'false');
    }
    calNavWeek?.classList.toggle('is-cal-hidden', !isWeek);
    calNavMonth?.classList.toggle('is-cal-hidden', isWeek);
    calBodyWeek?.classList.toggle('is-cal-hidden', !isWeek);
    calBodyMonth?.classList.toggle('is-cal-hidden', !isMonth);
    calBodyTwoMonths?.classList.toggle('is-cal-hidden', !isTwoMonths);
  }

  /** now-line: current grid vm reference; null when no calendar is rendered. */
  let nowLineVm = null;
  /** now-line: setInterval id; cleared whenever the grid is destroyed or re-rendered. */
  let nowLineTimer = null;
  /**
   * After the user scrolls the day grid (#appts-calendar-grid — vertical and/or horizontal),
   * do not auto-anchor the viewport to the now-line until branch/date session changes or explicit Today/Now/recenter.
   */
  let calendarViewportManualScrollLock = false;
  /** `branchId|YYYY-MM-DD` — lock resets when this changes. */
  let calendarViewportScrollSessionKey = '';
  /** > 0 while JS performs programmatic scroll on the grid (ignore scroll events for lock). */
  let calendarGridProgrammaticScrollDepth = 0;
  let calendarViewportHeightSyncRaf = null;

  function onCalendarMaybeUserScroll() {
    if (calendarGridProgrammaticScrollDepth > 0) return;
    calendarViewportManualScrollLock = true;
  }

  /** Scrollport for vertical day scroll (#appts-calendar-grid). Horizontal pan is on .ops-calendar-head-scroll + .ops-calendar-lanes-scroll (synced). */
  function getCalendarVerticalScrollEl() {
    const g = document.getElementById('appts-calendar-grid');
    return g instanceof HTMLElement ? g : null;
  }

  /** Keep staff header strip aligned with lanes when the user pans horizontally. */
  function bindOpsCalendarHorizontalScrollSync(a, b) {
    if (!(a instanceof HTMLElement) || !(b instanceof HTMLElement)) return;
    let lock = false;

    function syncFrom(src, dst) {
      if (lock || src.scrollLeft === dst.scrollLeft) return;
      lock = true;
      dst.scrollLeft = src.scrollLeft;
      requestAnimationFrame(() => { lock = false; });
    }

    function onWheelPan(e) {
      if (!(e.currentTarget instanceof HTMLElement)) return;
      const horizontalDelta = Math.abs(e.deltaX) > 0 ? e.deltaX : (e.shiftKey ? e.deltaY : 0);
      if (horizontalDelta === 0) return;
      e.preventDefault();
      e.currentTarget.scrollLeft += horizontalDelta;
      closeAllStaffMenus();
      dismissAllActiveSlotPreviews();
      scheduleCalendarHorizontalNavStateSync();
    }

    a.addEventListener('scroll', () => {
      syncFrom(a, b);
      closeAllStaffMenus();
      dismissAllActiveSlotPreviews();
      syncCalendarOverlayHeadScrollLeft();
      scheduleCalendarHorizontalNavStateSync();
    }, { passive: true });
    b.addEventListener('scroll', () => {
      syncFrom(b, a);
      dismissAllActiveSlotPreviews();
      syncCalendarOverlayHeadScrollLeft();
      scheduleCalendarHorizontalNavStateSync();
    }, { passive: true });
    a.addEventListener('wheel', onWheelPan, { passive: false });
    b.addEventListener('wheel', onWheelPan, { passive: false });

    // Drag-to-pan: intentionally not implemented on the header strip to avoid
    // interfering with staff-head click → dropdown. Horizontal panning is
    // available via the bottom scrollbar, Shift+wheel, and touchpad swipe.
  }

  function getCalendarHorizontalScrollEls() {
    const headScroll = document.querySelector('.ops-calendar-head-scroll');
    const lanesScroll = document.querySelector('.ops-calendar-lanes-scroll');
    return {
      headScroll: headScroll instanceof HTMLElement ? headScroll : null,
      lanesScroll: lanesScroll instanceof HTMLElement ? lanesScroll : null,
    };
  }

  function refreshCalendarHorizontalScrollEls() {
    const els = getCalendarHorizontalScrollEls();
    calendarHorizontalHeadScrollEl = els.headScroll;
    calendarHorizontalLanesScrollEl = els.lanesScroll;
    return els;
  }

  function refreshCalendarOverlayHeadEls() {
    const grid = document.getElementById('appts-calendar-grid');
    if (!(grid instanceof HTMLElement)) {
      calendarOverlayHeadEl = null;
      calendarOverlayHeadScrollEl = null;
      return { overlay: null, scroll: null };
    }
    const overlay = grid.querySelector('.ops-calendar-head-overlay');
    const scroll = overlay ? overlay.querySelector('.ops-calendar-head-overlay__scroll') : null;
    calendarOverlayHeadEl = overlay instanceof HTMLElement ? overlay : null;
    calendarOverlayHeadScrollEl = scroll instanceof HTMLElement ? scroll : null;
    return { overlay: calendarOverlayHeadEl, scroll: calendarOverlayHeadScrollEl };
  }

  function ensureCalendarOverlayHead(vm) {
    const grid = document.getElementById('appts-calendar-grid');
    if (!(grid instanceof HTMLElement)) return null;
    if (!vm || !Array.isArray(vm.columns) || vm.columns.length === 0) {
      calendarOverlayHeadEl?.remove();
      calendarOverlayHeadEl = null;
      calendarOverlayHeadScrollEl = null;
      return null;
    }
    let overlay = grid.querySelector('.ops-calendar-head-overlay');
    if (!(overlay instanceof HTMLElement)) {
      overlay = document.createElement('div');
      overlay.className = 'ops-calendar-head-overlay';
      overlay.setAttribute('aria-hidden', 'true');
      /* No inert — the nav slot inside needs real pointer events for the arrow buttons.
         pointer-events:none is applied via CSS to the time and scroll children only. */

      const t = document.createElement('div');
      t.className = 'ops-calendar-head-overlay__time ops-time-head';
      t.textContent = 'Time';
      overlay.appendChild(t);

      const scroll = document.createElement('div');
      scroll.className = 'ops-calendar-head-overlay__scroll';
      const inner = document.createElement('div');
      inner.className = 'ops-calendar-head-overlay__inner';
      scroll.appendChild(inner);
      overlay.appendChild(scroll);

      /* Fixed right slot for the staff nav arrow buttons — same width as .ops-calendar-head-nav-slot.
         pointer-events:auto (overrides overlay's none) so buttons are clickable. */
      const navSlot = document.createElement('div');
      navSlot.className = 'ops-calendar-head-overlay__nav';
      overlay.appendChild(navSlot);

      grid.insertBefore(overlay, grid.firstChild);
    }

    const scroll = overlay.querySelector('.ops-calendar-head-overlay__scroll');
    const inner = overlay.querySelector('.ops-calendar-head-overlay__inner');
    if (!(scroll instanceof HTMLElement) || !(inner instanceof HTMLElement)) return null;

    inner.innerHTML = '';
    const overlayCw = staffColumnsPerView != null
      ? Math.max(64, Number(columnWidthPx) || 160)
      : Math.max(96, Math.min(420, Number(columnWidthPx) || 160));
    const nOverlayCols = vm.columns.length;
    vm.columns.forEach((col) => {
      const c = document.createElement('div');
      c.className = 'ops-calendar-head-overlay__cell';
      c.dataset.staffId = String(col.id);
      c.setAttribute('role', 'button');
      c.setAttribute('tabindex', '0');
      c.setAttribute('aria-haspopup', 'true');
      c.setAttribute('aria-label', col.label + ', open options menu');

      const name = document.createElement('div');
      name.className = 'ops-staff-head-name';
      name.textContent = col.label;
      c.appendChild(name);

      /* Delegate to the hidden original .ops-staff-head — use the overlay cell's
         getBoundingClientRect() for menu positioning since the original element
         is height:0 / visibility:hidden (wrong rect). */
      c.addEventListener('click', (e) => {
        e.stopPropagation();
        const staffId = c.dataset.staffId;
        const originalHead = wrap.querySelector('.ops-staff-head[data-staff-id="' + staffId + '"]');
        if (!(originalHead instanceof HTMLElement)) return;
        const menu = originalHead.querySelector('.ops-staff-menu');
        if (!(menu instanceof HTMLElement)) return;
        const wasOpen = menu.classList.contains('ops-staff-menu--open');
        closeAllStaffMenus();
        if (!wasOpen) {
          positionStaffMenuFixed(c, menu);
          menu.classList.add('ops-staff-menu--open');
          menu.removeAttribute('inert');
          menu.setAttribute('aria-hidden', 'false');
          originalHead.setAttribute('aria-expanded', 'true');
          originalHead.classList.add('ops-staff-head--open');
          const first = menu.querySelector('[role="menuitem"]');
          if (first) requestAnimationFrame(() => first.focus());
        }
      });
      c.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); c.click(); }
        if (e.key === 'Escape') { closeAllStaffMenus(); c.focus(); }
      });

      inner.appendChild(c);
    });
    if (nOverlayCols > 0) {
      inner.style.gridTemplateColumns = 'repeat(' + nOverlayCols + ', minmax(' + overlayCw + 'px, ' + overlayCw + 'px))';
    }

    calendarOverlayHeadEl = overlay;
    calendarOverlayHeadScrollEl = scroll;
    return overlay;
  }

  function syncCalendarOverlayHeadScrollLeft() {
    const lanes = calendarHorizontalLanesScrollEl;
    const overlayScroll = calendarOverlayHeadScrollEl;
    if (!(lanes instanceof HTMLElement) || !(overlayScroll instanceof HTMLElement)) return;
    const left = Math.max(0, Math.round(lanes.scrollLeft || 0));
    if (Math.abs((overlayScroll.scrollLeft || 0) - left) <= 1) return;
    overlayScroll.scrollLeft = left;
  }

  function ensureCalendarHorizontalScrollEls() {
    const headOk = calendarHorizontalHeadScrollEl instanceof HTMLElement && calendarHorizontalHeadScrollEl.isConnected;
    const lanesOk = calendarHorizontalLanesScrollEl instanceof HTMLElement && calendarHorizontalLanesScrollEl.isConnected;
    if (headOk && lanesOk) {
      return {
        headScroll: calendarHorizontalHeadScrollEl,
        lanesScroll: calendarHorizontalLanesScrollEl,
      };
    }
    return refreshCalendarHorizontalScrollEls();
  }

  function collectCalendarHorizontalColumnGeometry() {
    const els = ensureCalendarHorizontalScrollEls();
    const headCols = els.headScroll
      ? Array.from(els.headScroll.querySelectorAll('.ops-staff-head')).filter((el) => el instanceof HTMLElement)
      : [];
    const laneCols = els.lanesScroll
      ? Array.from(els.lanesScroll.querySelectorAll('.ops-lane')).filter((el) => el instanceof HTMLElement)
      : [];
    const count = Math.min(headCols.length, laneCols.length || headCols.length);
    const geometry = [];

    for (let i = 0; i < count; i++) {
      const head = headCols[i];
      const lane = laneCols[i] || null;
      const left = Math.max(0, head.offsetLeft || 0);
      const width = Math.max(1, head.offsetWidth || lane?.offsetWidth || 1);
      geometry.push({
        index: i,
        staffId: String(head.dataset.staffId || lane?.dataset.staffId || ''),
        left,
        width,
        right: left + width,
        headEl: head,
        laneEl: lane,
      });
    }

    calendarHorizontalColumnGeometry = geometry;
    return geometry;
  }

  function getCalendarHorizontalTailPaddingPx(geometry, visibleWidth) {
    /* Returning 0 intentionally: non-zero tail padding creates a DOM-scrollable zone
       beyond the last useful column.  The clamp (clampCalendarHorizontalScrollToSemanticEnd)
       then fights browser momentum/inertia inside that zone, producing visible name
       vibration on trackpad scroll.  With tailPadPx = 0 the DOM maxScrollLeft equals
       maxUsefulScrollLeft exactly — the browser stops at the correct edge on its own. */
    void geometry; void visibleWidth;
    return 0;
  }

  function getCalendarHorizontalNormalizedColumns(state) {
    if (!state || !Array.isArray(state.columns) || state.columns.length === 0) return [];
    const firstColumnLeft = Math.max(0, Number(state.columns[0].left) || 0);
    return state.columns.map((col) => {
      const normalizedLeft = Math.max(0, Math.round((Number(col.left) || 0) - firstColumnLeft));
      const width = Math.max(1, Math.round(Number(col.width) || 1));
      return Object.assign({}, col, {
        firstColumnLeft,
        normalizedLeft,
        normalizedRight: normalizedLeft + width,
      });
    });
  }

  function getCalendarHorizontalScrollState() {
    const els = ensureCalendarHorizontalScrollEls();
    const scrollEl = (els.lanesScroll || els.headScroll) instanceof HTMLElement ? (els.lanesScroll || els.headScroll) : null;
    const visibleWidth = scrollEl ? Math.max(1, scrollEl.clientWidth || 0) : 0;
    const scrollLeft = scrollEl ? Math.max(0, scrollEl.scrollLeft || 0) : 0;
    const rawGeometry = collectCalendarHorizontalColumnGeometry();
    const tailPadPx = getCalendarHorizontalTailPaddingPx(rawGeometry, visibleWidth);
    const columns = getCalendarHorizontalNormalizedColumns({
      columns: rawGeometry,
    });
    const totalWidth = columns.length > 0 ? columns[columns.length - 1].normalizedRight + tailPadPx : 0;
    const maxScrollLeft = Math.max(0, totalWidth - visibleWidth);
    return {
      headScroll: els.headScroll,
      lanesScroll: els.lanesScroll,
      scrollEl,
      visibleWidth,
      maxScrollLeft,
      scrollLeft,
      columns,
      firstColumnLeft: columns.length > 0 ? columns[0].firstColumnLeft : 0,
      tailPadPx,
      hasOverflow: columns.length > 1 && maxScrollLeft > 1,
      atStart: maxScrollLeft <= 1 || scrollLeft <= 1,
      atEnd: maxScrollLeft <= 1 || scrollLeft >= maxScrollLeft - 1,
    };
  }

  function getCalendarHorizontalRepresentativeWidth(state) {
    const widths = Array.isArray(state && state.columns)
      ? state.columns.map((col) => Math.max(1, Number(col.width) || 0)).filter((w) => Number.isFinite(w) && w > 0)
      : [];
    if (widths.length === 0) {
      return Math.max(1, Math.round(Number(columnWidthPx) || 160));
    }
    widths.sort((a, b) => a - b);
    const mid = Math.floor(widths.length / 2);
    return widths.length % 2 === 1 ? widths[mid] : Math.round((widths[mid - 1] + widths[mid]) / 2);
  }

  function getCalendarHorizontalLeadingIndex(state) {
    if (!state || !Array.isArray(state.columns) || state.columns.length === 0) return -1;
    const currentScrollLeft = Math.max(0, Number(state.scrollLeft) || 0);
    const epsilon = 2;
    let leadingIndex = 0;
    for (let i = 0; i < state.columns.length; i++) {
      const col = state.columns[i];
      if ((Number(col.normalizedLeft) || 0) <= currentScrollLeft + epsilon) {
        leadingIndex = i;
      }
    }
    return leadingIndex;
  }

  function getCalendarHorizontalLastLeftAnchorIndex(state) {
    if (!state || !Array.isArray(state.columns) || state.columns.length === 0) return -1;
    const viewportWidth = Math.max(1, Number(state.visibleWidth) || 0);
    const last = state.columns[state.columns.length - 1];
    const maxUsefulScrollLeft = Math.max(0, Math.round((Number(last?.normalizedRight) || 0) - viewportWidth));
    let lastLeftAnchorIndex = 0;
    for (let i = 0; i < state.columns.length; i++) {
      if ((Number(state.columns[i].normalizedLeft) || 0) <= maxUsefulScrollLeft + 1) {
        lastLeftAnchorIndex = i;
      }
    }
    return lastLeftAnchorIndex;
  }

  function getCalendarHorizontalMaxUsefulScrollLeft(state) {
    if (!state || !Array.isArray(state.columns) || state.columns.length === 0) return 0;
    const viewportWidth = Math.max(1, Number(state.visibleWidth) || 0);
    const last = state.columns[state.columns.length - 1];
    return Math.max(0, Math.round((Number(last?.normalizedRight) || 0) - viewportWidth));
  }

  function clampCalendarHorizontalScrollToSemanticEnd(state) {
    if (!state || !state.scrollEl || !Array.isArray(state.columns) || state.columns.length === 0) return state;
    const currentScrollLeft = Math.max(0, Math.round(Number(state.scrollLeft) || 0));
    const maxUsefulScrollLeft = getCalendarHorizontalMaxUsefulScrollLeft(state);
    const clamped = Math.max(0, Math.min(maxUsefulScrollLeft, currentScrollLeft));
    if (Math.abs(clamped - currentScrollLeft) <= 1) {
      return state;
    }
    if (state.headScroll instanceof HTMLElement) {
      state.headScroll.scrollLeft = clamped;
    }
    if (state.lanesScroll instanceof HTMLElement) {
      state.lanesScroll.scrollLeft = clamped;
    }
    if (state.scrollEl instanceof HTMLElement && state.scrollEl !== state.headScroll && state.scrollEl !== state.lanesScroll) {
      state.scrollEl.scrollLeft = clamped;
    }
    return getCalendarHorizontalScrollState();
  }

  function getCalendarHorizontalSemanticNavState(state) {
    if (!state || !Array.isArray(state.columns) || state.columns.length === 0) {
      return {
        currentLeadingIndex: -1,
        lastLeftAnchorIndex: -1,
        semanticAtStart: true,
        semanticAtEnd: true,
      };
    }
    const currentLeadingIndex = getCalendarHorizontalLeadingIndex(state);
    const lastLeftAnchorIndex = getCalendarHorizontalLastLeftAnchorIndex(state);
    const maxUsefulScrollLeft = getCalendarHorizontalMaxUsefulScrollLeft(state);
    const currentScrollLeft = Math.max(0, Number(state.scrollLeft) || 0);
    /* Use scroll-position comparison instead of anchor index comparison so that
       narrow viewports (where maxUsefulScrollLeft < 1 column width → lastLeftAnchorIndex=0)
       do not permanently report semanticAtEnd=true and block navigation. */
    return {
      currentLeadingIndex,
      lastLeftAnchorIndex,
      semanticAtStart: currentScrollLeft <= 1,
      semanticAtEnd: maxUsefulScrollLeft <= 1 || currentScrollLeft >= maxUsefulScrollLeft - 1,
    };
  }

  function isCalendarHorizontalNavDevLogEnabled() {
    try {
      return new URLSearchParams(window.location.search).get('calendar_staff_nav_debug') === '1';
    } catch (_e) {
      return false;
    }
  }

  function logCalendarHorizontalNavDebug(stage, payload) {
    if (!isCalendarHorizontalNavDevLogEnabled()) return;
    if (window && window.console && typeof window.console.debug === 'function') {
      window.console.debug('calendar-staff-nav-' + stage, payload);
    }
  }

  function applyCalendarHorizontalTailPadding(state) {
    const headInner = state.headScroll && state.headScroll.querySelector('.ops-calendar-head-inner');
    const laneWrap = state.lanesScroll && state.lanesScroll.querySelector('.ops-lanes');
    const padPx = Math.max(0, Number(state.tailPadPx) || 0);
    if (headInner instanceof HTMLElement) {
      headInner.style.paddingRight = padPx + 'px';
    }
    if (laneWrap instanceof HTMLElement) {
      laneWrap.style.paddingRight = padPx + 'px';
    }
  }

  function dockCalendarHorizontalNavNearStaffHeader() {
    if (!(calendarHorizontalNavControls instanceof HTMLElement)) return;
    /* Place the arrow buttons into the overlay header's right nav slot so they sit
       flush at the end of the header row and never overlap the staff name cells. */
    const grid = document.getElementById('appts-calendar-grid');
    const overlay = grid ? grid.querySelector('.ops-calendar-head-overlay') : null;
    const navSlot = overlay ? overlay.querySelector('.ops-calendar-head-overlay__nav') : null;
    if (navSlot instanceof HTMLElement) {
      if (calendarHorizontalNavControls.parentElement !== navSlot) {
        navSlot.appendChild(calendarHorizontalNavControls);
      }
      /* Reveal only after successfully placed in the nav slot — keeps display:none
         (set in HTML) until this point so no toolbar flash occurs. */
      calendarHorizontalNavControls.style.removeProperty('display');
      calendarHorizontalNavControls.classList.remove('appts-cal-staff-pan--docked-grid');
      calendarHorizontalNavControls.classList.add('appts-cal-staff-pan--docked-head-slot');
    }
  }

  function updateCalendarHorizontalNavState() {
    dockCalendarHorizontalNavNearStaffHeader();
    let state = getCalendarHorizontalScrollState();
    state = clampCalendarHorizontalScrollToSemanticEnd(state);
    applyCalendarHorizontalTailPadding(state);
    syncNowLineHorizontalBounds();
    if (!calendarHorizontalNavControls || !calendarHorizontalNavPrev || !calendarHorizontalNavNext) return state;

    const shouldShow = true;
    const canPan = state.hasOverflow;
    const semantic = getCalendarHorizontalSemanticNavState(state);
    calendarHorizontalNavControls.hidden = !shouldShow;
    calendarHorizontalNavControls.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
    calendarHorizontalNavControls.classList.toggle('is-inactive', !canPan);
    calendarHorizontalNavPrev.disabled = !canPan || semantic.semanticAtStart;
    calendarHorizontalNavNext.disabled = !canPan || semantic.semanticAtEnd;

    logCalendarHorizontalNavDebug('state', {
      currentLeadingIndex: semantic.currentLeadingIndex,
      lastLeftAnchorIndex: semantic.lastLeftAnchorIndex,
      semanticAtStart: semantic.semanticAtStart,
      semanticAtEnd: semantic.semanticAtEnd,
      scrollLeft: state.scrollLeft,
      maxScrollLeft: state.maxScrollLeft,
    });

    return state;
  }

  function scheduleCalendarHorizontalNavStateSync() {
    if (calendarHorizontalNavStateRaf != null) return;
    calendarHorizontalNavStateRaf = window.requestAnimationFrame(() => {
      calendarHorizontalNavStateRaf = null;
      refreshCalendarHorizontalScrollEls();
      refreshCalendarOverlayHeadEls();
      syncCalendarOverlayHeadScrollLeft();
      updateCalendarHorizontalNavState();
    });
  }

  function refreshCalendarHorizontalNavStateAfterSettle(scrollTarget) {
    if (!(scrollTarget instanceof HTMLElement)) {
      scheduleCalendarHorizontalNavStateSync();
      return;
    }
    if ('onscrollend' in scrollTarget) {
      const onScrollEnd = () => {
        scrollTarget.removeEventListener('scrollend', onScrollEnd);
        scheduleCalendarHorizontalNavStateSync();
      };
      scrollTarget.addEventListener('scrollend', onScrollEnd, { once: true });
      return;
    }
    let stableCount = 0;
    let last = Math.max(0, Math.round(scrollTarget.scrollLeft || 0));
    let checks = 0;
    const poll = () => {
      checks += 1;
      const now = Math.max(0, Math.round(scrollTarget.scrollLeft || 0));
      if (now === last) {
        stableCount += 1;
      } else {
        stableCount = 0;
        last = now;
      }
      if (stableCount >= 2 || checks >= 24) {
        scheduleCalendarHorizontalNavStateSync();
        return;
      }
      window.setTimeout(() => {
        window.requestAnimationFrame(poll);
      }, 32);
    };
    window.requestAnimationFrame(poll);
  }

  function prefersReducedMotion() {
    try {
      return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (_e) {
      return false;
    }
  }

  function stopCalendarHorizontalNavAnimation() {
    if (calendarHorizontalNavAnimationRaf != null) {
      window.cancelAnimationFrame(calendarHorizontalNavAnimationRaf);
      calendarHorizontalNavAnimationRaf = null;
    }
  }

  function animateCalendarHorizontalScrollTo(scrollTarget, targetScrollLeft) {
    if (!(scrollTarget instanceof HTMLElement)) {
      scheduleCalendarHorizontalNavStateSync();
      return;
    }
    const startLeft = Math.max(0, Number(scrollTarget.scrollLeft) || 0);
    const endLeft = Math.max(0, Number(targetScrollLeft) || 0);
    const distance = endLeft - startLeft;
    if (Math.abs(distance) <= 1 || prefersReducedMotion()) {
      scrollTarget.scrollLeft = endLeft;
      refreshCalendarHorizontalNavStateAfterSettle(scrollTarget);
      return;
    }

    stopCalendarHorizontalNavAnimation();
    const durationMs = Math.max(150, Math.min(320, Math.round(180 + Math.abs(distance) * 0.22)));
    const startedAt = performance.now();
    const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

    const step = (now) => {
      if (!(scrollTarget instanceof HTMLElement) || !scrollTarget.isConnected) {
        calendarHorizontalNavAnimationRaf = null;
        scheduleCalendarHorizontalNavStateSync();
        return;
      }
      const elapsed = now - startedAt;
      const p = Math.max(0, Math.min(1, elapsed / durationMs));
      const eased = easeOutCubic(p);
      const nextLeft = startLeft + distance * eased;
      scrollTarget.scrollLeft = nextLeft;

      if (p < 1) {
        calendarHorizontalNavAnimationRaf = window.requestAnimationFrame(step);
        return;
      }
      scrollTarget.scrollLeft = endLeft; // hard snap to exact staff anchor
      calendarHorizontalNavAnimationRaf = null;
      refreshCalendarHorizontalNavStateAfterSettle(scrollTarget);
    };

    calendarHorizontalNavAnimationRaf = window.requestAnimationFrame(step);
  }

  function scrollCalendarHorizontallyByStaff(direction) {
    const state = updateCalendarHorizontalNavState();
    if (!state.hasOverflow || !state.scrollEl || !Number.isFinite(direction) || direction === 0) return;
    const semantic = getCalendarHorizontalSemanticNavState(state);
    const leadingIndex = semantic.currentLeadingIndex;
    const lastLeftAnchorIndex = semantic.lastLeftAnchorIndex;
    if (leadingIndex < 0) return;
    const maxUsefulScrollLeft = getCalendarHorizontalMaxUsefulScrollLeft(state);
    const currentScrollLeft = Math.max(0, Number(state.scrollLeft) || 0);

    let targetScrollLeft;
    if (direction > 0) {
      /* Forward: go to next column anchor, but clamp to maxUsefulScrollLeft.
         If the next anchor would exceed maxUsefulScrollLeft, land exactly at maxUsefulScrollLeft
         so the last column(s) fill the viewport without empty tail space. */
      const nextIndex = Math.min(state.columns.length - 1, leadingIndex + 1);
      const nextAnchor = Math.max(0, Math.round(Number(state.columns[nextIndex].normalizedLeft) || 0));
      targetScrollLeft = Math.min(maxUsefulScrollLeft, nextAnchor);
    } else {
      /* Backward: go to previous column anchor, clamp to 0. */
      const prevIndex = Math.max(0, leadingIndex - 1);
      targetScrollLeft = Math.max(0, Math.round(Number(state.columns[prevIndex].normalizedLeft) || 0));
    }

    if (Math.abs(targetScrollLeft - currentScrollLeft) <= 1) {
      logCalendarHorizontalNavDebug('noop-end', {
        currentLeadingIndex: leadingIndex,
        lastLeftAnchorIndex,
        semanticAtStart: semantic.semanticAtStart,
        semanticAtEnd: semantic.semanticAtEnd,
        scrollLeft: state.scrollLeft,
        maxScrollLeft: state.maxScrollLeft,
        targetScrollLeft,
      });
      scheduleCalendarHorizontalNavStateSync();
      return;
    }
    const scrollTarget = state.lanesScroll || state.headScroll || state.scrollEl;

    closeAllStaffMenus();
    dismissAllActiveSlotPreviews();

    animateCalendarHorizontalScrollTo(scrollTarget, targetScrollLeft);
    logCalendarHorizontalNavDebug('scroll', {
      currentLeadingIndex: leadingIndex,
      lastLeftAnchorIndex,
      semanticAtStart: semantic.semanticAtStart,
      semanticAtEnd: semantic.semanticAtEnd,
      scrollLeft: state.scrollLeft,
      maxScrollLeft: state.maxScrollLeft,
      targetScrollLeft,
      animated: true,
    });
  }

  /**
   * Bounded height for #appts-calendar-grid: subtract fixed rows inside .appts-calendar-main from
   * its client height, set --appts-day-grid-viewport-h on the page host (CSS consumes it).
   */
  function syncCalendarViewportHeight() {
    const host = document.querySelector('.appointments-workspace-page.appts-calendar-page');
    const mainCol = document.querySelector('.appts-calendar-main');
    const grid = document.getElementById('appts-calendar-grid');
    if (!host || !mainCol || !grid) return;

    const mainH = mainCol.clientHeight;
    if (mainH < 8) return;

    const kids = Array.from(mainCol.children);
    let fixed = 0;
    for (const el of kids) {
      if (el === grid) continue;
      if (!(el instanceof HTMLElement)) continue;
      if (el.hidden) continue;
      const st = window.getComputedStyle(el);
      if (st.display === 'none') continue;
      fixed += el.offsetHeight;
    }
    const cs = window.getComputedStyle(mainCol);
    const gap = parseFloat(cs.rowGap) || 0;
    const nGaps = Math.max(0, kids.length - 1);
    const gridH = Math.max(120, Math.floor(mainH - fixed - gap * nGaps));
    host.style.setProperty('--appts-day-grid-viewport-h', gridH + 'px');
  }

  function scheduleSyncCalendarViewportHeight() {
    if (calendarViewportHeightSyncRaf != null) return;
    calendarViewportHeightSyncRaf = window.requestAnimationFrame(() => {
      calendarViewportHeightSyncRaf = null;
      window.requestAnimationFrame(() => {
        syncCalendarViewportHeight();
      });
    });
  }
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

  /** Gregorian date-only arithmetic (UTC components) – weekday is global for Y-M-D. */
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

  /** Monday = 0 … Sunday = 6 (ISO week aligned). */
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
    [calMonthGrid, calTwoMonthsGrid1, calTwoMonthsGrid2].forEach((grid) => {
      if (!grid) return;
      grid.querySelectorAll('.appts-cal-month__cell--day').forEach((el) => {
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
    });
  }

  function clearMonthGridDecorationsFor(grid) {
    if (!grid) return;
    grid.querySelectorAll('.appts-cal-month__cell--day').forEach((el) => {
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
    const weekCtrl = weekSummaryAbort;
    const weekDeadline = bindAbortDeadline(weekCtrl, CALENDAR_FETCH_TIMEOUT_MS);
    const params = new URLSearchParams();
    params.set('date', cur);
    if (branchEl && branchEl.value) params.set('branch_id', branchEl.value);
    try {
      const res = await fetch('/calendar/week-summary?' + params.toString(), {
        headers: { Accept: 'application/json' },
        signal: weekCtrl.signal,
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
      if (e && e.name === 'AbortError') {
        if (weekSummaryAbort !== weekCtrl) return;
        weekSummaryErrorText = 'Week summary: timed out.';
        clearWeekSummaryDecorations();
        latestWeekSummary = null;
        refreshSummaryRailVisible();
        return;
      }
      weekSummaryErrorText = 'Week summary: network error.';
      clearWeekSummaryDecorations();
      latestWeekSummary = null;
      refreshSummaryRailVisible();
    } finally {
      clearTimeout(weekDeadline);
    }
  }

  function applyMonthSummaryPayload(payload, targetGrid, targetMonth) {
    const grid = targetGrid || calMonthGrid;
    if (!payload || typeof payload !== 'object' || !payload.month_summary_contract || !grid || !branchEl || !dateEl) {
      return;
    }
    const bid = parseInt(String(branchEl.value || '0'), 10) || 0;
    if ((Number(payload.branch_id) || 0) !== bid) {
      return;
    }
    const vm = targetMonth || visibleMonthFromDateEl();
    if (!vm || !payload.month) return;
    const py = Number(payload.month.year);
    const pm = Number(payload.month.month);
    if (py !== vm.y || pm !== vm.m) {
      clearMonthGridDecorations();
      latestMonthSummary = null;
      return;
    }
    if (!targetGrid) latestMonthSummary = payload;
    clearMonthGridDecorationsFor(grid);
    const byDate = {};
    const list = Array.isArray(payload.days) ? payload.days : [];
    for (let i = 0; i < list.length; i++) {
      const row = list[i];
      if (row && row.date) byDate[row.date] = row;
    }
    grid.querySelectorAll('.appts-cal-month__cell--day').forEach((btn) => {
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
    const monthCtrl = monthSummaryAbort;
    const monthDeadline = bindAbortDeadline(monthCtrl, CALENDAR_FETCH_TIMEOUT_MS);
    const params = new URLSearchParams();
    params.set('year', String(vm.y));
    params.set('month', String(vm.m));
    params.set('date', String(dateEl.value || '').trim());
    if (branchEl && branchEl.value) params.set('branch_id', branchEl.value);
    try {
      const res = await fetch('/calendar/month-summary?' + params.toString(), {
        headers: { Accept: 'application/json' },
        signal: monthCtrl.signal,
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
        applyMonthSummaryPayload(payload, calMonthGrid, vm);
      } else {
        monthSummaryErrorText = 'Month summary: unexpected response.';
        clearMonthGridDecorations();
        latestMonthSummary = null;
        refreshSummaryRailVisible();
      }
    } catch (e) {
      if (e && e.name === 'AbortError') {
        if (monthSummaryAbort !== monthCtrl) return;
        monthSummaryErrorText = 'Month summary: timed out.';
        clearMonthGridDecorations();
        latestMonthSummary = null;
        refreshSummaryRailVisible();
        return;
      }
      monthSummaryErrorText = 'Month summary: network error.';
      clearMonthGridDecorations();
      latestMonthSummary = null;
      refreshSummaryRailVisible();
    } finally {
      clearTimeout(monthDeadline);
    }
  }

  async function fetchMonthSummaryPayloadFor(year, month) {
    const ctrl = new AbortController();
    const deadline = bindAbortDeadline(ctrl, CALENDAR_FETCH_TIMEOUT_MS);
    try {
      const params = new URLSearchParams();
      params.set('year', String(year));
      params.set('month', String(month));
      params.set('date', String(dateEl.value || '').trim());
      if (branchEl && branchEl.value) params.set('branch_id', branchEl.value);
      const res = await fetch('/calendar/month-summary?' + params.toString(), {
        headers: { Accept: 'application/json' },
        signal: ctrl.signal,
      });
      let payload = null;
      try {
        payload = await res.json();
      } catch (_e) {
        return { payload: null, error: 'invalid response' };
      }
      const err = payload && typeof payload === 'object' ? payload.error : undefined;
      const errMsg = typeof err === 'string' ? err : err && typeof err === 'object' && typeof err.message === 'string' ? err.message : null;
      if (!res.ok || errMsg) {
        return { payload: null, error: errMsg || 'could not load' };
      }
      if (!payload || !payload.month_summary_contract) {
        return { payload: null, error: 'unexpected response' };
      }
      return { payload, error: '' };
    } catch (e) {
      if (e && e.name === 'AbortError') {
        return { payload: null, error: 'timed out' };
      }
      return { payload: null, error: 'network error' };
    } finally {
      clearTimeout(deadline);
    }
  }

  async function loadTwoMonthsSummary() {
    const vm = visibleMonthFromDateEl();
    if (!vm || !dateEl) return;
    const first = { y: vm.y, m: vm.m };
    const secondDate = new Date(Date.UTC(vm.y, vm.m, 1));
    const second = { y: secondDate.getUTCFullYear(), m: secondDate.getUTCMonth() + 1 };
    twoMonthsSummarySeq += 1;
    const seq = twoMonthsSummarySeq;
    const [r1, r2] = await Promise.all([
      fetchMonthSummaryPayloadFor(first.y, first.m),
      fetchMonthSummaryPayloadFor(second.y, second.m),
    ]);
    if (seq !== twoMonthsSummarySeq) return;
    twoMonthsSummaryErrorText = '';
    clearMonthGridDecorationsFor(calTwoMonthsGrid1);
    clearMonthGridDecorationsFor(calTwoMonthsGrid2);
    if (r1.payload) applyMonthSummaryPayload(r1.payload, calTwoMonthsGrid1, first);
    if (r2.payload) applyMonthSummaryPayload(r2.payload, calTwoMonthsGrid2, second);
    if (r1.error || r2.error) {
      const err1 = r1.error ? 'Current month: ' + r1.error : '';
      const err2 = r2.error ? 'Next month: ' + r2.error : '';
      twoMonthsSummaryErrorText = [err1, err2].filter(Boolean).join(' · ');
    }
    refreshSummaryRailVisible();
  }

  function updateRailDayMeta(vm, apptCount) {
    if (!vm) return;
    if (calendarMode === 'week' && latestWeekSummary) return;
    if (calendarMode === 'month' && latestMonthSummary) return;
    if (calendarMode === 'two-months') return;
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
    if (calendarMode === 'week') {
      const ws = weekStartMondayIso(cur);
      const we = shiftIsoDate(ws, 6);
      const wsY = parseInt(ws.slice(0, 4), 10), wsM = parseInt(ws.slice(5, 7), 10), wsD = parseInt(ws.slice(8, 10), 10);
      const weY = parseInt(we.slice(0, 4), 10), weM = parseInt(we.slice(5, 7), 10), weD = parseInt(we.slice(8, 10), 10);
      const startDate = new Date(Date.UTC(wsY, wsM - 1, wsD));
      const endDate = new Date(Date.UTC(weY, weM - 1, weD));
      if (wsM === weM && wsY === weY) {
        const monthStr = startDate.toLocaleDateString('en-US', { month: 'long', timeZone: 'UTC' });
        calContextMonth.textContent = monthStr + ' ' + wsD + ' \u2013 ' + weD + ', ' + wsY;
      } else {
        const s = startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });
        const e = endDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });
        calContextMonth.textContent = s + ' \u2013 ' + e + ', ' + weY;
      }
    } else if (calendarMode === 'two-months') {
      const next = new Date(Date.UTC(y, mo, 1));
      const left = refUtc.toLocaleDateString('en-US', { month: 'short', year: 'numeric', timeZone: 'UTC' });
      const right = next.toLocaleDateString('en-US', { month: 'short', year: 'numeric', timeZone: 'UTC' });
      calContextMonth.textContent = left + ' \u2013 ' + right;
    } else {
      calContextMonth.textContent = refUtc.toLocaleDateString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' });
    }
    calHeroDay.textContent = String(dayNum);
    calHeroWeekday.textContent = refUtc.toLocaleDateString('en-US', { weekday: 'long', timeZone: 'UTC' });
    if (calHeroKicker) {
      calHeroKicker.textContent = cur === todayStr ? 'Today' : 'Selected';
    }
  }

  function formatIsoDateForToolbarDisplay(iso) {
    const s = String(iso || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return '';
    return s.slice(8, 10) + '.' + s.slice(5, 7) + '.' + s.slice(0, 4);
  }

  function syncCalendarToolbarDateLabel() {
    if (!calendarToolbarDateLabel || !dateEl) return;
    calendarToolbarDateLabel.textContent = formatIsoDateForToolbarDisplay(dateEl.value);
  }

  function shiftCalendarDayBy(deltaDays) {
    if (!dateEl || !/^\d{4}-\d{2}-\d{2}$/.test(String(dateEl.value || ''))) return;
    selectedSlot = null;
    dateEl.value = shiftIsoDate(dateEl.value, deltaDays);
    syncCalendarToolbarDateLabel();
    renderSmartCard();
    pushCalendarHistoryIfChanged();
    load();
  }

  function pickDateAndReload(iso) {
    if (!dateEl || dateEl.value === iso) return;
    selectedSlot = null;
    dateEl.value = iso;
    syncCalendarToolbarDateLabel();
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
      btn.setAttribute('aria-label', cellUtc.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' }));
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

  function renderMonthGridCells(grid, y, m, selectedIso, todayStr) {
    if (!grid) return;
    const last = daysInMonthUtc(y, m);
    const firstIso = ymFirstIso(y, m);
    const pad = mondayOffsetFromIso(firstIso);
    const cells = pad + last;
    const rows = Math.ceil(cells / 7);
    const total = rows * 7;
    grid.innerHTML = '';
    grid.setAttribute('aria-label', new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' }));
    for (let i = 0; i < total; i++) {
      const dayIx = i - pad + 1;
      if (i < pad || dayIx > last) {
        const padEl = document.createElement('div');
        padEl.className = 'appts-cal-month__cell appts-cal-month__cell--pad';
        padEl.setAttribute('aria-hidden', 'true');
        grid.appendChild(padEl);
        continue;
      }
      const iso = y + '-' + String(m).padStart(2, '0') + '-' + String(dayIx).padStart(2, '0');
      const cellUtc = new Date(Date.UTC(y, m - 1, dayIx));
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'appts-cal-month__cell appts-cal-month__cell--day';
      btn.dataset.date = iso;
      btn.setAttribute('aria-label', cellUtc.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' }));
      if (iso === selectedIso) {
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
      grid.appendChild(btn);
    }
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
    renderMonthGridCells(calMonthGrid, y, m, cur, todayStr);
    const bootM = document.getElementById('appts-calendar-month-summary-bootstrap');
    if (bootM && bootM.textContent) {
      try {
        const boot = JSON.parse(bootM.textContent);
        if (boot && boot.month_summary_contract) {
          monthSummaryErrorText = '';
          refreshSummaryRailVisible();
          applyMonthSummaryPayload(boot, calMonthGrid, vm);
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

  function renderTwoMonthsGrid() {
    if (!calTwoMonthsGrid1 || !calTwoMonthsGrid2 || !dateEl) return;
    const cur = String(dateEl.value || '').trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(cur)) return;
    const vm = visibleMonthFromDateEl();
    if (!vm) return;
    const todayStr = getBranchNow().dateStr;
    const first = { y: vm.y, m: vm.m };
    const secondDate = new Date(Date.UTC(vm.y, vm.m, 1));
    const second = { y: secondDate.getUTCFullYear(), m: secondDate.getUTCMonth() + 1 };
    if (calTwoMonthsLabel1) {
      calTwoMonthsLabel1.textContent = new Date(Date.UTC(first.y, first.m - 1, 1)).toLocaleDateString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' });
    }
    if (calTwoMonthsLabel2) {
      calTwoMonthsLabel2.textContent = new Date(Date.UTC(second.y, second.m - 1, 1)).toLocaleDateString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' });
    }
    renderMonthGridCells(calTwoMonthsGrid1, first.y, first.m, cur, todayStr);
    renderMonthGridCells(calTwoMonthsGrid2, second.y, second.m, cur, todayStr);
    loadTwoMonthsSummary();
  }

  function renderSmartCard() {
    updateHero();
    syncModeChrome();
    if (calendarMode === 'week') {
      renderWeekStrip();
    } else if (calendarMode === 'month') {
      renderMonthGrid();
    } else {
      renderTwoMonthsGrid();
    }
  }

  function shiftCalendarWeek(deltaWeeks) {
    const cur = dateEl.value;
    if (!cur) return;
    selectedSlot = null;
    dateEl.value = shiftIsoDate(cur, deltaWeeks * 7);
    syncCalendarToolbarDateLabel();
    renderSmartCard();
    pushCalendarHistoryIfChanged();
    load();
  }

  function shiftCalendarMonth(deltaM) {
    const cur = dateEl.value;
    if (!cur) return;
    selectedSlot = null;
    dateEl.value = addMonthsIso(cur, deltaM);
    syncCalendarToolbarDateLabel();
    renderSmartCard();
    pushCalendarHistoryIfChanged();
    load();
  }

  function goToBranchToday() {
    const t = getBranchNow().dateStr;
    if (!t || !/^\d{4}-\d{2}-\d{2}$/.test(t)) return;
    if (dateEl.value === t) {
      calendarViewportManualScrollLock = false;
      syncCalendarToolbarDateLabel();
      renderSmartCard();
      scheduleNowLineViewportAnchor({ behavior: 'smooth' });
      updateNowButtonState();
      return;
    }
    selectedSlot = null;
    dateEl.value = t;
    syncCalendarToolbarDateLabel();
    renderSmartCard();
    pushCalendarHistoryIfChanged();
    load();
  }

  function refreshCalendarSummaries() {
    if (calendarMode === 'week') {
      loadWeekSummary();
    } else if (calendarMode === 'month') {
      loadMonthSummary();
    } else {
      loadTwoMonthsSummary();
    }
  }

  /** Remove now-line DOM elements and cancel the update timer. */
  function destroyNowLine() {
    if (nowLineTimer) { clearInterval(nowLineTimer); nowLineTimer = null; }
    nowLineVm = null;
    document.getElementById('ops-now-line-indicator')?.remove();
    document.getElementById('ops-now-label-indicator')?.remove();
  }

  /** Keep now-line horizontally clipped to the visible lanes viewport. */
  function syncNowLineHorizontalBounds() {
    const line = document.getElementById('ops-now-line-indicator');
    if (!(line instanceof HTMLElement)) return;
    const lanesScroll = wrap.querySelector('.ops-calendar-lanes-scroll');
    if (!(lanesScroll instanceof HTMLElement)) return;
    line.style.left = Math.max(0, Math.round(lanesScroll.offsetLeft || 0)) + 'px';
    line.style.width = Math.max(0, Math.round(lanesScroll.clientWidth || 0)) + 'px';
    line.style.right = 'auto';
  }

  /** Reposition (or hide) the now-line based on current branch-local time vs selected date. */
  function positionNowLine() {
    const line  = document.getElementById('ops-now-line-indicator');
    const label = document.getElementById('ops-now-label-indicator');
    if (!nowLineVm || !line || !label) return;
    syncNowLineHorizontalBounds();
    const { minutes: nowMinutes, dateStr: nowDate } = getBranchNow();
    const isToday = dateEl.value === nowDate;
    const inRange = nowMinutes >= nowLineVm.start && nowMinutes <= nowLineVm.end;
    if (!isToday || !inRange) {
      line.hidden = true;
      label.hidden = true;
      return;
    }
    const topPx = (nowMinutes - nowLineVm.start) * getPixelsPerMinute() + GRID_TOP_INSET_PX;
    line.style.top  = topPx + 'px';
    label.style.top = topPx + 'px';
    label.textContent = fmtTime(nowMinutes);
    line.hidden  = false;
    label.hidden = false;
    // Keep the now-line centered as real time advances (respects manual scroll lock).
    scheduleNowLineViewportAnchor();
  }

  /**
   * Create now-line and now-label DOM elements, position them, and start the 30-second update timer.
   * Viewport anchoring to the line is separate: {@link scheduleNowLineViewportAnchor} (load / fit / Now);
   * {@link positionNowLine} only moves the line — it never scrolls the grid.
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

    // Label in .ops-time-labels (fixed 84px gutter); lanes pan in .ops-calendar-lanes-scroll.
    const label = document.createElement('div');
    label.id = 'ops-now-label-indicator';
    label.className = 'ops-now-label';
    label.hidden = true;
    label.setAttribute('aria-hidden', 'true');
    const labelsCol = calBody.querySelector('.ops-time-labels');
    (labelsCol || calBody).appendChild(label);

    positionNowLine();

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

  function buildBlockedTimeUrlForStaff(staffId) {
    const q = currentCalendarQuery();
    if (staffId) q.set('staff_id', String(staffId));
    return '/appointments/blocked-slots/panel?' + q.toString();
  }

  // ── Staff column context menu (per-branch sessionStorage — avoids wrong-branch column hiding) ──
  function hiddenStaffStorageKey() {
    const b = branchEl && branchEl.value ? String(branchEl.value) : '0';
    return 'appts_cal_hidden_staff_' + b;
  }

  function getHiddenStaffIds() {
    try {
      const raw = sessionStorage.getItem(hiddenStaffStorageKey());
      if (!raw) return new Set();
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? new Set(parsed.map(String)) : new Set();
    } catch (e) { return new Set(); }
  }

  function setHiddenStaffIds(set) {
    try {
      sessionStorage.setItem(hiddenStaffStorageKey(), JSON.stringify([...set]));
    } catch (e) { /* ignore */ }
  }

  function closeAllStaffMenus() {
    document.querySelectorAll('.ops-staff-menu--open').forEach((m) => {
      m.classList.remove('ops-staff-menu--open');
      m.setAttribute('aria-hidden', 'true');
      m.setAttribute('inert', '');
      const head = m.closest('.ops-staff-head');
      if (head) {
        head.classList.remove('ops-staff-head--open');
        head.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /** Pin the fixed-positioned staff menu below its header cell in viewport coordinates. */
  function positionStaffMenuFixed(header, menu) {
    const r = header.getBoundingClientRect();
    menu.style.left  = r.left + 'px';
    menu.style.top   = r.bottom + 'px';
    menu.style.width = r.width + 'px';
  }

  function appendStaffMenuItemIcon(btn, symbolId) {
    const wrap = document.createElement('span');
    wrap.className = 'ops-staff-menu__ic';
    wrap.setAttribute('aria-hidden', 'true');
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'ops-staff-menu__icon-svg');
    svg.setAttribute('width', '16');
    svg.setAttribute('height', '16');
    svg.setAttribute('viewBox', '0 0 16 16');
    svg.setAttribute('fill', 'currentColor');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#' + symbolId);
    svg.appendChild(use);
    wrap.appendChild(svg);
    btn.appendChild(wrap);
  }

  function toggleStaffMenu(header, menu) {
    const wasOpen = menu.classList.contains('ops-staff-menu--open');
    closeAllStaffMenus();
    if (!wasOpen) {
      positionStaffMenuFixed(header, menu);
      menu.classList.add('ops-staff-menu--open');
      menu.removeAttribute('inert');
      menu.setAttribute('aria-hidden', 'false');
      header.classList.add('ops-staff-head--open');
      header.setAttribute('aria-expanded', 'true');
      const first = menu.querySelector('[role="menuitem"]');
      if (first) requestAnimationFrame(() => first.focus());
    }
  }

  async function handleStaffMenuAction(action, staffId, staffLabel) {
    if (action === 'block') {
      await openDrawerUrl(buildBlockedTimeUrlForStaff(staffId));
    } else if (action === 'schedule') {
      window.location.href = '/staff/' + encodeURIComponent(staffId) + '/edit?tab=schedule';
    } else if (action === 'services') {
      window.location.href = '/staff/' + encodeURIComponent(staffId) + '/edit?tab=services';
    } else if (action === 'profile') {
      window.location.href = '/staff/' + encodeURIComponent(staffId);
    } else if (action === 'hide') {
      const hidden = getHiddenStaffIds();
      hidden.add(String(staffId));
      setHiddenStaffIds(hidden);
      schedulePersistCalendarPrefs();
      load();
    }
  }

  function updateHiddenColumnsIndicator() {
    const slot =
      document.getElementById('calendar-toolbar-context') ||
      document.getElementById('appts-calendar-toolbar');
    if (!slot) return;
    const existing = document.getElementById('calendar-hidden-cols-restore');
    const hidden = getHiddenStaffIds();
    if (hidden.size === 0) {
      if (existing) existing.remove();
      return;
    }
    const label = hidden.size === 1 ? '1 column hidden' : hidden.size + ' columns hidden';
    if (!existing) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'calendar-hidden-cols-restore';
      btn.className = 'ds-btn calendar-hidden-cols-pill';
      btn.title = 'Click to restore all hidden staff columns';
      btn.setAttribute('aria-label', 'Restore hidden staff columns');
      btn.textContent = label;
      btn.addEventListener('click', () => {
        setHiddenStaffIds(new Set());
        schedulePersistCalendarPrefs();
        load();
      });
      slot.appendChild(btn);
    } else {
      existing.textContent = label;
      if (existing.parentElement !== slot) {
        slot.appendChild(existing);
      }
    }
  }

  // ── Appointment right-click context menu ────────────────────────────────────
  let ctxMenuEl = null;
  // Tracks where the current drag originated: 'calendar' = from a calendar block, 'clipboard' = from the clipboard panel
  let currentDragSource = null;

  function getCsrfToken() {
    const el = document.getElementById('appts-calendar-grid');
    if (el && el.dataset.csrf) return el.dataset.csrf;
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  function getCsrfName() {
    const el = document.getElementById('appts-calendar-grid');
    if (el && el.dataset.csrfName) return el.dataset.csrfName;
    return document.querySelector('meta[name="csrf-name"]')?.content || 'csrf_token';
  }

  async function apptQuickFetch(url, extraBody = {}) {
    const fd = new FormData();
    fd.append(getCsrfName(), getCsrfToken());
    for (const [k, v] of Object.entries(extraBody)) fd.append(k, String(v));
    const ac = new AbortController();
    const qDeadline = bindAbortDeadline(ac, CALENDAR_FETCH_TIMEOUT_MS);
    let res;
    try {
      res = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
        signal: ac.signal,
      });
    } finally {
      clearTimeout(qDeadline);
    }
    let payload;
    try { payload = await res.json(); } catch (e) { payload = null; }
    return { ok: res.ok, status: res.status, payload };
  }

  function closeApptContextMenu() {
    if (ctxMenuEl) { ctxMenuEl.remove(); ctxMenuEl = null; }
  }

  /**
   * @param {{ linkedInvoiceId?: number, clientId?: number, capabilities?: object }} [ctx]
   */
  function buildApptContextMenuItems(status, staffLocked, ctx) {
    const linkedInvoiceId = ctx && Number(ctx.linkedInvoiceId) > 0 ? Number(ctx.linkedInvoiceId) : 0;
    const clientId = ctx && Number(ctx.clientId) > 0 ? Number(ctx.clientId) : 0;
    const caps = normalizeCalendarCapabilities(ctx?.capabilities);
    const salesCreate = caps.sales_create;
    const salesPay = caps.sales_pay;
    const salesView = caps.sales_view;
    const appointmentsCreate = caps.appointments_create;

    const s = String(status || 'scheduled');
    const terminal = ['cancelled', 'no_show', 'completed'].includes(s);
    const canEdit = !terminal;
    const canCheckin = ['scheduled', 'confirmed', 'in_progress'].includes(s);
    const canConfirm = s === 'scheduled';
    const canUnconfirm = s === 'confirmed';
    const canProgress = ['scheduled', 'confirmed'].includes(s);
    const canComplete = !terminal && s !== 'completed';
    const canCancel = s !== 'cancelled' && s !== 'completed';
    const canNoShow = ['scheduled', 'confirmed'].includes(s);
    const locked = !!staffLocked;
    const canCleanup = !terminal;
    const canBilling = s !== 'cancelled';

    /** @type {Array<null|object>} */
    const items = [];
    // PMS reference order: Complete → Confirm → Check-in → Payment → Deposit → Cancel → Lock → (extra workflow) → divider → …

    if (canComplete) {
      items.push({ label: 'Complete Appointment', action: 'complete' });
    } else {
      items.push({
        type: 'disabled',
        label: 'Complete Appointment',
        title:
          s === 'completed'
            ? 'This appointment is already completed.'
            : 'Not available for cancelled or no-show appointments.',
      });
    }

    if (canConfirm) items.push({ label: 'Confirm', action: 'confirm' });
    else items.push({ type: 'disabled', label: 'Confirm', title: 'Only scheduled appointments can be confirmed here.' });
    if (canUnconfirm) items.push({ label: 'Unconfirm', action: 'unconfirm' });

    if (canCheckin) items.push({ label: 'Check-in Guest', action: 'checkin' });
    else {
      items.push({
        type: 'disabled',
        label: 'Check-in Guest',
        title: 'Check-in is available for scheduled, confirmed, or in-progress appointments.',
      });
    }

    if (!canBilling) {
      items.push({ type: 'disabled', label: 'Take Payment/Check-Out', title: 'Cancelled appointments cannot be checked out here.' });
    } else if (linkedInvoiceId > 0 && salesPay) {
      items.push({ label: 'Take Payment/Check-Out', action: 'take_payment_invoice' });
    } else if (linkedInvoiceId > 0 && salesView) {
      items.push({
        label: 'Take Payment/Check-Out',
        action: 'view_invoice',
        title: 'Opens the linked invoice. Use Sales → Pay if you have permission to record a payment.',
      });
    } else if (linkedInvoiceId > 0) {
      items.push({ type: 'disabled', label: 'Take Payment/Check-Out', title: 'You need sales.view to open the linked invoice.' });
    } else if (salesCreate) {
      items.push({ label: 'Take Payment/Check-Out', action: 'checkout_new_sale' });
    } else {
      items.push({ type: 'disabled', label: 'Take Payment/Check-Out', title: 'You need sales.create to open checkout without a linked invoice.' });
    }

    if (!canBilling) {
      items.push({ type: 'disabled', label: 'Take Deposit', title: 'Cancelled appointments cannot take a deposit here.' });
    } else if (salesCreate) {
      items.push({ label: 'Take Deposit', action: 'take_deposit_sale' });
    } else {
      items.push({ type: 'disabled', label: 'Take Deposit', title: 'You need sales.create to record a deposit in Sales.' });
    }

    if (canCancel) items.push({ label: 'Cancel Appointment', action: 'cancel' });
    else items.push({ type: 'disabled', label: 'Cancel Appointment', title: 'Already cancelled or completed.' });

    if (locked) items.push({ label: 'Unlock Technician', action: 'staff_unlock' });
    else items.push({ label: 'Lock to Technician', action: 'staff_lock' });

    if (canProgress) items.push({ label: 'Start service', action: 'in_progress' });
    if (canNoShow) items.push({ label: 'No show', action: 'no_show' });
    items.push(null);

    items.push({ label: 'Move to Clipboard', action: 'clipboard', mod: 'clip' });
    items.push(null);
    if (canEdit) items.push({ label: 'Edit', action: 'edit' });

    const viewChildren = [
      { label: 'View Appointment', action: 'view' },
      linkedInvoiceId > 0
        ? { label: 'View Invoice', action: 'view_invoice' }
        : { type: 'disabled', label: 'View Invoice', title: 'No invoice is linked to this appointment.' },
    ];
    items.push({ submenu: 'View', title: 'Appointment details and linked sale.', children: viewChildren });

    const printChildren = [
      { label: 'Print Appointment', action: 'print_appointment' },
      clientId > 0
        ? { label: 'Print Customer Itinerary', action: 'print_itinerary' }
        : { type: 'disabled', label: 'Print Customer Itinerary', title: 'No client on this appointment.' },
      linkedInvoiceId > 0
        ? { label: 'Print Invoice', action: 'print_invoice' }
        : { type: 'disabled', label: 'Print Invoice', title: 'No invoice linked. Create one from Sales for this appointment.' },
    ];
    items.push({ submenu: 'Print', children: printChildren });

    items.push({ label: 'Send Appointment Info', action: 'send_confirmation' });
    if (appointmentsCreate && clientId > 0) {
      items.push({
        label: 'Add to Group',
        action: 'add_companion_booking',
        title: 'New appointment for the same client (book a companion / party member).',
      });
    } else {
      items.push({
        type: 'disabled',
        label: 'Add to Group',
        title: clientId <= 0
          ? 'No client on this appointment.'
          : 'You need permission to create appointments.',
      });
    }
    items.push({
      submenu: 'Remove Cleanup Time',
      title: 'Prep (before) and turnover (after) adjust staff availability only.',
      children: canCleanup
        ? [
            { label: 'Remove All Cleanup', action: 'cleanup_all' },
            { label: 'Remove Employee Cleanup', action: 'cleanup_employee' },
            { label: 'Remove Room Cleanup', action: 'cleanup_room' },
          ]
        : [{ type: 'disabled', label: 'Not available for this status', title: '' }],
    });
    items.push(null);
    if (canEdit) items.push({ label: 'Edit Customer Notes', action: 'edit_notes' });
    items.push(null);
    items.push({ label: 'Delete', action: 'delete', mod: 'danger' });
    return items;
  }

  function appendApptContextMenuNodes(menu, defs, apptId) {
    defs.forEach((def) => {
      if (def === null) {
        const sep = document.createElement('div');
        sep.className = 'cal-ctx-sep';
        sep.setAttribute('role', 'separator');
        menu.appendChild(sep);
        return;
      }
      if (def.type === 'disabled') {
        const sp = document.createElement('span');
        sp.className = 'cal-ctx-item cal-ctx-item--disabled';
        sp.textContent = def.label;
        if (def.title) sp.title = def.title;
        sp.setAttribute('aria-disabled', 'true');
        menu.appendChild(sp);
        return;
      }
      if (def.submenu && def.children) {
        const wrap = document.createElement('div');
        wrap.className = 'cal-ctx-submenu-wrap';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cal-ctx-item cal-ctx-item--sub';
        btn.textContent = def.submenu;
        if (def.title) btn.title = def.title;
        btn.setAttribute('role', 'menuitem');
        btn.setAttribute('aria-haspopup', 'true');
        const sub = document.createElement('div');
        sub.className = 'cal-ctx-submenu';
        sub.setAttribute('role', 'menu');
        def.children.forEach((ch) => {
          if (ch.type === 'disabled') {
            const sp = document.createElement('span');
            sp.className = 'cal-ctx-item cal-ctx-item--disabled';
            sp.textContent = ch.label;
            if (ch.title) sp.title = ch.title;
            sp.setAttribute('aria-disabled', 'true');
            sub.appendChild(sp);
            return;
          }
          const subBtn = document.createElement('button');
          subBtn.type = 'button';
          subBtn.className = 'cal-ctx-item' + (ch.mod ? ' cal-ctx-item--' + ch.mod : '');
          subBtn.textContent = ch.label;
          if (ch.title) subBtn.title = ch.title;
          subBtn.dataset.apptId = String(apptId);
          subBtn.dataset.action = ch.action;
          subBtn.setAttribute('role', 'menuitem');
          sub.appendChild(subBtn);
        });
        wrap.appendChild(btn);
        wrap.appendChild(sub);
        menu.appendChild(wrap);
        return;
      }
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'cal-ctx-item' + (def.mod ? ' cal-ctx-item--' + def.mod : '');
      btn.textContent = def.label;
      if (def.title) btn.title = def.title;
      btn.dataset.apptId = String(apptId);
      btn.dataset.action = def.action;
      btn.setAttribute('role', 'menuitem');
      menu.appendChild(btn);
    });
  }

  /**
   * @param {{ linkedInvoiceId?: number, clientId?: number, capabilities?: object }} [menuCtx]
   */
  function showApptContextMenu(clientX, clientY, apptId, status, staffLocked, menuCtx) {
    closeApptContextMenu();
    const menu = document.createElement('div');
    menu.id = 'cal-appt-ctx-menu';
    menu.className = 'cal-ctx-menu';
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-label', 'Appointment options');

    const mergedCtx = Object.assign({}, menuCtx || {}, { capabilities: calendarCapabilities });
    appendApptContextMenuNodes(menu, buildApptContextMenuItems(status, staffLocked, mergedCtx), apptId);

    document.body.appendChild(menu);
    ctxMenuEl = menu;

    menu.style.left = '-9999px';
    menu.style.top  = '-9999px';
    requestAnimationFrame(() => {
      const vw = window.innerWidth, vh = window.innerHeight;
      const r  = menu.getBoundingClientRect();
      const x  = clientX + r.width  > vw ? Math.max(4, clientX - r.width)  : clientX;
      const y  = clientY + r.height > vh ? Math.max(4, clientY - r.height) : clientY;
      menu.style.left = x + 'px';
      menu.style.top  = y + 'px';
    });

    menu.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-action]');
      if (!btn) return;
      e.stopPropagation();
      closeApptContextMenu();
      await handleApptContextAction(btn.dataset.action, btn.dataset.apptId);
    });

    menu.addEventListener('keydown', (e) => {
      const items = [...menu.querySelectorAll('button[role="menuitem"][data-action], .cal-ctx-submenu-wrap > button[role="menuitem"]')];
      const idx = items.indexOf(document.activeElement);
      if (e.key === 'Escape') { closeApptContextMenu(); }
      if (e.key === 'ArrowDown') { e.preventDefault(); if (idx < items.length - 1) items[idx + 1].focus(); }
      if (e.key === 'ArrowUp')   { e.preventDefault(); if (idx > 0) items[idx - 1].focus(); }
    });

    const first = menu.querySelector('button[role="menuitem"]');
    if (first) requestAnimationFrame(() => first.focus());
  }

  async function handleApptContextAction(action, apptIdStr) {
    const id = parseInt(apptIdStr, 10);
    if (!id) return;
    const base = '/appointments/' + id;
    if (action === 'clipboard') {
      // Read display data from the appointment block DOM
      const block = document.querySelector('[data-appt-id="' + apptIdStr + '"]');
      const title      = block?.querySelector('.ops-block-title')?.textContent || ('Appointment #' + id);
      const meta       = block?.querySelector('.ops-block-meta')?.textContent  || '';
      const time       = block?.querySelector('.ops-block-time')?.textContent  || '';
      const status     = block?.dataset?.apptStatus || '';
      const rawStartAt = block?.dataset?.rawStartAt || '';
      addToClipboard(id, title, meta, time, status, rawStartAt);
      return;
    }
    if (action === 'view') {
      await openDrawerUrl(base + '?drawer=1');
    } else if (action === 'edit') {
      await openDrawerUrl(base + '/edit?drawer=1');
    } else if (action === 'print' || action === 'print_appointment') {
      window.open(base + '/print', '_blank', 'noopener,noreferrer');
    } else if (action === 'print_itinerary') {
      window.open(base + '/print-itinerary', '_blank', 'noopener,noreferrer');
    } else if (action === 'checkout_new_sale' || action === 'take_deposit_sale') {
      window.open('/sales/invoices/create?appointment_id=' + encodeURIComponent(String(id)), '_blank', 'noopener,noreferrer');
    } else if (action === 'take_payment_invoice') {
      const blockInv = document.querySelector('[data-appt-id="' + apptIdStr + '"]');
      const lidPay = blockInv && blockInv.dataset.linkedInvoiceId ? String(blockInv.dataset.linkedInvoiceId).trim() : '';
      if (lidPay) {
        window.open('/sales/invoices/' + encodeURIComponent(lidPay) + '/payments/create', '_blank', 'noopener,noreferrer');
      }
    } else if (action === 'add_companion_booking') {
      const blockC = document.querySelector('[data-appt-id="' + apptIdStr + '"]');
      const cid = blockC && blockC.dataset.clientId ? parseInt(blockC.dataset.clientId, 10) : 0;
      if (cid > 0) {
        const params = currentCalendarQuery();
        params.set('client_id', String(cid));
        params.set('drawer', '1');
        await openDrawerUrl('/appointments/create?' + params.toString());
      }
      return;
    } else if (action === 'view_invoice') {
      const block = document.querySelector('[data-appt-id="' + apptIdStr + '"]');
      const lid = block && block.dataset.linkedInvoiceId ? String(block.dataset.linkedInvoiceId).trim() : '';
      if (lid) {
        window.open('/sales/invoices/' + encodeURIComponent(lid), '_blank', 'noopener,noreferrer');
      }
    } else if (action === 'print_invoice') {
      const block = document.querySelector('[data-appt-id="' + apptIdStr + '"]');
      const lid = block && block.dataset.linkedInvoiceId ? String(block.dataset.linkedInvoiceId).trim() : '';
      if (lid) {
        window.open('/sales/invoices/' + encodeURIComponent(lid), '_blank', 'noopener,noreferrer');
      }
    } else if (action === 'checkin') {
      const { ok, payload } = await apptQuickFetch(base + '/check-in');
      if (!ok) { alert((payload?.error?.message) || 'Could not check in.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
      loadSidePanelData();
    } else if (action === 'confirm') {
      const { ok, payload } = await apptQuickFetch(base + '/status', { status: 'confirmed' });
      if (!ok) { alert((payload?.error?.message) || 'Could not confirm.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'unconfirm') {
      const { ok, payload } = await apptQuickFetch(base + '/status', { status: 'scheduled' });
      if (!ok) { alert((payload?.error?.message) || 'Could not unconfirm.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'in_progress') {
      const { ok, payload } = await apptQuickFetch(base + '/status', { status: 'in_progress' });
      if (!ok) { alert((payload?.error?.message) || 'Could not update status.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'complete') {
      const { ok, payload } = await apptQuickFetch(base + '/status', { status: 'completed' });
      if (!ok) { alert((payload?.error?.message) || 'Could not complete.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
      loadSidePanelData();
    } else if (action === 'no_show') {
      if (!confirm('Mark as no-show?')) return;
      const { ok, payload } = await apptQuickFetch(base + '/status', { status: 'no_show' });
      if (!ok) { alert((payload?.error?.message) || 'Could not mark no-show.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'cancel') {
      if (!confirm('Cancel this appointment?')) return;
      const { ok, payload } = await apptQuickFetch(base + '/cancel');
      if (!ok) { alert((payload?.error?.message) || 'Could not cancel.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'delete') {
      if (!confirm('Permanently delete this appointment?')) return;
      const { ok, payload } = await apptQuickFetch(base + '/delete');
      if (!ok) { alert((payload?.error?.message) || 'Could not delete.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'cleanup_all') {
      const { ok, payload } = await apptQuickFetch(base + '/buffer-cleanup', { scope: 'all' });
      if (!ok) { alert((payload?.error?.message) || 'Could not update cleanup buffers.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'cleanup_employee') {
      const { ok, payload } = await apptQuickFetch(base + '/buffer-cleanup', { scope: 'employee' });
      if (!ok) { alert((payload?.error?.message) || 'Could not update cleanup buffers.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'cleanup_room') {
      const { ok, payload } = await apptQuickFetch(base + '/buffer-cleanup', { scope: 'room' });
      if (!ok) { alert((payload?.error?.message) || 'Could not update cleanup buffers.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'staff_lock') {
      const { ok, payload } = await apptQuickFetch(base + '/staff-lock', { locked: '1' });
      if (!ok) { alert((payload?.error?.message) || 'Could not lock staff.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'staff_unlock') {
      const { ok, payload } = await apptQuickFetch(base + '/staff-lock', { locked: '0' });
      if (!ok) { alert((payload?.error?.message) || 'Could not unlock staff.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'send_confirmation') {
      const { ok, payload } = await apptQuickFetch(base + '/send-confirmation');
      if (!ok) { alert((payload?.error?.message) || 'Could not queue confirmation.'); return; }
      window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
    } else if (action === 'edit_notes') {
      await openDrawerUrl(base + '/edit?drawer=1#appt-notes');
      setTimeout(() => {
        const ta = document.getElementById('appt-notes');
        if (ta && typeof ta.focus === 'function') ta.focus();
      }, 400);
    }
  }

  // ── Calendar side tools panel ────────────────────────────────────────────────
  let sidePanelAbort = null;

  function initToolsPanel() {
    const panel = document.getElementById('cal-tools-panel');
    if (!panel) return;
    const tabs = panel.querySelectorAll('[data-tools-tab]');
    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        tabs.forEach((t) => { t.classList.remove('cal-tools-tab--active'); t.setAttribute('aria-selected', 'false'); });
        tab.classList.add('cal-tools-tab--active');
        tab.setAttribute('aria-selected', 'true');
        const paneId = 'cal-tools-' + tab.dataset.toolsTab;
        panel.querySelectorAll('.cal-tools-pane').forEach((p) => {
          const isTarget = p.id === paneId;
          p.hidden = !isTarget;
          if (isTarget) p.classList.add('cal-tools-pane--active');
          else p.classList.remove('cal-tools-pane--active');
        });
      });
    });

    // ── Clipboard pane: accept drops from calendar blocks ──────────────────
    const clipPane = document.getElementById('cal-tools-clipboard');
    if (clipPane) {
      clipPane.addEventListener('dragenter', (e) => {
        if (!Array.from(e.dataTransfer.types).includes('text/plain')) return;
        e.preventDefault();
        clipPane.classList.add('cal-clipboard-drag-over');
        // Auto-switch to clipboard tab so user sees where the item will land
        const clipTab = panel.querySelector('[data-tools-tab="clipboard"]');
        if (clipTab && !clipTab.classList.contains('cal-tools-tab--active')) clipTab.click();
      });
      clipPane.addEventListener('dragover', (e) => {
        if (!Array.from(e.dataTransfer.types).includes('text/plain')) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
      });
      clipPane.addEventListener('dragleave', (e) => {
        if (!clipPane.contains(e.relatedTarget)) clipPane.classList.remove('cal-clipboard-drag-over');
      });
      clipPane.addEventListener('drop', (e) => {
        e.preventDefault();
        clipPane.classList.remove('cal-clipboard-drag-over');
        let data;
        try { data = JSON.parse(e.dataTransfer.getData('text/plain')); } catch (err) { return; }
        if (!data || !data.id) return;
        addToClipboard(data.id, data.title || '', data.meta || '', data.time || '', data.status || '', data.rawStartAt || '');
      });
    }
  }

  async function loadSidePanelData() {
    const panel = document.getElementById('cal-tools-panel');
    if (!panel || !dateEl || !branchEl) return;
    if (sidePanelAbort) sidePanelAbort.abort();
    sidePanelAbort = new AbortController();
    const sideCtrl = sidePanelAbort;
    const sideDeadline = bindAbortDeadline(sideCtrl, CALENDAR_FETCH_TIMEOUT_MS);
    const params = new URLSearchParams();
    params.set('date', dateEl.value);
    if (branchEl.value) params.set('branch_id', branchEl.value);
    try {
      const res = await fetch('/calendar/side-panel?' + params.toString(), {
        headers: { Accept: 'application/json' },
        signal: sideCtrl.signal,
      });
      if (!res.ok) return;
      const raw = await res.text();
      let body;
      try {
        body = raw ? JSON.parse(raw) : null;
      } catch (_e) {
        return;
      }
      if (!body || !body.success) return;
      const data = body.data;
      renderWaitlistPane(data);
      renderCheckinPane(data);
    } catch (e) {
      if (e && e.name === 'AbortError' && sidePanelAbort !== sideCtrl) return;
    } finally {
      clearTimeout(sideDeadline);
    }
  }

  function renderWaitlistPane(data) {
    const pane = document.getElementById('cal-tools-waitlist');
    const badge = document.getElementById('cal-tools-waitlist-badge');
    if (!pane || !data) return;
    const count = Number(data.waitlist_count) || 0;
    const url = String(data.waitlist_url || '/appointments/waitlist');
    if (badge) {
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.hidden = count === 0;
    }
    if (count === 0) {
      pane.innerHTML = '<p class="cal-tools-hint">No active waitlist entries for this date.</p>'
        + '<a href="' + url + '" class="cal-tools-link">Open full waitlist</a>';
    } else {
      pane.innerHTML = '<p class="cal-tools-count">' + count + ' waiting</p>'
        + '<a href="' + url + '" class="cal-tools-link cal-tools-link--primary">View &amp; manage waitlist →</a>';
    }
  }

  function renderCheckinPane(data) {
    const pane = document.getElementById('cal-tools-checkin');
    const badge = document.getElementById('cal-tools-checkin-badge');
    if (!pane || !data) return;
    const checkins = Array.isArray(data.checkins) ? data.checkins : [];
    if (badge) {
      badge.textContent = checkins.length > 99 ? '99+' : String(checkins.length);
      badge.hidden = checkins.length === 0;
    }
    if (checkins.length === 0) {
      pane.innerHTML = '<p class="cal-tools-hint">No check-ins recorded yet for this date.</p>';
      return;
    }
    const items = checkins.map((c) => {
      const name = c.client_name || 'Guest';
      const svc  = c.service_name || '';
      const time = c.checked_in_at || '';
      const link = '/appointments/' + c.id;
      return '<a href="' + link + '" class="cal-tools-entry" data-drawer-url="' + link + '?drawer=1">'
        + '<span class="cal-tools-entry__time">' + escHtml(time) + '</span>'
        + '<span class="cal-tools-entry__name">' + escHtml(name) + '</span>'
        + (svc ? '<span class="cal-tools-entry__svc">' + escHtml(svc) + '</span>' : '')
        + '</a>';
    }).join('');
    pane.innerHTML = '<div class="cal-tools-entries">' + items + '</div>';
  }

  function escHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ── Appointment Clipboard ────────────────────────────────────────────────────
  const CLIPBOARD_KEY_PREFIX = 'appts_cal_clipboard';
  const CLIPBOARD_MAX        = 20;

  function clipboardKey() {
    return CLIPBOARD_KEY_PREFIX + '_' + (branchEl?.value || '0');
  }

  /** @returns {Array<{id:number,title:string,meta:string,time:string,status:string,link:string}>} */
  function getClipboard() {
    try {
      const raw = sessionStorage.getItem(clipboardKey());
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) { return []; }
  }

  function saveClipboard(items) {
    try { sessionStorage.setItem(clipboardKey(), JSON.stringify(items)); } catch (e) { /* quota exceeded: silently ignore */ }
  }

  function addToClipboard(apptId, title, meta, time, status, rawStartAt) {
    const items = getClipboard();
    if (items.some((i) => i.id === Number(apptId))) return; // already there
    if (items.length >= CLIPBOARD_MAX) items.shift(); // evict oldest to stay within cap
    items.push({
      id:         Number(apptId),
      title:      String(title      || ''),
      meta:       String(meta       || ''),
      time:       String(time       || ''),
      status:     String(status     || ''),
      rawStartAt: String(rawStartAt || ''),
      link:   '/appointments/' + apptId,
    });
    saveClipboard(items);
    renderClipboardPane();
    // Switch to clipboard tab so user sees the result
    const clipTab = document.querySelector('[data-tools-tab="clipboard"]');
    if (clipTab) clipTab.click();
  }

  function removeFromClipboard(apptId) {
    saveClipboard(getClipboard().filter((i) => i.id !== Number(apptId)));
    renderClipboardPane();
  }

  function renderClipboardPane() {
    const pane      = document.getElementById('cal-tools-clipboard');
    const listEl    = document.getElementById('cal-clipboard-items');
    const emptyHint = document.getElementById('cal-clipboard-empty-hint');
    const clearBtn  = document.getElementById('cal-clipboard-clear');
    const badge     = document.getElementById('cal-tools-clipboard-badge');
    if (!pane || !listEl) return;

    const items = getClipboard();

    // Badge
    if (badge) { badge.textContent = String(items.length); badge.hidden = items.length === 0; }

    if (items.length === 0) {
      listEl.hidden = true;
      listEl.innerHTML = '';
      if (emptyHint) emptyHint.hidden = false;
      if (clearBtn)  clearBtn.hidden = true;
      return;
    }

    if (emptyHint) emptyHint.hidden = true;
    if (clearBtn)  clearBtn.hidden = false;
    listEl.hidden = false;

    listEl.innerHTML = items.map((item) => `
      <div class="cal-clipboard-item" data-clipboard-id="${item.id}">
        <div class="cal-clipboard-item__body">
          <span class="cal-clipboard-item__time">${escHtml(item.time)}</span>
          <span class="cal-clipboard-item__name">${escHtml(item.title)}</span>
          ${item.meta ? `<span class="cal-clipboard-item__svc">${escHtml(item.meta)}</span>` : ''}
        </div>
        <div class="cal-clipboard-item__actions">
          <button type="button" class="cal-clipboard-btn cal-clipboard-btn--reschedule"
                  data-clipboard-action="reschedule" data-clipboard-id="${item.id}"
                  title="Open to reschedule">↗</button>
          <button type="button" class="cal-clipboard-btn cal-clipboard-btn--remove"
                  data-clipboard-action="remove" data-clipboard-id="${item.id}"
                  title="Remove from clipboard">✕</button>
        </div>
      </div>
    `).join('');

    // Wire item action buttons + make items draggable
    listEl.querySelectorAll('.cal-clipboard-item').forEach((itemEl) => {
      const clipId   = itemEl.dataset.clipboardId;
      const clipItem = items.find((i) => String(i.id) === String(clipId));

      // Action buttons
      const rescheduleBtn = itemEl.querySelector('[data-clipboard-action="reschedule"]');
      const removeBtn     = itemEl.querySelector('[data-clipboard-action="remove"]');
      if (rescheduleBtn) rescheduleBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        await openDrawerUrl('/appointments/' + clipId + '/edit?drawer=1');
      });
      if (removeBtn) removeBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        removeFromClipboard(clipId);
      });

      // Draggable → drop onto calendar lane to reschedule
      if (!clipItem) return;
      itemEl.draggable = true;
      itemEl.addEventListener('dragstart', (e) => {
        currentDragSource = 'clipboard';
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', JSON.stringify({
          type:       'clipboard-appt-drag',
          id:         clipItem.id,
          title:      clipItem.title,
          meta:       clipItem.meta,
          time:       clipItem.time,
          status:     clipItem.status,
          rawStartAt: clipItem.rawStartAt || '',
        }));
        requestAnimationFrame(() => itemEl.classList.add('cal-clipboard-item--dragging'));
      });
      itemEl.addEventListener('dragend', () => {
        currentDragSource = null;
        itemEl.classList.remove('cal-clipboard-item--dragging');
        document.querySelectorAll('.ops-drop-preview').forEach((p) => { p.hidden = true; });
      });
    });
  }

  function initClipboardClearBtn() {
    const btn = document.getElementById('cal-clipboard-clear');
    if (!btn) return;
    btn.addEventListener('click', () => {
      saveClipboard([]);
      renderClipboardPane();
    });
  }

  function snapTimeFromTop(offsetPx, dayStart, step, dayEnd) {
    const rawMinutes = dayStart + Math.max(0, Math.round((offsetPx - GRID_TOP_INSET_PX) / getPixelsPerMinute()));
    const snapped = Math.round(rawMinutes / step) * step;
    const clamped = (dayEnd != null && Number.isFinite(dayEnd)) ? Math.min(snapped, dayEnd) : snapped;
    return fmtTime(clamped);
  }

  function toMinutes(hhmm) {
    const [h, m] = String(hhmm || '00:00').split(':').map(Number);
    if (!Number.isFinite(h) || !Number.isFinite(m)) return 0;
    return (h * 60) + m;
  }

  /** True when the string carries an explicit offset / Z (UTC instant), not a naive DATETIME. */
  function dateTimeStringHasExplicitOffset(s) {
    const x = String(s || '').trim();
    if (x === '') return false;
    if (/Z$/i.test(x)) return true;
    // +00:00, +0000, -05:00 at end (after optional fractional seconds)
    return /[+-]\d{2}:\d{2}$/.test(x) || /[+-]\d{4}$/.test(x);
  }

  function minutesFromInstantInBranchTimezone(ms) {
    try {
      const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: BRANCH_TIMEZONE,
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
      }).formatToParts(new Date(ms));
      let h = 0;
      let m = 0;
      for (const p of parts) {
        if (p.type === 'hour') h = parseInt(p.value, 10) || 0;
        if (p.type === 'minute') m = parseInt(p.value, 10) || 0;
      }
      return h * 60 + m;
    } catch (_e) {
      const d = new Date(ms);
      return d.getHours() * 60 + d.getMinutes();
    }
  }

  /**
   * Minutes from midnight on the day grid axis: matches branch-local `time_grid.day_start` semantics.
   * Naive `YYYY-MM-DD HH:mm:ss` / `YYYY-MM-DDTHH:mm:ss` (no offset) → clock substring (PHP/DB branch-wall contract).
   * ISO with Z/offset → convert instant to branch wall clock (fixes label/position vs now-line drift).
   */
  function minutesFromDateTime(dt) {
    const raw = String(dt || '').trim();
    if (raw === '') return 0;
    if (dateTimeStringHasExplicitOffset(raw)) {
      const forParse = raw.includes('T') ? raw : raw.replace(/^(\d{4}-\d{2}-\d{2})\s+/, '$1T');
      const ms = Date.parse(forParse);
      if (!Number.isNaN(ms)) {
        return minutesFromInstantInBranchTimezone(ms);
      }
    }
    return toMinutes(raw.slice(11, 16));
  }

  /**
   * Vertical span of the day grid in minutes (matches {@link buildCalendarViewModel} envelope).
   * @returns {{ range: number } | null}
   */
  function computePayloadDayRangeMinutes(payload) {
    if (!payload || typeof payload !== 'object') return null;
    const grid = payload.time_grid && typeof payload.time_grid === 'object' ? payload.time_grid : {};
    const rawGridStep = Number(grid.slot_minutes);
    const step = Number.isFinite(rawGridStep) && rawGridStep > 0 ? rawGridStep : GRID_STEP_FALLBACK_MINUTES;
    const dayStart = toMinutes(grid.day_start || '09:00');
    const dayEnd = toMinutes(grid.day_end || '18:00');
    const safeEnd = dayEnd > dayStart ? dayEnd : dayStart + step;
    const range = safeEnd - dayStart;
    if (!Number.isFinite(range) || range <= 0) return null;
    return { range };
  }

  /**
   * Scale time zoom so the full day range fits in the calendar grid viewport (no vertical scroll for the workday).
   * Does not run during slider-driven re-renders; triggered after load + grid resize only.
   * @param {{ explicitUserFit?: boolean }} [opts] explicitUserFit: Tools > Fit (suppress follow-up implicit fits briefly).
   */
  function tryFitWorkdayToViewport(opts) {
    const options = opts && typeof opts === 'object' ? opts : {};
    const explicitUserFit = !!options.explicitUserFit;
    if (!explicitUserFit && performance.now() < implicitWorkdayFitSuppressedUntil) return;
    if (inWorkdayFitRender) return;
    if (!explicitUserFit && calendarPrefsPersistedFromServer) return;
    if (!explicitUserFit && appliedDefaultViewConfigFromBootstrap) return;
    if (!explicitUserFit && isCalendarAutofitTimeZoomLocked()) return;
    if (!explicitUserFit && dateEl && dateEl.value === getBranchNow().dateStr) return;
    const pl = lastCalendarPayload;
    const gridEl = document.getElementById('appts-calendar-grid');
    if (!pl || !gridEl || !wrap) return;
    if (wrap.querySelector('.calendar-empty-hint')) return;
    const r = computePayloadDayRangeMinutes(pl);
    if (!r) return;
    const { range } = r;
    const gridH = gridEl.clientHeight;
    if (gridH < 100) return;
    const headEl = wrap.querySelector('.ops-calendar-head');
    const headH = headEl ? Math.ceil(headEl.getBoundingClientRect().height) : 48;
    const vFitPad = 8;
    const available = gridH - headH - vFitPad;
    if (available < 80) return;
    const targetPxPerMin = (available - GRID_VERTICAL_INSETS_PX) / range;
    if (!Number.isFinite(targetPxPerMin) || targetPxPerMin <= 0.05) return;
    const fitFloor = MIN_TIME_ZOOM_PERCENT;
    // Floor so rounded zoom never exceeds viewport; cap how far we shrink on auto-fit so the grid stays readable.
    let clamped = Math.max(
      fitFloor,
      Math.min(MAX_TIME_ZOOM_PERCENT, Math.floor((targetPxPerMin / BASE_PIXELS_PER_MINUTE) * 100))
    );
    while (clamped > fitFloor) {
      const bodyH = range * BASE_PIXELS_PER_MINUTE * (clamped / 100) + GRID_VERTICAL_INSETS_PX;
      if (bodyH <= available + 1) break;
      clamped -= 1;
    }
    if (clamped === timeZoomPercent) {
      if (explicitUserFit) {
        implicitWorkdayFitSuppressedUntil = performance.now() + 450;
        if (statusEl) {
          statusEl.textContent = 'Time zoom already fits the workday in this view.';
          window.setTimeout(() => {
            if (statusEl && statusEl.textContent === 'Time zoom already fits the workday in this view.') {
              statusEl.textContent = '';
            }
          }, 3200);
        }
      }
      return;
    }
    inWorkdayFitRender = true;
    timeZoomPercent = clamped;
    syncCalendarToolbarControls();
    renderCalendar(lastCalendarPayload);
    inWorkdayFitRender = false;
    if (explicitUserFit) implicitWorkdayFitSuppressedUntil = performance.now() + 500;
  }

  function scheduleWorkdayViewportFit() {
    if (inWorkdayFitRender) return;
    if (performance.now() < implicitWorkdayFitSuppressedUntil) return;
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        if (performance.now() < implicitWorkdayFitSuppressedUntil) return;
        tryFitWorkdayToViewport();
        scheduleNowLineViewportAnchor({ behavior: 'auto' });
      });
    });
  }

  /**
   * Compute the auto-fit time zoom for a payload without rendering.
   * Used in load() to pre-apply zoom before the first renderCalendar call
   * so the calendar renders once at the correct zoom (no post-render reflow).
   * Returns null if auto-fit should not run for this load (persisted prefs, locked, etc.).
   */
  function computeFitZoomForLoad(payload) {
    if (calendarPrefsPersistedFromServer) return null;
    if (appliedDefaultViewConfigFromBootstrap) return null;
    if (isCalendarAutofitTimeZoomLocked()) return null;
    if (dateEl && dateEl.value === getBranchNow().dateStr) return null;
    const r = computePayloadDayRangeMinutes(payload);
    if (!r) return null;
    const { range } = r;
    const gridEl = document.getElementById('appts-calendar-grid');
    if (!gridEl) return null;
    const gridH = gridEl.clientHeight;
    if (gridH < 100) return null;
    // ops-calendar-head is not yet rendered at this point; use the same 48px fallback
    // that tryFitWorkdayToViewport uses as its fallback — close enough for single-pass render.
    const headH = 48;
    const vFitPad = 8;
    const available = gridH - headH - vFitPad;
    if (available < 80) return null;
    const targetPxPerMin = (available - GRID_VERTICAL_INSETS_PX) / range;
    if (!Number.isFinite(targetPxPerMin) || targetPxPerMin <= 0.05) return null;
    let clamped = Math.max(
      MIN_TIME_ZOOM_PERCENT,
      Math.min(MAX_TIME_ZOOM_PERCENT, Math.floor((targetPxPerMin / BASE_PIXELS_PER_MINUTE) * 100))
    );
    while (clamped > MIN_TIME_ZOOM_PERCENT) {
      const bodyH = range * BASE_PIXELS_PER_MINUTE * (clamped / 100) + GRID_VERTICAL_INSETS_PX;
      if (bodyH <= available + 1) break;
      clamped -= 1;
    }
    return clamped;
  }

  function scrollViewportProgrammatic(scrollEl, top, behavior) {
    if (!scrollEl) return;
    const b = behavior || 'instant';
    calendarGridProgrammaticScrollDepth++;
    const release = () => {
      calendarGridProgrammaticScrollDepth = Math.max(0, calendarGridProgrammaticScrollDepth - 1);
    };
    scrollEl.scrollTo({ top, behavior: b });
    if (b === 'smooth') {
      let settled = false;
      const done = () => {
        if (settled) return;
        settled = true;
        try {
          scrollEl.removeEventListener('scrollend', done);
        } catch (_e) { /* ignore */ }
        clearTimeout(fallback);
        release();
      };
      const fallback = window.setTimeout(done, 500);
      try {
        scrollEl.addEventListener('scrollend', done, { passive: true });
      } catch (_e) {
        /* scrollend unsupported */
      }
    } else {
      queueMicrotask(release);
    }
  }

  /**
   * Scroll the internal grid viewport so the red now-line sits ~30% down the visible band
   * (below sticky header) — upcoming time remains visible below the line.
   * Does not run when manual-scroll lock is active (unless opts.ignoreLock).
   * Timer-driven {@link positionNowLine} updates never call this — only explicit / load / fit paths.
   */
  function scrollNowLineToViewportBand(opts) {
    const options = opts && typeof opts === 'object' ? opts : {};
    if (!options.ignoreLock && calendarViewportManualScrollLock) return;
    const gridEl = document.getElementById('appts-calendar-grid');
    const scrollEl = getCalendarVerticalScrollEl();
    const line = document.getElementById('ops-now-line-indicator');
    const head = wrap && wrap.querySelector('.ops-calendar-head');
    if (!gridEl || !scrollEl || !line || line.hidden || !head || !dateEl) return;
    const { dateStr: nowDate } = getBranchNow();
    if (dateEl.value !== nowDate) return;
    const gridRect = gridEl.getBoundingClientRect();
    const headRect = head.getBoundingClientRect();
    const lineRect = line.getBoundingClientRect();
    const viewportTop = headRect.bottom;
    const viewportBottom = gridRect.bottom;
    if (viewportBottom <= viewportTop + 4) return;
    const bandH = viewportBottom - viewportTop;
    /** Target: 50% — now-line centered in the visible band. */
    const targetY = viewportTop + bandH * 0.5;
    const lineMidY = lineRect.top + lineRect.height / 2;
    const delta = lineMidY - targetY;
    if (Math.abs(delta) < 3) return;
    const maxScroll = Math.max(0, scrollEl.scrollHeight - scrollEl.clientHeight);
    if (maxScroll < 1) return;
    const nextTop = Math.max(0, Math.min(maxScroll, scrollEl.scrollTop + delta));
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let behavior = 'instant';
    if (options.behavior === 'smooth' && !prefersReducedMotion) {
      behavior = 'smooth';
    }
    if (options.behavior === 'auto') {
      behavior = prefersReducedMotion ? 'instant' : 'smooth';
    }
    scrollViewportProgrammatic(scrollEl, nextTop, behavior);
  }

  /**
   * @param {{ behavior?: 'auto' | 'smooth', ignoreLock?: boolean }} [opts]
   */
  function scheduleNowLineViewportAnchor(opts) {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        scrollNowLineToViewportBand(opts);
      });
    });
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
    const top = Math.max(0, (clampedStart - dayStart) * getPixelsPerMinute());
    const height = Math.max(MIN_BLOCK_HEIGHT, (clampedEnd - clampedStart) * getPixelsPerMinute());
    return { top: Number(top) || 0, height: Number(height) || MIN_BLOCK_HEIGHT };
  }

  function fmtTime(totalMinutes) {
    const safe = Math.max(0, Math.floor(totalMinutes));
    const hh = String(Math.floor(safe / 60)).padStart(2, '0');
    const mm = String(safe % 60).padStart(2, '0');
    return hh + ':' + mm;
  }

  function fmtFromDt(dt) {
    const raw = String(dt || '').trim();
    if (raw === '') return '';
    if (dateTimeStringHasExplicitOffset(raw)) {
      const forParse = raw.includes('T') ? raw : raw.replace(/^(\d{4}-\d{2}-\d{2})\s+/, '$1T');
      const ms = Date.parse(forParse);
      if (!Number.isNaN(ms)) {
        try {
          const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone: BRANCH_TIMEZONE,
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
          }).formatToParts(new Date(ms));
          let h = 0;
          let m = 0;
          for (const p of parts) {
            if (p.type === 'hour') h = parseInt(p.value, 10) || 0;
            if (p.type === 'minute') m = parseInt(p.value, 10) || 0;
          }
          return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        } catch (_e) {
          /* fall through to substring */
        }
      }
    }
    const t = raw.slice(11, 16);
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

  function normalizeCalendarBadges(raw) {
    if (!Array.isArray(raw)) return [];
    return raw.filter((b) => b && typeof b === 'object' && typeof b.icon_id === 'string');
  }

  /**
   * Top row: leading (calendar badges and/or kind label) + time on the right.
   * @param {HTMLElement} hostEl
   * @param {{ badges?: unknown, timeLabel?: string, kindLabel?: string }} opts
   */
  function appendBlockHead(hostEl, opts) {
    if (!hostEl || !opts || typeof opts !== 'object') return;
    const badges = normalizeCalendarBadges(opts.badges);
    const timeLabel = opts.timeLabel ? String(opts.timeLabel) : '';
    const kindLabel = opts.kindLabel ? String(opts.kindLabel) : '';
    if (!badges.length && !timeLabel && !kindLabel) return;

    const head = document.createElement('div');
    head.className = 'ops-block-head';

    const leading = document.createElement('div');
    leading.className = 'ops-block-head__leading';

    if (badges.length) {
      const wrap = document.createElement('div');
      wrap.className = 'ops-block-badges';
      wrap.setAttribute('role', 'presentation');
      badges.forEach((b) => {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'ops-block-badge-ic');
        svg.setAttribute('width', '11');
        svg.setAttribute('height', '11');
        svg.setAttribute('focusable', 'false');
        if (b.label) {
          const t = document.createElementNS('http://www.w3.org/2000/svg', 'title');
          t.textContent = String(b.label);
          svg.appendChild(t);
        }
        const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        use.setAttribute('href', '#' + b.icon_id);
        if (b.color_token) {
          svg.style.color = 'var(--' + b.color_token + ')';
        }
        svg.appendChild(use);
        wrap.appendChild(svg);
      });
      leading.appendChild(wrap);
    }
    if (kindLabel) {
      const kindEl = document.createElement('div');
      kindEl.className = 'ops-block-kind';
      kindEl.textContent = kindLabel;
      leading.appendChild(kindEl);
    }

    head.appendChild(leading);

    if (timeLabel) {
      const timeEl = document.createElement('div');
      timeEl.className = 'ops-block-time';
      timeEl.textContent = timeLabel;
      head.appendChild(timeEl);
    }

    hostEl.insertBefore(head, hostEl.firstChild);
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
        if (String(a.status || '') === 'in_progress' && !showInProgressAppointments) {
          return;
        }
        const bufBefore = Math.max(0, parseInt(String(a.buffer_before_effective ?? '0'), 10) || 0);
        const bufAfter = Math.max(0, parseInt(String(a.buffer_after_effective ?? '0'), 10) || 0);
        const svcStart = minutesFromDateTime(a.start_at);
        const svcEnd = minutesFromDateTime(a.end_at);
        const extStart = svcStart - bufBefore;
        const extEnd = svcEnd + bufAfter;
        const placement = blockPlacement(extStart, extEnd, dayStart, dayEnd, step);
        if (!placement) return;
        const totalBlockMin = Math.max(1, extEnd - extStart);
        const serviceMinutes = Math.max(1, svcEnd - svcStart);
        const pctBefore = totalBlockMin > 0 ? (bufBefore / totalBlockMin) * 100 : 0;
        const pctAfter = totalBlockMin > 0 ? (bufAfter / totalBlockMin) * 100 : 0;
        const pctCore = Math.max(0, 100 - pctBefore - pctAfter);
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
        const roomNm = a.room_name != null && String(a.room_name).trim() !== '' ? safeLabel(String(a.room_name), 28) : '';
        if (roomNm) {
          metaLine = metaLine ? (metaLine + ' · ' + roomNm) : roomNm;
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
        const clientPhone = a.client_phone != null && String(a.client_phone).trim() !== '' ? String(a.client_phone).trim() : '';
        const staffAssignmentLocked = parseInt(String(a.staff_assignment_locked ?? '0'), 10) === 1;
        const clientIdNum = a.client_id != null && String(a.client_id).trim() !== '' ? Number(a.client_id) : 0;
        const linkedInvNum = a.linked_invoice_id != null && String(a.linked_invoice_id).trim() !== ''
          ? Number(a.linked_invoice_id)
          : 0;
        const domBadge = a.calendar_dominant_badge && typeof a.calendar_dominant_badge === 'object'
          ? a.calendar_dominant_badge
          : null;
        items.push({
          kind: 'appointment',
          id: Number(a.id || 0),
          status: String(a.status || 'scheduled'),
          rawStartAt: String(a.start_at || ''),
          top: placement.top,
          height: placement.height,
          timeLabel,
          title: labelPrimary,
          meta: metaLine,
          statusLabel: statusLine,
          prebooked,
          noShowAlert,
          noShowTitle,
          clientPhone,
          staffAssignmentLocked,
          clientId: Number.isFinite(clientIdNum) && clientIdNum > 0 ? clientIdNum : 0,
          linkedInvoiceId: Number.isFinite(linkedInvNum) && linkedInvNum > 0 ? linkedInvNum : 0,
          bufferPctBefore: pctBefore,
          bufferPctAfter: pctAfter,
          bufferPctCore: pctCore,
          serviceMinutes,
          link: '/appointments/' + (a.id ?? ''),
          calendarBadges: normalizeCalendarBadges(a.calendar_badges),
          calendarDominant: domBadge && domBadge.code ? domBadge : null
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
      height: range * getPixelsPerMinute() + GRID_VERTICAL_INSETS_PX,
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

  /**
   * Calculate the pixel column width that makes exactly `n` staff columns (plus a ~23% peek of
   * the next column) fit within the VISIBLE calendar viewport.
   *
   * We deliberately measure from #appts-calendar-grid (the outermost calendar element, which has
   * overflow-x:hidden and is constrained by the page layout) rather than from lanesScroll /
   * headScroll. Those elements live inside .ops-calendar which has min-width:860px — reading their
   * clientWidth returns the *element* width (860px+), not the clipped *visible* width.
   *
   * Time gutter (.ops-time-head / .ops-time-labels) is 84px, hardcoded in CSS.
   */
  const CAL_TIME_GUTTER_PX = 84;
  /**
   * @param {number} n  - User's chosen columns-per-view snap value (1–6).
   * @param {number} [nVisible] - Actual visible staff count after filtering.
   *   When nVisible ≤ n, all staff fit on screen → fill the full width with no peek
   *   (no empty space to the right of the last column).
   *   When nVisible > n (scrolling needed), use the +0.15 peek divisor so the next
   *   column's edge is visible, signalling more content.
   */
  function computeColumnWidthFromStaffCount(n, nVisible) {
    const gridEl = document.getElementById('appts-calendar-grid');
    let viewW = 0;
    if (gridEl instanceof HTMLElement && gridEl.clientWidth > 10) {
      viewW = Math.max(100, gridEl.clientWidth - CAL_TIME_GUTTER_PX);
    } else {
      viewW = Math.max(200, window.innerWidth - 130);
    }
    /* Always divide by exactly N (or fewer if there are fewer staff).
       No +0.15 peek — columns fill the full viewport width precisely.
       The ‹ › nav arrows signal scrollability; no partial column needed. */
    const cols = (nVisible != null && nVisible > 0) ? Math.min(n, nVisible) : n;
    return Math.max(64, Math.floor(viewW / cols));
  }

  function renderCalendar(payload) {
    lastCalendarPayload = payload && typeof payload === 'object' ? payload : null;
    flushDeferredHiddenStaffIdsIfReady();
    refreshCalendarHorizontalScrollEls();
    /* Responsive: recalculate column width from current viewport every render. */
    if (staffColumnsPerView != null) {
      columnWidthPx = computeColumnWidthFromStaffCount(staffColumnsPerView);
    }
    const gridHost = document.getElementById('appts-calendar-grid');
    /* In responsive staff-columns mode the computed width can be larger than the old slider max
       of 420 px (e.g. n=1 fills most of the viewport). Only the legacy free-px mode is clamped. */
    const cw = staffColumnsPerView != null
      ? Math.max(64, Number(columnWidthPx) || 160)
      : Math.max(96, Math.min(420, Number(columnWidthPx) || 160));
    if (gridHost) {
      gridHost.style.setProperty('--cal-col-min', cw + 'px');
    }
    if (payload && payload.capabilities && typeof payload.capabilities === 'object') {
      calendarCapabilities = normalizeCalendarCapabilities(payload.capabilities);
    }
    wrap.innerHTML = '';
    const apptCount = countAppointmentsInPayload(payload);
    // Filter hidden staff columns before building the view model.
    const hiddenIds = getHiddenStaffIds();
    if (hiddenIds.size > 0 && payload && Array.isArray(payload.staff)) {
      payload = Object.assign({}, payload, {
        staff: payload.staff.filter((s) => !hiddenIds.has(String(s.id))),
      });
    }
    // Apply per-branch staff column order (Scheduled and Freelancers reorder independently).
    if (payload && Array.isArray(payload.staff)) {
      const staff = payload.staff.slice(0);
      const sched = [];
      const fr = [];
      staff.forEach((s) => {
        const st = String(s && s.staff_type ? s.staff_type : 'scheduled');
        if (st === 'freelancer') fr.push(s);
        else sched.push(s);
      });
      const orderByIds = (list, orderIds) => {
        const want = Array.isArray(orderIds) ? orderIds.map(String) : [];
        const byId = new Map(list.map((s) => [String(s.id), s]));
        const used = new Set();
        const out = [];
        want.forEach((id) => {
          const item = byId.get(String(id));
          if (!item) return;
          used.add(String(id));
          out.push(item);
        });
        list.forEach((s) => {
          const id = String(s.id);
          if (used.has(id)) return;
          out.push(s);
        });
        return out;
      };
      payload = Object.assign({}, payload, {
        staff: orderByIds(sched, staffOrderScheduledIds).concat(orderByIds(fr, staffOrderFreelancerIds)),
      });
    }
    updateHiddenColumnsIndicator();
    const vm = buildCalendarViewModel(payload);
    /* Precise column-width: now that we know the visible staff count, recalculate.
       If all staff fit in the chosen N slots, columns fill the full width (no peek, no waste).
       Re-apply the CSS variable immediately so the layout below uses the correct value. */
    if (staffColumnsPerView != null && vm.columns.length > 0) {
      columnWidthPx = computeColumnWidthFromStaffCount(staffColumnsPerView, vm.columns.length);
      const cwFinal = Math.max(64, columnWidthPx);
      if (gridHost) gridHost.style.setProperty('--cal-col-min', cwFinal + 'px');
    }
    renderBranchHoursIndicator(vm.branchHours, vm.closureDate);
    if (!vm.columns.length) {
      wrap.innerHTML = '<p class="calendar-empty-hint">No active staff for this branch and date.</p>';
      destroyNowLine();
      updateRailDayMeta(vm, apptCount);
      updateCalendarHorizontalNavState();
      window.dispatchEvent(new CustomEvent('calendar-workspace:grid-updated'));
      return;
    }

    const root = document.createElement('div');
    root.className = 'ops-calendar ops-calendar--overlay-head';

    const head = document.createElement('div');
    head.className = 'ops-calendar-head';
    const headTime = document.createElement('div');
    headTime.className = 'ops-time-head';
    headTime.textContent = 'Time';
    head.appendChild(headTime);
    const headScroll = document.createElement('div');
    headScroll.className = 'ops-calendar-head-scroll';
    const headInner = document.createElement('div');
    headInner.className = 'ops-calendar-head-inner';
    headScroll.appendChild(headInner);
    head.appendChild(headScroll);
    vm.columns.forEach((col) => {
      const h = document.createElement('div');
      h.className = 'ops-staff-head';
      h.dataset.staffId = String(col.id);
      h.setAttribute('role', 'button');
      h.setAttribute('tabindex', '0');
      h.setAttribute('aria-haspopup', 'true');
      h.setAttribute('aria-expanded', 'false');
      h.setAttribute('aria-label', col.label + ', open options menu');
      const inner = document.createElement('div');
      inner.className = 'ops-staff-head-inner';
      const name = document.createElement('div');
      name.className = 'ops-staff-head-name';
      name.textContent = col.label;
      inner.appendChild(name);
      h.appendChild(inner);
      const exp = document.createElement('span');
      exp.className = 'ops-staff-head__exp';
      exp.setAttribute('aria-hidden', 'true');
      h.appendChild(exp);

      // ── dropdown menu ──────────────────────────────────────────────────────
      const menu = document.createElement('div');
      menu.className = 'ops-staff-menu';
      menu.setAttribute('role', 'menu');
      menu.setAttribute('aria-label', col.label + ' options');
      menu.setAttribute('aria-hidden', 'true');
      menu.setAttribute('inert', '');

      [
        { label: 'Block Out Time', action: 'block', icon: 'bi-lock' },
        { label: 'Edit Schedule', action: 'schedule', icon: 'bi-calendar-week' },
        { label: 'Edit Services', action: 'services', icon: 'bi-sliders2' },
        { label: 'View Profile', action: 'profile', icon: 'bi-person-vcard' },
        null,
        { label: 'Hide Column', action: 'hide', mod: 'danger', icon: 'bi-eye-slash' },
      ].forEach((def) => {
        if (def === null) {
          const sep = document.createElement('div');
          sep.className = 'ops-staff-menu__sep';
          sep.setAttribute('role', 'separator');
          menu.appendChild(sep);
          return;
        }
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ops-staff-menu__item' + (def.mod ? ' ops-staff-menu__item--' + def.mod : '');
        btn.dataset.action = def.action;
        btn.dataset.staffId = String(col.id);
        btn.dataset.staffLabel = col.label;
        btn.setAttribute('role', 'menuitem');
        appendStaffMenuItemIcon(btn, def.icon);
        const lbl = document.createElement('span');
        lbl.className = 'ops-staff-menu__label';
        lbl.textContent = def.label;
        btn.appendChild(lbl);
        menu.appendChild(btn);
      });

      h.appendChild(menu);

      h.addEventListener('click', (e) => {
        if (e.target.closest('.ops-staff-menu')) return;
        const expanded = h.getAttribute('aria-expanded') === 'true';
        closeAllStaffMenus();
        if (!expanded) {
          positionStaffMenuFixed(h, menu);
          menu.classList.add('ops-staff-menu--open');
          menu.removeAttribute('inert');
          menu.setAttribute('aria-hidden', 'false');
          h.setAttribute('aria-expanded', 'true');
          h.classList.add('ops-staff-head--open');
          const first = menu.querySelector('[role="menuitem"]');
          if (first) requestAnimationFrame(() => first.focus());
        }
        e.stopPropagation();
      });

      h.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); h.click(); }
        if (e.key === 'Escape') { closeAllStaffMenus(); h.focus(); }
        if (e.key === 'ArrowDown' && menu.classList.contains('ops-staff-menu--open')) {
          e.preventDefault();
          const items = [...menu.querySelectorAll('[role="menuitem"]')];
          if (items.length) items[0].focus();
        }
      });

      menu.addEventListener('click', async (e) => {
        const item = e.target.closest('[data-action]');
        if (!item) return;
        e.stopPropagation();
        closeAllStaffMenus();
        await handleStaffMenuAction(item.dataset.action, item.dataset.staffId, item.dataset.staffLabel || '');
      });

      menu.addEventListener('keydown', (e) => {
        const items = [...menu.querySelectorAll('[role="menuitem"]')];
        const idx = items.indexOf(document.activeElement);
        if (e.key === 'Escape') { closeAllStaffMenus(); h.focus(); }
        if (e.key === 'ArrowDown') { e.preventDefault(); if (idx < items.length - 1) items[idx + 1].focus(); }
        if (e.key === 'ArrowUp')   { e.preventDefault(); if (idx > 0) items[idx - 1].focus(); else { closeAllStaffMenus(); h.focus(); } }
        if (e.key === 'Tab') { closeAllStaffMenus(); }
      });

      headInner.appendChild(h);
    });
    const nStaffCols = vm.columns.length;
    if (nStaffCols > 0) {
      const colPat = 'repeat(' + nStaffCols + ', minmax(' + cw + 'px, ' + cw + 'px))';
      headInner.style.gridTemplateColumns = colPat;
    }
    headInner.style.paddingRight = '0px';

    const body = document.createElement('div');
    body.className = 'ops-calendar-body';
    body.style.height = vm.height + 'px';

    const labelsCol = document.createElement('div');
    labelsCol.className = 'ops-time-labels';
    vm.marks.forEach((mark) => {
      const row = document.createElement('div');
      row.className = 'ops-time-label';
      row.style.top = ((mark - vm.start) * getPixelsPerMinute() + GRID_TOP_INSET_PX) + 'px';
      const mod = ((mark % 60) + 60) % 60;
      if (mod === 0) {
        row.classList.add('ops-time-label--hour');
        row.textContent = fmtTime(mark);
      } else if (mod === 30) {
        row.classList.add('ops-time-label--half');
        row.textContent = fmtTime(mark);
      } else {
        row.classList.add('ops-time-label--micro');
      }
      labelsCol.appendChild(row);
    });
    body.appendChild(labelsCol);

    const lanesScroll = document.createElement('div');
    lanesScroll.className = 'ops-calendar-lanes-scroll';

    const laneWrap = document.createElement('div');
    laneWrap.className = 'ops-lanes';
    if (vm.columns.length > 0) {
      laneWrap.style.gridTemplateColumns =
        'repeat(' + vm.columns.length + ', minmax(' + cw + 'px, ' + cw + 'px))';
    }
    laneWrap.style.paddingRight = '0px';
    vm.columns.forEach((col) => {
      const lane = document.createElement('div');
      lane.className = 'ops-lane';
      lane.setAttribute('role', 'presentation');
      lane.dataset.staffId = String(col.id);
      const hoverPreview = document.createElement('div');
      hoverPreview.className = 'ops-slot-preview';
      hoverPreview.setAttribute('aria-hidden', 'true');
      const hoverLine = document.createElement('div');
      hoverLine.className = 'ops-slot-preview__line';
      hoverPreview.appendChild(hoverLine);
      const hoverDot = document.createElement('div');
      hoverDot.className = 'ops-slot-preview__dot';
      hoverPreview.appendChild(hoverDot);
      const hoverLabel = document.createElement('div');
      hoverLabel.className = 'ops-slot-preview__label';
      hoverPreview.appendChild(hoverLabel);
      lane.appendChild(hoverPreview);

      vm.marks.forEach((mark) => {
        const line = document.createElement('div');
        const lineMod = ((mark % 60) + 60) % 60;
        line.className = 'ops-grid-line' +
          (lineMod === 0 ? ' ops-grid-line--hour' : lineMod === 30 ? ' ops-grid-line--half' : '');
        line.style.top = ((mark - vm.start) * getPixelsPerMinute() + GRID_TOP_INSET_PX) + 'px';
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
          if (item.kind === 'appointment') {
            block.dataset.apptId     = String(item.id);
            block.dataset.apptStatus = item.status || '';
            block.dataset.rawStartAt = item.rawStartAt || '';
            block.dataset.staffLocked = item.staffAssignmentLocked ? '1' : '0';
            block.dataset.clientId = item.clientId ? String(item.clientId) : '';
            block.dataset.linkedInvoiceId = item.linkedInvoiceId ? String(item.linkedInvoiceId) : '';
            if (item.calendarDominant && item.calendarDominant.color_token) {
              block.style.setProperty('--cal-dominant', 'var(--' + item.calendarDominant.color_token + ')');
            } else {
              block.style.removeProperty('--cal-dominant');
            }
            if (item.calendarDominant && item.calendarDominant.code) {
              block.setAttribute('data-dominant-badge', String(item.calendarDominant.code));
            } else {
              block.removeAttribute('data-dominant-badge');
            }
          }
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
          const blockCq = document.createElement('div');
          blockCq.className = 'ops-block__cq';
          block.appendChild(blockCq);
          if (item.kind === 'appointment' && (item.bufferPctBefore > 0 || item.bufferPctAfter > 0)) {
            block.classList.add('ops-block-appt--with-buffers');
          }
          if (item.kind === 'appointment' && (item.bufferPctBefore > 0.5 || item.bufferPctAfter > 0.5)) {
            if (item.bufferPctBefore > 0.5) {
              const bbf = document.createElement('div');
              bbf.className = 'ops-block-buffer ops-block-buffer--before';
              bbf.style.flexBasis = item.bufferPctBefore + '%';
              bbf.title = 'Prep / staff buffer';
              blockCq.appendChild(bbf);
            }
            const core = document.createElement('div');
            core.className = 'ops-block-core';
            core.style.flexGrow = 1;
            core.style.flexBasis = item.bufferPctCore + '%';
            core.style.minHeight = '0';
            blockCq.appendChild(core);
            appendBlockHead(core, { badges: item.calendarBadges, timeLabel: item.timeLabel });
            const ttl = document.createElement('div');
            ttl.className = 'ops-block-title';
            ttl.textContent = safeLabel(item.title, MAX_TITLE_LENGTH) || 'Appointment';
            core.appendChild(ttl);
            if (item.meta) {
              const meta = document.createElement('div');
              meta.className = 'ops-block-meta';
              meta.textContent = safeLabel(item.meta, MAX_META_LENGTH);
              core.appendChild(meta);
            }
            if (item.statusLabel) {
              const st = document.createElement('div');
              st.className = 'ops-block-status';
              st.textContent = safeLabel(item.statusLabel, 32);
              core.appendChild(st);
            }
            if (item.bufferPctAfter > 0.5) {
              const bba = document.createElement('div');
              bba.className = 'ops-block-buffer ops-block-buffer--after';
              bba.style.flexBasis = item.bufferPctAfter + '%';
              bba.title = 'Turnover / room buffer (staff availability)';
              blockCq.appendChild(bba);
            }
          } else {
            let blockedTitle = '';
            if (item.kind === 'appointment') {
              appendBlockHead(blockCq, { badges: item.calendarBadges, timeLabel: item.timeLabel });
            } else if (item.kind === 'blocked') {
              blockedTitle = safeLabel(item.title, MAX_TITLE_LENGTH) || '';
              /* One headline: default "Blocked" only in .ops-block-title; .ops-block-kind only if title adds detail. */
              if (blockedTitle && blockedTitle !== 'Blocked') {
                appendBlockHead(blockCq, { kindLabel: 'Blocked', timeLabel: item.timeLabel });
              } else {
                appendBlockHead(blockCq, { timeLabel: item.timeLabel });
              }
            }
            const ttl0 = document.createElement('div');
            ttl0.className = 'ops-block-title';
            if (item.kind === 'blocked') {
              ttl0.textContent = blockedTitle && blockedTitle !== 'Blocked' ? blockedTitle : 'Blocked';
            } else {
              ttl0.textContent = safeLabel(item.title, MAX_TITLE_LENGTH) || 'Appointment';
            }
            blockCq.appendChild(ttl0);
            if (item.meta) {
              const meta0 = document.createElement('div');
              meta0.className = 'ops-block-meta';
              meta0.textContent = safeLabel(item.meta, MAX_META_LENGTH);
              blockCq.appendChild(meta0);
            }
            if (item.kind === 'appointment' && item.statusLabel) {
              const st0 = document.createElement('div');
              st0.className = 'ops-block-status';
              st0.textContent = safeLabel(item.statusLabel, 32);
              blockCq.appendChild(st0);
            }
          }
          if (item.kind === 'appointment' && item.clientPhone) {
            const prevTitle = block.getAttribute('title') || '';
            const contactLine = 'Contact: ' + item.clientPhone;
            block.setAttribute('title', prevTitle ? (prevTitle + '\n' + contactLine) : contactLine);
          }
          // ── Drag from calendar → clipboard or another lane ──────────────
          if (item.kind === 'appointment') {
            block.draggable = true;
            block.addEventListener('dragstart', (e) => {
              currentDragSource = 'calendar';
              e.dataTransfer.effectAllowed = 'copy'; // calendar blocks copy TO clipboard only
              e.dataTransfer.setData('text/plain', JSON.stringify({
                type: 'appt-drag',
                id:         item.id,
                title:      item.title      || '',
                meta:       item.meta       || '',
                time:       item.timeLabel  || '',
                status:     item.status     || '',
                rawStartAt: item.rawStartAt || '',
              }));
              requestAnimationFrame(() => block.classList.add('ops-block-appt--dragging'));
            });
            block.addEventListener('dragend', () => {
              currentDragSource = null;
              block.classList.remove('ops-block-appt--dragging');
              // Clear any lane drop-preview that may be left if the drag was abandoned
              document.querySelectorAll('.ops-drop-preview').forEach((p) => { p.hidden = true; });
            });
          }
          lane.appendChild(block);
        });

      lane.addEventListener('mousemove', (e) => {
        if (e.target.closest('.ops-block')) {
          hoverPreview.classList.remove('is-active', 'is-flipped');
          clearSlotPreviewViewportPin(hoverPreview);
          return;
        }
        const rect = lane.getBoundingClientRect();
        const offsetY = e.clientY - rect.top;
        const snapped = snapTimeFromTop(offsetY, vm.start, vm.step, vm.end);
        const topPx = Math.max(GRID_TOP_INSET_PX, (toMinutes(snapped) - vm.start) * getPixelsPerMinute() + GRID_TOP_INSET_PX);
        positionSlotPreviewInViewport(hoverPreview, lane, topPx);
        hoverLabel.textContent = snapped;
        refreshSlotPreviewFlip(hoverPreview, lane, topPx);
        hoverPreview.classList.add('is-active');
      });

      lane.addEventListener('mouseleave', () => {
        hoverPreview.classList.remove('is-active', 'is-flipped');
        clearSlotPreviewViewportPin(hoverPreview);
      });

      // ── Drop target: reschedule appointment onto this lane+time ───────────
      const dropPreview = document.createElement('div');
      dropPreview.className = 'ops-drop-preview';
      dropPreview.hidden = true;
      dropPreview.setAttribute('aria-hidden', 'true');
      const dropLabel = document.createElement('div');
      dropLabel.className = 'ops-drop-preview__label';
      dropPreview.appendChild(dropLabel);
      lane.appendChild(dropPreview);

      lane.addEventListener('dragover', (e) => {
        if (!Array.from(e.dataTransfer.types).includes('text/plain')) return;
        // Only allow drops from the clipboard panel, not direct calendar→calendar moves
        if (currentDragSource !== 'clipboard') return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const rect = lane.getBoundingClientRect();
        const snapped = snapTimeFromTop(e.clientY - rect.top, vm.start, vm.step, vm.end);
        const topPx = Math.max(GRID_TOP_INSET_PX, (toMinutes(snapped) - vm.start) * getPixelsPerMinute() + GRID_TOP_INSET_PX);
        dropPreview.style.top = topPx + 'px';
        dropPreview.hidden = false;
        dropLabel.textContent = col.label + ' · ' + snapped;
        hoverPreview.classList.remove('is-active', 'is-flipped');
        clearSlotPreviewViewportPin(hoverPreview);
      });

      lane.addEventListener('dragleave', (e) => {
        if (!lane.contains(e.relatedTarget)) dropPreview.hidden = true;
      });

      let dropInFlight = false;
      lane.addEventListener('drop', async (e) => {
        e.preventDefault();
        dropPreview.hidden = true;
        if (dropInFlight) return;
        let data;
        try { data = JSON.parse(e.dataTransfer.getData('text/plain')); } catch (err) { return; }
        // Only accept drops that originate from the clipboard panel — never direct calendar→calendar drags
        if (!data || !data.id || data.type !== 'clipboard-appt-drag') return;
        const rect = lane.getBoundingClientRect();
        const snapped = snapTimeFromTop(e.clientY - rect.top, vm.start, vm.step, vm.end);
        const startTime = (dateEl.value) + ' ' + snapped + ':00';
        if (statusEl) statusEl.textContent = 'Rescheduling…';
        dropInFlight = true;
        const extraBody = { start_time: startTime, staff_id: String(col.id) };
        if (data.rawStartAt) extraBody.expected_current_start_at = data.rawStartAt;
        const { ok, payload } = await apptQuickFetch(
          '/appointments/' + data.id + '/reschedule',
          extraBody
        );
        dropInFlight = false;
        if (!ok) {
          const msg = (payload && payload.error && payload.error.message) || 'Could not reschedule.';
          if (statusEl) statusEl.textContent = '\u26a0 ' + msg;
          setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 4000);
          return;
        }
        removeFromClipboard(data.id);
        if (statusEl) {
          statusEl.textContent = '\u2713 Moved to ' + col.label + ' at ' + snapped;
          setTimeout(() => { if (statusEl) statusEl.textContent = ''; }, 3000);
        }
        window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
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
          time: snapTimeFromTop(offsetY, vm.start, vm.step, vm.end),
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

    lanesScroll.appendChild(laneWrap);
    body.appendChild(lanesScroll);
    bindOpsCalendarHorizontalScrollSync(headScroll, lanesScroll);
    root.appendChild(head);
    root.appendChild(body);
    wrap.appendChild(root);
    refreshCalendarHorizontalScrollEls();
    collectCalendarHorizontalColumnGeometry();
    ensureCalendarOverlayHead(vm);
    refreshCalendarOverlayHeadEls();
    syncCalendarOverlayHeadScrollLeft();
    initNowLine(vm);
    updateRailDayMeta(vm, apptCount);
    updateCalendarHorizontalNavState();
    scheduleCalendarHorizontalNavStateSync();
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
    if (openTime && closeTime) {
      if (anomalies > 0) {
        branchHoursIndicatorEl.textContent =
          anomalies + ' appointment(s) fall outside opening hours (' + openTime + '–' + closeTime + ').';
        branchHoursIndicatorEl.className =
          'appts-calendar-hours calendar-branch-hours-indicator calendar-branch-hours-indicator--anomaly';
      } else {
        branchHoursIndicatorEl.textContent = '';
        branchHoursIndicatorEl.className = 'appts-calendar-hours calendar-branch-hours-indicator';
      }
    } else {
      branchHoursIndicatorEl.textContent = 'Opening hours not configured for this branch/day.';
      branchHoursIndicatorEl.className =
        'appts-calendar-hours calendar-branch-hours-indicator calendar-branch-hours-indicator--missing';
    }
  }

  function branchEnvelopeForLane(meta, dayStart, dayEnd) {
    if (!meta || !meta.available) return null;
    const range = Math.max(1, (dayEnd - dayStart));
    if (meta.isClosedDay) {
      return {
        beforeHeight: range * getPixelsPerMinute(),
        afterTop: range * getPixelsPerMinute(),
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
      beforeHeight: Math.max(0, (openClamped - dayStart) * getPixelsPerMinute()),
      afterTop: Math.max(0, (closeClamped - dayStart) * getPixelsPerMinute()),
      afterHeight: Math.max(0, (dayEnd - closeClamped) * getPixelsPerMinute())
    };
  }

  function getCurrentBranchStaffIdSet() {
    const s = new Set();
    if (lastCalendarPayload && Array.isArray(lastCalendarPayload.staff)) {
      lastCalendarPayload.staff.forEach((x) => {
        if (x != null && x.id != null) s.add(String(x.id));
      });
    }
    return s;
  }

  /** Apply hidden_staff_ids only for staff that exist on the current branch grid (same-branch saved-view contract). */
  function applyHiddenStaffIdsFromSavedConfig(ids) {
    if (!Array.isArray(ids)) return;
    const valid = getCurrentBranchStaffIdSet();
    if (valid.size === 0) {
      pendingDeferredHiddenStaffIds = ids.map(String);
      return;
    }
    pendingDeferredHiddenStaffIds = null;
    const next = new Set();
    ids.forEach((x) => {
      const id = String(x);
      if (valid.has(id)) next.add(id);
    });
    setHiddenStaffIds(next);
  }

  function flushDeferredHiddenStaffIdsIfReady() {
    if (pendingDeferredHiddenStaffIds === null) return;
    const ids = pendingDeferredHiddenStaffIds;
    if (!lastCalendarPayload || !Array.isArray(lastCalendarPayload.staff) || lastCalendarPayload.staff.length === 0) {
      return;
    }
    pendingDeferredHiddenStaffIds = null;
    applyHiddenStaffIdsFromSavedConfig(ids);
  }

  function applyViewConfigFields(cfg) {
    if (!cfg || typeof cfg !== 'object') return;
    if (cfg.staff_columns_per_view != null && Number.isFinite(Number(cfg.staff_columns_per_view))) {
      staffColumnsPerView = Math.max(1, Math.min(6, Math.round(Number(cfg.staff_columns_per_view))));
      /* columnWidthPx will be computed from viewport in the next renderCalendar call */
    } else if (cfg.column_width_px != null && Number.isFinite(Number(cfg.column_width_px))) {
      /* Legacy fallback: saved raw px → keep staffColumnsPerView=2, override will recalculate anyway */
      columnWidthPx = Math.max(96, Math.min(420, Number(cfg.column_width_px)));
    }
    if (cfg.time_zoom_percent != null && Number.isFinite(Number(cfg.time_zoom_percent))) {
      timeZoomPercent = Math.max(MIN_TIME_ZOOM_PERCENT, Math.min(MAX_TIME_ZOOM_PERCENT, Number(cfg.time_zoom_percent)));
    }
    if (typeof cfg.show_in_progress === 'boolean') {
      showInProgressAppointments = cfg.show_in_progress;
    }
    if (Array.isArray(cfg.hidden_staff_ids)) {
      applyHiddenStaffIdsFromSavedConfig(cfg.hidden_staff_ids);
    }
    if (Array.isArray(cfg.staff_order_scheduled_ids)) {
      const next = [];
      const seen = new Set();
      cfg.staff_order_scheduled_ids.forEach((id) => {
        const s = String(id);
        if (s === '' || seen.has(s)) return;
        seen.add(s);
        next.push(s);
      });
      staffOrderScheduledIds = next;
    }
    if (Array.isArray(cfg.staff_order_freelancer_ids)) {
      const next = [];
      const seen = new Set();
      cfg.staff_order_freelancer_ids.forEach((id) => {
        const s = String(id);
        if (s === '' || seen.has(s)) return;
        seen.add(s);
        next.push(s);
      });
      staffOrderFreelancerIds = next;
    }
  }

  function applyCalendarUiBootstrap(data) {
    if (!data || typeof data !== 'object') return;
    appliedDefaultViewConfigFromBootstrap = false;
    const persisted = Boolean(data.preferences_persisted);
    // DB row for this branch → always use server preferences (authoritative).
    if (persisted && data.preferences && typeof data.preferences === 'object') {
      applyViewConfigFields(data.preferences);
    } else if (!persisted && data.default_view_config && typeof data.default_view_config === 'object') {
      // No per-branch row yet: apply default saved view config if the user has one (GET still sends generic defaults in `preferences`).
      appliedDefaultViewConfigFromBootstrap = true;
      applyViewConfigFields(data.default_view_config);
    } else if (data.preferences && typeof data.preferences === 'object') {
      applyViewConfigFields(data.preferences);
    }
    syncCalendarToolbarControls();
  }

  function buildCalendarPrefsPayload() {
    /* When in responsive staff-columns mode, columnWidthPx is derived from viewport at runtime
       and must NOT be sent — it can exceed the server's 96–420 range. Only staff_columns_per_view
       is saved. column_width_px is included only in legacy free-px fallback mode. */
    const base = {
      staff_columns_per_view: staffColumnsPerView,
      time_zoom_percent: timeZoomPercent,
      show_in_progress: showInProgressAppointments,
      hidden_staff_ids: [...getHiddenStaffIds()],
      staff_order_scheduled_ids: Array.isArray(staffOrderScheduledIds) ? staffOrderScheduledIds.slice(0, 240) : [],
      staff_order_freelancer_ids: Array.isArray(staffOrderFreelancerIds) ? staffOrderFreelancerIds.slice(0, 240) : [],
    };
    if (staffColumnsPerView == null) {
      base.column_width_px = Math.max(96, Math.min(420, columnWidthPx));
    }
    return base;
  }

  /**
   * Best-effort save when the document is unloading (debounced POST may not have fired yet).
   * Uses fetch keepalive so the request can outlive the page.
   */
  function sendCalendarPrefsKeepalive() {
    try {
      if (calendarPersistenceWriteDisabled) return;
      if (!dateEl || !dateEl.value) return;
      const token = getCsrfToken();
      if (!token) return;
      const params = currentCalendarQuery();
      const url = '/calendar/ui-preferences?' + params.toString();
      fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': token,
        },
        body: JSON.stringify(buildCalendarPrefsPayload()),
        keepalive: true,
      }).catch(() => {});
    } catch (_e) { /* ignore */ }
  }

  function buildCurrentViewConfig() {
    return {
      column_width_px: columnWidthPx,
      time_zoom_percent: timeZoomPercent,
      show_in_progress: showInProgressAppointments,
      hidden_staff_ids: [...getHiddenStaffIds()],
      staff_order_scheduled_ids: Array.isArray(staffOrderScheduledIds) ? staffOrderScheduledIds.slice(0, 240) : [],
      staff_order_freelancer_ids: Array.isArray(staffOrderFreelancerIds) ? staffOrderFreelancerIds.slice(0, 240) : [],
    };
  }

  function schedulePersistCalendarPrefs() {
    if (calendarPersistenceWriteDisabled) return;
    if (calendarToolbarSaveTimer) clearTimeout(calendarToolbarSaveTimer);
    calendarToolbarSaveTimer = setTimeout(() => {
      void persistCalendarPrefs();
    }, 280);
  }

  /** Clear debounced save and POST immediately (Fit / Staff Apply — avoid races with load/bootstrap). */
  function commitCalendarPrefsToServerImmediate() {
    if (calendarPersistenceWriteDisabled) return;
    if (calendarToolbarSaveTimer) clearTimeout(calendarToolbarSaveTimer);
    calendarToolbarSaveTimer = null;
    void persistCalendarPrefs();
  }

  async function persistCalendarPrefs() {
    if (calendarPersistenceWriteDisabled) return;
    const params = currentCalendarQuery();
    const url = '/calendar/ui-preferences?' + params.toString();
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-Token': getCsrfToken(),
        },
        body: JSON.stringify(buildCalendarPrefsPayload()),
      });
      const j = await res.json().catch(() => null);
      if (res.status === 419) {
        // CSRF middleware returns HTML; 419 is unambiguous (do not treat other non-JSON as session expiry).
        calendarPrefsSaveFailCount = 0;
        showCalendarPrefsAlert('Session expired \u2014 please refresh the page.');
        return;
      }
      if (!res.ok && j === null) {
        calendarPrefsSaveFailCount++;
        if (calendarPrefsSaveFailCount < 2) {
          calendarToolbarSaveTimer = setTimeout(() => { void persistCalendarPrefs(); }, 3000);
          return;
        }
        calendarPrefsSaveFailCount = 0;
        showCalendarPrefsAlert('Could not read server response. Check your connection or try again.');
        return;
      }
      if (!res.ok || !(j && j.success)) {
        const errCode = j && j.error && typeof j.error.code === 'string' ? j.error.code : '';
        if (errCode === 'PERSISTENCE_UNAVAILABLE') {
          calendarPersistenceWriteDisabled = true;
          calendarPrefsSaveFailCount = 0;
          const msg = j && j.error && typeof j.error.message === 'string'
            ? j.error.message
            : 'Calendar preferences storage is not available.';
          showCalendarPrefsAlert(msg);
          return;
        }
        calendarPrefsSaveFailCount++;
        if (calendarPrefsSaveFailCount < 2) {
          calendarToolbarSaveTimer = setTimeout(() => { void persistCalendarPrefs(); }, 3000);
          return;
        }
        calendarPrefsSaveFailCount = 0;
        let msg = j && j.error && typeof j.error.message === 'string'
          ? j.error.message
          : 'Unable to save calendar preferences.';
        if (errCode === 'INTERNAL_ERROR') {
          msg += ' Ensure database migration 134 (calendar_user_preferences) is applied, or ask an administrator to check server logs.';
        }
        showCalendarPrefsAlert(msg);
        return;
      }
      // Success
      calendarPrefsSaveFailCount = 0;
      calendarPrefsPersistedFromServer = true;
      clearCalendarAutofitTimeZoomLock();
      clearCalendarPrefsAlert();
    } catch (_e) {
      calendarPrefsSaveFailCount++;
      if (calendarPrefsSaveFailCount < 2) {
        calendarToolbarSaveTimer = setTimeout(() => { void persistCalendarPrefs(); }, 3000);
        return;
      }
      calendarPrefsSaveFailCount = 0;
      showCalendarPrefsAlert('Unable to save calendar preferences (network error).');
    }
  }

  let openToolbarPopover = null;
  let openToolbarTrigger = null;
  let openDialog = null;
  let openDialogTrigger = null;
  let releasePopoverFocusTrap = null;
  let releaseDialogFocusTrap = null;

  function setToolbarViewsInlineError(message) {
    const el = document.getElementById('cal-toolbar-views-error');
    if (!el) return;
    const text = String(message || '').trim();
    if (text === '') {
      el.textContent = '';
      el.classList.add('visually-hidden');
      return;
    }
    el.textContent = text;
    el.classList.remove('visually-hidden');
  }

  function installFocusTrap(container, onEscape) {
    if (!container) return () => {};
    const keyHandler = (ev) => {
      if (ev.key === 'Escape') {
        ev.preventDefault();
        if (typeof onEscape === 'function') onEscape();
        return;
      }
      if (ev.key !== 'Tab') return;
      const nodes = [...container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
        .filter((n) => !n.hasAttribute('hidden') && n.getAttribute('aria-hidden') !== 'true');
      if (nodes.length === 0) return;
      const first = nodes[0];
      const last = nodes[nodes.length - 1];
      if (ev.shiftKey && document.activeElement === first) {
        ev.preventDefault();
        last.focus();
      } else if (!ev.shiftKey && document.activeElement === last) {
        ev.preventDefault();
        first.focus();
      }
    };
    container.addEventListener('keydown', keyHandler);
    return () => container.removeEventListener('keydown', keyHandler);
  }

  /** Milk-glass contextual anchor: active while Tools panel or any in-anchor popover is open. */
  function syncCalendarContextAnchorActiveState() {
    const anchor = document.getElementById('cal-toolbar-context-anchor');
    if (!anchor) return;
    const panel = document.getElementById('cal-toolbar-tools-panel');
    const panelOpen = !!(panel && !panel.hidden);
    const nestedOpen = !!anchor.querySelector('.appts-cal-toolbar__popover[aria-hidden="false"]');
    anchor.classList.toggle('appts-cal-context-anchor--active', panelOpen || nestedOpen);
  }

  function closeCalendarToolbarPopovers(restoreFocus = false) {
    document.querySelectorAll('.appts-cal-toolbar__popover[aria-hidden="false"]').forEach((p) => {
      p.setAttribute('aria-hidden', 'true');
      p.hidden = true;
    });
    document.querySelectorAll('.appts-cal-toolbar__btn[aria-expanded="true"]').forEach((b) => {
      b.setAttribute('aria-expanded', 'false');
    });
    if (typeof releasePopoverFocusTrap === 'function') releasePopoverFocusTrap();
    releasePopoverFocusTrap = null;
    if (restoreFocus && openToolbarTrigger && typeof openToolbarTrigger.focus === 'function') {
      openToolbarTrigger.focus();
    }
    openToolbarPopover = null;
    openToolbarTrigger = null;
    if (calendarToolbarSaveTimer) {
      clearTimeout(calendarToolbarSaveTimer);
      calendarToolbarSaveTimer = null;
      void persistCalendarPrefs();
    }
    syncCalendarContextAnchorActiveState();
  }

  function closeToolsDropdownIfOpen() {
    const toolsPanel = document.getElementById('cal-toolbar-tools-panel');
    const toolsToggle = document.getElementById('cal-toolbar-tools-toggle');
    if (!toolsPanel || toolsPanel.hidden) return;
    toolsPanel.hidden = true;
    if (toolsToggle) toolsToggle.setAttribute('aria-expanded', 'false');
    if (calendarToolbarSaveTimer) {
      clearTimeout(calendarToolbarSaveTimer);
      calendarToolbarSaveTimer = null;
      void persistCalendarPrefs();
    }
    syncCalendarContextAnchorActiveState();
  }

  function hideToolbarDialog(restoreFocus = false) {
    const backdrop = document.getElementById('cal-toolbar-dialog-backdrop');
    if (backdrop) backdrop.hidden = true;
    if (openDialog) {
      openDialog.hidden = true;
      openDialog.setAttribute('aria-hidden', 'true');
    }
    if (typeof releaseDialogFocusTrap === 'function') releaseDialogFocusTrap();
    releaseDialogFocusTrap = null;
    if (restoreFocus && openDialogTrigger && typeof openDialogTrigger.focus === 'function') {
      openDialogTrigger.focus();
    }
    openDialog = null;
    openDialogTrigger = null;
  }

  function showToolbarDialog(dialogId, triggerBtn) {
    const dialog = document.getElementById(dialogId);
    const backdrop = document.getElementById('cal-toolbar-dialog-backdrop');
    if (!dialog || !backdrop) return null;
    closeCalendarToolbarPopovers(false);
    hideToolbarDialog(false);
    backdrop.hidden = false;
    dialog.hidden = false;
    dialog.setAttribute('aria-hidden', 'false');
    openDialog = dialog;
    openDialogTrigger = triggerBtn || null;
    const focusable = dialog.querySelector('input, button, [href], [tabindex]:not([tabindex="-1"])');
    if (focusable && typeof focusable.focus === 'function') focusable.focus();
    releaseDialogFocusTrap = installFocusTrap(dialog, () => hideToolbarDialog(true));
    return dialog;
  }

  function updateSliderFill(sliderEl, min, max, value) {
    if (!sliderEl) return;
    const pct = Math.max(0, Math.min(100, ((value - min) / (max - min)) * 100));
    sliderEl.style.setProperty('--track-fill', pct.toFixed(1) + '%');
  }

  function updateZoomPresetActiveState() {
    document.querySelectorAll('[data-zoom-preset]').forEach((btn) => {
      const v = parseInt(btn.dataset.zoomPreset, 10);
      btn.classList.toggle('appts-cal-zoom__preset--active', v === timeZoomPercent);
    });
  }

  function syncCalendarToolbarControls() {
    const z = document.getElementById('cal-toolbar-zoom-slider');
    const ch = document.getElementById('cal-toolbar-in-progress');
    const zv = document.getElementById('cal-toolbar-zoom-value');
    const cv = document.getElementById('cal-toolbar-col-value');
    if (z) z.value = String(timeZoomPercent);
    if (ch) ch.checked = !!showInProgressAppointments;
    if (zv) zv.textContent = String(timeZoomPercent) + '%';
    if (cv) cv.textContent = String(staffColumnsPerView) + (staffColumnsPerView === 1 ? ' column' : ' columns');
    updateSliderFill(z, 25, 200, timeZoomPercent);
    document.querySelectorAll('[data-col-count]').forEach((btn) => {
      const n = parseInt(btn.dataset.colCount, 10);
      const active = n === staffColumnsPerView;
      btn.classList.toggle('appts-cal-zoom__col-btn--active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    updateZoomPresetActiveState();
  }

  async function refreshViewsListInToolbar() {
    const ul = document.getElementById('cal-toolbar-views-list');
    if (!ul) return;
    ul.innerHTML = '';
    const params = currentCalendarQuery();
    try {
      const res = await fetch('/calendar/ui-preferences?' + params.toString(), {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });
      const j = await res.json().catch(() => null);
      const views = j && j.success && j.data && Array.isArray(j.data.views) ? j.data.views : [];
      views.forEach((v) => {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = '#';
        a.className = 'appts-cal-toolbar__views-link';
        a.textContent = String(v.name || '') + (v.is_default ? ' (default)' : '');
        a.addEventListener('click', async (e) => {
          e.preventDefault();
          try {
            const det = await fetch('/calendar/saved-views/' + encodeURIComponent(String(v.id)), {
              headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const dj = await det.json().catch(() => null);
            const vw = dj && dj.success && dj.data && dj.data.view ? dj.data.view : null;
            if (vw && vw.config && typeof vw.config === 'object') {
              applyViewConfigFields(vw.config);
              setCalendarAutofitTimeZoomLocked();
              activeSavedViewId = Number(v.id) || null;
              syncCalendarToolbarControls();
              schedulePersistCalendarPrefs();
              load();
            }
          } catch (_e) { /* ignore */ }
          closeCalendarToolbarPopovers();
        });
        li.appendChild(a);
        ul.appendChild(li);
      });
    } catch (_e) { /* ignore */ }
  }

  /** Borderless "Today" in the command strip: accent when the grid is already on today's date. */
  function updateNowButtonState() {
    const btn = document.getElementById('calendar-toolbar-today-btn');
    if (!btn) return;
    const todayStr = getBranchNow().dateStr;
    const isToday = dateEl && dateEl.value === todayStr;
    btn.classList.toggle('appts-cal-toolbar-today-btn--today', isToday);
    btn.removeAttribute('title');
    btn.setAttribute('aria-label', isToday ? 'Recenter on current time' : 'Jump to today');
  }

  function initCalendarToolbar() {
    const root = document.getElementById('appts-calendar-toolbar');
    if (!root) return;

    const toolsToggle = document.getElementById('cal-toolbar-tools-toggle');
    const toolsPanel = document.getElementById('cal-toolbar-tools-panel');
    if (toolsToggle && toolsPanel) {
      toolsToggle.addEventListener('click', (e) => {
        e.stopPropagation();
        const opening = toolsPanel.hidden;
        if (!opening) {
          closeCalendarToolbarPopovers(false);
        }
        toolsPanel.hidden = !opening;
        toolsToggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
        syncCalendarContextAnchorActiveState();
      });
    }

    const refreshBtn = document.getElementById('cal-toolbar-refresh');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', () => {
        closeCalendarToolbarPopovers();
        load();
      });
    }

    const todayBarBtn = document.getElementById('calendar-toolbar-today-btn');
    if (todayBarBtn) {
      todayBarBtn.addEventListener('click', () => {
        const todayStr = getBranchNow().dateStr;
        if (dateEl && dateEl.value === todayStr) {
          calendarViewportManualScrollLock = false;
          scheduleNowLineViewportAnchor({ behavior: 'smooth' });
        } else {
          goToBranchToday();
        }
        updateNowButtonState();
      });
    }

    const zoomBtn = document.getElementById('cal-toolbar-zoom');
    const zoomPop = document.getElementById('cal-toolbar-zoom-pop');
    const staffBtn = document.getElementById('cal-toolbar-staff');
    const staffPop = document.getElementById('cal-toolbar-staff-pop');
    const viewBtn = document.getElementById('cal-toolbar-views');
    const viewPop = document.getElementById('cal-toolbar-views-pop');
    const printBtn = document.getElementById('cal-toolbar-print');
    const printPop = document.getElementById('cal-toolbar-print-pop');

    const prefersHoverSubmenus =
      typeof window.matchMedia === 'function' &&
      window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    /**
     * Tools column flyouts: hover-open on fine pointers (with gap-tolerant delays),
     * click-toggle on touch / coarse pointers. Keyboard: Enter/Space toggles + focus trap when open.
     */
    function bindToolsMenuFlyout(btn, pop, options = {}) {
      const onBeforeOpen = options.onBeforeOpen;
      if (!btn || !pop) return;

      let openTimer = null;
      let closeTimer = null;
      const OPEN_DELAY_MS = 70;
      const CLOSE_DELAY_MS = 220;

      function clearFlyoutTimers() {
        if (openTimer) {
          clearTimeout(openTimer);
          openTimer = null;
        }
        if (closeTimer) {
          clearTimeout(closeTimer);
          closeTimer = null;
        }
      }

      function openFlyout() {
        clearFlyoutTimers();
        closeCalendarToolbarPopovers(false);
        if (typeof onBeforeOpen === 'function') onBeforeOpen();
        pop.hidden = false;
        pop.setAttribute('aria-hidden', 'false');
        btn.setAttribute('aria-expanded', 'true');
        openToolbarPopover = pop;
        openToolbarTrigger = btn;
        if (typeof releasePopoverFocusTrap === 'function') releasePopoverFocusTrap();
        releasePopoverFocusTrap = null;
        syncCalendarContextAnchorActiveState();
      }

      function scheduleOpenFlyout() {
        clearFlyoutTimers();
        openTimer = setTimeout(() => {
          openTimer = null;
          openFlyout();
        }, OPEN_DELAY_MS);
      }

      function scheduleCloseFlyout() {
        clearFlyoutTimers();
        closeTimer = setTimeout(() => {
          closeTimer = null;
          if (pop.getAttribute('aria-hidden') === 'false') {
            closeCalendarToolbarPopovers(false);
          }
        }, CLOSE_DELAY_MS);
      }

      if (prefersHoverSubmenus) {
        btn.addEventListener('mouseenter', () => scheduleOpenFlyout());
        btn.addEventListener('mouseleave', () => scheduleCloseFlyout());
        pop.addEventListener('mouseenter', clearFlyoutTimers);
        pop.addEventListener('mouseleave', () => scheduleCloseFlyout());
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
        });
      } else {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const wasOpen = pop.getAttribute('aria-hidden') === 'false';
          closeCalendarToolbarPopovers(false);
          if (!wasOpen) {
            if (typeof onBeforeOpen === 'function') onBeforeOpen();
            pop.hidden = false;
            pop.setAttribute('aria-hidden', 'false');
            btn.setAttribute('aria-expanded', 'true');
            openToolbarPopover = pop;
            openToolbarTrigger = btn;
            const autofocusTarget = pop.querySelector('input, button, a[href], [tabindex]:not([tabindex="-1"])');
            if (autofocusTarget && typeof autofocusTarget.focus === 'function') autofocusTarget.focus();
            releasePopoverFocusTrap = installFocusTrap(pop, () => closeCalendarToolbarPopovers(true));
          }
          syncCalendarContextAnchorActiveState();
        });
      }

      btn.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        const wasOpen = pop.getAttribute('aria-hidden') === 'false';
        if (wasOpen) {
          closeCalendarToolbarPopovers(true);
          if (typeof btn.focus === 'function') btn.focus();
          return;
        }
        closeCalendarToolbarPopovers(false);
        if (typeof onBeforeOpen === 'function') onBeforeOpen();
        pop.hidden = false;
        pop.setAttribute('aria-hidden', 'false');
        btn.setAttribute('aria-expanded', 'true');
        openToolbarPopover = pop;
        openToolbarTrigger = btn;
        const autofocusTarget = pop.querySelector('input, button, a[href], [tabindex]:not([tabindex="-1"])');
        if (autofocusTarget && typeof autofocusTarget.focus === 'function') autofocusTarget.focus();
        if (typeof releasePopoverFocusTrap === 'function') releasePopoverFocusTrap();
        releasePopoverFocusTrap = installFocusTrap(pop, () => closeCalendarToolbarPopovers(true));
        syncCalendarContextAnchorActiveState();
      });
    }

    bindToolsMenuFlyout(zoomBtn, zoomPop);
    bindToolsMenuFlyout(staffBtn, staffPop, { onBeforeOpen: populateStaffFilterModal });
    bindToolsMenuFlyout(viewBtn, viewPop, {
      onBeforeOpen: () => {
        setToolbarViewsInlineError('');
        void refreshViewsListInToolbar();
      },
    });
    bindToolsMenuFlyout(printBtn, printPop);

    const folderBtn = document.getElementById('cal-toolbar-folder');
    if (folderBtn) {
      folderBtn.addEventListener('click', () => {
        closeCalendarToolbarPopovers();
        const tab = document.querySelector('.cal-tools-tab[data-tools-tab="waitlist"]');
        if (tab) tab.click();
      });
    }

    const zSl = document.getElementById('cal-toolbar-zoom-slider');
    if (zSl) {
      zSl.addEventListener('input', () => {
        setCalendarAutofitTimeZoomLocked();
        timeZoomPercent = Math.max(MIN_TIME_ZOOM_PERCENT, Math.min(MAX_TIME_ZOOM_PERCENT, parseInt(String(zSl.value), 10) || 100));
        schedulePersistCalendarPrefs();
        if (lastCalendarPayload) renderCalendar(lastCalendarPayload);
        syncCalendarToolbarControls();
        scheduleCalendarHorizontalNavStateSync();
      });
    }
    document.querySelectorAll('[data-col-count]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const n = parseInt(btn.dataset.colCount, 10);
        if (!Number.isFinite(n) || n < 1) return;
        staffColumnsPerView = Math.max(1, Math.min(6, n));
        columnWidthPx = computeColumnWidthFromStaffCount(staffColumnsPerView);
        schedulePersistCalendarPrefs();
        if (lastCalendarPayload) renderCalendar(lastCalendarPayload);
        syncCalendarToolbarControls();
        scheduleCalendarHorizontalNavStateSync();
      });
    });
    const ip = document.getElementById('cal-toolbar-in-progress');
    if (ip) {
      ip.addEventListener('change', () => {
        showInProgressAppointments = !!ip.checked;
        schedulePersistCalendarPrefs();
        if (lastCalendarPayload) renderCalendar(lastCalendarPayload);
        scheduleCalendarHorizontalNavStateSync();
      });
    }

    // ── Zoom reset buttons ─────────────────────────────────────────────────
    document.getElementById('cal-toolbar-col-reset')?.addEventListener('click', () => {
      staffColumnsPerView = 2;
      columnWidthPx = computeColumnWidthFromStaffCount(2);
      clearCalendarAutofitTimeZoomLock();
      syncCalendarToolbarControls();
      schedulePersistCalendarPrefs();
      if (lastCalendarPayload) renderCalendar(lastCalendarPayload);
      scheduleCalendarHorizontalNavStateSync();
    });
    document.getElementById('cal-toolbar-zoom-reset')?.addEventListener('click', () => {
      timeZoomPercent = 100;
      clearCalendarAutofitTimeZoomLock();
      syncCalendarToolbarControls();
      schedulePersistCalendarPrefs();
      if (lastCalendarPayload) renderCalendar(lastCalendarPayload);
    });

    // ── Time zoom preset buttons ───────────────────────────────────────────
    document.querySelectorAll('[data-zoom-preset]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const v = parseInt(btn.dataset.zoomPreset, 10);
        if (!Number.isFinite(v)) return;
        timeZoomPercent = Math.max(MIN_TIME_ZOOM_PERCENT, Math.min(MAX_TIME_ZOOM_PERCENT, v));
        setCalendarAutofitTimeZoomLocked();
        syncCalendarToolbarControls();
        schedulePersistCalendarPrefs();
        if (lastCalendarPayload) renderCalendar(lastCalendarPayload);
      });
    });

    // ── "Fit" preset: auto-fit workday to viewport ─────────────────────────
    document.getElementById('cal-toolbar-zoom-fit')?.addEventListener('click', () => {
      clearCalendarAutofitTimeZoomLock();
      calendarPrefsPersistedFromServer = false;
      appliedDefaultViewConfigFromBootstrap = false;
      tryFitWorkdayToViewport({ explicitUserFit: true });
      syncCalendarToolbarControls();
      commitCalendarPrefsToServerImmediate();
      scheduleCalendarHorizontalNavStateSync();
    });

    document.getElementById('cal-toolbar-staff-apply')?.addEventListener('click', () => {
      const box = document.getElementById('cal-toolbar-staff-fields');
      if (!box) return;
      const hidden = new Set();
      box.querySelectorAll('input[type="checkbox"][data-staff-id]').forEach((inp) => {
        if (!inp.checked) hidden.add(String(inp.dataset.staffId));
      });
      const readOrder = (section) => {
        const out = [];
        const scope = box.querySelector('[data-staff-section="' + section + '"]');
        if (!scope) return out;
        scope.querySelectorAll('.appts-cal-staff-modal__row[data-staff-id]').forEach((row) => {
          if (!(row instanceof HTMLElement)) return;
          const id = String(row.dataset.staffId || '');
          if (id) out.push(id);
        });
        return out;
      };
      staffOrderScheduledIds = readOrder('scheduled');
      staffOrderFreelancerIds = readOrder('freelancer');
      setHiddenStaffIds(hidden);
      closeCalendarToolbarPopovers();
      if (lastCalendarPayload) renderCalendar(lastCalendarPayload);
      updateCalendarHorizontalNavState();
      commitCalendarPrefsToServerImmediate();
    });
    document.getElementById('cal-toolbar-staff-all')?.addEventListener('click', () => {
      document.querySelectorAll('#cal-toolbar-staff-fields input[type="checkbox"]').forEach((i) => { i.checked = true; });
      scheduleCalendarHorizontalNavStateSync();
      updateCalendarHorizontalNavState();
    });
    document.getElementById('cal-toolbar-staff-none')?.addEventListener('click', () => {
      document.querySelectorAll('#cal-toolbar-staff-fields input[type="checkbox"]').forEach((i) => { i.checked = false; });
      scheduleCalendarHorizontalNavStateSync();
      updateCalendarHorizontalNavState();
    });
    document.getElementById('cal-toolbar-staff-cancel')?.addEventListener('click', () => {
      closeCalendarToolbarPopovers(true);
    });

    document.getElementById('cal-toolbar-view-save')?.addEventListener('click', () => {
      setToolbarViewsInlineError('');
      const dialog = showToolbarDialog('cal-toolbar-save-dialog', viewBtn);
      const input = document.getElementById('cal-toolbar-save-name');
      const errEl = document.getElementById('cal-toolbar-save-error');
      if (input) input.value = '';
      if (errEl) {
        errEl.textContent = '';
        errEl.classList.add('visually-hidden');
      }
      if (!dialog || !input) return;
      input.focus();
    });

    document.getElementById('cal-toolbar-save-cancel')?.addEventListener('click', () => {
      hideToolbarDialog(true);
    });

    document.getElementById('cal-toolbar-save-confirm')?.addEventListener('click', async () => {
      const input = document.getElementById('cal-toolbar-save-name');
      const errEl = document.getElementById('cal-toolbar-save-error');
      const name = input ? String(input.value || '').trim() : '';
      if (name === '') {
        if (errEl) {
          errEl.textContent = 'View name is required.';
          errEl.classList.remove('visually-hidden');
        }
        return;
      }
      try {
        const res = await fetch('/calendar/saved-views', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': getCsrfToken(),
          },
          body: JSON.stringify({
            name,
            config: buildCurrentViewConfig(),
            set_as_default: false,
          }),
        });
        const j = await res.json().catch(() => null);
        if (j && j.success && j.data && j.data.id) {
          activeSavedViewId = Number(j.data.id);
          hideToolbarDialog(true);
          closeCalendarToolbarPopovers();
          await refreshViewsListInToolbar();
          return;
        }
        const msg = j && j.error && typeof j.error.message === 'string'
          ? j.error.message
          : 'Unable to save view.';
        if (errEl) {
          errEl.textContent = msg;
          errEl.classList.remove('visually-hidden');
        }
      } catch (_e) {
        if (errEl) {
          errEl.textContent = 'Network error while saving view.';
          errEl.classList.remove('visually-hidden');
        }
      }
    });
    document.getElementById('cal-toolbar-view-default')?.addEventListener('click', async () => {
      if (!activeSavedViewId) {
        setToolbarViewsInlineError('Load a saved view first, or save a new one.');
        return;
      }
      setToolbarViewsInlineError('');
      try {
        const res = await fetch('/calendar/saved-views/' + activeSavedViewId + '/set-default', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': getCsrfToken(),
          },
        });
        const j = await res.json().catch(() => null);
        if (!(j && j.success)) {
          setToolbarViewsInlineError((j && j.error && j.error.message) || 'Unable to set default view.');
          return;
        }
        await refreshViewsListInToolbar();
      } catch (_e) { /* ignore */ }
      closeCalendarToolbarPopovers();
    });
    document.getElementById('cal-toolbar-view-delete')?.addEventListener('click', () => {
      if (!activeSavedViewId) {
        setToolbarViewsInlineError('No saved view selected.');
        return;
      }
      setToolbarViewsInlineError('');
      const errEl = document.getElementById('cal-toolbar-delete-error');
      if (errEl) {
        errEl.textContent = '';
        errEl.classList.add('visually-hidden');
      }
      showToolbarDialog('cal-toolbar-delete-dialog', viewBtn);
    });
    document.getElementById('cal-toolbar-delete-cancel')?.addEventListener('click', () => {
      hideToolbarDialog(true);
    });
    document.getElementById('cal-toolbar-delete-confirm')?.addEventListener('click', async () => {
      const errEl = document.getElementById('cal-toolbar-delete-error');
      try {
        const res = await fetch('/calendar/saved-views/' + activeSavedViewId + '/delete', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': getCsrfToken(),
          },
        });
        const j = await res.json().catch(() => null);
        if (!(j && j.success)) {
          const msg = j && j.error && typeof j.error.message === 'string' ? j.error.message : 'Unable to delete view.';
          if (errEl) {
            errEl.textContent = msg;
            errEl.classList.remove('visually-hidden');
          }
          return;
        }
        activeSavedViewId = null;
        hideToolbarDialog(true);
        closeCalendarToolbarPopovers();
        await refreshViewsListInToolbar();
        return;
      } catch (_e) {
        if (errEl) {
          errEl.textContent = 'Network error while deleting view.';
          errEl.classList.remove('visually-hidden');
        }
      }
    });

    document.getElementById('cal-toolbar-dialog-backdrop')?.addEventListener('click', () => {
      hideToolbarDialog(true);
    });

    document.addEventListener('keydown', (ev) => {
      if (ev.key === 'Escape' && openDialog) {
        hideToolbarDialog(true);
      }
    });

    document.querySelectorAll('.appts-cal-dialog').forEach((dlg) => {
      dlg.addEventListener('click', (ev) => ev.stopPropagation());
    });

    document.addEventListener('click', (ev) => {
      if (openDialog) {
        const t = ev.target;
        if (!(t instanceof Element) || !t.closest('.appts-cal-dialog')) {
          hideToolbarDialog(false);
        }
      }
    });

    document.querySelectorAll('[data-cal-print]').forEach((a) => {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        const kind = a.getAttribute('data-cal-print');
        const q = currentCalendarQuery();
        const path =
          kind === 'planning' ? '/appointments/calendar/day/print/planning'
            : kind === 'appointments' ? '/appointments/calendar/day/print/appointments'
              : kind === 'itineraries' ? '/appointments/calendar/day/print/itineraries'
                : '/appointments/calendar/day/print/calendar';
        window.open(path + '?' + q.toString(), '_blank', 'noopener,noreferrer');
        closeCalendarToolbarPopovers();
      });
    });

    root.addEventListener('click', (e) => e.stopPropagation());
    document.querySelectorAll('.appts-cal-toolbar__popover').forEach((p) => {
      p.addEventListener('click', (ev) => ev.stopPropagation());
    });
    document.addEventListener('click', (ev) => {
      if (openDialog) return;
      closeCalendarToolbarPopovers();
      const contextAnchor = document.getElementById('cal-toolbar-context-anchor');
      const toolsPanel = document.getElementById('cal-toolbar-tools-panel');
      const toolsToggle = document.getElementById('cal-toolbar-tools-toggle');
      if (
        contextAnchor &&
        toolsPanel &&
        toolsToggle &&
        !toolsPanel.hidden &&
        (!(ev.target instanceof Node) || !contextAnchor.contains(ev.target))
      ) {
        toolsPanel.hidden = true;
        toolsToggle.setAttribute('aria-expanded', 'false');
        syncCalendarContextAnchorActiveState();
      }
    });

    syncCalendarContextAnchorActiveState();

    function populateStaffFilterModal() {
      const box = document.getElementById('cal-toolbar-staff-fields');
      if (!box || !lastCalendarPayload || !Array.isArray(lastCalendarPayload.staff)) return;
      box.innerHTML = '';
      const hidden = getHiddenStaffIds();
      const staff = lastCalendarPayload.staff.slice(0);
      const schedStaff = [];
      const frStaff = [];
      staff.forEach((s) => {
        const st = String(s.staff_type || 'scheduled');
        if (st === 'freelancer') frStaff.push(s);
        else schedStaff.push(s);
      });
      const orderByIds = (list, orderIds) => {
        const want = Array.isArray(orderIds) ? orderIds.map(String) : [];
        const byId = new Map(list.map((s) => [String(s.id), s]));
        const used = new Set();
        const out = [];
        want.forEach((id) => {
          const item = byId.get(String(id));
          if (!item) return;
          used.add(String(id));
          out.push(item);
        });
        list.forEach((s) => {
          const id = String(s.id);
          if (used.has(id)) return;
          out.push(s);
        });
        return out;
      };
      const orderedSched = orderByIds(schedStaff, staffOrderScheduledIds);
      const orderedFr = orderByIds(frStaff, staffOrderFreelancerIds);

      let dragActiveType = null; // 'scheduled' | 'freelancer'
      let dragActiveId = '';
      const clearDropHints = () => {
        box.querySelectorAll('.appts-cal-staff-modal__row').forEach((r) => r.classList.remove('is-drop-target'));
      };
      const syncOrderArraysFromDom = () => {
        const read = (scope) => {
          const out = [];
          scope.querySelectorAll('.appts-cal-staff-modal__row[data-staff-id]').forEach((row) => {
            if (!(row instanceof HTMLElement)) return;
            const id = String(row.dataset.staffId || '');
            if (id) out.push(id);
          });
          return out;
        };
        const sScope = box.querySelector('[data-staff-section="scheduled"]');
        const fScope = box.querySelector('[data-staff-section="freelancer"]');
        if (sScope) staffOrderScheduledIds = read(sScope);
        if (fScope) staffOrderFreelancerIds = read(fScope);
      };

      const sched = document.createElement('div');
      sched.className = 'appts-cal-staff-modal__col';
      sched.dataset.staffSection = 'scheduled';
      const fr = document.createElement('div');
      fr.className = 'appts-cal-staff-modal__col';
      fr.dataset.staffSection = 'freelancer';
      const schedRows = document.createElement('div');
      schedRows.className = 'appts-cal-staff-modal__rows';
      const frRows = document.createElement('div');
      frRows.className = 'appts-cal-staff-modal__rows appts-cal-staff-modal__rows--single';
      const hSched = document.createElement('h3');
      hSched.className = 'appts-cal-staff-modal__subhead';
      hSched.textContent = 'Scheduled';
      const hFr = document.createElement('h3');
      hFr.className = 'appts-cal-staff-modal__subhead';
      hFr.textContent = 'Freelancers';
      sched.appendChild(hSched);
      fr.appendChild(hFr);
      const buildRow = (s, staffType) => {
        const id = String(s.id);
        const label = ((s.first_name || '') + ' ' + (s.last_name || '')).trim() || ('Staff #' + id);
        const row = document.createElement('label');
        row.className = 'appts-cal-staff-modal__row';
        row.dataset.staffId = id;
        row.dataset.staffType = staffType;
        row.draggable = true;

        const handle = document.createElement('span');
        handle.className = 'appts-cal-staff-modal__drag';
        handle.setAttribute('aria-hidden', 'true');
        handle.textContent = '⋮⋮';
        row.appendChild(handle);

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.dataset.staffId = id;
        cb.checked = !hidden.has(id);
        row.appendChild(cb);

        const name = document.createElement('span');
        name.className = 'appts-cal-staff-modal__name';
        name.textContent = label;
        row.appendChild(name);

        row.addEventListener('dragstart', (e) => {
          dragActiveType = staffType;
          dragActiveId = id;
          clearDropHints();
          row.classList.add('is-dragging');
          try {
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', id);
          } catch (_err) { /* ignore */ }
        });
        row.addEventListener('dragend', () => {
          row.classList.remove('is-dragging');
          dragActiveType = null;
          dragActiveId = '';
          clearDropHints();
          syncOrderArraysFromDom();
        });
        row.addEventListener('dragover', (e) => {
          if (!dragActiveType || dragActiveType !== staffType) return;
          if (!dragActiveId || dragActiveId === id) return;
          e.preventDefault();
          clearDropHints();
          row.classList.add('is-drop-target');
          try { e.dataTransfer.dropEffect = 'move'; } catch (_err) { /* ignore */ }
        });
        row.addEventListener('drop', (e) => {
          if (!dragActiveType || dragActiveType !== staffType) return;
          if (!dragActiveId || dragActiveId === id) return;
          e.preventDefault();
          const container = row.parentElement;
          if (!(container instanceof HTMLElement)) return;
          const before = new Map();
          container.querySelectorAll('.appts-cal-staff-modal__row').forEach((el) => {
            if (el instanceof HTMLElement) before.set(el, el.getBoundingClientRect());
          });
          const dragging = container.querySelector('.appts-cal-staff-modal__row.is-dragging');
          if (!(dragging instanceof HTMLElement)) return;
          container.insertBefore(dragging, row);
          container.querySelectorAll('.appts-cal-staff-modal__row').forEach((el) => {
            if (!(el instanceof HTMLElement)) return;
            const a = before.get(el);
            if (!a) return;
            const b = el.getBoundingClientRect();
            const dx = a.left - b.left;
            const dy = a.top - b.top;
            if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) return;
            el.animate(
              [
                { transform: `translate(${dx}px, ${dy}px)` },
                { transform: 'translate(0, 0)' },
              ],
              { duration: 320, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' }
            );
          });
          clearDropHints();
          syncOrderArraysFromDom();
        });
        row.addEventListener('keydown', (e) => {
          if (!(e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown'))) return;
          e.preventDefault();
          const container = row.parentElement;
          if (!(container instanceof HTMLElement)) return;
          const before = new Map();
          container.querySelectorAll('.appts-cal-staff-modal__row').forEach((el) => {
            if (el instanceof HTMLElement) before.set(el, el.getBoundingClientRect());
          });
          const rows = Array.from(container.querySelectorAll('.appts-cal-staff-modal__row'));
          const idx = rows.indexOf(row);
          if (idx < 0) return;
          if (e.key === 'ArrowUp' && idx > 0) {
            container.insertBefore(row, rows[idx - 1]);
          }
          if (e.key === 'ArrowDown' && idx < rows.length - 1) {
            container.insertBefore(rows[idx + 1], row);
          }
          container.querySelectorAll('.appts-cal-staff-modal__row').forEach((el) => {
            if (!(el instanceof HTMLElement)) return;
            const a = before.get(el);
            if (!a) return;
            const b = el.getBoundingClientRect();
            const dx = a.left - b.left;
            const dy = a.top - b.top;
            if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) return;
            el.animate(
              [
                { transform: `translate(${dx}px, ${dy}px)` },
                { transform: 'translate(0, 0)' },
              ],
              { duration: 260, easing: 'cubic-bezier(0.22, 1, 0.36, 1)' }
            );
          });
          syncOrderArraysFromDom();
        });

        return row;
      };

      orderedSched.forEach((s) => {
        schedRows.appendChild(buildRow(s, 'scheduled'));
      });
      orderedFr.forEach((s) => {
        frRows.appendChild(buildRow(s, 'freelancer'));
      });
      sched.appendChild(schedRows);
      fr.appendChild(frRows);
      box.appendChild(sched);
      box.appendChild(fr);
      syncOrderArraysFromDom();
    }
  }

  async function load() {
    pendingDeferredHiddenStaffIds = null;
    const date = dateEl.value;
    if (!date) return;
    const params = new URLSearchParams();
    params.set('date', date);
    if (branchEl.value) params.set('branch_id', branchEl.value);
    statusEl.textContent = 'Loading day calendar\u2026';
    destroyNowLine();
    if (currentLoadAbort) {
      currentLoadAbort.abort();
    }
    const abortCtrl = new AbortController();
    currentLoadAbort = abortCtrl;
    const branchIdNow = branchEl.value ? (parseInt(String(branchEl.value), 10) || 0) : 0;
    const viewportSessionKey = String(branchIdNow) + '|' + date;
    if (viewportSessionKey !== calendarViewportScrollSessionKey) {
      calendarViewportManualScrollLock = false;
      calendarViewportScrollSessionKey = viewportSessionKey;
    }
    if (lastUiPrefsBranchId !== branchIdNow) {
      calendarPrefsPersistedFromServer = false;
    }
    const dayDeadline = bindAbortDeadline(abortCtrl, CALENDAR_FETCH_TIMEOUT_MS);
    try {
      const res = await fetch('/calendar/day?' + params.toString(), {
        headers: {'Accept': 'application/json'},
        signal: abortCtrl.signal,
      });
      const rawText = await res.text();
      let payload = null;
      try {
        payload = rawText ? JSON.parse(rawText) : null;
      } catch (_parseErr) {
        const snippet = rawText && rawText.trim().charAt(0) === '<' ? ' (HTML error page)' : '';
        statusEl.textContent =
          'Calendar response is not valid JSON (HTTP ' + res.status + ')' + snippet
          + '. Often: run DB migration 133 (appointments.appointment_calendar_meta).';
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
      if (!payload || typeof payload !== 'object') {
        statusEl.textContent = 'Invalid calendar payload (HTTP ' + res.status + ').';
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
        statusEl.textContent = errMsg || ('Failed to load calendar (HTTP ' + res.status + ').');
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
      lastCalendarPayload = payload;
      try {
        const prefsRes = await fetch('/calendar/ui-preferences?' + params.toString(), {
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          signal: abortCtrl.signal,
        });
        if (prefsRes.ok) {
          const pj = await prefsRes.json().catch(() => null);
          if (pj && pj.success && pj.data && typeof pj.data === 'object') {
            lastUiPrefsBranchId = branchIdNow;
            const st = pj.data.calendar_ui_storage;
            if (st && st.preferences_table_ready === false) {
              calendarPersistenceWriteDisabled = true;
            } else {
              calendarPersistenceWriteDisabled = false;
            }
            calendarPrefsPersistedFromServer = Boolean(pj.data.preferences_persisted);
            applyCalendarUiBootstrap(pj.data);
          }
        }
      } catch (_prefErr) {
        /* optional: migration 134 not applied — keep calendarPrefsPersistedFromServer if same branch */
      }
      clearCalendarPrefsAlert();
      statusEl.textContent = '';
      scheduleSyncCalendarViewportHeight();
      // Pre-compute auto-fit zoom before first renderCalendar so the calendar renders
      // once at the correct zoom level (single-pass first paint — no post-render reflow).
      const preRenderFit = computeFitZoomForLoad(payload);
      if (preRenderFit !== null && preRenderFit !== timeZoomPercent) {
        timeZoomPercent = preRenderFit;
        syncCalendarToolbarControls();
      }
      renderCalendar(payload);
      scheduleSyncCalendarViewportHeight();
      {
        const gh = document.getElementById('appts-calendar-grid');
        if (gh) void gh.getBoundingClientRect();
      }
      // scheduleWorkdayViewportFit is retained for ResizeObserver-triggered reflows
      // (e.g. panel open/close after render). With pre-compute above, the diff is
      // typically < 2 on initial load so tryFitWorkdayToViewport returns early.
      scheduleWorkdayViewportFit();
      updateNowButtonState();
      loadSidePanelData();
    } catch (e) {
      if (e && e.name === 'AbortError') {
        if (currentLoadAbort !== abortCtrl) {
          return;
        }
        statusEl.textContent = 'Day calendar request timed out. Check your connection or try again.';
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
      statusEl.textContent = 'Could not load calendar (network or unexpected error).';
        wrap.innerHTML = '';
        clearWeekSummaryDecorations();
        latestWeekSummary = null;
        clearMonthGridDecorations();
        latestMonthSummary = null;
        weekSummaryErrorText = '';
        monthSummaryErrorText = '';
        refreshSummaryRailVisible();
    } finally {
      clearTimeout(dayDeadline);
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
    syncCalendarToolbarDateLabel();
    renderSmartCard();
    pushCalendarHistoryIfChanged();
    load();
  });
  branchEl.addEventListener('change', () => {
    selectedSlot = null;
    activeSavedViewId = null;
    pushCalendarHistoryIfChanged();
    renderSmartCard();
    renderClipboardPane(); // refresh clipboard to show items scoped to the new branch
    load();
  });

  // Toolbar "New Appointment" buttons navigate full-page, not drawer.
  // Empty slot click (lane click handler) is the drawer entry path.
  newAppointmentBtns.forEach((btn) => btn.addEventListener('click', () => {
    const params = currentCalendarQuery();
    window.location.href = '/appointments/create?' + params.toString();
  }));
  if (blockedTimeBtn) {
    blockedTimeBtn.addEventListener('click', async () => {
      await openDrawerUrl(buildBlockedTimeUrl());
    });
  }
  window.addEventListener('app:appointments-calendar-refresh', () => {
    refreshCalendarSummaries();
    load();
    loadSidePanelData();
    scheduleCalendarHorizontalNavStateSync();
  });
  document.addEventListener('fullscreenchange', scheduleCalendarHorizontalNavStateSync);

  document.addEventListener('click', (e) => {
    if (!(e.target instanceof Element)) return;
    if (!e.target.closest('.ops-staff-head') && !e.target.closest('.ops-calendar-head-overlay__cell')) closeAllStaffMenus();
    if (!e.target.closest('#cal-appt-ctx-menu')) closeApptContextMenu();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const innerToolbarPopoverOpen = document.querySelector('.appts-cal-toolbar__popover[aria-hidden="false"]');
      closeAllStaffMenus();
      closeApptContextMenu();
      closeCalendarToolbarPopovers();
      if (!innerToolbarPopoverOpen) {
        closeToolsDropdownIfOpen();
      }
    }
  });

  if (wrap) {
    wrap.addEventListener('contextmenu', (e) => {
      const block = e.target.closest('[data-block-type="appointment"]');
      if (!block || !block.dataset.apptId) return;
      e.preventDefault();
      closeAllStaffMenus();
      showApptContextMenu(
        e.clientX,
        e.clientY,
        block.dataset.apptId,
        block.dataset.apptStatus || '',
        block.dataset.staffLocked === '1',
        {
          clientId: parseInt(block.dataset.clientId || '0', 10) || 0,
          linkedInvoiceId: parseInt(block.dataset.linkedInvoiceId || '0', 10) || 0,
        }
      );
    });
  }

  applyCalendarUiPageBootstrapIfPresent();
  syncCalendarToolbarControls();
  updateCalendarHorizontalNavState();

  initCalendarToolbar();
  updateNowButtonState();

  (function initCalendarGridViewportFit() {
    const gridEl = document.getElementById('appts-calendar-grid');
    if (!gridEl || typeof ResizeObserver === 'undefined') return;
    let t = null;
    const ro = new ResizeObserver(() => {
      if (performance.now() < implicitWorkdayFitSuppressedUntil) return;
      if (t) clearTimeout(t);
      t = setTimeout(() => {
        if (performance.now() < implicitWorkdayFitSuppressedUntil) return;
        scheduleWorkdayViewportFit();
        /* Responsive staff columns: viewport changed → recalculate column width.
           Pass visible staff count so we avoid empty space when all staff fit. */
        if (staffColumnsPerView != null && lastCalendarPayload) {
          const visibleN = Array.isArray(lastCalendarPayload.staff) ? lastCalendarPayload.staff.length : 0;
          const newCw = computeColumnWidthFromStaffCount(staffColumnsPerView, visibleN > 0 ? visibleN : undefined);
          if (newCw !== columnWidthPx) {
            columnWidthPx = newCw;
            renderCalendar(lastCalendarPayload);
            syncCalendarToolbarControls();
          }
        }
        scheduleCalendarHorizontalNavStateSync();
      }, 100);
    });
    ro.observe(gridEl);
  })();

  (function initCalendarGridHorizontalScrollPort() {
    const gridEl = document.getElementById('appts-calendar-grid');
    if (!gridEl || gridEl.dataset.calGridHScrollBound === '1') return;
    gridEl.dataset.calGridHScrollBound = '1';
    gridEl.addEventListener('scroll', dismissAllActiveSlotPreviews, { passive: true });
    gridEl.addEventListener('scroll', onCalendarMaybeUserScroll, { passive: true });
  })();

  (function initCalendarViewportHeightSync() {
    scheduleSyncCalendarViewportHeight();
    window.addEventListener('resize', scheduleSyncCalendarViewportHeight, { passive: true });
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', scheduleSyncCalendarViewportHeight, { passive: true });
    }
    const mainCol = document.querySelector('.appts-calendar-main');
    if (mainCol && typeof ResizeObserver !== 'undefined') {
      const ro = new ResizeObserver(() => {
        scheduleSyncCalendarViewportHeight();
        scheduleCalendarHorizontalNavStateSync();
      });
      ro.observe(mainCol);
    }
  })();

  (function initCalendarHorizontalNavSync() {
    updateCalendarHorizontalNavState();
    window.addEventListener('resize', scheduleCalendarHorizontalNavStateSync, { passive: true });
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', scheduleCalendarHorizontalNavStateSync, { passive: true });
    }
  })();

  initToolsPanel();
  renderClipboardPane();
  initClipboardClearBtn();

  if (calModeWeek) {
    calModeWeek.addEventListener('click', () => setCalendarMode('week'));
  }
  if (calModeMonth) {
    calModeMonth.addEventListener('click', () => setCalendarMode('month'));
  }
  if (calModeTwoMonths) {
    calModeTwoMonths.addEventListener('click', () => setCalendarMode('two-months'));
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

  if (calendarToolbarPrevDay) {
    calendarToolbarPrevDay.addEventListener('click', () => shiftCalendarDayBy(-1));
  }
  if (calendarToolbarNextDay) {
    calendarToolbarNextDay.addEventListener('click', () => shiftCalendarDayBy(1));
  }
  if (calendarHorizontalNavPrev) {
    calendarHorizontalNavPrev.addEventListener('click', () => scrollCalendarHorizontallyByStaff(-1));
  }
  if (calendarHorizontalNavNext) {
    calendarHorizontalNavNext.addEventListener('click', () => scrollCalendarHorizontallyByStaff(1));
  }
  if (calendarToolbarDateFocus && calCard) {
    calendarToolbarDateFocus.addEventListener('click', () => {
      try {
        calCard.focus({ preventScroll: false });
      } catch (_e) {
        calCard.focus();
      }
      try {
        calCard.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
      } catch (_e2) {
        calCard.scrollIntoView();
      }
    });
  }

  if (calCard) {
    calCard.addEventListener('keydown', (e) => {
      if (!dateEl || !/^\d{4}-\d{2}-\d{2}$/.test(String(dateEl.value || ''))) return;
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        selectedSlot = null;
        dateEl.value = shiftIsoDate(dateEl.value, -1);
        syncCalendarToolbarDateLabel();
        renderSmartCard();
        pushCalendarHistoryIfChanged();
        load();
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        selectedSlot = null;
        dateEl.value = shiftIsoDate(dateEl.value, 1);
        syncCalendarToolbarDateLabel();
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
      syncCalendarToolbarDateLabel();
    }
    if (b != null && b !== '') {
      const bs = String(b);
      if (branchEl.options) {
        if ([...branchEl.options].some((opt) => opt.value === bs)) {
          branchEl.value = bs;
        }
      } else {
        branchEl.value = bs;
      }
    }
    selectedSlot = null;
    renderSmartCard();
    load();
  });

  window.addEventListener('pagehide', () => {
    if (calendarToolbarSaveTimer) {
      clearTimeout(calendarToolbarSaveTimer);
      calendarToolbarSaveTimer = null;
      sendCalendarPrefsKeepalive();
    }
  });
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'hidden') return;
    if (!calendarToolbarSaveTimer) return;
    clearTimeout(calendarToolbarSaveTimer);
    calendarToolbarSaveTimer = null;
    void persistCalendarPrefs();
  });

  replaceCalendarHistoryCanonical();

  syncCalendarToolbarDateLabel();
  renderSmartCard();
  load();
})();