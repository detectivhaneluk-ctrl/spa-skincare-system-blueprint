/**
 * Appointments day calendar: immersive workspace from grid scroll only.
 * Threshold + hysteresis; suspended while drawer open or focus in workspace forms.
 */
(function () {
  'use strict';

  var ENTER_SCROLL_PX = 96;
  var EXIT_SCROLL_PX = 28;
  var TRANSITION_MS = 280;
  var SCROLL_RESET_CLEAR_PX = 6;

  var root = document.getElementById('calendar-workspace-root');
  var grid = document.getElementById('appts-calendar-grid');
  var exitBtn = document.getElementById('calendar-immersive-exit');

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

  function evaluateScroll() {
    if (isDrawerOpen()) {
      return;
    }
    if (isFocusBlockingImmersive()) {
      return;
    }

    var st = grid.scrollTop;

    if (suppressAutoEnter) {
      if (st < SCROLL_RESET_CLEAR_PX) {
        suppressAutoEnter = false;
      } else {
        return;
      }
    }

    if (!immersive && st > ENTER_SCROLL_PX) {
      setImmersive(true);
    } else if (immersive && st < EXIT_SCROLL_PX) {
      setImmersive(false);
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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleEvaluate);
  } else {
    scheduleEvaluate();
  }
})();
