(() => {
  const host = document.getElementById('app-drawer-host');
  if (!host) {
    return;
  }

  const state = {
    isOpen: false,
    isDirty: false,
    activeUrl: '',
    activeWidth: 'medium',
  };

  /** Top-right {@see OlliraToast} for drawer JSON flows (no full page reload → no PHP flash). */
  function pushAppToast(kind, text) {
    if (!text || typeof text !== 'string') {
      return;
    }
    const T = window.OlliraToast;
    if (!T) {
      return;
    }
    if (kind === 'error' && typeof T.error === 'function') {
      T.error(text);
      return;
    }
    if (kind === 'success' && typeof T.success === 'function') {
      T.success(text);
      return;
    }
    if (typeof T.show === 'function') {
      T.show({ type: kind === 'error' ? 'error' : 'success', message: text });
    }
  }

  const DRAWER_SUBMIT_SPINNER =
    '<span class="app-drawer-submit__spinner" aria-hidden="true">' +
    '<svg class="app-drawer-submit__spinner-svg" width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
    '<circle cx="12" cy="12" r="9.25" stroke="currentColor" stroke-opacity="0.22" stroke-width="2"/>' +
    '<path d="M12 2.75 A9.25 9.25 0 0 1 21.25 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>' +
    '</svg></span>' +
    '<span class="visually-hidden">Saving…</span>';

  function resolveDrawerSubmitButton(form, submitter) {
    if (
      submitter &&
      submitter.tagName === 'BUTTON' &&
      submitter.getAttribute('type') === 'submit' &&
      form.contains(submitter)
    ) {
      return submitter;
    }
    return form.querySelector('button[type="submit"]');
  }

  /**
   * Primary action loading on the clicked submit control (no drawer status strip).
   */
  function setDrawerFormSubmitting(form, active, submitter = null) {
    const allSubmit = [...form.querySelectorAll('button[type="submit"]')];
    if (!active) {
      allSubmit.forEach((b) => {
        b.disabled = false;
        b.removeAttribute('aria-disabled');
        if (b.dataset.drawerSubmitHtml) {
          b.innerHTML = b.dataset.drawerSubmitHtml;
          delete b.dataset.drawerSubmitHtml;
        }
        b.style.minWidth = '';
        b.classList.remove('is-drawer-submit-loading');
        b.removeAttribute('aria-busy');
      });
      return;
    }
    const primary = resolveDrawerSubmitButton(form, submitter);
    allSubmit.forEach((b) => {
      b.disabled = true;
      b.setAttribute('aria-disabled', 'true');
    });
    if (!primary) {
      return;
    }
    if (!primary.dataset.drawerSubmitHtml) {
      primary.dataset.drawerSubmitHtml = primary.innerHTML;
    }
    const w = primary.getBoundingClientRect().width;
    if (w >= 48) {
      primary.style.minWidth = `${Math.ceil(w)}px`;
    }
    primary.classList.add('is-drawer-submit-loading');
    primary.setAttribute('aria-busy', 'true');
    primary.innerHTML = DRAWER_SUBMIT_SPINNER;
  }

  function buildShell() {
    host.innerHTML = `
      <div class="app-drawer" hidden>
        <button class="app-drawer__overlay" type="button" aria-label="Close drawer"></button>
        <section class="app-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="app-drawer-title">
          <header class="app-drawer__header">
            <div class="app-drawer__heading">
              <p class="app-drawer__eyebrow" id="app-drawer-subtitle" hidden></p>
              <h2 class="app-drawer__title" id="app-drawer-title">Workspace</h2>
            </div>
            <div class="app-drawer__header-actions" id="app-drawer-header-actions"></div>
            <button class="app-drawer__close" type="button" aria-label="Close drawer">×</button>
          </header>
          <div class="app-drawer__status" id="app-drawer-status" hidden></div>
          <div class="app-drawer__body" id="app-drawer-body"></div>
          <footer class="app-drawer__footer" id="app-drawer-footer" hidden></footer>
        </section>
      </div>
    `;
  }

  buildShell();

  const drawerEl = host.querySelector('.app-drawer');
  const panelEl = host.querySelector('.app-drawer__panel');
  const overlayEl = host.querySelector('.app-drawer__overlay');
  const closeEl = host.querySelector('.app-drawer__close');
  const titleEl = host.querySelector('#app-drawer-title');
  const subtitleEl = host.querySelector('#app-drawer-subtitle');
  const statusEl = host.querySelector('#app-drawer-status');
  const bodyEl = host.querySelector('#app-drawer-body');
  const footerEl = host.querySelector('#app-drawer-footer');
  const headerActionsEl = host.querySelector('#app-drawer-header-actions');

  /** Matches `Staff edit_profile` $displayOrder — used when `data-schedule-display-order` is missing. */
  const DEFAULT_SCHEDULE_DISPLAY_ORDER = [1, 2, 3, 4, 5, 6, 0];

  function parseScheduleDisplayOrder(form) {
    const raw = form && form.getAttribute('data-schedule-display-order');
    if (!raw) {
      return DEFAULT_SCHEDULE_DISPLAY_ORDER.slice();
    }
    try {
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) && parsed.length ? parsed : DEFAULT_SCHEDULE_DISPLAY_ORDER.slice();
    } catch (_) {
      return DEFAULT_SCHEDULE_DISPLAY_ORDER.slice();
    }
  }

  /**
   * Staff schedule grid in `#profile-schedule-form`: inline scripts do not run when HTML is injected via
   * `innerHTML`, so toggle/copy-prev must use delegation on the drawer body.
   */
  bodyEl.addEventListener('change', function (event) {
    const t = event.target;
    if (!t || !t.classList || !t.classList.contains('day-toggle')) {
      return;
    }
    if (!(t instanceof HTMLInputElement) || t.type !== 'checkbox') {
      return;
    }
    const form = t.closest('#profile-schedule-form');
    if (!form || !bodyEl.contains(t)) {
      return;
    }
    const row = t.closest('.staff-schedule-row');
    if (!row) {
      return;
    }
    const inputs = row.querySelectorAll('.day-time-input');
    const copyBtn = row.querySelector('.btn-copy-prev');
    const on = t.checked;
    row.classList.toggle('staff-schedule-row--on', on);
    row.classList.toggle('staff-schedule-row--off', !on);
    inputs.forEach(function (i) {
      i.disabled = !on;
    });
    if (copyBtn) {
      copyBtn.disabled = !on;
    }
    if (on) {
      const arr = Array.from(inputs);
      if (arr[0] && !arr[0].value) {
        arr[0].value = '09:00';
      }
      if (arr[1] && !arr[1].value) {
        arr[1].value = '17:00';
      }
    }
  });

  bodyEl.addEventListener('click', function (event) {
    const btn = event.target.closest('.btn-copy-prev');
    if (!btn || btn.disabled || !bodyEl.contains(btn)) {
      return;
    }
    const form = btn.closest('#profile-schedule-form');
    if (!form) {
      return;
    }
    const order = parseScheduleDisplayOrder(form);
    const dow = parseInt(btn.getAttribute('data-dow') || '', 10);
    if (Number.isNaN(dow)) {
      return;
    }
    const idx = order.indexOf(dow);
    if (idx <= 0) {
      return;
    }
    const prevDow = order[idx - 1];
    const prevRow = form.querySelector('.staff-schedule-row[data-dow="' + prevDow + '"]');
    if (!prevRow) {
      return;
    }
    const prevToggle = prevRow.querySelector('.day-toggle');
    if (!prevToggle || !prevToggle.checked) {
      return;
    }
    const src = Array.from(prevRow.querySelectorAll('.day-time-input'));
    const dstRow = btn.closest('.staff-schedule-row');
    if (!dstRow) {
      return;
    }
    const dst = Array.from(dstRow.querySelectorAll('.day-time-input'));
    src.forEach(function (s, i) {
      if (dst[i] && !dst[i].disabled) {
        dst[i].value = s.value;
      }
    });
  });

  const DRAWER_FETCH_TIMEOUT_MS = 25000;
  /** Must exceed .app-drawer --drawer-t (360ms) + compositor slack; used if transitionend is missed. */
  const DRAWER_CLOSE_FALLBACK_MS = 440;

  let drawerCloseFallbackTimer = null;
  let panelCloseTransitionHandler = null;
  /** Bumps on each open/close start so stale timeouts / handlers never tear down a new session. */
  let drawerMotionToken = 0;

  function clearDrawerCloseFallback() {
    if (drawerCloseFallbackTimer != null) {
      clearTimeout(drawerCloseFallbackTimer);
      drawerCloseFallbackTimer = null;
    }
  }

  function detachPanelCloseTransitionHandler() {
    if (panelCloseTransitionHandler) {
      panelEl.removeEventListener('transitionend', panelCloseTransitionHandler);
      panelCloseTransitionHandler = null;
    }
  }

  function finalizeDrawerClose(expectedToken) {
    if (expectedToken !== drawerMotionToken) {
      return;
    }
    if (drawerEl.hidden) {
      return;
    }
    detachPanelCloseTransitionHandler();
    clearDrawerCloseFallback();
    drawerEl.hidden = true;
    drawerEl.setAttribute('aria-hidden', 'true');
    drawerEl.classList.remove('is-open');
    lockBody(false);
    bodyEl.innerHTML = '';
    setFooter('');
    setHeaderActions('');
    setStatus('');
    setDirty(false);
    state.isOpen = false;
    state.activeUrl = '';
    window.dispatchEvent(new CustomEvent('app:drawer-closed'));
  }
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

  function lockBody(lock) {
    document.documentElement.classList.toggle('app-drawer-lock', lock);
    document.body.classList.toggle('app-drawer-lock', lock);
  }

  function setDirty(dirty) {
    state.isDirty = !!dirty;
    panelEl.classList.toggle('is-dirty', state.isDirty);
  }

  function currentContentRoot() {
    return bodyEl.querySelector('[data-drawer-content-root]');
  }

  function escHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function readMetadata(root) {
    const title = root && root.dataset.drawerTitle ? root.dataset.drawerTitle : 'Workspace';
    const subtitle = root && root.dataset.drawerSubtitle ? root.dataset.drawerSubtitle : '';
    const width = root && root.dataset.drawerWidth ? root.dataset.drawerWidth : 'medium';
    return { title, subtitle, width };
  }

  function applyMetadata(root) {
    const meta = readMetadata(root);
    state.activeWidth = meta.width;
    panelEl.setAttribute('data-width', meta.width);
    titleEl.textContent = meta.title;
    subtitleEl.textContent = meta.subtitle;
    subtitleEl.hidden = meta.subtitle === '';
  }

  function setStatus(message, kind) {
    if (!message) {
      statusEl.hidden = true;
      statusEl.textContent = '';
      statusEl.className = 'app-drawer__status';
      return;
    }
    statusEl.hidden = false;
    statusEl.textContent = message;
    statusEl.className = 'app-drawer__status' + (kind ? ' app-drawer__status--' + kind : '');
  }

  function setFooter(html) {
    if (!html) {
      footerEl.hidden = true;
      footerEl.innerHTML = '';
      return;
    }
    footerEl.hidden = false;
    footerEl.innerHTML = html;
  }

  function setHeaderActions(html) {
    headerActionsEl.innerHTML = html || '';
  }

  function canClose() {
    if (!state.isDirty) {
      return true;
    }
    return window.confirm('Discard unsaved changes?');
  }

  /** If the drawer was hidden without going through closeDrawer, reset state so new opens are not blocked. */
  function syncOpenStateFromDom() {
    if (drawerEl.hidden && state.isOpen) {
      detachPanelCloseTransitionHandler();
      clearDrawerCloseFallback();
      lockBody(false);
      state.isOpen = false;
      state.activeUrl = '';
      setDirty(false);
      drawerEl.classList.remove('is-open');
      drawerEl.setAttribute('aria-hidden', 'true');
    }
  }

  function closeDrawer(force = false) {
    if (!force && !canClose()) {
      return false;
    }
    if (drawerEl.hidden) {
      return true;
    }

    detachPanelCloseTransitionHandler();
    clearDrawerCloseFallback();
    drawerMotionToken += 1;
    const closeToken = drawerMotionToken;
    state.isOpen = false;
    drawerEl.classList.remove('is-open');

    panelCloseTransitionHandler = (e) => {
      if (e.target !== panelEl || e.propertyName !== 'transform') {
        return;
      }
      detachPanelCloseTransitionHandler();
      clearDrawerCloseFallback();
      finalizeDrawerClose(closeToken);
    };
    panelEl.addEventListener('transitionend', panelCloseTransitionHandler);

    drawerCloseFallbackTimer = window.setTimeout(() => {
      drawerCloseFallbackTimer = null;
      detachPanelCloseTransitionHandler();
      finalizeDrawerClose(closeToken);
    }, DRAWER_CLOSE_FALLBACK_MS);

    return true;
  }

  function openShell(options = {}) {
    const skipEnterAnimation = !!(options && options.skipEnterAnimation);
    detachPanelCloseTransitionHandler();
    clearDrawerCloseFallback();
    drawerMotionToken += 1;

    state.isOpen = true;
    drawerEl.hidden = false;
    drawerEl.removeAttribute('aria-hidden');
    lockBody(true);

    const alreadyOpen = drawerEl.classList.contains('is-open');
    if (skipEnterAnimation || alreadyOpen) {
      drawerEl.classList.add('is-open');
      return;
    }

    drawerEl.classList.remove('is-open');
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        drawerEl.classList.add('is-open');
      });
    });
  }

  async function fetchHtml(url) {
    const ac = new AbortController();
    const tid = bindAbortDeadline(ac, DRAWER_FETCH_TIMEOUT_MS);
    try {
      const separator = url.includes('?') ? '&' : '?';
      const res = await fetch(url + separator + 'drawer=1', {
        headers: {
          'X-App-Drawer': '1',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        signal: ac.signal,
      });
      const html = await res.text();
      if (!res.ok) {
        throw new Error('Failed to load drawer content.');
      }
      return html;
    } catch (e) {
      if (e && e.name === 'AbortError') {
        throw new Error('Request timed out.');
      }
      throw e;
    } finally {
      clearTimeout(tid);
    }
  }

  function refreshCalendarIfPresent() {
    window.dispatchEvent(new CustomEvent('app:appointments-calendar-refresh'));
  }

  /** Host pages where staff profile drawer should dismiss after save instead of reloading edit inside the drawer. */
  function isAppointmentsShellHost() {
    const p = window.location.pathname || '';
    if (p === '/appointments' || p.startsWith('/appointments/')) {
      return true;
    }
    if (p.startsWith('/calendar')) {
      return true;
    }
    if (/^\/clients\/\d+\/appointments/.test(p)) {
      return true;
    }
    return false;
  }

  function isStaffEditReloadUrl(url) {
    if (!url || typeof url !== 'string') {
      return false;
    }
    try {
      const path = new URL(url, window.location.origin).pathname;
      return /^\/staff\/\d+\/edit$/.test(path);
    } catch (_) {
      return /^\/staff\/\d+\/edit(\?|$)/.test(url);
    }
  }

  function setContent(html, requestedUrl) {
    /* Clear loading strip in the same turn as real HTML — avoids one frame of “content + loading bar” (layout jump). */
    setStatus('', '');
    bodyEl.innerHTML = html;
    state.activeUrl = requestedUrl || state.activeUrl;
    const root = currentContentRoot();
    applyMetadata(root);
    initDrawerTabs(bodyEl);
    initDrawerForms(bodyEl);
    initDrawerCloseTriggers(bodyEl);
    initDirtyTracking(bodyEl);
    initAppointmentCreate(bodyEl);
    initAppointmentEdit(bodyEl);
    initDrawerLinks(bodyEl);
    if (typeof window.initClientCreateDelivery === 'function') {
      window.initClientCreateDelivery(bodyEl);
    }
    if (typeof window.initClientCreatePhoneDedupe === 'function') {
      window.initClientCreatePhoneDedupe(bodyEl);
    }
  }

  /**
   * @returns {Promise<boolean>} true if the open was started (drawer shell shown, fetch kicked off); false if blocked (e.g. user cancelled dirty close)
   */
  async function openUrl(url, options = {}) {
    if (!url) {
      return false;
    }
    syncOpenStateFromDom();
    if (state.isOpen && !options.force && !canClose()) {
      return false;
    }
    state.activeUrl = url;
    const skipEnter =
      !drawerEl.hidden && drawerEl.classList.contains('is-open');
    openShell({ skipEnterAnimation: skipEnter });
    setStatus('Loading workspace…', 'loading');
    /* Spinner only in body — status row already carries the message (no duplicate “Loading…” pop-in). */
    bodyEl.innerHTML =
      '<div class="app-drawer__load-placeholder" role="status" aria-live="polite">' +
      '<span class="app-drawer__load-spinner" aria-hidden="true"></span>' +
      '<span class="visually-hidden">Loading workspace</span>' +
      '</div>';
    (async () => {
      try {
        const html = await fetchHtml(url);
        setContent(html, url);
        if (options.refreshCalendar) {
          refreshCalendarIfPresent();
        }
      } catch (error) {
        bodyEl.innerHTML = '<div class="app-drawer__empty app-drawer__empty--error">Could not load this workspace.</div>';
        setStatus('', '');
        pushAppToast('error', error && error.message ? error.message : 'Could not load drawer.');
      }
    })();
    return true;
  }

  function handleResponsePayload(payload, form) {
    if (!payload || typeof payload !== 'object') {
      throw new Error('Unexpected response.');
    }
    if (!payload.success) {
      const message = payload.error && payload.error.message ? payload.error.message : 'Request failed.';
      if (payload.data && payload.data.html) {
        setContent(payload.data.html, state.activeUrl);
      } else {
        setStatus('', '');
      }
      throw new Error(message);
    }

    const data = payload.data && typeof payload.data === 'object' ? payload.data : {};
    if (data.html) {
      setContent(data.html, data.url || state.activeUrl);
    }
    if (data.message) {
      pushAppToast('success', data.message);
      setStatus('', '');
    }
    if (data.refresh_calendar) {
      refreshCalendarIfPresent();
    }
    if (data.open_url) {
      openUrl(data.open_url, { force: true });
      return;
    }
    /** Full navigation after success (e.g. client create → profile) — closes drawer first. */
    if (data.window_assign && typeof data.window_assign === 'string') {
      closeDrawer(true);
      window.location.assign(data.window_assign);
      return;
    }
    if (data.close_drawer) {
      closeDrawer(true);
      return;
    }
    if (data.reload_host) {
      closeDrawer(true);
      window.location.reload();
      return;
    }
    if (data.reload_url && form) {
      if (isAppointmentsShellHost() && isStaffEditReloadUrl(data.reload_url)) {
        refreshCalendarIfPresent();
        closeDrawer(true);
        return;
      }
      openUrl(data.reload_url, { force: true });
    }
  }

  async function submitDrawerForm(form) {
    const formData = new FormData(form);
    const ac = new AbortController();
    const tid = bindAbortDeadline(ac, DRAWER_FETCH_TIMEOUT_MS);
    let res;
    try {
      res = await fetch(form.action, {
        method: (form.method || 'POST').toUpperCase(),
        body: formData,
        headers: {
          'Accept': 'application/json',
          'X-App-Drawer': '1',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        signal: ac.signal,
      });
    } catch (e) {
      if (e && e.name === 'AbortError') {
        throw new Error('Request timed out.');
      }
      throw e;
    } finally {
      clearTimeout(tid);
    }
    const payload = await res.json();
    if (!res.ok && (!payload || payload.success !== false)) {
      throw new Error('Request failed.');
    }
    handleResponsePayload(payload, form);
  }

  function initDrawerCloseTriggers(root) {
    root.querySelectorAll('[data-app-drawer-close]').forEach((el) => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        closeDrawer();
      });
    });
  }

  function initDrawerForms(root) {
    root.querySelectorAll('form[data-drawer-submit]').forEach((form) => {
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const confirmMessage = form.dataset.drawerConfirm || '';
        if (confirmMessage && !window.confirm(confirmMessage)) {
          return;
        }
        const clickedSubmit = event.submitter || null;
        setDrawerFormSubmitting(form, true, clickedSubmit);
        try {
          await submitDrawerForm(form);
        } catch (error) {
          const msg = error && error.message ? error.message : 'Could not complete request.';
          pushAppToast('error', msg);
        } finally {
          setDrawerFormSubmitting(form, false);
        }
      });
    });
  }

  function initDirtyTracking(root) {
    setDirty(false);
    root.querySelectorAll('form[data-drawer-dirty-track]').forEach((form) => {
      const markDirty = () => setDirty(true);
      form.querySelectorAll('input, select, textarea').forEach((field) => {
        field.addEventListener('change', markDirty);
        field.addEventListener('input', markDirty);
      });
      form.addEventListener('submit', () => setDirty(false));
    });
  }

  function initDrawerTabs(root) {
    root.querySelectorAll('[data-drawer-tabs]').forEach((tabRoot) => {
      const buttons = [...tabRoot.querySelectorAll('[data-drawer-tab]')];
      const panels = [...tabRoot.querySelectorAll('[data-drawer-tab-panel]')];
      if (!buttons.length || !panels.length) {
        return;
      }
      const activate = (tabId) => {
        buttons.forEach((button) => {
          const active = button.dataset.drawerTab === tabId;
          button.classList.toggle('is-active', active);
          button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach((panel) => {
          panel.hidden = panel.dataset.drawerTabPanel !== tabId;
        });
      };
      const initial = buttons.find((button) => button.dataset.drawerTabDefault === '1') || buttons[0];
      buttons.forEach((button) => {
        button.addEventListener('click', () => activate(button.dataset.drawerTab || ''));
      });
      activate(initial.dataset.drawerTab || '');
    });
  }

  function initDrawerLinks(root) {
    root.querySelectorAll('[data-drawer-url]').forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        openUrl(link.getAttribute('href') || link.dataset.drawerUrl || '');
      });
    });
  }

  function initAppointmentCreate(root) {
    const form = root.querySelector('#drawer-booking-form');
    if (!form) {
      return;
    }

    const branchEl = form.querySelector('#branch_id');
    const clientEl = form.querySelector('#client_id');
    const clientSearchEl = form.querySelector('#client-search');
    const clientSearchHintEl = form.querySelector('#client-search-hint');
    const clientSearchResultsEl = form.querySelector('#client-search-results');
    const serviceEl = form.querySelector('#service_id');
    const categoryEl = form.querySelector('#service_category_id');
    const dateEl = form.querySelector('#date');
    const staffEl = form.querySelector('#staff_id');
    const roomEl = form.querySelector('#room_id');
    const startEl = form.querySelector('#selected_start_time');
    const statusEl = form.querySelector('#appointment_status');
    const statusConfirmEl = form.querySelector('#appointment_status_confirmed');
    const slotsWrap = form.querySelector('#slots-container');
    const statusHintEl = form.querySelector('#slots-status');
    const loadBtn = form.querySelector('#load-slots-btn');
    const categoryHintEl = form.querySelector('#category-hint');
    const selectedSlotLabelEl = form.querySelector('[data-selected-slot-label]');
    const estimatedEndLabelEl = form.querySelector('[data-estimated-end-label]');
    const serviceHintEl = form.querySelector('#service-description-hint');
    const preferredTime = form.dataset.prefillTime || '';
    const defaultSlotMinutes = Math.max(5, Number(form.dataset.slotMinutes || '30') || 30);
    const prefillEndTime = form.dataset.prefillEndTime || '';
    const createBaseUrl = form.dataset.createBaseUrl || '/appointments/create';
    const staffServicesUrl = form.dataset.staffServicesUrl || '/appointments/staff-services';
    const staffScopedMode = form.dataset.staffScoped === '1';
    const clientDetailEls = {
      name: form.querySelector('[data-client-detail="name"]'),
      email: form.querySelector('[data-client-detail="email"]'),
      country: form.querySelector('[data-client-detail="country"]'),
      phone: form.querySelector('[data-client-detail="phone"]'),
      source: form.querySelector('[data-client-detail="source"]'),
    };

    if (!branchEl || !clientEl || !serviceEl || !dateEl || !staffEl || !startEl || !slotsWrap || !statusHintEl || !loadBtn) {
      return;
    }

    // ── Staff-scoped category-first data ────────────────────────────────────
    // staffCatalog[categoryKey] = { id, name, services: [{id, name, duration_minutes, description}] }
    let staffCatalog = null;

    const populateCategorySelect = (categories) => {
      if (!categoryEl) return;
      categoryEl.innerHTML = '';
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = '— Choose category —';
      categoryEl.appendChild(placeholder);
      categories.forEach((cat) => {
        const opt = document.createElement('option');
        opt.value = cat.id !== null ? String(cat.id) : '__uncat__';
        opt.textContent = cat.name;
        categoryEl.appendChild(opt);
      });
      categoryEl.disabled = false;
      if (categoryHintEl) categoryHintEl.hidden = true;
      // Auto-select if only one category
      if (categories.length === 1) {
        categoryEl.value = categoryEl.options[1].value;
        categoryEl.dispatchEvent(new Event('change'));
      }
    };

    const populateServiceSelect = (services) => {
      serviceEl.innerHTML = '';
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = services.length === 0 ? '— No services in category —' : '— Choose service —';
      serviceEl.appendChild(placeholder);
      services.forEach((svc) => {
        const opt = document.createElement('option');
        opt.value = String(svc.id);
        opt.dataset.serviceDuration = String(svc.duration_minutes || 0);
        if (svc.description) opt.title = svc.description;
        opt.textContent = svc.name + ' (' + (svc.duration_minutes || 0) + ' min)';
        serviceEl.appendChild(opt);
      });
      serviceEl.disabled = services.length === 0;
      // Auto-select if only one service
      if (services.length === 1) {
        serviceEl.value = serviceEl.options[1].value;
        serviceEl.dispatchEvent(new Event('change'));
      }
    };

    const onCategoryChange = () => {
      if (!staffCatalog || !categoryEl) return;
      const selectedKey = categoryEl.value;
      serviceEl.value = '';
      computeEstimatedEnd();
      if (!selectedKey) {
        serviceEl.innerHTML = '<option value="">— Choose category first —</option>';
        serviceEl.disabled = true;
        return;
      }
      const cat = staffCatalog.find((c) => (c.id !== null ? String(c.id) : '__uncat__') === selectedKey);
      if (cat) {
        populateServiceSelect(cat.services || []);
      } else {
        serviceEl.innerHTML = '<option value="">— No services available —</option>';
        serviceEl.disabled = true;
      }
    };

    async function loadStaffCatalog() {
      if (!staffScopedMode || !staffEl.value || !branchEl.value) return;
      if (categoryEl && categoryHintEl) {
        categoryEl.disabled = true;
        categoryHintEl.textContent = 'Loading…';
        categoryHintEl.hidden = false;
      }
      const params = new URLSearchParams();
      params.set('staff_id', staffEl.value);
      params.set('branch_id', branchEl.value);
      const ac = new AbortController();
      const tid = bindAbortDeadline(ac, DRAWER_FETCH_TIMEOUT_MS);
      try {
        const res = await fetch(staffServicesUrl + '?' + params.toString(), {
          headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
          signal: ac.signal,
        });
        const payload = await res.json();
        if (!res.ok || !payload.success) {
          if (categoryEl && categoryHintEl) {
            categoryHintEl.textContent = (payload.error && payload.error.message) ? payload.error.message : 'Could not load categories.';
            categoryHintEl.hidden = false;
          }
          return;
        }
        staffCatalog = Array.isArray(payload.data && payload.data.categories) ? payload.data.categories : [];
        populateCategorySelect(staffCatalog);
      } catch (err) {
        if (categoryEl && categoryHintEl) {
          categoryHintEl.textContent = (err && err.name === 'AbortError') ? 'Request timed out.' : 'Could not load categories.';
          categoryHintEl.hidden = false;
        }
      } finally {
        clearTimeout(tid);
      }
    }

    if (staffScopedMode && categoryEl) {
      categoryEl.addEventListener('change', onCategoryChange);
      loadStaffCatalog();
    }

    const updateServiceDescriptionHint = () => {
      const opt = serviceEl.options ? serviceEl.options[serviceEl.selectedIndex] : null;
      const hint = opt && typeof opt.title === 'string' ? opt.title.trim() : '';
      if (!serviceHintEl) {
        return;
      }
      serviceHintEl.textContent = hint;
      serviceHintEl.hidden = hint === '';
    };

    const updateSelectedSlotLabel = () => {
      if (!selectedSlotLabelEl) {
        return;
      }
      selectedSlotLabelEl.textContent = startEl.value ? String(startEl.value).replace(' ', ' at ') : 'No slot selected yet.';
    };

    const serviceDurationMinutes = () => {
      const opt = serviceEl.options ? serviceEl.options[serviceEl.selectedIndex] : null;
      const raw = opt && opt.dataset ? Number(opt.dataset.serviceDuration || '0') : 0;
      return raw > 0 ? raw : defaultSlotMinutes;
    };

    const computeEstimatedEnd = () => {
      if (!estimatedEndLabelEl) {
        return;
      }
      if (!startEl.value) {
        estimatedEndLabelEl.textContent = 'Pending service selection';
        return;
      }
      const date = dateEl.value || String(startEl.value).slice(0, 10);
      const time = String(startEl.value).slice(-5);
      if (!date || !/^\d{2}:\d{2}$/.test(time)) {
        estimatedEndLabelEl.textContent = prefillEndTime || 'Pending service selection';
        return;
      }
      const start = new Date(date + 'T' + time + ':00');
      if (Number.isNaN(start.getTime())) {
        estimatedEndLabelEl.textContent = prefillEndTime || 'Pending service selection';
        return;
      }
      start.setMinutes(start.getMinutes() + serviceDurationMinutes());
      estimatedEndLabelEl.textContent = String(start.getHours()).padStart(2, '0') + ':' + String(start.getMinutes()).padStart(2, '0');
    };

    const updateClientDetails = () => {
      const opt = clientEl.options ? clientEl.options[clientEl.selectedIndex] : null;
      const data = opt && opt.dataset ? opt.dataset : {};
      if (clientDetailEls.name) clientDetailEls.name.textContent = data.clientName || 'Select a client';
      if (clientDetailEls.email) clientDetailEls.email.textContent = data.clientEmail || '—';
      if (clientDetailEls.country) clientDetailEls.country.textContent = data.clientCountry || '—';
      if (clientDetailEls.phone) clientDetailEls.phone.textContent = data.clientPhone || '—';
      if (clientDetailEls.source) clientDetailEls.source.textContent = data.clientSource || '—';
    };

    const syncClientSearchToSelection = () => {
      if (!clientSearchEl || !clientEl.options) {
        return;
      }
      const opt = clientEl.options[clientEl.selectedIndex] || null;
      clientSearchEl.value = opt && opt.value ? String(opt.dataset.clientName || opt.textContent || '').trim() : '';
    };

    const describeClientOption = (opt) => {
      const data = opt && opt.dataset ? opt.dataset : {};
      const name = String(data.clientName || opt.textContent || '').trim();
      const meta = [data.clientEmail || '', data.clientPhone || '', data.clientCountry || ''].filter(Boolean);
      return {
        id: String(opt.value || '').trim(),
        name,
        meta: meta.join(' · '),
        haystack: [
          String(opt.value || '').trim(),
          String(opt.textContent || '').trim(),
          String(data.clientName || '').trim(),
          String(data.clientEmail || '').trim(),
          String(data.clientPhone || '').trim(),
          String(data.clientCountry || '').trim(),
          String(data.clientSource || '').trim(),
        ].filter(Boolean).join(' ').toLowerCase(),
      };
    };

    const hideClientSearchResults = () => {
      if (!clientSearchResultsEl) {
        return;
      }
      clientSearchResultsEl.hidden = true;
      clientSearchResultsEl.innerHTML = '';
    };

    const selectClientOption = (opt) => {
      if (!opt || !opt.value) {
        return;
      }
      clientEl.value = opt.value;
      updateClientDetails();
      syncClientSearchToSelection();
      hideClientSearchResults();
      if (clientSearchHintEl) {
        clientSearchHintEl.textContent = 'Client selected.';
      }
    };

    const renderClientSearchResults = (matches, query) => {
      if (!clientSearchResultsEl) {
        return;
      }
      if (query === '') {
        hideClientSearchResults();
        return;
      }
      if (!matches.length) {
        clientSearchResultsEl.hidden = false;
        clientSearchResultsEl.innerHTML = '<div class="appt-create-search-results__empty">No matching clients found.</div>';
        return;
      }
      const visibleMatches = matches.slice(0, 8);
      clientSearchResultsEl.hidden = false;
      clientSearchResultsEl.innerHTML = visibleMatches.map((match) => {
        const summary = match.meta ? '<span class="appt-create-search-results__meta">' + escHtml(match.meta) + '</span>' : '';
        return '<button type="button" class="appt-create-search-results__item" data-client-result="' + escHtml(match.id) + '">'
          + '<span class="appt-create-search-results__title">' + escHtml(match.name || ('Client #' + match.id)) + '</span>'
          + '<span class="appt-create-search-results__id">#' + escHtml(match.id) + '</span>'
          + summary
          + '</button>';
      }).join('');
      clientSearchResultsEl.querySelectorAll('[data-client-result]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const next = Array.from(clientEl.options || []).find((opt) => opt.value === btn.dataset.clientResult);
          if (next) {
            selectClientOption(next);
          }
        });
      });
    };

    const filterClientOptions = () => {
      if (!clientSearchEl || !clientEl.options) {
        return;
      }
      const query = clientSearchEl.value.trim().toLowerCase();
      const matches = [];
      let exactMatch = null;
      Array.from(clientEl.options).forEach((opt, index) => {
        if (index === 0) {
          return;
        }
        const client = describeClientOption(opt);
        const visible = query === '' || client.haystack.includes(query);
        if (!visible) {
          return;
        }
        matches.push(client);
        const exactNeedle = [
          client.id.toLowerCase(),
          client.name.toLowerCase(),
          String(opt.dataset && opt.dataset.clientEmail ? opt.dataset.clientEmail : '').trim().toLowerCase(),
          String(opt.dataset && opt.dataset.clientPhone ? opt.dataset.clientPhone : '').trim().toLowerCase(),
        ];
        if (query !== '' && exactNeedle.includes(query) && exactMatch === null) {
          exactMatch = opt;
        }
      });

      if (query === '') {
        if (clientSearchHintEl) {
          clientSearchHintEl.textContent = 'Filter by ID, name, email, or phone.';
        }
        hideClientSearchResults();
        return;
      }

      renderClientSearchResults(matches, query);

      if (matches.length === 0) {
        if (clientSearchHintEl) {
          clientSearchHintEl.textContent = 'No matching clients found.';
        }
        return;
      }

      if (clientSearchHintEl) {
        clientSearchHintEl.textContent = matches.length === 1
          ? '1 client match.'
          : String(matches.length) + ' client matches.';
      }

      const selectedOpt = clientEl.options[clientEl.selectedIndex] || null;
      const selectedStillVisible = !!(selectedOpt && selectedOpt.value && matches.some((match) => match.id === selectedOpt.value));
      const nextOpt = exactMatch || (matches.length === 1 ? Array.from(clientEl.options || []).find((opt) => opt.value === matches[0].id) : null);
      if (!selectedStillVisible && nextOpt) {
        clientEl.value = nextOpt.value;
        updateClientDetails();
      }
    };

    const syncStatusFromToggle = () => {
      if (!statusEl || !statusConfirmEl) {
        return;
      }
      statusEl.value = statusConfirmEl.checked ? 'confirmed' : 'scheduled';
    };

    const buildReloadUrl = () => {
      const params = new URLSearchParams();
      if (branchEl.value) params.set('branch_id', branchEl.value);
      if (dateEl.value) params.set('date', dateEl.value);
      const time = String(startEl.value || '').trim().slice(-5);
      if (/^\d{2}:\d{2}$/.test(time)) {
        params.set('time', time);
      } else if (preferredTime) {
        params.set('time', preferredTime);
      }
      params.set('slot_minutes', String(defaultSlotMinutes));
      return createBaseUrl + (params.toString() ? '?' + params.toString() : '');
    };

    async function loadSlots() {
      const serviceId = serviceEl.value;
      const date = dateEl.value;
      if (!serviceId || !date) {
        statusHintEl.textContent = 'Select service and date first.';
        return;
      }
      statusHintEl.textContent = 'Loading...';
      slotsWrap.innerHTML = '';
      const params = new URLSearchParams();
      params.set('service_id', serviceId);
      params.set('date', date);
      if (staffEl.value) params.set('staff_id', staffEl.value);
      if (branchEl.value) params.set('branch_id', branchEl.value);
      if (roomEl && roomEl.value) params.set('room_id', roomEl.value);
      const slotAc = new AbortController();
      const slotTid = bindAbortDeadline(slotAc, DRAWER_FETCH_TIMEOUT_MS);
      try {
        const res = await fetch('/appointments/slots?' + params.toString(), {
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'same-origin',
          signal: slotAc.signal,
        });
        const payload = await res.json();
        if (!res.ok || !payload.success) {
          statusHintEl.textContent = payload.error && payload.error.message ? payload.error.message : 'Failed to load slots.';
          return;
        }
        const slots = payload.data && Array.isArray(payload.data.slots) ? payload.data.slots : [];
        if (!slots.length) {
          slotsWrap.innerHTML = '<span class="hint">No slots available.</span>';
          statusHintEl.textContent = '';
          return;
        }
        statusHintEl.textContent = 'Pick a slot or keep the prefilled time.';
        slots.forEach((slot) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'slot-btn';
          btn.textContent = slot;
          if (preferredTime && preferredTime === slot) {
            btn.classList.add('is-active');
          }
          btn.addEventListener('click', () => {
            if (!clientEl.value) {
              statusHintEl.textContent = 'Select client first.';
              return;
            }
            if (!staffEl.value) {
              statusHintEl.textContent = 'Select staff before booking.';
              return;
            }
            startEl.value = date + ' ' + slot;
            slotsWrap.querySelectorAll('.slot-btn').forEach((item) => item.classList.remove('is-active'));
            btn.classList.add('is-active');
            updateSelectedSlotLabel();
            computeEstimatedEnd();
            statusHintEl.textContent = 'Selected ' + slot + '. Save when ready.';
          });
          slotsWrap.appendChild(btn);
        });
      } catch (error) {
        if (error && error.name === 'AbortError') {
          statusHintEl.textContent = 'Slots request timed out.';
        } else {
          statusHintEl.textContent = 'Could not load slots.';
        }
      } finally {
        clearTimeout(slotTid);
      }
    }

    loadBtn.addEventListener('click', loadSlots);
    if (clientSearchEl) {
      clientSearchEl.addEventListener('input', filterClientOptions);
      clientSearchEl.addEventListener('focus', filterClientOptions);
      clientSearchEl.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') {
          return;
        }
        if (!clientSearchResultsEl || clientSearchResultsEl.hidden) {
          return;
        }
        const first = clientSearchResultsEl.querySelector('[data-client-result]');
        if (!first) {
          return;
        }
        event.preventDefault();
        first.click();
      });
    }
    clientEl.addEventListener('change', () => {
      updateClientDetails();
      syncClientSearchToSelection();
      if (clientSearchHintEl) {
        clientSearchHintEl.textContent = 'Filter by ID, name, email, or phone.';
      }
      hideClientSearchResults();
    });
    document.addEventListener('click', (event) => {
      const target = event.target;
      if (!form.contains(target)) {
        hideClientSearchResults();
        return;
      }
      if (clientSearchEl && target !== clientSearchEl && clientSearchResultsEl && !clientSearchResultsEl.contains(target)) {
        hideClientSearchResults();
      }
    });
    if (String(branchEl.tagName || '').toUpperCase() === 'SELECT') {
      branchEl.addEventListener('change', () => {
        if (!branchEl.value) {
          return;
        }
        if (window.AppDrawer && typeof window.AppDrawer.openUrl === 'function') {
          window.AppDrawer.openUrl(buildReloadUrl(), { force: true });
        }
      });
    }
    if (statusConfirmEl) {
      statusConfirmEl.addEventListener('change', syncStatusFromToggle);
    }
    form.addEventListener('submit', (event) => {
      if (!startEl.value) {
        event.preventDefault();
        statusHintEl.textContent = 'Select or keep a start slot first.';
      }
    });
    [serviceEl, dateEl, staffEl, branchEl, roomEl].forEach((el) => {
      if (!el) {
        return;
      }
      el.addEventListener('change', () => {
        statusHintEl.textContent = '';
        slotsWrap.innerHTML = '<span class="hint">Load slots to refresh availability.</span>';
      });
    });
    serviceEl.addEventListener('change', updateServiceDescriptionHint);
    serviceEl.addEventListener('change', computeEstimatedEnd);
    updateServiceDescriptionHint();
    updateSelectedSlotLabel();
    syncClientSearchToSelection();
    filterClientOptions();
    updateClientDetails();
    syncStatusFromToggle();
    computeEstimatedEnd();
  }

  function initAppointmentEdit(root) {
    const serviceEl = root.querySelector('#service_id');
    const serviceHintEl = root.querySelector('#service-description-hint');
    if (!serviceEl || !serviceHintEl) {
      return;
    }
    const update = () => {
      const opt = serviceEl.options[serviceEl.selectedIndex];
      const hint = opt && typeof opt.title === 'string' ? opt.title.trim() : '';
      serviceHintEl.textContent = hint;
      serviceHintEl.hidden = hint === '';
    };
    serviceEl.addEventListener('change', update);
    update();
  }

  function closestFromEventTarget(target, selector) {
    return target instanceof Element ? target.closest(selector) : null;
  }

  function handleDocumentClick(event) {
    const target = closestFromEventTarget(event.target, '[data-drawer-url]');
    if (!target) {
      return;
    }
    if (target.closest('#app-drawer-host')) {
      return;
    }
    event.preventDefault();
    openUrl(target.getAttribute('href') || target.dataset.drawerUrl || '');
  }

  /**
   * Best-effort warm-up: after the pointer rests on a drawer link, hint the browser to prefetch the URL.
   * Does not bypass openUrl() — the real open still does a normal fetch (cookies, CSRF, session stay authoritative).
   * Trade-off: extra GETs if users hover many links (low priority in the network stack).
   */
  const drawerPrefetchIssued = new Set();
  let drawerPrefetchHoverTimer = null;

  function issueDrawerPrefetch(href) {
    const raw = String(href || '').trim();
    if (raw === '' || raw.toLowerCase().startsWith('javascript:')) {
      return;
    }
    let u;
    try {
      u = new URL(raw, window.location.href);
    } catch (_) {
      return;
    }
    if (u.origin !== window.location.origin) {
      return;
    }
    if (!u.searchParams.has('drawer')) {
      u.searchParams.set('drawer', '1');
    }
    const key = u.pathname + u.search;
    if (drawerPrefetchIssued.has(key)) {
      return;
    }
    drawerPrefetchIssued.add(key);
    const link = document.createElement('link');
    link.rel = 'prefetch';
    link.href = u.toString();
    document.head.appendChild(link);
  }

  document.addEventListener(
    'pointerenter',
    (event) => {
      const el = closestFromEventTarget(event.target, '[data-drawer-url]');
      if (!el || el.closest('#app-drawer-host')) {
        return;
      }
      const href = el.getAttribute('href') || el.dataset.drawerUrl;
      if (!href) {
        return;
      }
      window.clearTimeout(drawerPrefetchHoverTimer);
      drawerPrefetchHoverTimer = window.setTimeout(() => {
        drawerPrefetchHoverTimer = null;
        issueDrawerPrefetch(href);
      }, 320);
    },
    true
  );

  overlayEl.addEventListener('click', () => {
    closeDrawer();
  });
  closeEl.addEventListener('click', () => {
    closeDrawer();
  });
  document.addEventListener('click', handleDocumentClick);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && state.isOpen) {
      closeDrawer();
    }
  });
  window.addEventListener('app:drawer-open-url', (event) => {
    const detail = event.detail && typeof event.detail === 'object' ? event.detail : {};
    openUrl(detail.url || '', detail);
  });

  window.AppDrawer = {
    close: closeDrawer,
    openUrl,
    refreshCurrent() {
      if (state.activeUrl) {
        openUrl(state.activeUrl, { force: true });
      }
    },
    setDirty,
  };
})();
