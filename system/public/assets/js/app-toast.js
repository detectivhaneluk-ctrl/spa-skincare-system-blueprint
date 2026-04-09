(() => {
  const host = document.getElementById('app-toast-host');
  const bootEl = document.getElementById('app-toast-initial');
  if (!host) {
    return;
  }

  const LABELS = {
    success: 'Done',
    error: 'Something went wrong',
    warning: 'Attention',
    info: 'Notice',
  };

  const ICONS = {
    success: `<svg class="app-toast__icon-svg" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <circle class="app-toast__svg-ring" cx="12" cy="12" r="9.25" stroke="currentColor" stroke-width="1.65" fill="none" pathLength="100"/>
      <path class="app-toast__svg-stroke app-toast__svg-stroke--check" d="M7.2 12.05 L11.05 15.85 16.8 8.35" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" fill="none" pathLength="100"/>
    </svg>`,
    error: `<svg class="app-toast__icon-svg" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <circle class="app-toast__svg-ring" cx="12" cy="12" r="9.25" stroke="currentColor" stroke-width="1.65" fill="none" pathLength="100"/>
      <path class="app-toast__svg-stroke app-toast__svg-stroke--ex1" d="M9 9 L15 15" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none" pathLength="100"/>
      <path class="app-toast__svg-stroke app-toast__svg-stroke--ex2" d="M15 9 L9 15" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none" pathLength="100"/>
    </svg>`,
    warning: `<svg class="app-toast__icon-svg" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <path class="app-toast__svg-stroke app-toast__svg-stroke--tri" d="M12 4.25 L4.2 18.5 H19.8 Z" stroke="currentColor" stroke-width="1.65" stroke-linejoin="round" fill="none" pathLength="100"/>
      <path class="app-toast__svg-stroke app-toast__svg-stroke--bang" d="M12 10 v3.2 M12 16.2 v.01" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none" pathLength="100"/>
    </svg>`,
    info: `<svg class="app-toast__icon-svg" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <circle class="app-toast__svg-ring" cx="12" cy="12" r="9.25" stroke="currentColor" stroke-width="1.65" fill="none" pathLength="100"/>
      <path class="app-toast__svg-stroke app-toast__svg-stroke--idot" d="M12 10.2 V16" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" fill="none" pathLength="100"/>
      <path class="app-toast__svg-stroke app-toast__svg-stroke--idot2" d="M12 7.35 v.01" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" fill="none" pathLength="100"/>
    </svg>`,
  };

  const DEFAULT_DUR = { success: 5200, info: 5200, warning: 7800, error: 9800 };

  let regionEl = null;

  function ensureRegion() {
    if (regionEl && host.contains(regionEl)) {
      return regionEl;
    }
    regionEl = document.createElement('div');
    regionEl.className = 'app-toast__region';
    regionEl.setAttribute('role', 'region');
    regionEl.setAttribute('aria-live', 'polite');
    regionEl.setAttribute('aria-relevant', 'additions text');
    host.appendChild(regionEl);
    return regionEl;
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function show(raw) {
    const type = ['success', 'error', 'warning', 'info'].includes(raw.type) ? raw.type : 'info';
    const message = typeof raw.message === 'string' ? raw.message : '';
    if (!message) {
      return null;
    }
    const title = typeof raw.title === 'string' && raw.title !== '' ? raw.title : LABELS[type] || LABELS.info;
    const duration =
      typeof raw.duration === 'number' && raw.duration >= 0 ? raw.duration : DEFAULT_DUR[type] ?? 6000;

    const region = ensureRegion();
    const el = document.createElement('div');
    el.className = `app-toast app-toast--${type}`;
    el.setAttribute('role', 'status');

    const iconHtml = ICONS[type] || ICONS.info;
    el.innerHTML = `
      <div class="app-toast__icon" aria-hidden="true">${iconHtml}</div>
      <div class="app-toast__body">
        <p class="app-toast__title">${escapeHtml(title)}</p>
        <p class="app-toast__message">${escapeHtml(message)}</p>
      </div>
      <button type="button" class="app-toast__close" aria-label="Dismiss notification">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
      <div class="app-toast__progress" aria-hidden="true"><span class="app-toast__progress-bar"></span></div>
    `;

    const closeBtn = el.querySelector('.app-toast__close');
    const bar = el.querySelector('.app-toast__progress-bar');
    let removed = false;
    let timer = null;

    function removeToast() {
      if (removed) {
        return;
      }
      removed = true;
      if (timer) {
        clearTimeout(timer);
        timer = null;
      }
      el.classList.add('app-toast--out');
      const t = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 80 : 280;
      window.setTimeout(() => {
        el.remove();
        if (region && region.childElementCount === 0) {
          region.remove();
          regionEl = null;
        }
      }, t);
    }

    closeBtn.addEventListener('click', removeToast);

    region.appendChild(el);
    requestAnimationFrame(() => {
      el.classList.add('app-toast--in');
    });

    if (bar && duration > 0 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      bar.style.animationDuration = `${duration}ms`;
    } else if (bar) {
      bar.style.display = 'none';
    }

    if (duration > 0) {
      timer = window.setTimeout(removeToast, duration + 320);
    }

    try {
      window.dispatchEvent(
        new CustomEvent('ollira:toast', { detail: { type, title, message, duration } })
      );
    } catch (_) {
      /* ignore */
    }

    return el;
  }

  window.OlliraToast = {
    show,
    success(message, title) {
      show({ type: 'success', message, title });
    },
    error(message, title) {
      show({ type: 'error', message, title });
    },
    warning(message, title) {
      show({ type: 'warning', message, title });
    },
    info(message, title) {
      show({ type: 'info', message, title });
    },
  };

  if (bootEl && bootEl.textContent) {
    try {
      const initial = JSON.parse(bootEl.textContent);
      if (Array.isArray(initial)) {
        initial.forEach((item) => {
          if (item && typeof item === 'object') {
            show(item);
          }
        });
      }
    } catch (_) {
      /* ignore malformed bootstrap */
    }
    bootEl.remove();
  }
})();
