# Public booking & self-service — implementation map (code-grounded)

**Purpose:** Single reference for current-state **Phase 0** (public booking hardening) and **Phase 1** (public self-service foundations) backend runtime behavior, derived only from the current codebase. This doc separates what is already implemented from what remains postponed.

**Related:** `BOOKER-PARITY-MASTER-ROADMAP.md`, `public-online-booking-foundation-progress.md`, `booker-modernization-booking-concurrency-contract.md` (W3).

---

## 1. Current route → middleware → controller → service chain

### 1.1 Registered routes

From `system/routes/web.php` (no per-route middleware array — empty `[]`):

| Method | Path | Handler |
|--------|------|---------|
| GET | `/api/public/booking/slots` | `PublicBookingController::slots` |
| POST | `/api/public/booking/book` | `PublicBookingController::book` |
| GET | `/api/public/booking/consent-check` | `PublicBookingController::consentCheck` (branch gate + **410**, no consent probe) |
| POST | `/api/public/booking/manage` | `PublicBookingController::manageLookup` (JSON or form body: `token`) |
| POST | `/api/public/booking/manage/slots` | `PublicBookingController::manageRescheduleSlots` (body: `token`, `date`) |
| POST | `/api/public/booking/manage/cancel` | `PublicBookingController::manageCancel` |
| POST | `/api/public/booking/manage/reschedule` | `PublicBookingController::manageReschedule` |

### 1.2 Global middleware (all requests)

From `Core\Router\Dispatcher::$globalMiddleware` (`system/core/router/Dispatcher.php`), **in order**:

1. **`CsrfMiddleware`**
2. **`ErrorHandlerMiddleware`**
3. **`BranchContextMiddleware`**

Public routes do **not** use `AuthMiddleware` or `PermissionMiddleware`.

### 1.3 Per-flow call graph

**GET slots**

1. `CsrfMiddleware` — passes (GET exempt).
2. `BranchContextMiddleware` — if no logged-in user, sets `BranchContext` branch to `null` (public `branch_id` in query is **not** applied to `BranchContext`; booking uses explicit IDs in the service).
3. `PublicBookingController::slots` → `PublicBookingAbuseGuardService::consume` (`read_slots_ip`, IP key) then scoped `read_slots_scope` (calendar-cell composite key) → `PublicBookingService::getPublicSlots` → `requireBranchAnonymousPublicBookingApi` (branch validity + `online_booking.enabled` + `public_api_enabled`), then availability and min-lead filter in PHP.

**GET consent-check**

1. Same middleware as slots for GET.
2. `PublicBookingController::consentCheck` → separate read limits (`read_consent_ip`, `read_consent_branch`) — not the slots buckets → `PublicBookingService::requireBranchAnonymousPublicBookingApi` → **410 Gone** with JSON exactly  
   `{ "success": false, "error": "Public consent status lookup is disabled. Required consents are enforced when you submit a booking." }`  
   — **no** `client_id` / `service_id` handling and **no** `ConsentService` call (PB-HARDEN-08).

**POST book**

1. `CsrfMiddleware` — **POST is not exempt** (see §3).
2. `PublicBookingController::book` — reads **`$_POST` only** (no `php://input` JSON). If POST contains a **positive** `client_id`, **422** + generic public error (PB-HARDEN-NEXT) → else `PublicBookingAbuseGuardService::consume` (**`book`**, IP key) → parse/validate booking fields → **`book_contact`** (SHA-256 of `book_contact|` + normalized identity: email, else phone, else `anonymous`; **no IP**) → **`book_slot`** (SHA-256 of branch+service+staff+normalized start; **no IP**) → **`book_fingerprint`** (SHA-256 composite including IP) → `PublicBookingService::createBooking` → `resolveClient` (**`ClientRepository::create` only** — no email duplicate match, no `client_id`) → `AppointmentService::createFromPublicBooking` → `insertNewSlotAppointmentWithLocks` (`AppointmentRepository::create`). Appointment-layer errors are mapped to **public-safe** strings (consent detail suppressed; PB-HARDEN-NEXT).

**Inside `AppointmentService::insertNewSlotAppointmentWithLocks`** (public path uses `runValidateTimes = false`; `explicitBranchId` set):

- DB transaction (if not nested), `lockActiveStaffAndServiceRows`, resolve `branch_id`, optional `validateTimes` (skipped for public),
- **`ConsentService::checkClientConsentsForService`** — failure throws `DomainException` with missing/expired names (suppressed on the anonymous public response; mapped to `ERROR_PUBLIC_BOOKING_GENERIC`),
- **`AvailabilityService::isSlotAvailable`** — failure throws `DomainException` “Selected slot is no longer available.”,
- `AppointmentRepository::create`, `AuditService::log` with `source` / `public_booking`, `auditActorUserId` null.

