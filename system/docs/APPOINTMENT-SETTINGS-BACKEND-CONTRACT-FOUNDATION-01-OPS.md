# Appointment settings — backend contract (foundation 01 + 02 + branch scope 03)

Ops reference for tenant `appointments.*` settings used by internal booking, client profile read-side alerts, and the operator day calendar (read-side display only).

## Settings keys (group `appointments`)

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `appointments.min_lead_minutes` | int | 0 | **Runtime:** `AppointmentService::validateTimes` |
| `appointments.max_days_ahead` | int | 180 | **Runtime:** `AppointmentService::validateTimes` |
| `appointments.allow_past_booking` | bool | false | **Runtime:** `AppointmentService::validateTimes` |
| `appointments.allow_end_after_closing` | bool | false | **Runtime:** `AppointmentService` operating-hours guard — when **false** (default), end time may not be after the branch’s configured **closing** time on that day; when **true**, end-after-close is allowed. **Does not** relax start-before-opening, closed days, or unconfigured days (those checks stay enforced). |
| `appointments.check_staff_availability_in_search` | bool | true | **Read-side (search only):** `AvailabilityService::getAvailableSlots` — when **true** (default), search uses staff weekly schedule, breaks, and staff blocked slots (current behavior). When **false**, search still uses branch operating hours and still excludes overlapping **appointments** (buffered), but **does not** filter by staff schedule / breaks / blocked slots — results can be broader. **Does not** change `isSlotAvailable` / `isStaffWindowAvailable` when called for **booking** (full constraints remain). |
| `appointments.allow_room_overbooking` | bool | false | **Room-only (not generic resources).** When **false** (default), internal flows that pass a positive **`room_id`** enforce **`AppointmentRepository::hasRoomConflict`** (search + write). When **true**, that room overlap check is skipped for **internal** paths only; staff overlap, hours, eligibility, etc. unchanged. **Public** booking / public availability channel does **not** use this bypass. See **`APPOINTMENT-ALLOW-ROOM-OVERBOOKING-SETTING-01-OPS.md`**. |
| `appointments.allow_staff_booking_on_off_days` | bool | false | **Internal booking + internal slot search only.** When **true**, empty staff working intervals (off-day / no weekly hours) are treated as **branch open–close** for **internal** `isSlotAvailable` / `isStaffWindowAvailable` and `getAvailableSlots(..., 'internal')`, **except** when the staff has a date **`closed`** availability exception. **Public** online booking and **`getAvailableSlots(..., 'public')`** ignore this setting (always strict off-days). Still enforced: breaks, staff blocked slots, overlapping appointments (buffers), service–staff eligibility, staff active/branch match, `validateTimes`, `assertWithinBranchOperatingHours` (including wave 06 end-after-close rule). |
| `appointments.allow_staff_concurrency` | bool | false | **Internal only (buffered staff overlap).** When **true**, **`AvailabilityService::isStaffWindowAvailable`** skips **`hasBufferedAppointmentConflict`** for **`forPublicBookingChannel === false`**. **Public** booking / public slot search always enforce buffered overlap regardless of this flag. Unchanged: room rules, schedule, breaks, blocked slots, hours, consent, eligibility. See **`APPOINTMENT-STAFF-CONCURRENCY-SETTING-01-OPS.md`**. |
| `appointments.no_show_alert_enabled` | bool | false | **Read-side:** `ClientAppointmentProfileProvider::getSummary` → `no_show_alert` + flat flags; appointment **show** page; day calendar JSON `client_no_show_alert` per row. See **`APPOINTMENT-NO-SHOW-ALERT-OPERATIONALIZATION-01-OPS.md`**. |
| `appointments.no_show_alert_threshold` | int | 1 | Clamped 1–99. Count = appointments with `status = no_show` (non-deleted), same scope as summary SQL. |
| `appointments.calendar_service_show_start_time` | bool | true | **Read-side:** day calendar — **standalone** appointments (`series_id` absent/zero) |
| `appointments.calendar_service_label_mode` | enum | `client_and_service` | See **Label modes** below (standalone) |
| `appointments.calendar_series_show_start_time` | bool | true | **Read-side:** day calendar — **series-linked** rows (`appointments.series_id` > 0). Defaults match service pair. See **`APPOINTMENT-CALENDAR-DISPLAY-PARITY-FOUNDATION-01-OPS.md`**. |
| `appointments.calendar_series_label_mode` | enum | `client_and_service` | Same enum as service; used only when `series_id` > 0 on the calendar row. |
| `appointments.prebook_display_enabled` | bool | false | **Read-side:** `display_flags.prebooked` |
| `appointments.prebook_threshold_value` | int | 2 | Canonical; clamped 1–9999 |
| `appointments.prebook_threshold_unit` | enum | `hours` | `hours` \| `minutes` |
| `appointments.prebook_threshold_hours` | int | — | **Legacy (wave 01 only):** see **Legacy pre-book** |
| `appointments.client_itinerary_show_staff` | bool | **true** | **Read-side:** internal client profile **Appointment History** (`/clients/{id}`) — when **false**, `ClientAppointmentProfileProvider::listRecent` returns `staff_name: null` and the Staff column is omitted in `modules/clients/views/show.php`. |
| `appointments.client_itinerary_show_space` | bool | **false** | **Read-side:** same surface — when **true**, `listRecent` includes `room_name` from `appointments.room_id` → `rooms.name` (LEFT JOIN); when **false**, `room_name` is masked to null and the Space column is omitted. Default **false** preserves pre–wave-09 visible layout (no Space column) until operators opt in. |
| `appointments.print_show_staff_appointment_list` | bool | **true** | **Read-side:** `GET /appointments/{id}/print` — **Staff day schedule** section (same primary staff, same calendar day). **`APPOINTMENT-PRINT-SETTINGS-SUPPORTED-SECTIONS-IMPLEMENTATION-01`**. |
| `appointments.print_show_client_service_history` | bool | **true** | **Read-side:** same route — **Recent client appointments** (`ClientAppointmentProfileProvider::listRecent`; staff/space columns still follow `client_itinerary_show_*`). |
| `appointments.print_show_package_detail` | bool | **true** | **Read-side:** same route — **Packages** (appointment usages + recent client packages). |
| `appointments.print_show_client_product_purchase_history` | bool | **false** | **Read-side:** same route — **Client product purchase history** (`ClientSalesProfileProvider::listRecentProductInvoiceLines`; invoice **product** lines only). Opt-in default. **`APPOINTMENT-PRINT-PRODUCT-PURCHASE-HISTORY-FOUNDATION-01`**. |

