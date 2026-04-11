/**
 * Global HTML5 constraint validation UI (replaces browser-native bubbles / locale tooltips).
 * - English copy (operator UI)
 * - Design-system inline messages + focus management
 *
 * Opt out: <form novalidate> or control data-skip-constraint-validation-ui
 */
(() => {
  const CLASS_MSG = 'ds-constraint-validation-msg';
  const CLASS_BAD = 'ds-constraint-validation-target--invalid';
  const ATTR_MSG = 'data-ds-constraint-validation';
  const ATTR_BACKUP = 'data-ds-cv-desc-backup';
  const ATTR_MSGID = 'data-ds-cv-msgid';

  let seq = 0;
  let focusScheduled = false;

  function isSubmittable(el) {
    return (
      el &&
      (el instanceof HTMLInputElement ||
        el instanceof HTMLSelectElement ||
        el instanceof HTMLTextAreaElement)
    );
  }

  function shouldDecorate(el) {
    if (!isSubmittable(el) || !el.willValidate) return false;
    const form = el.form;
    if (form && form.hasAttribute('novalidate')) return false;
    if (el.hasAttribute('data-skip-constraint-validation-ui')) return false;
    return true;
  }

  function resolveMessage(el) {
    const v = el.validity;
    if (v.customError && el.validationMessage) {
      return el.validationMessage;
    }
    if (v.valueMissing) {
      if (el instanceof HTMLSelectElement) return 'Please select an option.';
      const t = (el.type || '').toLowerCase();
      if (t === 'checkbox' || t === 'radio') return 'This option is required.';
      return 'This field is required.';
    }
    if (v.typeMismatch) {
      const t = (el.type || '').toLowerCase();
      if (t === 'email') return 'Enter a valid email address.';
      if (t === 'url') return 'Enter a valid URL.';
      return 'The value does not match the expected format.';
    }
    if (v.patternMismatch) return 'The value does not match the required pattern.';
    if (v.tooShort) {
      const m = el.getAttribute('minlength');
      return m ? `Enter at least ${m} characters.` : 'This value is too short.';
    }
    if (v.tooLong) {
      const m = el.getAttribute('maxlength');
      return m ? `Use at most ${m} characters.` : 'This value is too long.';
    }
    if (v.rangeUnderflow) return 'The value is too low.';
    if (v.rangeOverflow) return 'The value is too high.';
    if (v.stepMismatch) return 'The value does not match the allowed step.';
    if (v.badInput) return 'Enter a valid value.';
    if (el.validationMessage) return el.validationMessage;
    return 'This value is not valid.';
  }

  function insertMessageAfterControl(control, msgEl) {
    const t = (control.type || '').toLowerCase();
    if (t === 'radio') {
      const rGroup = control.closest('.staff-create-radio-group, fieldset');
      if (rGroup) {
        rGroup.insertAdjacentElement('afterend', msgEl);
        return;
      }
    }
    const lbl = control.closest('label');
    if (lbl && lbl.contains(control) && lbl !== control) {
      lbl.insertAdjacentElement('afterend', msgEl);
      return;
    }
    const group = control.closest('.apl-form-group, .form-row, .staff-create-field, .entity-form .form-row');
    if (group) {
      const hint = group.querySelector('.apl-form-hint, .staff-create-hint');
      if (hint && control.compareDocumentPosition(hint) & Node.DOCUMENT_POSITION_FOLLOWING) {
        group.insertBefore(msgEl, hint);
        return;
      }
    }
    control.insertAdjacentElement('afterend', msgEl);
  }

  function clearDecoration(control) {
    const id = control.getAttribute(ATTR_MSGID);
    if (id) {
      const node = document.getElementById(id);
      if (node) node.remove();
      control.removeAttribute(ATTR_MSGID);
    }
    control.classList.remove(CLASS_BAD);
    control.removeAttribute('aria-invalid');
    if (control.hasAttribute(ATTR_BACKUP)) {
      const back = control.getAttribute(ATTR_BACKUP);
      if (back) control.setAttribute('aria-describedby', back);
      else control.removeAttribute('aria-describedby');
      control.removeAttribute(ATTR_BACKUP);
    }
  }

  function showDecoration(control) {
    if (control.checkValidity()) {
      clearDecoration(control);
      return;
    }
    clearDecoration(control);

    const msg = resolveMessage(control);
    const id = `ds-cv-msg-${++seq}`;
    const p = document.createElement('p');
    p.id = id;
    p.className = CLASS_MSG;
    p.setAttribute(ATTR_MSG, '');
    p.setAttribute('role', 'alert');
    p.textContent = msg;

    control.setAttribute(ATTR_MSGID, id);
    control.classList.add(CLASS_BAD);
    control.setAttribute('aria-invalid', 'true');

    const existing = control.getAttribute('aria-describedby');
    if (!control.hasAttribute(ATTR_BACKUP)) {
      control.setAttribute(ATTR_BACKUP, existing || '');
    }
    const parts = (existing || '').split(/\s+/).filter(Boolean);
    if (!parts.includes(id)) parts.push(id);
    control.setAttribute('aria-describedby', parts.join(' '));

    insertMessageAfterControl(control, p);
  }

  function scheduleFocusFirstInvalid(form) {
    if (!form || focusScheduled) return;
    focusScheduled = true;
    queueMicrotask(() => {
      focusScheduled = false;
      const inv = form.querySelector(':invalid');
      if (inv && shouldDecorate(inv) && typeof inv.focus === 'function') {
        try {
          inv.focus({ preventScroll: false });
        } catch (_) {
          inv.focus();
        }
      }
    });
  }

  document.addEventListener(
    'invalid',
    (e) => {
      const el = e.target;
      if (!shouldDecorate(el)) return;
      e.preventDefault();
      showDecoration(el);
      const form = el.form;
      if (form) scheduleFocusFirstInvalid(form);
    },
    true
  );

  function maybeClear(e) {
    const el = e.target;
    if (!shouldDecorate(el)) return;
    if (!el.classList.contains(CLASS_BAD)) return;
    if (el.checkValidity()) {
      clearDecoration(el);
    }
  }

  document.addEventListener('input', maybeClear, true);
  document.addEventListener('change', maybeClear, true);

  window.OlliraConstraintValidation = {
    clearField(el) {
      if (el && isSubmittable(el)) clearDecoration(el);
    },
    clearForm(form) {
      if (!form || form.tagName !== 'FORM') return;
      form.querySelectorAll(`[${ATTR_MSGID}]`).forEach((node) => {
        if (isSubmittable(node)) clearDecoration(node);
      });
      form.querySelectorAll(`.${CLASS_BAD}`).forEach((node) => {
        if (isSubmittable(node)) clearDecoration(node);
      });
    },
    refreshField(el) {
      if (!shouldDecorate(el)) return;
      if (!el.checkValidity()) showDecoration(el);
      else clearDecoration(el);
    },
  };
})();
