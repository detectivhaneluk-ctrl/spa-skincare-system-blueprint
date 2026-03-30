# SETTINGS-APPOINTMENT-WRITE-CONTRACT-HARDENING-WAVE-03B

OPS memo: write/read contract for **Scheduling (appointments)**, **Cancellation**, **Waitlist**, and **Security** in Settings.

## Scheduling (`section=appointments`)

**POST allowlist:** `SettingsService::APPOINTMENT_SETTINGS_FORM_KEYS` (exported for the controller). Matches every `settings[appointments.*]` field on the Appointments workspace, including scheduling, alerts, staff, display, calendar label modes, pre-book threshold (value + unit), itinerary toggles, and print summary toggles.

**Not on the form:** `appointments.prebook_threshold_hours` remains in `SettingsService::APPOINTMENT_KEYS` for **legacy read + programmatic patch** only (`patchAppointmentSettings` still accepts `prebook_threshold_hours` and maps to value/unit).

**Save:** `SettingsController::store()` runs appointment patches only when `section=appointments`, with `appointments_context_branch_id` as the branch row (0 = organization default).

**Read:** `getAppointmentSettings($branchId)` — same branch selector as GET (`appointments_branch_id`).

**Runtime readers (non-exhaustive):** `AvailabilityService`, `AppointmentService`, `PublicBookingService`, `AppointmentController`, `AppointmentPrintSummaryService`, client itinerary providers — all use `getAppointmentSettings` with operational branch scope as implemented today.

## Cancellation (`section=cancellation`)

**POST allowlist:** `SettingsService::CANCELLATION_KEYS` (policy + display config keys). Matches the edit form and policy text saves under `settings[cancellation.*]`.

**Save:** `patchCancellationSettings` only when `section=cancellation`; organization scope (`branch_id` 0). Reason list CRUD uses separate POST fields (`reason_rows`, etc.) — unchanged.

**Read:** `getCancellationPolicySettings(null)` in Settings UI. Runtime code uses the same helper where policy snapshots are needed (e.g. booking/services); enforcement helpers may expose a narrower view — do not conflate without checking call sites.

**Intentionally deferred:** Automatic fee/tax charging, economics product work.

## Waitlist (`section=waitlist`)

**POST allowlist:** `SettingsService::WAITLIST_KEYS` — `enabled`, `auto_offer_enabled`, `max_active_per_client`, `default_expiry_minutes`. No extra UI-only keys in the save path.

**Scope:** Organization default (`patchWaitlistSettings` / `getWaitlistSettings` with `null` branch in controller).

## Security (`section=security`)

**POST allowlist:** `SettingsService::SECURITY_KEYS` — `security.password_expiration`, `security.inactivity_timeout_minutes`.

**Stored values:** password_expiration `never|90_days`; inactivity 15|30|120 minutes.

**Runtime:** `AuthMiddleware` reads `getSecuritySettings()` with branch context from `BranchContext` — may differ from Settings save scope (org default). Not altered in this wave; operators should be aware enforcement is branch-effective where middleware applies it.

## Shared hardening (this wave)

- Controller subsection constants alias `SettingsService` public key lists.
- `store()` applies cancellation / appointments / waitlist / security patches only for matching `section` (defense in depth with existing `scopedPostForSection`).
- `onlyPatchKeys()` at the top of `patchCancellationSettings`, `patchAppointmentSettings`, `patchWaitlistSettings`, `patchSecuritySettings`.

## Verification

```bash
php system/scripts/verify_settings_write_contract_wave_03b.php
```

## Deferred

- Branch-scoped cancellation/waitlist/security UI if product requires it.
- Unifying AuthMiddleware branch with Settings save scope.
- Deeper cancellation runtime vs policy matrix documentation outside this memo.
