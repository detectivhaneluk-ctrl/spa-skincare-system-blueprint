# FOUNDATION-92 — Appointment show header context (wave 2)

**Program:** `APPOINTMENT-SHOW-HEADER-CONTEXT-WAVE-2`  
**Mode:** Narrow presentation-only; no behavior changes.

---

## Scope

**Presentation-only wave.** Touch only **`AppointmentService`**, **`AppointmentController::show`**, **`show.php`**, this OPS doc, and **`BOOKER-PARITY-MASTER-ROADMAP.md`**.

---

## What changed

- **`AppointmentService::getShowHeaderDatetimeDisplay`** returns **`display_date_only`** (from **`start_at`**: weekday + calendar date) and **`display_time_range`** (12-hour start–end from parsed **`start_at`** / **`end_at`**, or trimmed raw fallbacks when parsing fails). Uses PHP default timezone (same as **`ApplicationTimezone`**). Does not mutate stored values.
- **`AppointmentController::show`** merges those keys onto the appointment payload.
- **`show.php`** header subtitle is built from **`Appointment #ID`** plus optional **` · `**-separated non-empty **`display_date_only`** and **`display_time_range`** segments.

---

## Behavioral guarantees (explicit)

- **Raw `start_at` / `end_at` semantics** in the database and in forms are **unchanged**.
- **No booking, reschedule, pricing, VAT, checkout, or availability behavior** was changed.
- **No** schema, routes, calendar JSON, list/index, waitlist, public booking, invoice, or CSS asset edits.

---

## Deliverables

- Code (three files above).
- This OPS document.
- Roadmap row: **`BOOKER-PARITY-MASTER-ROADMAP.md`** (FOUNDATION-92).
- ZIP: **`distribution/spa-skincare-system-blueprint-FOUNDATION-92-APPOINTMENT-SHOW-HEADER-CONTEXT-WAVE-2-CHECKPOINT.zip`** (excludes `system/.env`, `system/.env.local`, `system/storage/logs/**`, `system/storage/backups/**`, `*.log`, nested `*.zip`).

---

## Stop

FOUNDATION-92 ends here.
