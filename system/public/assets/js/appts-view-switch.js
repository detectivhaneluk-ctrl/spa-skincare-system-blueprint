/**
 * Appointments-only view switch (Calendar / List / Waitlist).
 * Positions .appts-view-switch__thumb under the active segment; mirrors cross-page
 * slide + tab-nav entrance flag used by base layout (data-ds-appts-tab-nav).
 */
(function () {
  'use strict';

  var HOOK = 'data-appts-view-switch';
  var READY = 'is-appts-view-switch-ready';
  var THUMB_SEL = '.appts-view-switch__thumb';
  var SEG_SEL = '.appts-view-switch__segment';
  var STORAGE_KEY = 'appts-view-switch-from';
  var TAB_NAV_KEY = 'ds-appts-tab-nav';

  function prefersReducedMotion() {
    try {
      return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
      return false;
    }
  }

  function findActive(root) {
    var q = root.querySelectorAll(SEG_SEL);
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

  function snapThumb(thumb, x, w) {
    thumb.style.transition = 'none';
    thumb.style.width = w + 'px';
    thumb.style.transform = 'translateX(' + x + 'px)';
  }

  function restoreTransition(thumb) {
    thumb.style.transition = '';
  }

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
      thumb.style.width = '0';
      thumb.style.transform = 'translateX(0)';
      root.classList.remove(READY);
      return;
    }
    var g = thumbSlotGeometry(root, active);
    thumb.style.width = g.w + 'px';
    thumb.style.transform = 'translateX(' + g.x + 'px)';
    root.classList.add(READY);
  }

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

    var stored = null;
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      if (raw) {
        stored = JSON.parse(raw);
        sessionStorage.removeItem(STORAGE_KEY);
      }
    } catch (e) {}

    if (stored && typeof stored.x === 'number' && typeof stored.w === 'number') {
      snapThumb(thumb, stored.x, stored.w);
      root.classList.add(READY);
      requestAnimationFrame(function () {
        restoreTransition(thumb);
        requestAnimationFrame(function () {
          thumb.style.width = targetW + 'px';
          thumb.style.transform = 'translateX(' + targetX + 'px)';
        });
      });
    } else {
      snapThumb(thumb, targetX, targetW);
      root.classList.add(READY);
      requestAnimationFrame(function () {
        restoreTransition(thumb);
      });
    }

    root.querySelectorAll(SEG_SEL).forEach(function (link) {
      link.addEventListener('click', function () {
        try {
          var currentThumb = root.querySelector(THUMB_SEL);
          if (currentThumb) {
            var act = findActive(root);
            if (act) {
              var geo = thumbSlotGeometry(root, act);
              sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ x: geo.x, w: geo.w }));
            }
          }
          sessionStorage.setItem(TAB_NAV_KEY, '1');
        } catch (e) {}
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