### Label modes (service appointments on calendar)

| Stored value | Title line | Meta line |
| --- | --- | --- |
| `client_and_service` | Client name (fallback: appointment id) | Service name |
| `service_and_client` | Service name | Client name |
| `service_only` | Service name | (empty) |
| `client_only` | Client name | (empty) |

Rendered in `system/modules/appointments/views/calendar-day.php`: **standalone** rows use `appointment_calendar_display.show_start_time` + `label_mode`; **series-linked** rows (`series_id` in JSON) use `series_show_start_time` + `series_label_mode`. Invalid enum values fall back like the service pair (series label falls back to effective service mode when missing).

### Pre-book threshold (canonical)

- **Seconds required** between `created_at` and `start_at` for `display_flags.prebooked` when enabled:
  - `hours` → `value * 3600`
  - `minutes` → `value * 60`
- Implemented on the calendar read path in `AppointmentController::applyDayCalendarAppointmentDisplay`.

### Legacy pre-book (`appointments.prebook_threshold_hours`)

- **Read:** If there is **no** stored row for `appointments.prebook_threshold_value`, `getAppointmentSettings` maps legacy `prebook_threshold_hours` to `prebook_threshold_value` (clamped 1–168 as in wave 01) and `prebook_threshold_unit = hours`.
- **Write:** New saves from Settings UI and `patchAppointmentSettings` use **canonical** keys only (`prebook_threshold_value`, `prebook_threshold_unit`). The legacy hours key is not updated by normal saves.
- **Patch compatibility:** `patchAppointmentSettings` may still accept `prebook_threshold_hours` **without** `prebook_threshold_value`; it writes canonical value + unit `hours` (value clamped 1–168 for that legacy path).

