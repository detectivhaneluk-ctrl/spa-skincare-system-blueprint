/**
 * Clients list: merge duplicate resolution modal (centered dialog over the table).
 */
(function () {
  'use strict';

  function qs(root, sel) {
    return (root || document).querySelector(sel);
  }

  function qsa(root, sel) {
    return [].slice.call((root || document).querySelectorAll(sel));
  }

  function pushToast(kind, msg) {
    var T = window.OlliraToast;
    if (T && typeof T[kind] === 'function') {
      T[kind](msg);
    } else if (kind === 'success') {
      window.alert(msg);
    } else {
      window.alert(msg);
    }
  }

  function setBodyLock(on) {
    document.documentElement.classList.toggle('cli-merge-modal-open', on);
    document.body.classList.toggle('cli-merge-modal-open', on);
  }

  function openModal(modal) {
    if (!modal) return;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    setBodyLock(true);
    var dialog = qs(modal, '.cli-merge-modal__dialog');
    if (dialog) {
      dialog.focus();
    }
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    setBodyLock(false);
  }

  function stripMergeQueryFromUrl() {
    if (!window.history || !window.history.replaceState) return;
    try {
      var u = new URL(window.location.href);
      if (!u.searchParams.has('merge_primary') && !u.searchParams.has('merge_secondary')) {
        return;
      }
      u.searchParams.delete('merge_primary');
      u.searchParams.delete('merge_secondary');
      var q = u.searchParams.toString();
      window.history.replaceState({}, '', u.pathname + (q ? '?' + q : '') + u.hash);
    } catch (_) {
      /* ignore */
    }
  }

  function syncPrimaryFromSelection(modal) {
    var cards = qsa(modal, '[data-cli-merge-card]');
    var sel = cards.find(function (c) {
      return c.classList.contains('is-selected');
    });
    if (!sel || cards.length < 2) return;
    var primaryId = parseInt(sel.getAttribute('data-client-id') || '0', 10);
    var other = cards.find(function (c) {
      return c !== sel;
    });
    var secondaryId = other ? parseInt(other.getAttribute('data-client-id') || '0', 10) : 0;
    var pi = qs(modal, '#cli-merge-primary-id');
    var si = qs(modal, '#cli-merge-secondary-id');
    if (pi) pi.value = String(primaryId);
    if (si) si.value = String(secondaryId);
  }

  function selectCard(modal, card) {
    qsa(modal, '[data-cli-merge-card]').forEach(function (c) {
      var on = c === card;
      c.classList.toggle('is-selected', on);
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
      var hint = c.querySelector('.cli-merge-card__hint');
      if (hint) {
        hint.textContent = on ? 'Primary profile' : 'Tap to use as primary';
      }
    });
    syncPrimaryFromSelection(modal);
  }

  function initMergeModal() {
    var modal = document.getElementById('cli-merge-modal');
    if (!modal) return;

    var form = document.getElementById('cli-merge-action-form');
    var confirmBtn = document.getElementById('cli-merge-confirm');

    var bannerOpen = document.getElementById('cli-merge-banner-open');
    if (bannerOpen) {
      bannerOpen.addEventListener('click', function () {
        openModal(modal);
      });
    }

    qsa(modal, '[data-cli-merge-close]').forEach(function (el) {
      el.addEventListener('click', function () {
        closeModal(modal);
      });
    });

    qsa(modal, '[data-cli-merge-card]').forEach(function (card) {
      card.addEventListener('click', function () {
        selectCard(modal, card);
      });
    });

    modal.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        closeModal(modal);
      }
    });

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        syncPrimaryFromSelection(modal);
        var fd = new FormData(form);
        if (confirmBtn) {
          confirmBtn.disabled = true;
        }
        fetch(form.getAttribute('action') || '/clients/merge', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        })
          .then(function (res) {
            return res.json().then(function (data) {
              return { ok: res.ok, data: data };
            });
          })
          .then(function (pack) {
            var d = pack.data || {};
            if (pack.ok && d.success) {
              pushToast('success', d.message || 'Merge queued.');
              closeModal(modal);
            } else {
              pushToast('error', (d && d.message) || 'Merge could not be queued.');
            }
          })
          .catch(function () {
            pushToast('error', 'Merge could not be queued.');
          })
          .finally(function () {
            if (confirmBtn) {
              confirmBtn.disabled = false;
            }
          });
      });
    }

    if (modal.getAttribute('data-cli-merge-auto-open') === '1') {
      openModal(modal);
      stripMergeQueryFromUrl();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMergeModal);
  } else {
    initMergeModal();
  }
})();