**After** `PublicBookingService::createBooking` commits the outer transaction: `issueManageToken` persists **`public_booking_manage_tokens`** (hash) and the controller JSON includes **`manage_token`** with **`appointment_id`**.

---

## 2. Proven current behavior (facts from code)

### 2.0 Public self-service foundations (already implemented)

- Public token-based lifecycle endpoints are present in runtime for manage lookup, slot lookup for reschedule, cancel, and reschedule under `/api/public/booking/manage*`.
- Runtime behavior is backend-contract focused (controller/service flow and mapped errors such as 404/422), not a first-party UI/client-portal product surface.
- This does **not** imply outbound delivery (email/SMS magic-link transport), payment-at-book capability, or CAPTCHA rollout.

### 2.1 Public slot lookup

- **Requires:** `branch_id`, `service_id`, `date` (YYYY-MM-DD); optional `staff_id`.
- **Rejects when:** branch missing/deleted; **`online_booking.enabled`** false or **`public_api_enabled`** false (`requireBranchAnonymousPublicBookingApi`); invalid date format; date outside **today … today + max_days_ahead**; service/staff not active for branch scope.
- **Returns:** JSON `{ success, data: { date, service_id, staff_id, slots[] } }` or `{ success: false, error }` with HTTP 200/422 from controller.

### 2.2 Public booking create

- **Requires:** `branch_id`, `service_id`, `staff_id`, `start_time` (or `date` + time-only `start_time` in POST); `first_name` + `last_name` + `email`. **Positive `client_id` in POST is rejected** (generic 422; PB-HARDEN-NEXT). When `online_booking.allow_new_clients` is false, anonymous POST book **always** fails (`ERROR_ALLOW_NEW_CLIENTS_OFF`).
- **Client row:** always `ClientRepository::create` — **no** email duplicate search / attach to existing client (PB-HARDEN-07).
- **Pre-transaction checks in service:** branch, online settings, min lead / max window, active service/staff, `allow_new_clients` policy.
- **Transactional core:** same locked pipeline as slot booking — consent + slot availability + insert (`AppointmentService`).
- **Post-commit issuance:** after a successful insert + commit, `PublicBookingService::issueManageToken` persists a row in **`public_booking_manage_tokens`** (hashed secret) via `PublicBookingManageTokenRepository::upsertForAppointment`.
- **Response:** JSON `{ success: true, appointment_id, manage_token }` with **201** or error with **422** (rate limit **429** before service). The plaintext **`manage_token`** is the bearer for `/api/public/booking/manage*`; it is **not** stored on the `appointments` row.

### 2.25 Public manage-token model (schema + `/manage*` API)

- **`appointments`** has **no** inline `public_token`, `confirmation_secret`, or similar columns (canonical snapshot: `system/data/full_project_schema.sql`; side table: migration `054_create_public_booking_manage_tokens_table.sql`).
- **Identity store:** **`public_booking_manage_tokens`** — one active logical token per appointment (`UNIQUE` `appointment_id`), **`token_hash`** (SHA-256 of the issued secret), `expires_at`, optional `revoked_at` / `last_used_at`, optional `branch_id`, timestamps. Lookup for manage flows uses `PublicBookingManageTokenRepository::findValidByTokenHash`.
- **`POST /api/public/booking/manage`** (`manageLookup`): body `token` (JSON preferred) → read-only appointment DTO + allowed actions; **404** when token missing/invalid/expired (`PublicBookingService::ERROR_MANAGE_LOOKUP_INVALID`).
- **`POST /api/public/booking/manage/slots`**: body `token`, `date` → reschedule slot list for the same appointment context; invalid token → **404**; other validation → **422**.
- **`POST /api/public/booking/manage/cancel`**: JSON or form `token` (+ optional `reason`) → token-authenticated cancel; invalid token → **404**; policy/unavailable → **422**.
- **`POST /api/public/booking/manage/reschedule`**: JSON or form `token`, `start_at` → token-authenticated reschedule; same status split as cancel path.
- **Rate limits:** manage read/write and token-scoped buckets apply on these routes; when `token` is non-empty, token-hash-derived keys are used (`PublicBookingController`).

### 2.3 Consent check (public route)

- **Requires:** `branch_id` only (for the same anonymous public API gate as slots/book: `requireBranchAnonymousPublicBookingApi`).
- **Does not:** call `ConsentService`; does not accept or use `client_id` / `service_id` for consent lookup; **no** ok/missing/expired payload.
- **Returns:** **410 Gone** with JSON exactly  
  `{ "success": false, "error": "Public consent status lookup is disabled. Required consents are enforced when you submit a booking." }`  
  when the anonymous public API gate passes; **422** when `branch_id` missing, branch invalid, `online_booking.enabled` false, or `public_api_enabled` false. Consent enforcement remains **only** inside `POST /book` → `AppointmentService::insertNewSlotAppointmentWithLocks` (PB-HARDEN-08).

