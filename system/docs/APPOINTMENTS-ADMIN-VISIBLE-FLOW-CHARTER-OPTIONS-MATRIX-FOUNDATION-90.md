# Appointments admin visible-flow charter options — FOUNDATION-90

Read-only. Compares **A / B / C / D** only. Service-description lane treated **closed** after FOUNDATION-89 unless a hard dependency appears (none found in this audit).

## Evidence anchor (visible gap)

| Finding | Location |
|---------|----------|
| List + show use `getDisplaySummary()` | `AppointmentController::index` / `show` assign `display_summary` via `AppointmentService::getDisplaySummary` |
| Summary uses raw `start_at` | `AppointmentService::getDisplaySummary` |
| Detail shows raw `start_at` / `end_at` | `modules/appointments/views/show.php` |
| Day calendar renders readable time ranges in JS | `modules/appointments/views/calendar-day.php` (e.g. `timeRangeLabel`, `fmtFromDt`) |

## Inventory — admin surfaces (summary)

| Surface | View(s) | Controller entrypoints |
|---------|-----------|-------------------------|
| List | `index.php` | `index()` |
| Create | `create.php` | `create()`, `store`, `storeFromCreatePath` |
| Edit | `edit.php` | `edit()`, `update` |
| Show / details | `show.php` | `show()` |
| Day calendar | `calendar-day.php` | `dayCalendarPage()`, `dayCalendar()` JSON |
| Waitlist | `waitlist.php`, `waitlist-create.php` | `waitlistPage`, `waitlistCreate`, `waitlistStore`, actions |
| Slot loading | inline JS in `create.php` | `slots()` |

## Charter definitions

| ID | Charter |
|----|---------|
| **A** | Appointments **admin usability / operator flow** (navigation, filters, defaults, fewer clicks) |
| **B** | **Waitlist** operational flow (queue, convert, link, status) |
| **C** | Appointment **details / summary consistency** (same facts, readable presentation, esp. datetimes) |
| **D** | **STOP** — no justified charter |

## Gate comparison (higher is better for “safest high-impact”)

Legend: **●●●** strong, **●●** ok, **●** weaker for this goal.

| Gate | A — Usability / flow | B — Waitlist ops | C — Detail / summary consistency | D — STOP |
|------|------------------------|------------------|-----------------------------------|----------|
| User-visible value | **●●** (depends on default-filter choice) | **●●** | **●●●** (every list row + detail) | — |
| Backend safety | **●●** (filter defaults change queries) | **●●** (action-heavy) | **●●●** (display-only if scoped) | **●●●** |
| Blast radius | **●●** | **●●** | **●●●** (tight if service-first) | **●●●** |
| Coupling risk | **●●** | **●** (forms + permissions) | **●●●** | **●●●** |
| Implementation narrowness | **●●** | **●●** | **●●●** | n/a |
| Semantic drift risk | **●** (default list semantics) | **●●** | **●●●** (presentation only) | **●●●** |

## Ranking outcome

1. **C** — Best fit: **high visibility** (list + show), **no schema**, **no invoice**, **no service-description**, aligns list/detail with calendar’s time literacy.  
2. **A** — Good if product wants **behavioral** list defaults; higher drift risk.  
3. **B** — Strong ops value but **wider** coupling in `waitlist.php` / actions.  
4. **D** — Not required; **C** is justified.

## Single OPS recommendation

**Charter C** — see `APPOINTMENTS-ADMIN-VISIBLE-FLOW-NEXT-HIGH-IMPACT-CHARTER-SELECTION-TRUTH-AUDIT-FOUNDATION-90-OPS.md`.

## Likely first-wave files (if Charter C is implemented)

| File | Role |
|------|------|
| `modules/appointments/services/AppointmentService.php` | `getDisplaySummary` + optional shared format helper |
| `modules/appointments/controllers/AppointmentController.php` | Only if passing formatted fields to `show` / index |
| `modules/appointments/views/show.php` | Raw `start_at`/`end_at` → formatted display |

`modules/appointments/views/index.php` may inherit improved `display_summary` with **no** markup change.
