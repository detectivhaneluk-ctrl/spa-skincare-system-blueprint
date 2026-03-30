# SETTINGS-ESTABLISHMENT-MECHANISM-TRUTH-AUDIT-01

Date: 2026-03-24  
Status: AUDIT ONLY (truth mapping; no implementation)

## 1) Current contradiction summary

1. The current `settings` establishment experience is merged into one long screen in `system/modules/settings/views/index.php` (overview + multiple "edit" blocks + related operations), not a focused multi-screen mechanism.
2. "Manage Hours" and "Manage Closure Dates" links currently point to `/branches`, but `/branches` is branch catalog CRUD only (name/code), not hours/closure operations.
3. Several fields are marked "Backend pending" in the view before proving repo truth. Some are truly missing, some are represented in other domains, and some are only partially represented.
4. The canonical mechanism (overview -> focused edit screen -> save/cancel -> return) is not currently expressed as dedicated settings screens/routes/state; only one `section=establishment` workspace exists.

## 2) Confirmed current write-backed establishment fields (current settings flow only)

Source of truth:
- `system/modules/settings/controllers/SettingsController.php` (`ESTABLISHMENT_WRITE_KEYS`, scoped post filtering, `patchEstablishmentSettings`)
- `system/core/app/SettingsService.php` (`ESTABLISHMENT_KEYS`, `patchEstablishmentSettings`)

Exactly write-backed today via `POST /settings` when `section=establishment`:
- `establishment.name`
- `establishment.phone`
- `establishment.email`
- `establishment.address`
- `establishment.currency`
- `establishment.timezone`
- `establishment.language`

No other establishment/contact/hours/closure fields are written by current settings establishment flow.

## 3) Full reference field/block source map (A/B/C/D)

Legend:
- A = backed by current settings flow
- B = backed elsewhere in repo (not current settings write flow)
- C = visible/readable elsewhere in repo but not editable from settings today
- D = not found in repo truth yet

| Field / block | Class | Repo truth evidence |
|---|---|---|
| establishment name | A | `settings[establishment.name]` in `system/modules/settings/views/index.php`; saved in `SettingsController::store`; persisted by `SettingsService::patchEstablishmentSettings` |
| address line 1 | A (as single `address`) | Only `establishment.address` exists and is writable; no split line1/line2 keys in settings service/controller |
| address line 2 | D | No `establishment.address2` or equivalent key/column/write path found |
| city | D | No establishment city field/key/write path found |
| postal code | D | No establishment postal code field/key/write path found |
| country | D | No establishment country field/key/write path found |
| phone | A | `establishment.phone` write-backed in settings controller/service |
| website | D | No establishment website key/write path in settings service/controller; only placeholder text in view |
| display name | C | `display_name` exists for clients/staff computed presentation (`ClientController`, `StaffController`), not establishment settings field |
| description | D | No establishment description key/write path found |
| tax/fiscal id | D | No establishment tax/fiscal-id key/write path found |
| SIRET/company id | D | No establishment SIRET/company-id key/write path found |
| bank details | D | No establishment bank-details key/write path found |
| primary contact first name | D | No dedicated primary contact model/field for establishment found |
| primary contact last name | D | No dedicated primary contact model/field for establishment found |
| primary contact phone | A (mapped to generic establishment phone) | `establishment.phone` is writable; no separate primary-contact structure exists |
| primary contact email | A (mapped to generic establishment email) | `establishment.email` is writable; no separate primary-contact structure exists |
| secondary contact first name | D | No secondary contact storage/write path found |
| secondary contact last name | D | No secondary contact storage/write path found |
| secondary contact phone | D | No secondary contact storage/write path found |
| secondary contact email | D | No secondary contact storage/write path found |
| opening hours/week schedule | B | Backed in staff domain (`staff_schedules` table, `StaffScheduleRepository`, routes `/staff/{id}/schedules*`) |
| closure dates list | B | Date-level blocking exists via appointments blocked slots (`appointment_blocked_slots`, `BlockedSlotRepository`, `/appointments/calendar/day`) |
| add/edit/delete closure date capability | B (partial semantics) | Add/delete exists for blocked slots (`POST /appointments/blocked-slots`, `/appointments/blocked-slots/{id}/delete`); no dedicated establishment "closure dates" module semantics/route |

Important nuance for hours/closures:
- Repo has **staff-level** weekly schedules and day blocks, not a proven establishment-level weekly opening-hours model.
- Repo has **blocked-slot CRUD** in appointments calendar, which can represent closure-like windows, but not a dedicated "establishment closure dates" bounded aggregate.

## 4) Route/screen truth table (for requested 6-screen mechanism)

Legend:
- A = dedicated route already exists
- B = same route can support it with subview/state and existing backend
- C = backend exists but route/screen does not
- D = neither route nor backend proof found

