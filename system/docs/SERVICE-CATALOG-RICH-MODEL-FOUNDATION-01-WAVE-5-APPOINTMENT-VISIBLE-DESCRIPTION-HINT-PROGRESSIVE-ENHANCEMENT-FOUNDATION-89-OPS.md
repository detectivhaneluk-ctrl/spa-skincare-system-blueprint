# FOUNDATION-89 — Wave-5 appointment visible description hint (progressive enhancement)

**Program:** `SERVICE-CATALOG-RICH-MODEL-FOUNDATION-01-WAVE-5-APPOINTMENT-VISIBLE-DESCRIPTION-HINT-PROGRESSIVE-ENHANCEMENT`  
**Mode:** Narrow implementation, display-only.

**Scope:** Same four appointment views from FOUNDATION-88 only:

- `modules/appointments/views/create.php`
- `modules/appointments/views/edit.php`
- `modules/appointments/views/waitlist.php`
- `modules/appointments/views/waitlist-create.php`

---

## What changed

This wave is a **progressive enhancement over FOUNDATION-88**.

- FOUNDATION-88 introduced optional `<option title="...">` metadata for service rows.
- FOUNDATION-89 keeps that metadata as-is and adds a small, visible hint element below each service `<select>`.
- Minimal inline JS in each touched view reads only the **selected option `title`** and mirrors it into the hint.

### Hint behavior

- On initial page load: selected option `title` is read and displayed when non-empty.
- On service selection change: hint updates immediately.
- If the selected option has empty/no `title`: hint text is cleared and hidden.

### Source of truth

The visible hint is sourced **only** from selected option title metadata (`option.title`).

No new backend fields, provider changes, or data attributes were added in this wave.

---

## Explicit non-changes

- **Visible option labels remain unchanged**.
- No booking logic changed.
- No POST shape changed.
- No duration auto-calc behavior changed.
- No pricing or VAT behavior changed.
- No checkout behavior changed.
- No invoice semantics changed.
- No `AppointmentController`, `parseInput()`, slot-loading fetch behavior, `AppointmentCheckoutProviderImpl`, `AvailabilityService`, `ServiceListProvider`, or `ServiceListProviderImpl` changes.
- No routes, schema, migrations, or CSS asset changes.

---

## Progressive enhancement note

Browsers with JavaScript disabled still keep FOUNDATION-88 behavior (title-only metadata on `<option>`). This wave adds visible hints only when JS runs.

---

## Deliverables

- View changes only in the four files above.
- This OPS document.
- Roadmap append: FOUNDATION-89 row in `BOOKER-PARITY-MASTER-ROADMAP.md`.
- ZIP: `distribution/spa-skincare-system-blueprint-FOUNDATION-89-WAVE-5-APPOINTMENT-VISIBLE-DESCRIPTION-HINT-CHECKPOINT.zip`.

---

## Stop

FOUNDATION-89 ends here. No further scope opened in this wave.