## Runtime enforcement vs read-side

- **Enforced on booking create/update:** `min_lead_minutes`, `max_days_ahead`, `allow_past_booking` via `AppointmentService::validateTimes`; branch **operating hours** window via `assertWithinBranchOperatingHours` (including configurable **end-after-closing** only when `appointments.allow_end_after_closing` is true). Staff **off-day** handling inside `AvailabilityService` for **internal** writes uses `allow_staff_booking_on_off_days`; **public** `createFromPublicBooking` and token **reschedule** use the strict (public) channel regardless of that setting. Buffered **staff appointment overlap** is enforced on all public-channel paths; internal paths may skip it when `appointments.allow_staff_concurrency` is **true** (`shouldEnforceBufferedStaffAppointmentOverlap`).
- **Read-side only:** no-show alert via `getSummary` → `no_show_alert` (structured) + appointment **show** + day calendar JSON `client_no_show_alert`; **Appointment History** staff/space columns (`client_itinerary_show_*` via `ClientAppointmentProfileProviderImpl::listRecent`); **appointment print summary** optional sections (`print_show_*` via `AppointmentPrintSummaryService::compose`); calendar label mode, start-time visibility, pre-book display flags; availability **slot search** respects `check_staff_availability_in_search` and audience (`internal` vs `public`) for `allow_staff_booking_on_off_days` (see foundation 08) — not a substitute for final booking validation.

## Canonical branch scope rule (wave 03)

**Appointment settings in this subset are branch-aware operational settings** with **organization (and platform) fallback**, using the same storage model as other `SettingsService` keys:

| Concept | Meaning |
| --- | --- |
| **Read** | `getAppointmentSettings(?int $branchId)` with **`$branchId > 0`**: branch row overrides org default (`branch_id = 0`) and platform default. With **`null` / org read**: only organization + platform merge (no branch-specific row). |
| **Write** | `patchAppointmentSettings` / `setAppointmentSettings` with **`$branchId = 0`**: writes the **organization default** row. With **`$branchId > 0`**: writes a **branch override** for that branch’s organization. |

**Consumers (aligned):**

| Consumer | Branch used for read |
| --- | --- |
| **Settings → Appointments** | Explicit **Editing scope** selector: `appointments_branch_id` query param (0 = org default) or POST `appointments_context_branch_id`. Default when param omitted: same resolution as opening hours (current `BranchContext`, else user `branch_id`, else 0). |
| **`AppointmentController::dayCalendar`** | `BranchContext::getCurrentBranchId()` (calendar filter branch). |
| **`ClientAppointmentProfileProviderImpl::getSummary`** | `BranchContext::getCurrentBranchId()` when `> 0`, else org-effective read (`null`). |
| **`AppointmentController::show`** | Uses `getSummary` for the appointment’s `client_id` → `clientAppointmentSummary` (banner when `no_show_alert.active`). |
| **`AppointmentController::dayCalendar`** | Payload includes **`appointment_calendar_display`** (`show_start_time`, `label_mode`, `series_show_start_time`, `series_label_mode`). Each JSON appointment row may include **`series_id`**, **`client_no_show_alert`** (same shape as `no_show_alert` in `getSummary`, or `null`). |
| **`ClientAppointmentProfileProviderImpl::listRecent`** | Same branch rule as `getSummary` for `getAppointmentSettings` — masks `staff_name` / `room_name` only (storage unchanged). Consumed by **`ClientController::show`** → `clients/views/show.php` **Appointment History** table. |
| **`AppointmentService::validateTimes`** | `branch_id` on the appointment payload. |
| **`AppointmentService` operating-hours guard** | `branch_id` on the appointment payload (same `getAppointmentSettings($branchId)` merge as booking window). |
| **`AvailabilityService::getAvailableSlots`** | `branchId` + audience: **`public`** = `PublicBookingService` / manage-token reschedule slot list (strict off-days). **`internal`** = `AppointmentController::slots` (staff UI); may widen to branch envelope on staff off-days when `allow_staff_booking_on_off_days` is true. **FOUNDATION-12:** optional **`room_id`** on internal slot search filters by **`hasRoomConflict`** unless **`appointments.allow_room_overbooking`** is true (**SETTING-01**). Public audience ignores room. See **`APPOINTMENT-ROOM-OCCUPANCY-IN-SLOTS-FOUNDATION-12-OPS.md`**, **`APPOINTMENT-ALLOW-ROOM-OVERBOOKING-SETTING-01-OPS.md`**. |
| **`AppointmentController::printSummaryPage`** | `BranchContext::getCurrentBranchId()` when `> 0`, else org-effective `getAppointmentSettings(null)` — same merge as other read-side appointment settings. |

