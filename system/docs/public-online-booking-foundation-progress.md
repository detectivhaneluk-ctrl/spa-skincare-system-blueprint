# Public Online Booking Foundation — Progress

Backend-first public (no-auth) booking: availability lookup, client resolve/create, consent gating, slot re-check, and booking creation. Branch must be supplied in request; lightweight rate limiting on book.

---

## Changed / added files

| File | Role |
|------|------|
| `system/modules/online-booking/services/PublicBookingService.php` | validateBranch, getPublicSlots, resolveClient, createBooking; reuses AvailabilityService, ClientRepository, AppointmentService (consent enforced inside `AppointmentService::insertNewSlotAppointmentWithLocks`, not on public `consent-check` GET) |
| `system/modules/online-booking/controllers/PublicBookingController.php` | slots (GET), book (POST, rate-limited), consentCheck (GET) |
| `system/routes/web.php` | Public routes: GET /api/public/booking/slots, POST /api/public/booking/book, GET /api/public/booking/consent-check (no auth) |
| `system/modules/bootstrap.php` | PublicBookingService, PublicBookingController bindings |
| `system/docs/public-online-booking-foundation-progress.md` | This doc |

---

## Public endpoints

- **GET /api/public/booking/slots**  
  Query: `branch_id` (required), `service_id` (required), `date` (YYYY-MM-DD), `staff_id` (optional).  
  Returns: `{ success, data: { date, service_id, staff_id, slots } }` or `{ success: false, error }`.  
  No auth.

- **POST /api/public/booking/book**  
  Body: `branch_id`, `service_id`, `staff_id`, `start_time` (Y-m-d H:i or H:i; if time only, use `date` as well), `first_name`, `last_name`, `email` (and optional `phone`). Optional `notes`. **Do not send `client_id`:** a positive numeric `client_id` is rejected with **422** and the generic public error (`PublicBookingService::ERROR_PUBLIC_BOOKING_GENERIC`; PB-HARDEN-NEXT).  
  Returns: `{ success, appointment_id }` or `{ success: false, error }`.  
  No auth. Rate-limited: **6 / 60s** per IP (`book`), **10 / 3600s** per normalized contact (`book_contact`, no IP), **12 / 300s** per slot (`book_slot`, no IP), **2 / 300s** per IP-inclusive fingerprint (`book_fingerprint`); PB-HARDEN-ABUSE-01.

- **GET /api/public/booking/consent-check**  
  Query: `branch_id` (required). Same read-bucket rate limit and `requireBranchPublicBookability` as slots/book. **Does not** call `ConsentService` or accept `client_id`/`service_id` for probing. When the branch gate passes: **410 Gone** with exactly  
  `{ "success": false, "error": "Public consent status lookup is disabled. Required consents are enforced when you submit a booking." }`.  
  **422** when `branch_id` missing, branch invalid, or online booking disabled.  
  No auth.

---

## Booking flow rules

1. **Branch:** All public endpoints require `branch_id`. Branch must exist and have `deleted_at IS NULL`. No BranchContext (no user); branch is always from request.
2. **Availability:** Uses existing `AvailabilityService::getAvailableSlots(serviceId, date, staffId, branchId)`. Service and staff (if provided) must be active and in scope for the branch.
3. **Slot re-check:** Immediately before creating the appointment (inside the same transaction as `staff`/`services` `FOR UPDATE`), `AvailabilityService::isSlotAvailable(serviceId, staffId, startAt, null, branchId)` is called. If false, booking fails with "Selected slot is no longer available." Concurrent public books for the same staff slot serialize on the staff row lock; see **PB-HARDEN-CONCURRENCY-VERIFY-01** in `booker-modernization-booking-concurrency-contract.md` §W3.
4. **Client:** `first_name`, `last_name`, `email` (and optional `phone`). Always **insert** a new `clients` row for this branch (`created_by`/`updated_by` null); **no** `ClientRepository::searchDuplicates` / email attach to an existing profile (PB-HARDEN-07). **`client_id` is not accepted** on the anonymous public POST (PB-HARDEN-NEXT). New clients are audited with `source: public_booking` and `actor_user_id` null.
5. **Consent:** Enforced only at booking time inside `AppointmentService::insertNewSlotAppointmentWithLocks` via `ConsentService::checkClientConsentsForService`. If required consents are missing or expired, the **anonymous** API returns only the generic public error string (`ERROR_PUBLIC_BOOKING_GENERIC`); it does **not** echo consent names or missing/expired detail (PB-HARDEN-NEXT). There is **no** public API to pre-query another client’s consent status (PB-HARDEN-08). No consent collection in this phase (client must have signed elsewhere or consent not required for service).
6. **Appointment create:** Uses `AppointmentRepository::create` with `created_by`/`updated_by` null. Audit `appointment_created` with `source: public_booking` and `actor_user_id` null.
7. **Abuse:** POST /api/public/booking/book uses DB sliding windows: per-IP `book` (6/60s), non-IP `book_contact` (10/h per email/phone/anonymous key), non-IP `book_slot` (12/5min per branch+service+staff+start), IP-inclusive `book_fingerprint` (2/5min). Limits run **before** `createBooking` (no client row / appointment write yet). Excess returns **429** + `Retry-After`; response does not name which limit fired (PB-HARDEN-ABUSE-01).

