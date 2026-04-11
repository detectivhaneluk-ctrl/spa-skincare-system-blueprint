/**
 * Positions .ds-segmented__thumb under the active .ds-segmented__link for iOS-style tracks.
 * Progressive enhancement: without this script, per-link active styles still apply.
 *
 * Cross-page slide: on click the departing thumb geometry is saved to sessionStorage;
 * on the next page load the thumb is placed at the saved position (no transition) then
 * animated to the new active link, so the pill appears to slide across page navigations.
 */
(function () {
  'use strict';

  var HOOK       = 'data-ds-segmented-thumb';
  var READY      = 'is-ds-thumb-ready';
  var THUMB_SEL  = '.ds-segmented__thumb';
  var STORAGE_KEY = 'ds-seg-thumb-from';

  function prefersReducedMotion() {
    try {
      return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
      return false;
    }
  }

  function findActive(root) {
    var q = root.querySelectorAll('.ds-segmented__link');
    var i;
    for (i = 0; i < q.length; i++) {
      if (q[i].getAttribute('aria-current') === 'page') {
        return q[i];
      }
    }
    for (i = 0; i < q.length; i++) {
      if (q[i].classList && q[i].classList.contains('is-active')) {
        return q[i];
      }
    }
    return null;
  }

  /** Snap thumb to an explicit {x, w} without any CSS transition. */
  function snapThumb(thumb, x, w) {
    thumb.style.transition = 'none';
    thumb.style.width      = w + 'px';
    thumb.style.transform  = 'translateX(' + x + 'px)';
  }

  /** Restore CSS transition (remove inline override so the stylesheet value applies). */
  function restoreTransition(thumb) {
    thumb.style.transition = '';
  }

  /** Active tab box relative to segmented root (matches visual layout; avoids offsetLeft drift with flex). */
  function thumbSlotGeometry(root, el) {
    var rr = root.getBoundingClientRect();
    var er = el.getBoundingClientRect();
    return { x: er.left - rr.left, w: er.width };
  }

  function syncThumb(root) {
    var thumb = root.querySelector(THUMB_SEL);
    if (!thumb) {
      return;
    }
    var active = findActive(root);
    if (!active) {
      thumb.style.width     = '0';
      thumb.style.transform = 'translateX(0)';
      root.classList.remove(READY);
      return;
    }
    var g = thumbSlotGeometry(root, active);
    thumb.style.width     = g.w + 'px';
    thumb.style.transform = 'translateX(' + g.x + 'px)';
    root.classList.add(READY);
  }

  /**
   * On initial page load:
   * 1. If a saved "from" position exists in sessionStorage, snap the thumb there first.
   * 2. Re-enable transition, then in the next animation frame animate to the active link.
   */
  function initRoot(root) {
    var thumb = root.querySelector(THUMB_SEL);
    if (!thumb) {
      return;
    }

    if (prefersReducedMotion()) {
      thumb.style.transition = 'none';
      syncThumb(root);
      root.classList.add(READY);
      return;
    }

    var active = findActive(root);
    if (!active) {
      syncThumb(root);
      return;
    }

    var tg = thumbSlotGeometry(root, active);
    var targetW = tg.w;
    var targetX = tg.x;

    /* Try to read the saved departure geometry */
    var stored = null;
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      if (raw) {
        stored = JSON.parse(raw);
        sessionStorage.removeItem(STORAGE_KEY);
      }
    } catch (e) { /* sessionStorage unavailable — degrade gracefully */ }

    if (stored && typeof stored.x === 'number' && typeof stored.w === 'number') {
      /* Snap to where the pill was on the previous page */
      snapThumb(thumb, stored.x, stored.w);
      root.classList.add(READY);

      /* One rAF to flush the snap, then restore transition and animate to target */
      requestAnimationFrame(function () {
        restoreTransition(thumb);
        requestAnimationFrame(function () {
          thumb.style.width     = targetW + 'px';
          thumb.style.transform = 'translateX(' + targetX + 'px)';
        });
      });
    } else {
      /* No saved position — just place it (no animation on first visit) */
      snapThumb(thumb, targetX, targetW);
      root.classList.add(READY);
      requestAnimationFrame(function () {
        restoreTransition(thumb);
      });
    }

    /* Save current thumb position whenever a segmented tab link is clicked */
    root.querySelectorAll('.ds-segmented__link').forEach(function (link) {
      link.addEventListener('click', function () {
        try {
          var currentThumb = root.querySelector(THUMB_SEL);
          if (currentThumb) {
            /* Read position from current active, not the clicked link yet */
            var act = findActive(root);
            if (act) {
              var geo = thumbSlotGeometry(root, act);
              sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                x: geo.x,
                w: geo.w
              }));
            }
          }
          /* Signal that next page is an intra-section navigation so the entrance
             animation is skipped and the controls appear instantly */
          sessionStorage.setItem('ds-appts-tab-nav', '1');
        } catch (e) { /* ignore */ }
      });
    });
  }

  function initAll() {
    document.querySelectorAll('[' + HOOK + ']').forEach(initRoot);
  }

  function syncAll() {
    document.querySelectorAll('[' + HOOK + ']').forEach(syncThumb);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  window.addEventListener('resize', syncAll);
  window.addEventListener('orientationchange', function () {
    window.setTimeout(syncAll, 100);
  });
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) {
      syncAll();
    }
  });
})();