| Target screen | Class | Truth |
|---|---|---|
| Establishment overview screen | B | Existing `/settings?section=establishment` can host an overview substate; route exists but currently merged with edit blocks |
| Edit Establishment Overview screen | B | Same `/settings?section=establishment` route already has write backend for establishment keys; can be split into focused subview/state without backend change |
| Edit Primary Contact screen | B (limited) | Same settings route can support focused subview/state using existing `establishment.phone` + `establishment.email`; no separate first/last-name backend |
| Edit Secondary Contact screen | D | No backend proof for secondary contact fields and no dedicated route/screen |
| Opening Hours screen | C | Backend/screen exists elsewhere (`/staff/{id}` schedule CRUD), but no settings route/screen for establishment opening hours |
| Closure Dates screen | C | Backend/screen exists elsewhere (`/appointments/calendar/day` blocked-slot list/create/delete), but no settings route/screen dedicated to establishment closure dates |

## 5) /branches validation verdict

Verdict: **CONTRADICTED**

Why:
- `/branches` routes (`system/routes/web/register_branches.php`) map only to `BranchAdminController` CRUD.
- `BranchAdminController` + branch views only manage branch catalog identity (`name`, `code`, activation status).
- No opening-hours schedule editor, no closure-date CRUD/list in branch module.

Therefore current settings links:
- "Manage Hours" -> `/branches` = contradicted
- "Manage Closure Dates" -> `/branches` = contradicted

## 6) Recommended canonical 6-screen mechanism decision

Chosen option: **Option 1** (single `/settings` route with explicit `section=establishment` + `screen=<subscreen>` state)

Justification based on repo truth:
1. Current settings architecture is already section-driven (`section` whitelist/normalization in `SettingsController` and sidebar linking in `shell.php`).
2. Establishment write backend is already centralized and safe behind current `/settings` POST contract.
3. Least drift: avoid adding new route tree while mechanism is still being corrected; use substate to enforce focused screens.
4. Clean modernization path: once screen state is explicit and tested, later extraction to dedicated subroutes can be done intentionally without backend rewrite.
5. Supports immediate mechanism correction (overview -> focused edit -> save/cancel -> return) while preserving existing permission and CSRF flow.

Constraint from truth:
- For opening hours and closure dates, settings "entry actions" should target proven operational surfaces (`/staff/{id}` patterns, `/appointments/calendar/day`) unless/until a dedicated settings-owned backend exists.

## 7) Exact next implementation wave to run

**SETTINGS-ESTABLISHMENT-MECHANISM-CORRECTION-IMPLEMENTATION-02**

Required scope for that wave:
1. Keep `/settings` route and `section=establishment`.
2. Introduce explicit `screen` state to enforce six focused screens behavior.
3. Split current merged establishment UI into:
   - overview only screen
   - focused establishment overview edit
   - focused primary contact edit (limited to backed fields)
   - focused secondary contact screen with truthful unsupported-state handling
   - focused opening-hours operational screen entry (truthful destination)
   - focused closure-dates operational screen entry (truthful destination)
4. Preserve existing backend contracts and write keys unless explicitly expanded by separate backend wave.
5. Replace `/branches` action targets with evidence-backed operational targets.

## 8) Explicit do-not-do list for next wave

1. Do not merge overview + all edit forms + operations into one long page again.
2. Do not point hours/closure actions to `/branches` unless branch module gains proven hours/closure functionality.
3. Do not label fields "Backend pending" without repo-proof classification (A/B/C/D).
4. Do not introduce new establishment field writes (website/tax/bank/contact names/etc.) without backend contract + storage proof.
5. Do not refactor unrelated settings sections, routes, or controllers in that wave.
6. Do not conflate staff schedule/blocked-slot data with establishment-level aggregates without explicit mapping rules.

## Evidence inventory (exact files inspected)

- `system/modules/settings/controllers/SettingsController.php`
- `system/modules/settings/views/index.php`
- `system/modules/settings/views/partials/shell.php`
- `system/routes/web/register_settings.php`
- `system/core/app/SettingsService.php`
- `system/routes/web/register_branches.php`
- `system/modules/branches/controllers/BranchAdminController.php`
- `system/modules/branches/views/index.php`
- `system/modules/branches/views/create.php`
- `system/modules/branches/views/edit.php`
- `system/core/Branch/BranchDirectory.php`
- `system/routes/web/register_staff.php`
- `system/modules/staff/views/show.php`
- `system/modules/staff/repositories/StaffAvailabilityExceptionRepository.php`
- `system/routes/web/register_appointments_calendar.php`
- `system/modules/appointments/controllers/AppointmentController.php`
- `system/modules/appointments/views/calendar-day.php`
- `system/modules/appointments/repositories/BlockedSlotRepository.php`
- `system/data/full_project_schema.sql`
- `system/data/seeders/002_seed_baseline_settings.php`