---

## Branch / consent / availability behavior

- **Branch:** Not from session or user. Required in query/body; validated against `branches` (id, deleted_at IS NULL). All availability and booking scoped by this branch.
- **Consent:** At **POST book** only, `AppointmentService::insertNewSlotAppointmentWithLocks` calls `ConsentService::checkClientConsentsForService` with explicit `branchId` — same rules as admin (required definitions; client must have signed and not expired). **`GET /api/public/booking/consent-check` does not call `ConsentService` or return ok/missing/expired** (PB-HARDEN-08).
- **Availability:** Reuses `AvailabilityService::getAvailableSlots` and `isSlotAvailable` (working hours, breaks, blocked slots, existing appointments). No change to conflict or buffer rules.

---

## Manual smoke test checklist

1. **Slots (public)**  
   Without auth: `GET /api/public/booking/slots?branch_id=1&service_id=1&date=2025-12-01`. Expect 200 and `success: true`, `data.slots` array (or empty). Omit `branch_id` → 422. Invalid branch_id → 422 with "Branch not found or inactive."

2. **Book (new client)**  
   POST /api/public/booking/book with branch_id, service_id, staff_id, start_time (or date + start_time), first_name, last_name, email. Expect 201 and `appointment_id`. Confirm appointment and client exist; client has branch_id; appointment has created_by null. **Even if** an older client row already has the same email, this flow must create a **new** client id (no reuse by email).

3. **Book (client_id rejected)**  
   POST with a positive `client_id` → **422** and `ERROR_PUBLIC_BOOKING_GENERIC` (no client lookup; PB-HARDEN-NEXT).

4. **Slot re-check**  
   Request slots, then book the same slot from another request (or after another booking consumes it). Second book should fail with "Selected slot is no longer available."

5. **Consent gating**  
   Set service to require a consent; do not sign for client. POST book → **422** with `ERROR_PUBLIC_BOOKING_GENERIC` only (no consent enumeration on the public response). Sign consent for client (via admin/internal flow), then book → 201.

6. **Rate limit**  
   Send 7+ POST book requests from same IP within 60 seconds; 7th should get 429. After window passes, next request succeeds (if payload valid).

7. **Consent-check route (no probe)**  
   GET /api/public/booking/consent-check?branch_id=1. When online booking is enabled for the branch, expect **410** and body  
   `{ "success": false, "error": "Public consent status lookup is disabled. Required consents are enforced when you submit a booking." }`.  
   When branch invalid or booking disabled, expect **422**. Booking-time consent gating is verified in step 5 (POST book).

---

## Final hardening pass

A later **final backend hardening pass** did not change public booking routes (no auth; branch from request). See `system/docs/archive/system-root-summaries/HARDENING-SUMMARY.md` §5 and `system/docs/archive/system-root-summaries/BACKEND-STATUS-SUMMARY.md`.

---

## Postponed

- **Payment:** No payment integration; booking creates appointment only.
- **Public UI:** No front-end; API only for use by external/widget/SPA later.
- **Client auth / magic link:** No login or magic link for returning clients; anonymous POST book always creates a **new** client row from contact fields (no email-based merge; no `client_id`; PB-HARDEN-NEXT).
- **Intake/registration:** No reuse of client_registration_requests in this flow; public path does not staff-intake link or email-merge into existing clients.
- **`allow_new_clients` false:** Anonymous public booking cannot complete (no authenticated existing-client path); returns `ERROR_ALLOW_NEW_CLIENTS_OFF` until a future signed-in or staff-mediated flow exists.
- **Branch allowlist:** Any non-deleted branch id can be targeted; gating is `online_booking.enabled` (effective per branch via `settings` fallback). No separate `allows_public_booking` key today. **Current audit:** `public-booking-per-branch-bookability-map.md`.
- **CAPTCHA / bot protection:** DB-backed throttling (IP + contact + slot + fingerprint); no CAPTCHA.
- **Abuse telemetry (PB-HARDEN-TELEMETRY-01):** deny/reject paths now write structured audit events for public booking operations via `AuditService` with actions `public_booking_rate_limited`, `public_booking_policy_denied`, and `public_booking_request_rejected` on target type `public_booking`. Metadata is operational-only (`endpoint`, `deny_reason`, `bucket`, `retry_after`, positive IDs, normalized slot start where relevant, optional 12-char hash marker prefixes); no raw email/phone/client_id/notes/consent detail/fingerprint keys.
