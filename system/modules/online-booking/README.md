# online-booking

Public (no-auth) booking API foundation.

## Responsibility
- Public availability (slots) lookup only; **staff** sessions, password changes, and security settings (including password expiration policy) live in core auth — **not** in this module.
- **POST book** always creates a **new** client row from contact fields (no public email-merge, **no** `client_id`; PB-HARDEN-NEXT)
- Consent gating at **POST book** only (`AppointmentService` / `ConsentService`; not exposed on public GET). Consent failures return a **generic** public `error` string (no missing/expired enumeration on the anonymous API)
- Booking creation with slot re-check and rate limiting; concurrent public books for the same staff/time serialize on **`staff` / `services` `FOR UPDATE`** inside `AppointmentService::insertNewSlotAppointmentWithLocks` so a second insert cannot slip past `isSlotAvailable` after the first commits (**PB-HARDEN-CONCURRENCY-VERIFY-01** — see `booker-modernization-booking-concurrency-contract.md` §W3)

## Routes (no auth)
- `GET /api/public/booking/slots` — availability (**GET only** — no POST). Gated by **`PublicBookingService::requireBranchAnonymousPublicBookingApi`**: valid branch, **`online_booking.enabled` = true**, **`public_api_enabled` = true** (`SettingsService::getOnlineBookingSettings`). DB-backed abuse guard — **Read limits:** per-IP `read_slots_ip` (40/min) plus scoped `read_slots_scope` (branch+service+date+staff+IP composite; PB-ABUSE-GUARD-IDENTITY-DEPTH-01) — not a single shared bucket with consent-check.
- `POST /api/public/booking/book` — create booking (DB-backed abuse guard; PB-HARDEN-ABUSE-01): **6 / 60s** per IP (`book`), **10 / 3600s** per normalized contact identity without IP (`book_contact`, SHA-256 key: email → `email:…`, else phone → `phone:…`, else `anonymous`), **12 / 300s** per slot without IP (`book_slot`, SHA-256 of branch+service+staff+normalized start), **2 / 300s** per IP-inclusive fingerprint (`book_fingerprint`). Body: `first_name`, `last_name`, `email` (+ optional `phone`, `notes`). **Do not send `client_id`:** any positive numeric `client_id` in POST is rejected with **422** and `PublicBookingService::ERROR_PUBLIC_BOOKING_GENERIC` (PB-HARDEN-NEXT). When `online_booking.allow_new_clients` is false for the branch, anonymous POST book always fails with `ERROR_ALLOW_NEW_CLIENTS_OFF`.
- `GET /api/public/booking/consent-check` — DB-backed abuse guard; **read limits:** per-IP `read_consent_ip` (40/min) plus scoped `read_consent_branch` (branch+IP composite; not the slots buckets) — required `branch_id` + `requireBranchAnonymousPublicBookingApi`; **no** `client_id`/`service_id`, **no** `ConsentService` lookup; **410 Gone** with `{ "success": false, "error": "Public consent status lookup is disabled. Required consents are enforced when you submit a booking." }` when the anonymous public API gate passes (PB-HARDEN-08)

Throttle violations return **429** with the same JSON `{ "success": false, "error": "Too many requests. Please try again later." }` and a **Retry-After** header (seconds). **Which** limit fired is not exposed.

`PublicBookingAbuseGuardService` prunes hit rows using `max(bucket_window, 3600)` seconds so mixed short/long windows on one request stay consistent.

## Public-safe errors (PB-HARDEN-NEXT)
The **`book_fingerprint` composite’s `client:` segment** is derived only from normalized **email**, else **phone**, else **`anonymous`** (same as `normalizeClientIdentityForFingerprint` in `PublicBookingController`). It **never** incorporates `client_id`, even if a client were to send it (positive `client_id` is rejected before throttles run).

Anonymous **POST book** uses fixed strings from `PublicBookingService` for responses that must not reveal client existence, branch membership, or consent detail:
- `ERROR_PUBLIC_BOOKING_GENERIC` — `Booking could not be completed. Please contact the spa if you need help.`
- `ERROR_ALLOW_NEW_CLIENTS_OFF` — `Online booking is not available for this request at this branch. Please contact the spa.`

A small **allowlist** of operational messages from the locked appointment pipeline may still be returned (e.g. slot no longer available). All other `DomainException` / `InvalidArgumentException` text from that path is mapped to `ERROR_PUBLIC_BOOKING_GENERIC`.

## Dependencies
- Appointments (AvailabilityService, AppointmentRepository)
- Clients (ClientRepository)
- Documents (ConsentService)
- Core (Database, Audit)