**Search vs booking boundary (foundation 07):** Staff **schedule / breaks / blocked-slot** constraints are skipped in search only when `check_staff_availability_in_search` is **false**. **Not** bypassed by 07 alone: service–staff eligibility, staff active/branch match, branch closed-day / hours gating, final booking `isSlotAvailable` rules, operating-hours write guards, room/resource logic. Buffered **staff** appointment overlap in **internal** search/write may be bypassed only when `appointments.allow_staff_concurrency` is **true** (**SETTING-01**); **public** audience always keeps overlap checks.

**Internal off-day bypass (foundation 08):** `allow_staff_booking_on_off_days` affects **only** internal availability (`isSlotAvailable` / `isStaffWindowAvailable` with **public channel flag false**) and **`getAvailableSlots(..., 'internal')`**. **`createFromPublicBooking`**, token **`reschedule`**, and **`getAvailableSlots(..., 'public')`** pass **public channel** = strict off-days always. Full-day **`closed`** exceptions are never bypassed.

There is **no ambiguity**: values are not “global only”; they are **settings rows** at org default and/or branch override, merged by `SettingsService::get()` precedence.

## Intentionally out of scope

**Class-type / course appointment display** has no domain truth in-repo yet; do **not** add UI or settings for it until a later wave. **Series-linked** service occurrences (`appointments.series_id`) are separate and are covered by **`calendar_series_*`** (see **`APPOINTMENT-CALENDAR-DISPLAY-PARITY-FOUNDATION-01-OPS.md`**).

**Room overbooking (room-only):** **`appointments.allow_room_overbooking`** — **`ROOM-OVERBOOKING-SETTING-01`** / `APPOINTMENT-ALLOW-ROOM-OVERBOOKING-SETTING-01-OPS.md`. Generic **resource** overbooking (non-room) is still **not** modeled.

**Staff concurrency (buffered overlap, internal-only bypass):** **`appointments.allow_staff_concurrency`** — **`APPOINTMENT-STAFF-CONCURRENCY-SETTING-01-OPS.md`**. Still deferred (separate charters): automatic package detection, lock staff to room, staff-specific price/duration — read-only code truth: **`APPOINTMENT-SETTINGS-REMAINING-PARITY-TRUTH-WAVE-01-OPS.md`**. **Check-in (operator timestamp foundation):** **`APPOINTMENT-CHECK-IN-FOUNDATION-IMPLEMENTATION-01-OPS.md`** (no appointment setting). **Appointment print:** consumer **`APPOINTMENT-PRINT-CONSUMER-FOUNDATION-01`**; supported section settings **`APPOINTMENT-PRINT-SETTINGS-SUPPORTED-SECTIONS-IMPLEMENTATION-01`** (see **Foundation 10**).

## Foundation 10 (appointment printout display toggles) — supported sections shipped; product line history shipped (foundation 01)

**Charter:** `APPOINTMENT-PRINTOUT-DISPLAY-TOGGLES-FOUNDATION-10`.

**Status (consumer):** **`GET /appointments/{id}/print`** (`AppointmentController::printSummaryPage`), `AppointmentPrintSummaryService`, `views/print.php`, `appointment-print.css`. Verifier: `php scripts/verify_appointment_print_consumer_foundation_01.php`.

**Status (settings — supported):** **`APPOINTMENT-PRINT-SETTINGS-SUPPORTED-SECTIONS-IMPLEMENTATION-01`** adds three **1:1** keys (default **true**, preserves pre-toggle visible layout): `appointments.print_show_staff_appointment_list`, `appointments.print_show_client_service_history`, `appointments.print_show_package_detail`. Settings UI + `SettingsService` + `SettingsController` allowlist. Verifier: `php scripts/verify_appointment_print_settings_supported_sections_implementation_01.php`.

