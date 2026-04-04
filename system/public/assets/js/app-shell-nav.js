/**
 * Tenant app shell: top bar vs sidebar layout preference (localStorage only).
 */
(function () {
  'use strict';

  var KEY_LAYOUT = 'ollira_app_nav_layout';
  var KEY_COLLAPSED = 'ollira_app_sidebar_collapsed';
  var BP_MOBILE = 900;

  function getLayout() {
    try {
      return localStorage.getItem(KEY_LAYOUT) === 'sidebar' ? 'sidebar' : 'top';
    } catch (e) {
      return 'top';
    }
  }

  function setLayout(mode) {
    var v = mode === 'sidebar' ? 'sidebar' : 'top';
    try {
      localStorage.setItem(KEY_LAYOUT, v);
    } catch (e) { /* ignore */ }
    document.documentElement.setAttribute('data-app-nav-layout', v);
    syncLayoutUi();
    syncRailAccessibility();
    if (v === 'top') {
      closeMobileSidebar();
    }
  }

  function isCollapsed() {
    return document.documentElement.getAttribute('data-app-sidebar-collapsed') === 'true';
  }

  function setCollapsed(collapsed) {
    if (collapsed) {
      document.documentElement.setAttribute('data-app-sidebar-collapsed', 'true');
    } else {
      document.documentElement.removeAttribute('data-app-sidebar-collapsed');
    }
    try {
      localStorage.setItem(KEY_COLLAPSED, collapsed ? '1' : '0');
    } catch (e) { /* ignore */ }
    syncCollapseToggle();
  }

  function isMobile() {
    return window.matchMedia('(max-width: ' + BP_MOBILE + 'px)').matches;
  }

  function syncLayoutUi() {
    var layout = document.documentElement.getAttribute('data-app-nav-layout') || 'top';
    document.querySelectorAll('[data-app-shell-layout]').forEach(function (btn) {
      var wants = btn.getAttribute('data-app-shell-layout');
      var pressed = wants === layout;
      btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
    });
  }

  function syncCollapseToggle() {
    var collapsed = document.documentElement.getAttribute('data-app-sidebar-collapsed') === 'true';
    document.querySelectorAll('[data-app-shell-sidebar-collapse]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
      btn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    });
  }

  function syncRailAccessibility() {
    var layout = document.documentElement.getAttribute('data-app-nav-layout') || 'top';
    var topRail = document.querySelector('.app-shell__header--top');
    var sideRail = document.getElementById('app-shell-sidebar');
    var mobileRail = document.querySelector('.app-shell__header--mobile');
    if (!topRail || !sideRail) return;

    if (layout === 'top') {
      topRail.removeAttribute('hidden');
      topRail.removeAttribute('inert');
      sideRail.setAttribute('hidden', '');
      sideRail.setAttribute('inert', '');
      if (mobileRail) {
        mobileRail.setAttribute('hidden', '');
        mobileRail.setAttribute('inert', '');
      }
    } else {
      topRail.setAttribute('hidden', '');
      topRail.setAttribute('inert', '');
      if (isMobile()) {
        if (mobileRail) {
          mobileRail.removeAttribute('hidden');
          mobileRail.removeAttribute('inert');
        }
        var open = document.documentElement.getAttribute('data-app-shell-mobile-open') === 'true';
        if (open) {
          sideRail.removeAttribute('hidden');
          sideRail.removeAttribute('inert');
        } else {
          sideRail.setAttribute('hidden', '');
          sideRail.setAttribute('inert', '');
        }
      } else {
        if (mobileRail) {
          mobileRail.setAttribute('hidden', '');
          mobileRail.setAttribute('inert', '');
        }
        sideRail.removeAttribute('hidden');
        sideRail.removeAttribute('inert');
      }
    }
  }

  function openMobileSidebar() {
    document.documentElement.setAttribute('data-app-shell-mobile-open', 'true');
    var sideRail = document.getElementById('app-shell-sidebar');
    var backdrop = document.querySelector('[data-app-shell-backdrop]');
    var menuBtn = document.querySelector('.app-shell__mobile-menu-btn');
    if (sideRail) {
      sideRail.removeAttribute('hidden');
      sideRail.removeAttribute('inert');
    }
    if (backdrop) backdrop.removeAttribute('hidden');
    if (menuBtn) menuBtn.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
    var first = sideRail && sideRail.querySelector('a[href], button:not([disabled])');
    if (first && typeof first.focus === 'function') {
      window.requestAnimationFrame(function () {
        first.focus();
      });
    }
  }

  function closeMobileSidebar() {
    document.documentElement.removeAttribute('data-app-shell-mobile-open');
    var sideRail = document.getElementById('app-shell-sidebar');
    var backdrop = document.querySelector('[data-app-shell-backdrop]');
    var menuBtn = document.querySelector('.app-shell__mobile-menu-btn');
    if (backdrop) backdrop.setAttribute('hidden', '');
    if (menuBtn) menuBtn.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    var layout = document.documentElement.getAttribute('data-app-nav-layout') || 'top';
    if (layout === 'sidebar' && isMobile() && sideRail) {
      sideRail.setAttribute('hidden', '');
      sideRail.setAttribute('inert', '');
    }
  }

  function onMobileMq() {
    syncRailAccessibility();
    if (!isMobile()) {
      closeMobileSidebar();
    }
  }

  function init() {
    var shell = document.querySelector('.app-shell');
    if (!shell) return;

    try {
      var layout = getLayout();
      document.documentElement.setAttribute('data-app-nav-layout', layout);
      if (localStorage.getItem(KEY_COLLAPSED) === '1') {
        document.documentElement.setAttribute('data-app-sidebar-collapsed', 'true');
      }
    } catch (e) { /* ignore */ }
    syncLayoutUi();
    syncCollapseToggle();
    syncRailAccessibility();

    document.querySelectorAll('[data-app-shell-layout]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var mode = btn.getAttribute('data-app-shell-layout');
        if (mode === 'sidebar' || mode === 'top') {
          setLayout(mode);
        }
      });
    });

    document.querySelectorAll('[data-app-shell-sidebar-collapse]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        setCollapsed(!isCollapsed());
      });
    });

    var menuBtn = document.querySelector('.app-shell__mobile-menu-btn');
    if (menuBtn) {
      menuBtn.addEventListener('click', function () {
        var open = document.documentElement.getAttribute('data-app-shell-mobile-open') === 'true';
        if (open) {
          closeMobileSidebar();
        } else {
          openMobileSidebar();
        }
      });
    }

    var backdrop = document.querySelector('[data-app-shell-backdrop]');
    if (backdrop) {
      backdrop.addEventListener('click', function () {
        closeMobileSidebar();
      });
    }

    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && document.documentElement.getAttribute('data-app-shell-mobile-open') === 'true') {
        closeMobileSidebar();
        if (menuBtn && typeof menuBtn.focus === 'function') {
          menuBtn.focus();
        }
      }
    });

    var mq = window.matchMedia('(max-width: ' + BP_MOBILE + 'px)');
    if (typeof mq.addEventListener === 'function') {
      mq.addEventListener('change', onMobileMq);
    } else if (typeof mq.addListener === 'function') {
      mq.addListener(onMobileMq);
    }

    sideNavLinkClicks();
  }

  /** Close mobile overlay when navigating within app (full page load still closes; good for same-tab). */
  function sideNavLinkClicks() {
    var sideRail = document.getElementById('app-shell-sidebar');
    if (!sideRail) return;
    sideRail.addEventListener('click', function (ev) {
      var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
      if (a && isMobile() && document.documentElement.getAttribute('data-app-nav-layout') === 'sidebar') {
        closeMobileSidebar();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
