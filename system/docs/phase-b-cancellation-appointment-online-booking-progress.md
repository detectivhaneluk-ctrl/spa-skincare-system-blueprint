# Phase B — Cancellation Policy, Appointment Settings, Online Booking Settings

Backend-first implementation. No UI redesign; minimal settings UI sections added.

---

## What was changed

### STEP B1 — Cancellation Policy foundation

- **SettingsService** (`system/core/app/SettingsService.php`): Added `CANCELLATION_KEYS`, `getCancellationSettings(?int $branchId)`, `setCancellationSettings(array $data, ?int $branchId)` for: cancellation.enabled, cancellation.min_notice_hours, cancellation.reason_required, cancellation.allow_privileged_override.
- **AppointmentService** (`system/modules/appointments/services/AppointmentService.php`): Injected SettingsService and PermissionService. In `cancel()`: loads cancellation settings for appointment branch; if cancellation.enabled is false, throws; if reason_required and notes empty, throws; if within min_notice_hours of start, allows cancel only when allow_privileged_override and user has permission `appointments.cancel_override`, otherwise throws; audit metadata includes `cancelled_via_override` when override is used.
- **Permission seed** (`system/data/seeders/001_seed_roles_permissions.php`): Added permission `appointments.cancel_override`.
- **Phase B seed** (`system/data/seeders/004_seed_phase_b_settings.php`): Seeds cancellation.* (and B2/B3) with requested defaults for branch_id 0 via setCancellationSettings/setAppointmentSettings/setOnlineBookingSettings.
- **SettingsController** and **Settings view**: Cancellation section and store handling; grouped keys excluded from "Other" and from generic loop.
- **Branch-write parity update (backend-only):** `SettingsController` now reuses the existing `online_booking_context_branch_id` context to load/save `cancellation.*` per selected branch (fallback global when context is 0), matching branch-aware runtime reads in `AppointmentService`.

### STEP B2 — Appointment Settings foundation

- **SettingsService**: Added `APPOINTMENT_KEYS`, `getAppointmentSettings(?int $branchId)`, `setAppointmentSettings(array $data, ?int $branchId)` for: appointments.min_lead_minutes, appointments.max_days_ahead, appointments.allow_past_booking.
- **AppointmentService**: In `validateTimes($data)`: loads appointment settings for branch from `$data['branch_id']`; rejects past start unless allow_past_booking; rejects start &lt; now + min_lead_minutes; rejects start date &gt; today + max_days_ahead. `create()`, `update()`, and `createFromSlot()` use validateTimes so all creation/update paths respect the booking window.
- **SettingsController** and **Settings view**: Appointment booking section and store handling.
- **Branch-write parity update (backend-only):** `SettingsController` now reuses the existing `online_booking_context_branch_id` context to load/save `appointments.*` per selected branch (fallback global when context is 0), matching branch-aware runtime reads in `AppointmentService`.

### STEP B3 — Online Booking Settings foundation

- **SettingsService**: Added `ONLINE_BOOKING_KEYS`, `getOnlineBookingSettings(?int $branchId)`, `setOnlineBookingSettings(array $data, ?int $branchId)` for: online_booking.enabled, online_booking.public_api_enabled, online_booking.min_lead_minutes, online_booking.max_days_ahead, online_booking.allow_new_clients.
- **PublicBookingService** (`system/modules/online-booking/services/PublicBookingService.php`): Injected SettingsService. `getPublicSlots()`: if online_booking.enabled is false for branch, returns error; validates date in [today, today + max_days_ahead]; after availability slots, filters to slots with start >= now + min_lead_minutes. `createBooking()`: if enabled is false returns error; validates start >= now + min_lead and start date <= today + max_days_ahead; if allow_new_clients is false, returns error before resolveClient (**runtime note:** anonymous public book no longer accepts `client_id` — PB-HARDEN-NEXT — so this gate blocks all anonymous books when false).
- **SettingsController** and **Settings view**: Online booking section and store handling.
- **Bootstrap** (`system/modules/bootstrap.php`): AppointmentService receives SettingsService and PermissionService; PublicBookingService receives SettingsService.

---

## Files changed

