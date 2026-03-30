# Phase E — Online Booking Settings foundation

Backend-first; no UI redesign. This phase is satisfied by the **existing Phase B (B3)** implementation. No new code was added in this pass.

---

## Findings (audit)

- **Public booking:** Routes `GET /api/public/booking/slots`, `POST /api/public/booking/book`, `GET /api/public/booking/consent-check`; slots/book → `PublicBookingController` → `PublicBookingService`. **`GET consent-check`** does not return consent status (410 + fixed error after branch gate; PB-HARDEN-08). Branch required in request; no auth.
- **Appointment booking rules:** `AppointmentService::validateTimes()` uses appointment settings (min_lead, max_days_ahead, allow_past_booking) for internal booking. Public booking uses its own online_booking settings for the same concepts (min_lead, max_days_ahead) plus enabled and allow_new_clients.
- **Settings architecture:** `SettingsService` has grouped keys, `get*Settings(?int $branchId)`, `set*Settings(array, ?int $branchId)`; `SettingsController` index passes grouped data, store has blocks per group; view has sections and excludes grouped keys from "Other". Online booking is already a grouped section.
- **Branch scoping:** `getOnlineBookingSettings($branchId)` / `setOnlineBookingSettings($data, $branchId)` support branch_id; PublicBookingService calls `getOnlineBookingSettings($branchId)` with branch from request. Admin settings page uses default branch (0) for load/save; per-branch override in UI is optional later.
- **Conclusion:** The Online Booking Settings **foundation** (grouped registration, persistence, retrieval, defaults/seed, minimal settings UI section) is already implemented in Phase B (B3). Phase E required no additional implementation.

---

## Chosen settings keys (already in place)

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `online_booking.enabled` | bool | false | Master switch; when false, public slots and book return error for that branch. |
| `online_booking.min_lead_minutes` | int | 120 | Minimum minutes between now and slot start; slots before that are filtered out; book rejects too-soon start. |
| `online_booking.max_days_ahead` | int | 60 | Maximum booking date = today + this many days; slots and book enforce. |
| `online_booking.allow_new_clients` | bool | true | When false, anonymous public POST book always rejects (`ERROR_ALLOW_NEW_CLIENTS_OFF`); there is no public `client_id` path (PB-HARDEN-NEXT). |

**Why these keys now:** They are the smallest set that (1) match the current `PublicBookingService` behaviour (enable/disable, lead time, horizon, new clients), (2) are rule/parameter settings not master data, and (3) are already enforced in code. No Booker reference HTML was provided; derivation is from the codebase and ADMIN-SETTINGS-BACKLOG (B3).

---

## Where they are enforced

- **`PublicBookingService::getPublicSlots($branchId, ...)`:** `getOnlineBookingSettings($branchId)`; if `enabled` false → error; date in [today, today + max_days_ahead]; after availability, filters slots with start >= now + min_lead_minutes.
- **`PublicBookingService::createBooking(..., $branchId, ...)`:** `getOnlineBookingSettings($branchId)`; if `enabled` false → error; start >= now + min_lead and start date <= today + max_days_ahead; if `allow_new_clients` false → `ERROR_ALLOW_NEW_CLIENTS_OFF` before resolveClient (PB-HARDEN-NEXT).

---

## Changed files (this pass)

| File | Change |
|------|--------|
| `system/docs/phase-e-online-booking-settings-progress.md` | **New.** Phase E progress doc (this file). |

No code or migration changes. Existing implementation lives in: `SettingsService`, `SettingsController`, `settings/views/index.php`, `004_seed_phase_b_settings.php`, `PublicBookingService`, `PublicBookingController`, routes.

---

## What was intentionally postponed

- **Per-branch settings in admin UI:** Backend supports `getOnlineBookingSettings($branchId)` / `setOnlineBookingSettings($data, $branchId)`. The settings page currently loads/saves with default branch (0). A future "branch selector + load/save per branch" can be added without new settings keys.
- **Extra online-booking keys** (e.g. confirmation mode, reminder offset, buffer, max slots per day per client): Not required by current public booking flow; add when implementing those behaviours.
- **Public-facing booking UI:** Out of scope; backend and settings only.
- **Booker-specific fields** not present in current codebase: Not added without a clear requirement and a safe enforcement point in `PublicBookingService` (or equivalent).

---

## Manual QA checklist (Phase E)

1. **Persistence and UI**  
   Run seed (include 004). Open /settings → Online booking section. Confirm: Online booking enabled (default off), Min lead (minutes) 120, Max days ahead 60, Allow new clients on. Change values, save, reload → values persist.

2. **Enabled**  
   With online booking **disabled**, call GET /api/public/booking/slots?branch_id=1&service_id=…&date=… → expect error "Online booking is not enabled for this branch." Enable in settings, save → same request returns slots (if date/service valid).

3. **min_lead_minutes**  
   Set min lead to 60. Request slots for today; slots before now+60min should be absent. Submit a book with start_time in 30 minutes → expect error "Selected time is too soon. Book at least 60 minutes in advance."

4. **max_days_ahead**  
   Set max days ahead to 7. Request slots for a date 10 days ahead → expect date error. Request slots for 5 days ahead → success (if enabled and service/date valid).

5. **allow_new_clients**  
   Set Allow new clients off; save. POST /api/public/booking/book with new client payload → expect **422** and `Online booking is not available for this request at this branch. Please contact the spa.` Set Allow new clients on → booking with new client data can succeed (other validations passing).

6. **Branch isolation**  
   If you have multiple branches, set online_booking.enabled true for branch_id 0 only (or one branch when per-branch UI exists). Call slots/book with another branch_id → when that branch has no override, behaviour follows branch 0 (or branch-specific if seeded). No cross-branch leakage in settings retrieval; PublicBookingService uses branch_id from request.

---

## Phase E acceptance readiness

**Phase E (Online Booking Settings foundation) is acceptance-ready.** The foundation was implemented in Phase B (B3): grouped settings, branch-aware get/set, persistence, seed defaults, and minimal settings UI section. Public booking routes enforce all four keys. This document records the audit, chosen keys, enforcement points, and QA; no further implementation was required for Phase E.
