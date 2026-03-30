# FOUNDATION-94 — Appointments list status filter (wave 4)

**Program:** `APPOINTMENTS-LIST-STATUS-FILTER-WAVE-4`  
**Mode:** Narrow operator-visible improvement; no schema; no refactor.

---

## Scope

**`AppointmentController::index`**, **`modules/appointments/views/index.php`**, this OPS doc, **`BOOKER-PARITY-MASTER-ROADMAP.md`** only.

---

## Repository (pre-existing)

**`AppointmentRepository::list`** and **`::count`** already apply **`$filters['status']`** when set (`AND a.status = ?` / `AND status = ?`). This wave **does not** change the repository.

---

## What changed

- **`AppointmentController::index`** reads **`status`** from **GET**, allows only the six canonical values (`scheduled`, `confirmed`, `in_progress`, `completed`, `cancelled`, `no_show`), ignores invalid or empty values, and passes a valid value through **`$filters['status']`** to **`list`** / **`count`**. Branch, date range, and pagination logic are unchanged.
- **`status_filter_labels`** is built with **`AppointmentService::formatStatusLabel()`** for each allowed value (humanized option text).
- **`status_filter_selected`** is the validated filter value or empty string for “all”.
- **`index.php`** adds a **Status** `<select>` inside the existing GET filter form: **All statuses** (empty value), raw snake_case **values**, humanized **labels**; selection follows **`status_filter_selected`**. Table, row actions, and day-calendar link behavior are unchanged.
- Empty state copy lightly updated so it reads naturally when status (or any filter) narrows the result set.

---

## Behavioral guarantees (explicit)

- **Raw stored `appointments.status` values** and domain transition rules are **unchanged**.
- **This wave only exposes** the existing repository status filter in the **admin list** controller + UI.
- **No booking, pricing, VAT, checkout, or availability behavior** was changed.
- **No** schema, routes, calendar JSON, show/edit/waitlist/public booking, invoice, or CSS asset edits.

---

## Deliverables

- Code (two files above).
- This OPS document.
- Roadmap row: **`BOOKER-PARITY-MASTER-ROADMAP.md`** (FOUNDATION-94).
- ZIP: **`distribution/spa-skincare-system-blueprint-FOUNDATION-94-APPOINTMENTS-LIST-STATUS-FILTER-WAVE-4-CHECKPOINT.zip`** (excludes `system/.env`, `system/.env.local`, `system/storage/logs/**`, `system/storage/backups/**`, `*.log`, nested `*.zip`).

---

## Stop

FOUNDATION-94 ends here.
