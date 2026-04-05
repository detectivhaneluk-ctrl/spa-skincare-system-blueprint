/**
 * Appointments day calendar: full-screen toggle (button only).
 * Single button toggles between "Full screen" and "Exit full screen".
 * No scroll trigger. Escape also exits.
 */
(function () {
  'use strict';

  var TRANSITION_MS = 260;

  var root     = document.getElementById('calendar-workspace-root');
  var grid     = document.getElementById('appts-calendar-grid');
  var btn      = document.getElementById('calendar-fullscreen-btn');
  var btnIcon  = document.getElementById('calendar-fullscreen-icon');
  var btnLabel = btn ? btn.querySelector('.appts-cal-fullscreen-label') : null;
  var mainEl   = root ? (root.closest('.app-shell__main') || document.querySelector('.app-shell__main')) : null;

  if (!root || !grid) { return; }

  var immersive      = false;
  var transitionTimer = null;
  var reducedMotion  = false;

  try { reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

  if (reducedMotion) { root.classList.add('is-immersive-prefers-reduced'); }

  function isDrawerOpen() {
    var d = document.querySelector('#app-drawer-host .app-drawer');
    return !!(d && !d.hasAttribute('hidden'));
  }

  /** Swap button icon + label to reflect current state. */
  function syncButton(on) {
    if (!btn) { return; }
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    btn.setAttribute('aria-label', on ? 'Exit full screen' : 'Enter full screen');
    if (btnLabel) { btnLabel.textContent = on ? 'Exit full screen' : 'Full screen'; }
    if (btnIcon) {
      btnIcon.querySelector('use').setAttribute('href', on ? '#bi-fullscreen-exit' : '#bi-fullscreen');
    }
  }

  function setImmersive(next) {
    if (next === immersive) { return; }
    immersive = next;

    root.classList.toggle('is-immersive', next);
    document.body.classList.toggle('calendar-is-fullscreen', next);
    if (mainEl) { mainEl.classList.toggle('calendar-main-is-immersive', next); }
    syncButton(next);

    if (reducedMotion) {
      root.classList.remove('is-immersive-transitioning');
      return;
    }
    root.classList.add('is-immersive-transitioning');
    if (transitionTimer) { window.clearTimeout(transitionTimer); }
    transitionTimer = window.setTimeout(function () {
      root.classList.remove('is-immersive-transitioning');
      transitionTimer = null;
    }, TRANSITION_MS);
  }

  if (btn) {
    btn.addEventListener('click', function () {
      if (immersive) {
        setImmersive(false);
      } else {
        if (!isDrawerOpen()) { setImmersive(true); }
      }
    });
  }

  document.addEventListener('keydown', function (e) {
    if (immersive && (e.key === 'Escape' || e.key === 'Esc')) {
      setImmersive(false);
    }
  });

  try {
    var mq = window.matchMedia('(prefers-reduced-motion: reduce)');
    var onMqChange = function () {
      reducedMotion = mq.matches;
      root.classList.toggle('is-immersive-prefers-reduced', reducedMotion);
    };
    if (mq.addEventListener) { mq.addEventListener('change', onMqChange); }
    else if (mq.addListener) { mq.addListener(onMqChange); }
  } catch (e2) {}

})();
