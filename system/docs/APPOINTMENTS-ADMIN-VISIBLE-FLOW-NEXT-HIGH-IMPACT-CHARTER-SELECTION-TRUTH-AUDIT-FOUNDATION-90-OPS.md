# FOUNDATION-90 — Appointments admin visible-flow next high-impact charter selection (read-only)

**Program:** `APPOINTMENTS-ADMIN-VISIBLE-FLOW-NEXT-HIGH-IMPACT-CHARTER-SELECTION-TRUTH-AUDIT`  
**Mode:** Read-only. No code, views, schema, or routes.  
**Intent:** Intentionally **exit** the service-catalog **description** micro-lane (FOUNDATION-81→89) and pick the **single safest** next appointments-admin charter with **more visible product surface** than FOUNDATION-89 (service-select hint text).

**Matrix:** `APPOINTMENTS-ADMIN-VISIBLE-FLOW-CHARTER-OPTIONS-MATRIX-FOUNDATION-90.md`.

---

## 1) Service-description lane status

**CLOSED after FOUNDATION-89** for default planning purposes:

- **FOUNDATION-88** — `<option title>` from catalog description.  
- **FOUNDATION-89** — visible hint under service selects (JS reads `option.title` only).  
- **FOUNDATION-85** — `ServiceListProvider` exposes nullable `description`; no further consumer work is **required** for correctness.

**No blocking dependency** surfaced in this audit that forces reopening catalog-description work for appointments-admin flows. Further description UX is **optional** and **out of scope** for this charter pick.

---

## 2) Inventory — current appointments admin surfaces (grep- and route-backed)

**Controller (single class):** `modules/appointments/controllers/AppointmentController.php` — index, create, store, storeFromCreatePath, show, edit, update, destroy, cancel/reschedule/status actions, package consume, **day calendar page**, **JSON day calendar**, **slots**, staff availability, **waitlist** list/create/store/actions, **blocked slots**, **series** actions, etc.

**Routes:** `system/routes/web/register_appointments_calendar.php` — staff-facing URLs under `/appointments` (list, create, edit, show, calendar day, slots, waitlist, blocked slots, series, etc.); separate `GET /calendar/day` for JSON calendar payload.

**Views (`modules/appointments/views/`):**

| View | Role |
|------|------|
| `index.php` | List + filters (branch, date range) + links to calendar / create |
| `create.php` | New booking + slot load JS (`GET /appointments/slots`) |
| `edit.php` | Full edit form |
| `show.php` | Details, reschedule/status forms, package consumption UI |
| `calendar-day.php` | Day grid + blocked-slot panel + large inline JS for `/calendar/day` JSON |
| `waitlist.php` | Queue filters + table + inline actions |
| `waitlist-create.php` | New waitlist entry |
| `partials/workspace-shell.php` | Workspace chrome / tabs |

**Repositories / services:** `AppointmentRepository`, `WaitlistRepository`, `BlockedSlotRepository`, `AppointmentSeriesRepository`; `AppointmentService`, `AvailabilityService`, `WaitlistService`, `BlockedSlotService`, `AppointmentSeriesService`. **Providers:** `AppointmentCheckoutProviderImpl`, `ClientAppointmentProfileProviderImpl` (not staff list UI).

**Core contracts:** Appointments module consumes shared contracts (`ServiceListProvider`, etc.); no appointment-specific contract required for this audit’s charter comparison.

---

## 3) Highest-impact visible gap (code-backed)

**`AppointmentService::getDisplaySummary()`** concatenates client name with the **raw** `start_at` string from the row:

```572:576:system/modules/appointments/services/AppointmentService.php
    public function getDisplaySummary(array $apt): string
    {
        $client = trim(($apt['client_first_name'] ?? '') . ' ' . ($apt['client_last_name'] ?? ''));
        $date = $apt['start_at'] ?? '';
        return $client . ' @ ' . $date;
    }
```

That summary drives the **list** primary column (`display_summary` in `index.php`) and the **show** page title / header (`show.php` uses `display_summary`).

**`show.php`** also prints **raw** `start_at` / `end_at` in the details `<dl>` (lines 108–114 in tree), while the **day calendar** UI already computes readable time ranges in JS for the grid.

**Gap:** Operators see **DB-shaped datetimes** in the primary list/detail narrative, while the **calendar** experience is already **time-centric and readable**. Aligning **list + detail** presentation with **human-readable, timezone-aware** formatting (using existing `ApplicationTimezone` / branch establishment settings — see `system/core/app/ApplicationTimezone.php`) is a **high daily-impact, visible** improvement that does **not** require schema, invoice coupling, or service-description semantics.

---

## 4) Charter comparison (A / B / C / D only)

| Type | Summary |
|------|---------|
| **A** | Admin usability / operator flow (e.g. list defaults, navigation shortcuts) |
| **B** | Waitlist operational flow (queue actions, convert/link UX) |
| **C** | Appointment details / summary **consistency** (display formatting, same facts everywhere) |
| **D** | **STOP** — no safe charter justified now |

See matrix for gate scores.

---

## 5) Ranking (gates only)

Winner on **user-visible value** + **narrowness** + **low semantic drift** (presentation-only): **C**.

- **A** (e.g. default list to “today”) changes **which rows load** without filters — higher **semantic** and **product** risk.  
- **B** improves ops but often couples to **edit/create** validation and **permissions**; convert/link flows already dense in `waitlist.php`.  
- **D** unnecessary — **C** is justified without schema.

---

## 6) Single recommendation (only)

**Charter C — Operator-visible appointment datetime / summary presentation consistency**

- **First implementation wave (likely files):**
  - `system/modules/appointments/services/AppointmentService.php` — extend or replace formatting used by `getDisplaySummary()` (and optionally a small shared private helper for datetime display).
  - `system/modules/appointments/controllers/AppointmentController.php` — only if the show action must attach **formatted** `start_at`/`end_at` for the view (prefer keeping formatting in the service layer for one source of truth).
  - `system/modules/appointments/views/show.php` — replace raw `start_at`/`end_at` in the details block with formatted values **if** not provided via controller.
- **`index.php`** — typically **no** change if `display_summary` already reflects the improved string from the controller.

**Explicitly not in this charter:** service catalog `description`, invoice/checkout, `AvailabilityService` SQL, public booking.

---

## 7) Waivers

| ID | Note |
|----|------|
| **W-90-1** | Display formatting must respect **`ApplicationTimezone`** / branch settings; a future implementation should cross-check **BKM-005** behavior. |
| **W-90-2** | This audit does not assert production data timezone storage; charter implementation must preserve **stored** `start_at`/`end_at` semantics. |

---

## 8) Deliverables

- This OPS document.  
- Matrix: `APPOINTMENTS-ADMIN-VISIBLE-FLOW-CHARTER-OPTIONS-MATRIX-FOUNDATION-90.md`.  
- Roadmap row FOUNDATION-90.  
- ZIP: `distribution/spa-skincare-system-blueprint-FOUNDATION-90-APPOINTMENTS-ADMIN-VISIBLE-FLOW-NEXT-HIGH-IMPACT-CHARTER-SELECTION-CHECKPOINT.zip`.

---

## 9) Stop

FOUNDATION-90 ends here; no implementation opened.
