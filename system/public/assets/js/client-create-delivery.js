/**
 * New Client form: progressive disclosure for optional delivery address (full page + drawer-injected HTML).
 */
(function () {
  'use strict';

  function syncClientCreateDeliveryCheckbox(checkbox) {
    var form = checkbox.closest('.client-create-form');
    if (!form) {
      return;
    }
    var reveal = form.querySelector('.client-create-delivery-reveal');
    var inner = form.querySelector('.client-create-delivery-reveal__inner');
    var addr1 = form.querySelector('input[name="delivery_address_1"]');
    var fields = form.querySelectorAll('[data-client-delivery-field]');
    var on = checkbox.checked;

    if (reveal) {
      reveal.classList.toggle('client-create-delivery-reveal--open', on);
    }
    if (checkbox) {
      checkbox.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    if (inner) {
      if (on) {
        inner.removeAttribute('inert');
      } else {
        inner.setAttribute('inert', '');
      }
    }
    if (addr1) {
      if (on) {
        addr1.setAttribute('required', 'required');
      } else {
        addr1.removeAttribute('required');
      }
    }
    if (!on && fields && fields.length) {
      fields.forEach(function (el) {
        el.value = '';
        el.removeAttribute('aria-invalid');
      });
    }
  }

  function initClientCreateDelivery(scope) {
    var root = scope && scope.querySelector ? scope : document;
    var boxes = root.querySelectorAll('.js-client-create-add-delivery');
    boxes.forEach(function (cb) {
      syncClientCreateDeliveryCheckbox(cb);
    });
  }

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (!t || !t.classList || !t.classList.contains('js-client-create-add-delivery')) {
      return;
    }
    if (!(t instanceof HTMLInputElement) || t.type !== 'checkbox') {
      return;
    }
    syncClientCreateDeliveryCheckbox(t);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initClientCreateDelivery(document);
    });
  } else {
    initClientCreateDelivery(document);
  }

  window.initClientCreateDelivery = initClientCreateDelivery;
})();
