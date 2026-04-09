/**
 * New Client: inline hint when mobile phone matches an existing client (full page + drawer).
 * Uses delegated listeners so injected drawer HTML works without inline scripts.
 */
(function () {
  'use strict';

  var DEBOUNCE_MS = 420;
  var MIN_DIGITS = 7;

  function digitsOnly(s) {
    return String(s || '').replace(/\D/g, '');
  }

  function appendDescribedBy(input, id) {
    if (!input || !id) return;
    var cur = (input.getAttribute('aria-describedby') || '').trim();
    var parts = cur ? cur.split(/\s+/) : [];
    if (parts.indexOf(id) === -1) {
      parts.push(id);
      input.setAttribute('aria-describedby', parts.join(' ').trim());
    }
  }

  function removeDescribedBy(input, id) {
    if (!input || !id) return;
    var cur = (input.getAttribute('aria-describedby') || '').trim();
    if (!cur) return;
    var next = cur
      .split(/\s+/)
      .filter(function (x) {
        return x && x !== id;
      })
      .join(' ')
      .trim();
    if (next) {
      input.setAttribute('aria-describedby', next);
    } else {
      input.removeAttribute('aria-describedby');
    }
  }

  function setHintVisible(hint, visible) {
    if (!hint) return;
    hint.hidden = !visible;
    hint.classList.toggle('is-visible', visible);
  }

  function renderHint(hint, profileUrl) {
    if (!hint) return;
    hint.innerHTML =
      'This phone number is already associated with an existing client. ' +
      '<a class="client-create-phone-dedupe-hint__link" href="' +
      profileUrl +
      '">View Profile</a>';
  }

  function clearHint(input, hint) {
    if (hint) {
      hint.innerHTML = '';
    }
    setHintVisible(hint, false);
    if (input) {
      removeDescribedBy(input, 'client-phone-dedupe-hint');
    }
  }

  function findState(input) {
    var form = input.closest('.client-create-form');
    if (!form) return null;
    var hint = form.querySelector('#client-phone-dedupe-hint');
    if (!hint) return null;
    var url = (input.getAttribute('data-phone-check-url') || '').trim() || '/clients/phone-exists';
    return { form: form, hint: hint, url: url };
  }

  function scheduleCheck(input) {
    var st = findState(input);
    if (!st) return;

    if (typeof input._phoneDedupeSeq !== 'number') {
      input._phoneDedupeSeq = 0;
    }
    input._phoneDedupeSeq += 1;
    var seq = input._phoneDedupeSeq;

    if (!input._phoneDedupeTimer) {
      input._phoneDedupeTimer = null;
    }
    if (input._phoneDedupeAbort) {
      try {
        input._phoneDedupeAbort.abort();
      } catch (e) {}
    }
    input._phoneDedupeAbort = typeof AbortController !== 'undefined' ? new AbortController() : null;

    window.clearTimeout(input._phoneDedupeTimer);
    input._phoneDedupeTimer = window.setTimeout(function () {
      input._phoneDedupeTimer = null;
      runCheck(input, st, seq);
    }, DEBOUNCE_MS);
  }

  function runCheck(input, st, seq) {
    if (typeof seq === 'number' && input._phoneDedupeSeq !== seq) {
      return;
    }
    var raw = (input.value || '').trim();
    var d = digitsOnly(raw);
    if (d.length < MIN_DIGITS) {
      clearHint(input, st.hint);
      return;
    }

    var ac = input._phoneDedupeAbort;
    var signal = ac ? ac.signal : undefined;
    var sep = st.url.indexOf('?') >= 0 ? '&' : '?';
    var reqUrl = st.url + sep + 'phone=' + encodeURIComponent(raw);

    fetch(reqUrl, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      signal: signal,
    })
      .then(function (res) {
        return res.json();
      })
      .then(function (data) {
        if (typeof seq === 'number' && input._phoneDedupeSeq !== seq) {
          return;
        }
        if (!data || !data.match || !data.client_id) {
          clearHint(input, st.hint);
          return;
        }
        var id = parseInt(String(data.client_id), 10);
        if (!id) {
          clearHint(input, st.hint);
          return;
        }
        renderHint(st.hint, '/clients/' + id);
        setHintVisible(st.hint, true);
        appendDescribedBy(input, 'client-phone-dedupe-hint');
      })
      .catch(function () {
        clearHint(input, st.hint);
      });
  }

  function initClientCreatePhoneDedupe(scope) {
    var root = scope && scope.querySelector ? scope : document;
    root.querySelectorAll('.js-client-phone-dedupe-input').forEach(function (el) {
      if (!(el instanceof HTMLInputElement)) return;
      scheduleCheck(el);
    });
  }

  document.addEventListener('input', function (e) {
    var t = e.target;
    if (!t || !t.classList || !t.classList.contains('js-client-phone-dedupe-input')) return;
    if (!(t instanceof HTMLInputElement)) return;
    scheduleCheck(t);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initClientCreatePhoneDedupe(document);
    });
  } else {
    initClientCreatePhoneDedupe(document);
  }

  window.initClientCreatePhoneDedupe = initClientCreatePhoneDedupe;
})();