| File | Change |
|------|--------|
| `system/core/app/SettingsService.php` | CANCELLATION_KEYS, APPOINTMENT_KEYS, ONLINE_BOOKING_KEYS; get/set helpers for cancellation, appointments, online_booking. |
| `system/modules/appointments/services/AppointmentService.php` | SettingsService, PermissionService; cancel() policy; validateTimes() booking window; createFromSlot() calls validateTimes. |
| `system/modules/online-booking/services/PublicBookingService.php` | SettingsService; getPublicSlots/createBooking enforce online_booking settings. |
| `system/modules/settings/controllers/SettingsController.php` | index: pass cancellation, appointment, onlineBooking; store: grouped save only when corresponding POST keys present; isGroupedKey skips cancellation., appointments., online_booking. |
| `system/modules/settings/views/index.php` | Cancellation policy, Appointment booking, Online booking sections; Other excludes cancellation., appointments., online_booking. |
| `system/data/seeders/001_seed_roles_permissions.php` | Permission appointments.cancel_override. |
| `system/data/seeders/004_seed_phase_b_settings.php` | **New.** Phase B defaults via setCancellationSettings, setAppointmentSettings, setOnlineBookingSettings. |
| `system/scripts/seed.php` | require 004_seed_phase_b_settings.php. |
| `system/modules/bootstrap.php` | AppointmentService, PublicBookingService constructor wiring. |

---

## Backward compatibility

- **Settings:** Phase B keys are new. Store only updates cancellation/appointments/online_booking when the form sends at least one key from that group, so old forms do not overwrite Phase B values.
- **Cancellation:** If no Phase B seed has run, getCancellationSettings() returns defaults (enabled true, min_notice 0, reason_required false, allow_override true). Existing cancel flows behave as before until settings are changed.
- **Appointments:** Same; getAppointmentSettings() defaults (min_lead 0, max_days 180, allow_past false) preserve previous behavior.
- **Online booking:** getOnlineBookingSettings() defaults (enabled false, min_lead 120, max_days 60, allow_new_clients true). Public booking endpoints enforce these; if no seed, enabled defaults to false so public booking is off until explicitly enabled.

---

## Where settings are enforced

- **Cancellation:** `AppointmentService::cancel()` — branch from the appointment; permission `appointments.cancel_override` for override inside min_notice_hours.
- **Appointment booking window:** `AppointmentService::validateTimes()` — branch from `$data['branch_id']`; used by create(), update(), createFromSlot().
- **Online booking:** `PublicBookingService::getPublicSlots()` — enabled, date range, slot filter by min_lead; `PublicBookingService::createBooking()` — enabled, start time window, allow_new_clients before resolving client.

---

## Manual QA checklist

### Cancellation (B1)

1. Run seed so Phase B settings exist. In Settings, set Cancellation: enabled ON, min notice 0, reason required OFF, allow privileged override ON. Save.
2. Create an appointment in the future. As a user without `appointments.cancel_override`, cancel it — should succeed (min notice 0).
3. Set min notice to 24 (hours). Cancel another future appointment within 24h without override permission — should fail with message about minimum notice.
4. As a user with `appointments.cancel_override` (e.g. role that has this permission), cancel within 24h — should succeed; audit log should show cancelled_via_override.
5. Set cancellation enabled OFF; try to cancel — should fail with "Cancellation is disabled."
6. Set reason required ON; try to cancel without notes — should fail; with notes — should succeed (and with override if within min notice).

### Internal booking (B2)

1. In Settings, Appointments: min lead 60, max days 30, allow past OFF. Save.
2. Create appointment with start time in 30 minutes — should fail (inside min_lead).
3. Create appointment with start time in 90 minutes, date within 30 days — should succeed.
4. Create appointment with date &gt; 30 days ahead — should fail.
5. Try to create appointment in the past — should fail.
6. Set allow past booking ON; create appointment in the past — should succeed (if validation only checks past; confirm per implementation).
7. Set min lead 0, max days 180 — confirm normal booking works.

### Public online booking (B3)

1. In Settings, Online booking: enabled OFF. Save. Call public slots/booking API for a branch — should get error / disabled message.
2. Set enabled ON, min lead 120, max days 60, allow new clients ON. Get slots for today and a valid service/branch — slots should start at least 120 minutes from now; dates beyond 60 days should be invalid.
3. Create a booking with name/email — should succeed when allow_new_clients ON.
4. Set allow new clients OFF; create booking with name/email — should fail with `ERROR_ALLOW_NEW_CLIENTS_OFF` (PB-HARDEN-NEXT; no public existing-client path).
5. Create booking with start in 60 minutes — should fail (min_lead 120). Create with start in 150 minutes — should succeed (if slot exists).

---

## Postponed / remaining integration points

- Cancellation fees, refunds, SMS/email, status-model redesign: not in Phase B.
- VAT, notifications, memberships, waitlist settings, marketing settings, document storage: not in Phase B.
- Public booking UI redesign: not in scope; settings foundation and service enforcement only.
- If public booking has more entry points (e.g. other controllers or routes), they should be wired to check online_booking.enabled and window/allow_new_clients where relevant; document any such points found during QA.
- Branch context: all enforcement uses branch from appointment or request; multi-branch QA should confirm each branch uses its own settings.