**Status (product purchase history):** **`APPOINTMENT-PRINT-PRODUCT-PURCHASE-HISTORY-FOUNDATION-01`** adds **`appointments.print_show_client_product_purchase_history`** (default **false**), `ClientSalesProfileProvider::listRecentProductInvoiceLines`, print section + ops truth. Verifier: `php scripts/verify_appointment_print_product_purchase_history_foundation_01.php`.

### Historical truth audit (pre-print-consumer) — what was searched (repo `system/` and workspace roots)

| Search / area | Result |
| --- | --- |
| `print`, `printable`, `window.print`, `@media print` in `*.php`, `*.js`, `*.css`, `*.html` under the repo | **No matches** for browser print CSS/JS hooks. |
| `system/modules/appointments/**/*.php` for `print`, `export`, `pdf`, `day sheet`, `sheet` (case-insensitive) | Only unrelated `sprintf` / prose; **no** print/export/PDF/day-sheet feature. |
| Glob `**/appointments/**/*print*` | **Zero files.** |
| `system/routes/**/*.php` for `appointment` + `print`, and for `print` / `pdf` route names | **No** appointment print or PDF routes. |
| `system/modules/appointments/views/` (all 8 view files) | **No** print-oriented markup or sections. |
| `system/modules/sales/views/` for `print` / `pdf` / `export` | **No matches.** |
| Known appointment surfaces | **`GET /appointments/calendar/day`** + **`GET /calendar/day`** (`AppointmentController::dayCalendarPage` / `dayCalendar`) = **interactive day grid** (HTML + JSON for JS), **not** a print template or print mode. **`GET /appointments/{id}`** (`show.php`) = **on-screen** appointment detail, not an “appointment printout” aggregate. |

### Related but out-of-scope for “appointment printouts”

- **`hardware.use_receipt_printer`** — payment/receipt dispatch after sales (`PaymentService` / `InvoiceService`), **not** an appointment-focused printout and **not** a combined staff list / client service history / product history / package block.
- **CLI `JSON_PRETTY_PRINT`** — debug/scripts only.

### Screenshot rows vs current product (after print consumer + supported print settings)

| Row | Verdict |
| --- | --- |
| **A. Staff appointment list** | **Supported:** same staff / same day list; **toggle** `print_show_staff_appointment_list` (default on). Not a full multi-staff day sheet. |
| **B. Client service history** | **Supported:** `listRecent` on print; **toggle** `print_show_client_service_history` (default on). |
| **C. Client product purchase history** | **Supported:** `listRecentProductInvoiceLines` on print; **toggle** `print_show_client_product_purchase_history` (default **off**). Invoice **product** lines only. |
| **D. Package details** | **Supported:** usages + recent client packages; **toggle** `print_show_package_detail` (default on). |

### Proof (foundation 10)

**Historical audit:** documentation-only search (table above, stale route claims predate consumer).  
**Consumer:** `php scripts/verify_appointment_print_consumer_foundation_01.php`.  
**Print settings:** `php scripts/verify_appointment_print_settings_supported_sections_implementation_01.php`.

### Further print settings (future)

Additional `appointments.print_show_*` keys **only** when a **rendered section** and **real data source** exist on **`GET /appointments/{id}/print`**. Product retail lines are covered by **`print_show_client_product_purchase_history`** + **`APPOINTMENT-PRINT-PRODUCT-PURCHASE-HISTORY-FOUNDATION-01-OPS.md`**.

## Verification / runtime proof

The verifier **refuses to run** without an explicit branch fixture (no inferred tenant).

From repo `system` directory:

```bash
php scripts/verify_appointment_settings_foundation_wave_01.php --branch-code=SMOKE_A
```

Or: `APPOINTMENT_SETTINGS_VERIFY_BRANCH_CODE=SMOKE_A` (must match an active `branches.code` in the DB, e.g. seeded smoke data).

**Proven (CLI, with scope):** branch-scoped patch + reload; organization merge unchanged when only branch overrides are written; four label modes; pre-book hours/minutes math; legacy `prebook_threshold_hours` read + patch mapping; no-show threshold trigger logic; snapshot restore.

