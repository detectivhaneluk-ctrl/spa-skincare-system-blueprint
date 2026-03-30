# FOUNDATION-93 — Appointment status label consistency (wave 3)

**Program:** `APPOINTMENT-STATUS-LABEL-CONSISTENCY-WAVE-3`  
**Mode:** Narrow presentation-only; no behavior changes.

---

## Scope

Admin appointments **list** and **show** surfaces only. Touch **`AppointmentService`**, **`AppointmentController`** (`index` / `show`), **`index.php`**, **`show.php`**, this OPS doc, and **`BOOKER-PARITY-MASTER-ROADMAP.md`**.

---

## What changed

- **`AppointmentService::formatStatusLabel(?string $status): string`** maps known stored values to human-readable labels (`scheduled` → **Scheduled**, `in_progress` → **In progress**, `no_show` → **No show**, etc.). Empty/whitespace → **—**; unknown non-empty values → trimmed raw string.
- **`AppointmentController::index`** sets **`status_label`** on each row via **`formatStatusLabel`**; raw **`status`** unchanged.
- **`AppointmentController::show`** sets **`status_label`** and **`status_select_labels`** (same formatter for each canonical value) so the **Update status** `<select>` can show humanized option text while **value** attributes stay snake_case.
- **`index.php`** — status pill **CSS class** derivation still uses **raw** **`status`**; visible pill text uses **`status_label`**.
- **`show.php`** — details **Status** row shows **`status_label`**; status form **values** unchanged.

---

## Behavioral guarantees (explicit)

- **Presentation-only wave.** Raw **`status`** values and **transition/validation rules** in **`AppointmentService`** are **unchanged**.
- **Only visible admin labels** changed for list/show status display and the status form option text.
- **No booking, pricing, VAT, checkout, or availability behavior** was changed.
- **No** schema, routes, calendar JSON, waitlist, public booking, invoice, or CSS asset edits.

---

## Deliverables

- Code (five files above).
- This OPS document.
- Roadmap row: **`BOOKER-PARITY-MASTER-ROADMAP.md`** (FOUNDATION-93).
- ZIP: **`distribution/spa-skincare-system-blueprint-FOUNDATION-93-APPOINTMENT-STATUS-LABEL-CONSISTENCY-WAVE-3-CHECKPOINT.zip`** (excludes `system/.env`, `system/.env.local`, `system/storage/logs/**`, `system/storage/backups/**`, `*.log`, nested `*.zip`).

---

## Stop

FOUNDATION-93 ends here.
