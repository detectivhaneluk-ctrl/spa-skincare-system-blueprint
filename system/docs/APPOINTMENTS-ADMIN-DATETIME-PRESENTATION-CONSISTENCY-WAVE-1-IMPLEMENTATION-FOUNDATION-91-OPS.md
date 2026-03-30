# FOUNDATION-91 — Appointments admin datetime presentation consistency (wave 1)

**Program:** `APPOINTMENTS-ADMIN-DATETIME-PRESENTATION-CONSISTENCY-WAVE-1-IMPLEMENTATION`  
**Charter:** FOUNDATION-90 **Charter C** — operator-visible datetime/summary presentation consistency (list + show).

---

## Scope

**Presentation-only.** No schema, routes, query/filter behavior, calendar JSON, slots, waitlist, public booking, invoice/service-description, or CSS asset changes.

---

## What changed

- **`AppointmentService::getDisplaySummary`** remains the single source of truth for list and show **summary** text (`Client @ …`). The time segment uses a **human-readable** formatted value derived from stored `start_at` (via service-local **`formatAppointmentDisplayDateTime`**) instead of echoing the raw DB-shaped string. If parsing fails, the **non-empty raw** `start_at` string is shown (same safety as before; never replaced with empty when input was non-empty).
- **`AppointmentService::getShowDatetimeDisplay`** — presentation-only helper returning **`display_start_at`** and **`display_end_at`** formatted strings for the admin show page detail rows. Does not mutate repository rows.
- **`AppointmentController::show`** merges those display keys onto the `$appointment` payload. Raw **`start_at`** / **`end_at`** are unchanged for **`datetime-local`** and downstream logic.
- **`show.php`** — only the visible **Start** / **End** detail rows render **`display_start_at`** / **`display_end_at`**; reschedule and other forms still use raw stored values.

**Files:**

- `system/modules/appointments/services/AppointmentService.php`
- `system/modules/appointments/controllers/AppointmentController.php`
- `system/modules/appointments/views/show.php`

**List index:** continues to use **`display_summary`** from the existing controller loop; no **`index.php`** change required.

---

## Behavioral guarantees (explicit)

- **This wave is presentation-only.** Stored **`appointments.start_at`** / **`end_at`** semantics and all booking/slot/pricing/VAT/checkout/waitlist/public-booking logic are **unchanged**.
- **PHP default timezone** for `date()` / `strtotime()` comes from existing **`ApplicationTimezone`** behavior (establishment/branch merge → `date_default_timezone_set`); **no** custom timezone overrides were added in this wave.
- **List summary** and **show** detail times now share the same human-readable datetime formatting source (**`formatAppointmentDisplayDateTime`** inside **`AppointmentService`**, also feeding **`getDisplaySummary`**).
- **Calendar JSON** (`dayCalendar`), **slots** API, **waitlist**, **public booking**, and **invoice** flows were **not** modified.

---

## Format

Display strings use a fixed locale-neutral English pattern: full weekday, full month name, day, year, 12-hour time with AM/PM (e.g. `Monday, March 23, 2025 at 2:30 PM`), interpreted in the active default timezone.

---

## Deliverables

- Code changes (three files above).
- This OPS document.
- Roadmap row: `BOOKER-PARITY-MASTER-ROADMAP.md` (FOUNDATION-91).
- ZIP: `distribution/spa-skincare-system-blueprint-FOUNDATION-91-APPOINTMENTS-ADMIN-DATETIME-PRESENTATION-CONSISTENCY-WAVE-1-CHECKPOINT.zip` (excludes `.env`, `.env.local`, `system/storage/logs/**`, `system/storage/backups/**`, `*.log`, nested `*.zip` where applicable).

---

## Stop

FOUNDATION-91 ends here; further calendar UI parity or i18n are out of scope unless tasked separately.