**Foundation 06 (end after closing):** from `system/`:

```bash
php scripts/verify_appointment_allow_end_after_closing_foundation_06.php --branch-code=SMOKE_A
```

**Proven (CLI):** default **false** blocks end-after-close; **true** allows it; start-before-opening still blocked; closed / not-configured days still blocked when DB fixtures exist; branch patch does not change org-default merge for this key; snapshot restore.

**Foundation 07 (check staff in search):** from `system/`:

```bash
php scripts/verify_appointment_check_staff_availability_in_search_foundation_07.php --branch-code=SMOKE_A
```

**Proven when a DB fixture exists:** with toggle **true**, `getAvailableSlots` empty for an off-schedule staff; with **false**, same triple yields slots; `isSlotAvailable` without search flag still **false** for that slot; restoring toggle tightens search again; org-default merge stable for branch-only patch; snapshot restore.

**May SKIP:** no qualifying `(service, staff, date)` row (see script stderr line for exact fixture criteria).

**Foundation 08 (staff booking on off days):** from `system/`:

```bash
php scripts/verify_appointment_allow_staff_booking_off_days_foundation_08.php --branch-code=SMOKE_A
```

**Staff concurrency (SETTING-01):** from `system/`:

```bash
php scripts/verify_appointment_allow_staff_concurrency_setting_01.php --branch-code=SMOKE_A
```

**Proven when a DB fixture exists:** internal vs public `getAvailableSlots` audience; `isSlotAvailable` public channel strict vs internal channel with bypass; org-default merge stable; snapshot restore.

**May SKIP:** same fixture dependency as foundation 07 (off-schedule staff + open branch day + no exception row on resolved date).

**Foundation 09 (client itinerary display toggles):** from `system/`:

```bash
php scripts/verify_client_itinerary_display_toggles_foundation_09.php --branch-code=SMOKE_A
```

**Proven when DB fixtures exist:** branch-scoped persist/reload for the two keys; branch-only patch does not change organization-default merge for `client_itinerary_show_staff`; `listRecent` returns non-null `staff_name` when staff toggle is on and an appointment has `staff_id`, and null when off; same for `room_name` when space toggle is on/off **if** there is an appointment with `room_id` on that branch.

**May SKIP (stdout):** no appointment with `room_id` on the branch — space masking cannot be proven without that row.

**No-show alert operationalization (01):** from `system/`:

```bash
php scripts/verify_appointment_no_show_alert_operationalization_01.php --branch-code=SMOKE_A
```

**May SKIP:** no `no_show` appointment on branch scope. See **`APPOINTMENT-NO-SHOW-ALERT-OPERATIONALIZATION-01-OPS.md`**.

**Calendar display series vs service (01):** from `system/`:

```bash
php scripts/verify_appointment_calendar_display_series_parity_foundation_01.php --branch-code=SMOKE_A
```

**Proven:** independent branch-scoped read for `calendar_series_*` vs `calendar_service_*`; org-default merge stable under branch-only patch; snapshot restore.

**May SKIP (stdout):** no non-deleted appointment with `series_id` on the branch — grouped day list `series_id` field not asserted against a fixture row.

**Appointment print consumer (foundation 01):** from `system/`:

```bash
php scripts/verify_appointment_print_consumer_foundation_01.php
```

**Proven (static):** route registration, `appointments.view` + auth middlewares, controller method, compose service, dedicated view + print CSS; product section is wired to `listRecentProductInvoiceLines` (not invoice headers only).

**Appointment print supported section settings (implementation 01):** from `system/`:

```bash
php scripts/verify_appointment_print_settings_supported_sections_implementation_01.php
```

**Proven (static):** four `appointments.print_show_*` keys in `SettingsService` / patch (three default true, one false); `SettingsController` allowlist + POST mapping; Settings UI checkboxes; `AppointmentPrintSummaryService` reads settings + `section_visibility`; print view gates sections including product purchase block when enabled.

**Not proven here:** HTTP settings form, authenticated session, or `GET /calendar/day` JSON/UI (add separate E2E if needed).
