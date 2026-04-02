/**
 * Appointments day calendar: immersive workspace from grid scroll only.
 * Threshold + hysteresis; suspended while drawer open or focus in workspace forms.
 */
(function () {
  'use strict';

  var ENTER_SCROLL_PX = 96;
  var EXIT_SCROLL_PX = 28;
  /** Below this, use tiny thresholds so small overflow still allows intentional enter (large monitors). */
  var MIN_MEANINGFUL_SCROLL_RANGE_PX = 16;
  var ENTER_SCROLL_RATIO = 0.22;
  var EXIT_SCROLL_RATIO = 0.07;
  var TRANSITION_MS = 280;
  var SCROLL_RESET_CLEAR_PX = 6;

  var root = document.getElementById('calendar-workspace-root');
  var grid = document.getElementById('appts-calendar-grid');
  var exitBtn = document.getElementById('calendar-immersive-exit');
  var mainEl = root ? (root.closest('.app-shell__main') || document.querySelector('.app-shell__main')) : null;

  if (!root || !grid) {
    return;
  }

  var immersive = false;
  var rafId = null;
  var transitionTimer = null;
  var suppressAutoEnter = false;
  var reducedMotion = false;

  try {
    reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  } catch (e) {
    reducedMotion = false;
  }

  if (reducedMotion) {
    root.classList.add('is-immersive-prefers-reduced');
  }

  function isDrawerOpen() {
    var d = document.querySelector('#app-drawer-host .app-drawer');
    return !!(d && !d.hasAttribute('hidden'));
  }

  function isFocusBlockingImmersive() {
    var ae = document.activeElement;
    if (!ae || !root.contains(ae)) {
      return false;
    }
    var tag = (ae.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'select' || tag === 'textarea') {
      return true;
    }
    if (tag === 'button' && ae.closest('#calendar-filter-form')) {
      return true;
    }
    if (ae.isContentEditable) {
      return true;
    }
    return false;
  }

  function setExitButtonVisible(show) {
    if (!exitBtn) {
      return;
    }
    exitBtn.hidden = !show;
    exitBtn.setAttribute('aria-hidden', show ? 'false' : 'true');
  }

  /** Always sync DOM + internal flag (click handler must not no-op on state drift). */
  function forceExitImmersive() {
    suppressAutoEnter = true;
    immersive = false;
    root.classList.remove('is-immersive', 'is-immersive-transitioning');
    if (mainEl) { mainEl.classList.remove('calendar-main-is-immersive'); }
    setExitButtonVisible(false);
    if (transitionTimer) {
      window.clearTimeout(transitionTimer);
      transitionTimer = null;
    }
    grid.scrollTop = 0;
    scheduleEvaluate();
  }

  function setImmersive(next) {
    if (next === immersive) {
      return;
    }
    immersive = next;
    root.classList.toggle('is-immersive', next);
    if (mainEl) { mainEl.classList.toggle('calendar-main-is-immersive', next); }
    setExitButtonVisible(next);

    if (reducedMotion) {
      root.classList.remove('is-immersive-transitioning');
      return;
    }

    root.classList.add('is-immersive-transitioning');
    if (transitionTimer) {
      window.clearTimeout(transitionTimer);
    }
    transitionTimer = window.setTimeout(function () {
      root.classList.remove('is-immersive-transitioning');
      transitionTimer = null;
    }, TRANSITION_MS);
  }

  function scrollRangePx() {
    return Math.max(0, grid.scrollHeight - grid.clientHeight);
  }

  /** Hysteresis scaled to how much the grid can actually scroll (fixed px caps for tall grids). */
  function scrollThresholds() {
    var maxScroll = scrollRangePx();
    if (maxScroll <= 0) {
      return { enter: Infinity, exit: 0, maxScroll: maxScroll };
    }
    if (maxScroll < MIN_MEANINGFUL_SCROLL_RANGE_PX) {
      if (maxScroll < 3) {
        return { enter: Infinity, exit: 0, maxScroll: maxScroll };
      }
      var enterTiny = Math.max(1, Math.min(maxScroll - 1, Math.floor(maxScroll * 0.45)));
      /* Exit at top: st < 1 (scrollTop is integer in practice). */
      return { enter: enterTiny, exit: 1, maxScroll: maxScroll };
    }
    var enterAt = Math.min(ENTER_SCROLL_PX, Math.max(12, Math.ceil(maxScroll * ENTER_SCROLL_RATIO)));
    var exitAt = Math.min(EXIT_SCROLL_PX, Math.max(4, Math.floor(maxScroll * EXIT_SCROLL_RATIO)));
    if (exitAt >= enterAt) {
      exitAt = Math.max(0, enterAt - 8);
    }
    return { enter: enterAt, exit: exitAt, maxScroll: maxScroll };
  }

  function evaluateScroll() {
    if (isDrawerOpen()) {
      return;
    }

    var st = grid.scrollTop;
    var th = scrollThresholds();

    if (suppressAutoEnter) {
      if (st < SCROLL_RESET_CLEAR_PX) {
        suppressAutoEnter = false;
      } else {
        return;
      }
    }

    /* Layout lost scroll range entirely: force standard workspace. */
    if (th.maxScroll <= 0 && immersive) {
      setImmersive(false);
      return;
    }

    /* Always allow scroll-to-top exit so chrome restores even if a filter field still has focus. */
    if (immersive && st < th.exit) {
      setImmersive(false);
      return;
    }

    if (isFocusBlockingImmersive()) {
      return;
    }

    if (!immersive && st > th.enter) {
      setImmersive(true);
    }
  }

  function scheduleEvaluate() {
    if (rafId !== null) {
      return;
    }
    rafId = window.requestAnimationFrame(function () {
      rafId = null;
      evaluateScroll();
    });
  }

  grid.addEventListener('scroll', scheduleEvaluate, { passive: true });

  root.addEventListener('focusin', function () {
    if (isFocusBlockingImmersive()) {
      scheduleEvaluate();
    }
  });

  root.addEventListener('focusout', function () {
    scheduleEvaluate();
  });

  root.addEventListener('click', function (e) {
    var el = e.target;
    if (!el || typeof el.closest !== 'function') {
      return;
    }
    var btn = el.closest('[data-calendar-immersive-exit]');
    if (!btn || !root.contains(btn)) {
      return;
    }
    e.preventDefault();
    forceExitImmersive();
  });

  try {
    var mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    if (mq.addEventListener) {
      mq.addEventListener('change', function () {
        reducedMotion = mq.matches;
        root.classList.toggle('is-immersive-prefers-reduced', reducedMotion);
      });
    } else if (mq.addListener) {
      mq.addListener(function () {
        reducedMotion = mq.matches;
        root.classList.toggle('is-immersive-prefers-reduced', reducedMotion);
      });
    }
  } catch (e2) {
    /* ignore */
  }

  window.addEventListener('app:drawer-closed', scheduleEvaluate);
  window.addEventListener('calendar-workspace:grid-updated', scheduleEvaluate);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleEvaluate);
  } else {
    scheduleEvaluate();
  }
})();