### 2.4 Rate limiting (current runtime hardening status)

- Throttling is **DB-backed** (`public_booking_abuse_hits`, migration `053_create_public_booking_abuse_hits_table.sql`) via `PublicBookingAbuseGuardService` / `PublicBookingAbuseGuardRepository`, wired in `system/modules/bootstrap.php`.
- Buckets in use: GET slots — **`read_slots_ip`** then **`read_slots_scope`** (calendar-cell composite key); GET consent-check — **`read_consent_ip`** then **`read_consent_branch`** (not the slots buckets); POST **`book`** (IP, 6/60s); **`book_contact`** (non-IP identity, SHA-256 key, 10/3600s); **`book_slot`** (non-IP slot pressure, SHA-256 key, 12/300s); **`book_fingerprint`** (SHA-256 of branch/service/staff/start/client-identity/**IP**, 2/300s). Prune retention uses `max(window, 3600s)` per consume (PB-HARDEN-ABUSE-01). **429** does not indicate which bucket tripped.
- Historical note: earlier snapshots described book-only or file-based throttling; that is stale.

---

## 3. Proven blockers and root causes

### 3.1 CSRF status (historical blocker closed in current runtime)

Historical evidence (at the time of the original audit): `CsrfMiddleware` ran for every non-GET request and required a non-empty token matching `$_SESSION['csrf_token']` via `SessionAuth::validateCsrf()` (`system/core/middleware/CsrfMiddleware.php`, `system/core/auth/SessionAuth.php`).

**Current status:** This section is retained for historical traceability only. Runtime hardening has already addressed public booking POST CSRF contract concerns, so this item is no longer the active next-step blocker for Phase 0.

### 3.2 POST body is form-encoded only

**Evidence:** `PublicBookingController::book` reads `$_POST[...]` only; no JSON body parsing in `system/core`.

**Effect:** `Content-Type: application/json` bodies are **ignored** unless the SAPI populates `$_POST` (normally it does not).

**Root cause:** Contract assumes HTML form or `application/x-www-form-urlencoded` / multipart.

### 3.3 Public appointment identity (manage-token side table)

**Evidence:** Migration `054_create_public_booking_manage_tokens_table.sql` + `PublicBookingManageTokenRepository`; `PublicBookingService::issueManageToken` after successful `createBooking` commit; `appointments` remains without inline `public_token` / `confirmation_secret` (identity lives in **`public_booking_manage_tokens`**).

**Effect:** Token-based manage lookup, cancel, and reschedule are implemented in runtime (`/api/public/booking/manage*`). **Outbound** delivery of `manage_token` (email/SMS) remains a separate product/ops item (see §8).

### 3.4 Public self-service status (corrected)

**Current truth:** Public token-based manage/cancel/reschedule entrypoints already exist in runtime (`/api/public/booking/manage*`) and are handled by `PublicBookingController` / `PublicBookingService` with public-safe error contracts.

**Remaining concern area:** Continue validating and hardening edge cases, policy handling, and operational posture; do not regress to staff-only assumptions in docs.

### 3.5 Consent gap for cold-start clients

**Evidence:** Public book runs `checkClientConsentsForService` inside `insertNewSlotAppointmentWithLocks`; required consents **block** booking. The public `consent-check` route does not expose consent status and does not sign; staff-only `DocumentController::signClientConsent` requires auth + `documents.edit`.

**Effect:** New public clients cannot complete booking if the service requires consents until staff signs or a **future public-safe consent capture** exists (out of scope for this map’s minimum cancel/reschedule track; overlaps Phase 3 documents flow).

### 3.6 Operational gaps (verify against current runtime)

- **`PublicBookingService::requireBranchAnonymousPublicBookingApi`** gates GET slots, POST book, and GET consent-check (branch validity + `online_booking.enabled` + `public_api_enabled` via `SettingsService::getOnlineBookingSettings`); token-based manage routes do not use this gate. Consent-check uses the same anonymous public gate as slots/book.
- Abuse guard buckets: **GET slots** — `read_slots_ip` (per-IP key) then `read_slots_scope` (branch+service+date+staff+IP composite); **GET consent-check** — `read_consent_ip` then `read_consent_branch` (branch+IP composite); **POST book** — `book` (per-IP), then `book_contact` and `book_slot` (SHA-256 keys without IP), then `book_fingerprint` (SHA-256 composite including IP). Shared NAT or egress still affects IP-keyed buckets; CAPTCHA, telemetry, and stronger keys are not implemented here.

---

## 4. Postponed/optional hardening items (not implied by current runtime)

*These are not required to describe current truth; they are optional follow-up items when explicitly approved.*

| Need | Minimum approach |
|------|------------------|
| **Token storage hardening (if needed)** | Tokens are stored hashed (`token_hash`) with `expires_at` / indexes per migration 054; further tightening (rotation policy, shorter TTL) remains an optional follow-up if product requires it. |
| **Manage lookup DTO hardening** | Keep/manage limited public DTO fields and avoid exposing internal-only notes/metadata unless explicitly required. |
| **Cancel/reschedule policy edge hardening** | Continue aligning token-based public actions with cancellation/online policy invariants and audit expectations. |
| **Request mode (optional)** | Either new `status` value (e.g. `pending_request`) with different public create path, or separate `appointment_requests` table linked to client/service/staff proposal — avoids overloading `scheduled` semantics. |

**Phase 0-only data (from roadmap next step):** adding a settings key for stricter “this branch accepts public API booking” may use **existing** `settings` storage pattern (`SettingsService`) without `appointments` schema — confirm keys in `SettingsService::getOnlineBookingSettings` / setters when implementing.

---

## 5. Implemented vs postponed scope split

### Implemented foundations (current runtime)

- `POST /api/public/booking/manage`
- `POST /api/public/booking/manage/slots`
- `POST /api/public/booking/manage/cancel`
- `POST /api/public/booking/manage/reschedule`
- Token-based lookup/cancel/reschedule behavior in `PublicBookingController` + `PublicBookingService` with mapped public error contracts.

### Still postponed / optional follow-ups

- Any first-party public UI/client portal surface.
- Outbound link delivery via email/SMS providers.
- Payment and CAPTCHA additions.
- Broad phase rewrite outside focused backend hardening tasks.

---

## 6. Backend ownership (current runtime)

- `PublicBookingController` and `PublicBookingService` own public self-service token lifecycle handling (lookup/manage/cancel/reschedule contracts).
- Existing appointment/availability/settings foundations remain the policy/availability substrate; no UI assumptions are introduced by these backend endpoints.

---

## 7. Safest next-focus order (after implemented foundations)

1. **Abuse / fraud posture hardening** (current Phase 0 next step) — strengthen anti-abuse controls beyond baseline throttling.
2. **Self-service contract hardening** — validate/manage edge cases around token-based manage/cancel/reschedule behavior without broad rewrites.
3. **Optional request mode** (separate status/table + approval flow) — only if product scope explicitly approves it.

---

## 8. Explicitly out of scope for now

- Any **UI** (widgets, pages, CSS, JS product work).
- **Outbound email/SMS** to deliver links (Phase 2); plaintext **`manage_token`** is already issued on successful POST `/book`, but transport is not implemented here.
- **Public consent signing** / full document completion (Phase 3).
- **Payment** at book, **CAPTCHA**, **DB exclusion constraints** — separate Phase 0/ops tasks per roadmap.
- **Broad refactors** of `AppointmentService` — only additive / narrowly scoped methods.
- Any claim that current backend endpoints mean end-user product completion.

---

## 9. Roadmap alignment note

- **Phase 0** residual (per `BOOKER-PARITY-MASTER-ROADMAP.md` **§5.C** / **§5.D**) is abuse / fraud posture (CAPTCHA, WAF, richer identity) **after** **§5.C P0** public-money trust is addressed. Per-branch policy enforcement (including the same gate on the consent-check **route**) is a completed hardening item; public per-client consent **lookup** was removed (PB-HARDEN-08).
- **Phase 1** foundations (token-based public manage/cancel/reschedule backend endpoints) are present in runtime; roadmap sequencing remains backend-first and currently prioritizes Phase 0 abuse/fraud hardening.

---

*Last reviewed against codebase paths: `system/routes/web.php`, `system/core/router/Dispatcher.php`, `system/core/middleware/CsrfMiddleware.php`, `system/core/middleware/BranchContextMiddleware.php`, `system/core/auth/SessionAuth.php`, `system/modules/online-booking/controllers/PublicBookingController.php`, `system/modules/online-booking/services/PublicBookingService.php`, `system/modules/online-booking/services/PublicBookingAbuseGuardService.php`, `system/modules/online-booking/repositories/PublicBookingAbuseGuardRepository.php`, `system/modules/online-booking/repositories/PublicBookingManageTokenRepository.php`, `system/data/migrations/053_create_public_booking_abuse_hits_table.sql`, `system/data/migrations/054_create_public_booking_manage_tokens_table.sql`, `system/modules/bootstrap.php`, `system/modules/appointments/services/AppointmentService.php` (create/cancel/insert pipeline).*
