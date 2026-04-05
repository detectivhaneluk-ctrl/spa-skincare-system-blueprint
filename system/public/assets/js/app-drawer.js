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

  function setContent(html, requestedUrl) {
    /* Clear loading strip in the same turn as real HTML — avoids one frame of “content + loading bar” (layout jump). */
    setStatus('', '');
    bodyEl.innerHTML = html;
    state.activeUrl = requestedUrl || state.activeUrl;
    const root = currentContentRoot();
    applyMetadata(root);
    initDrawerTabs(bodyEl);
    initDrawerForms(bodyEl);
    initDirtyTracking(bodyEl);
    initAppointmentCreate(bodyEl);
    initAppointmentEdit(bodyEl);
    initDrawerLinks(bodyEl);
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
        setStatus(error && error.message ? error.message : 'Could not load drawer.', 'error');
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
      }
      setStatus(message, 'error');
      throw new Error(message);
    }

    const data = payload.data && typeof payload.data === 'object' ? payload.data : {};
    if (data.html) {
      setContent(data.html, data.url || state.activeUrl);
    }
    if (data.message) {
      setStatus(data.message, 'success');
    }
    if (data.refresh_calendar) {
      refreshCalendarIfPresent();
    }
    if (data.open_url) {
      openUrl(data.open_url, { force: true });
      return;
    }
    if (data.close_drawer) {
      closeDrawer(true);
      return;
    }
    if (data.reload_url && form) {
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

  function initDrawerForms(root) {
    root.querySelectorAll('form[data-drawer-submit]').forEach((form) => {
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const confirmMessage = form.dataset.drawerConfirm || '';
        if (confirmMessage && !window.confirm(confirmMessage)) {
          return;
        }
        setStatus('Saving...', 'loading');
        try {
          await submitDrawerForm(form);
        } catch (error) {
          setStatus(error && error.message ? error.message : 'Could not complete request.', 'error');
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
    const serviceEl = form.querySelector('#service_id');
    const dateEl = form.querySelector('#date');
    const staffEl = form.querySelector('#staff_id');
    const roomEl = form.querySelector('#room_id');
    const startEl = form.querySelector('#selected_start_time');
    const slotsWrap = form.querySelector('#slots-container');
    const statusHintEl = form.querySelector('#slots-status');
    const loadBtn = form.querySelector('#load-slots-btn');
    const selectedSlotLabelEl = form.querySelector('[data-selected-slot-label]');
    const estimatedEndLabelEl = form.querySelector('[data-estimated-end-label]');
    const serviceHintEl = form.querySelector('#service-description-hint');
    const preferredTime = form.dataset.prefillTime || '';
    const defaultSlotMinutes = Math.max(5, Number(form.dataset.slotMinutes || '30') || 30);
    const prefillEndTime = form.dataset.prefillEndTime || '';
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
      if (clientDetailEls.source) clientDetailEls.source.textContent = data.clientSource || 'internal_calendar';
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
    clientEl.addEventListener('change', updateClientDetails);
    updateServiceDescriptionHint();
    updateSelectedSlotLabel();
    updateClientDetails();
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

  function handleDocumentClick(event) {
    const target = event.target.closest('[data-drawer-url]');
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
      const el = event.target.closest('[data-drawer-url]');
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
