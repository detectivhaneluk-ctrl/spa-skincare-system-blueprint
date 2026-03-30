# Per-branch public bookability — implementation map (completed historical context)

**Status:** Completed historical audit/implementation context. Per-branch public bookability hardening is now code-proven complete; this file is retained for traceability.

**Aligns with:** `BOOKER-PARITY-MASTER-ROADMAP.md` (Phase 0 completed item: per-branch public bookability; **canonical next work** is **§5.C** — public-money trust and enforcement gaps precede further abuse-only polish).

---

## 1. Current code-derived facts

### 1.1 `branches` table (schema source of truth)

From `system/data/full_project_schema.sql` — table `branches`:

| Column | Purpose |
|--------|---------|
| `id` | Primary key; used as `branch_id` everywhere in public API |
| `name`, `code` | Labels; `code` unique when set |
| `created_at`, `updated_at` | Audit timestamps |
| `deleted_at` | Soft delete |

**There is no column** such as `allows_public_booking`, `is_active`, or `public_booking_enabled` on `branches`. “Inactive” for public validation means **`deleted_at IS NOT NULL`** only.

### 1.2 Online booking settings — keys and storage

**Source of truth in code:** `Core\App\SettingsService` (`system/core/app/SettingsService.php`).

**Keys** (constants `ONLINE_BOOKING_KEYS`):

- `online_booking.enabled` (bool, default `false` when missing)
- `online_booking.min_lead_minutes` (int, default `120`)
- `online_booking.max_days_ahead` (int, default `60`)
- `online_booking.allow_new_clients` (bool, default `true`)

**Storage:** table `settings` — columns include `key`, `value`, `type`, `setting_group` (`online_booking`), **`branch_id`** (`UNIQUE` on `(key, branch_id)`). `branch_id = 0` is used when `null` is passed to getters/setters (`$bid = $branchId ?? 0`).

**Read resolution (`SettingsService::get`):**

```text
WHERE key = ? AND (branch_id = ? OR branch_id = 0)
ORDER BY branch_id DESC
LIMIT 1
```

So for a **non-zero** branch id, a row with that `branch_id` **overrides** the global (`branch_id = 0`) row for the same key. If only `branch_id = 0` rows exist, public requests for branch `N` still receive those values.

**Conclusion — global vs per-branch:**

- **Mechanism:** Per-branch **overrides are supported** at read time.
- **Admin UI today (current truth):** `SettingsController` resolves a branch-aware settings context and loads/saves `online_booking`, `payments`, `cancellation`, `appointments`, `waitlist`, `security`, and `notifications` against that context branch (`null` => global `branch_id = 0`, non-zero => branch override).

So the controller path is no longer global-only for online-booking settings; broader settings parity gaps remain a separate concern.

### 1.3 How public endpoints validate branch today

**Routes:** `system/routes/web.php` — `GET/POST /api/public/booking/slots`, `POST /api/public/booking/book`, `GET /api/public/booking/consent-check`; **no** route-level middleware (empty `[]`); global pipeline still runs (`CsrfMiddleware`, `ErrorHandlerMiddleware`, `BranchContextMiddleware`).

**Service:** `Modules\OnlineBooking\Services\PublicBookingService` (`system/modules/online-booking/services/PublicBookingService.php`).

| Step | Behavior |
|------|----------|
| Branch + online gate | `requireBranchPublicBookability($branchId)` — `validateBranch` then effective `getOnlineBookingSettings($branchId)`; if `!$ob['enabled']` → **“Online booking is not enabled for this branch.”** |
| Consent-check (controller) | After read-bucket rate limit, **`PublicBookingService::requireBranchPublicBookability`** (same gate as slots/book), then **410** fixed response — **no** `ConsentService` / per-client lookup (PB-HARDEN-08) |

**Call sites:**

- `getPublicSlots` — `requireBranchPublicBookability` → …
- `createBooking` — `requireBranchPublicBookability` → …
- `consentCheck` (controller) — `requireBranchPublicBookability` → **410** disabled response (no consent probe)

### 1.4 Does “online booking enabled” exist globally, per branch, both, or neither?

| Layer | Fact |
|-------|------|
| **Data model** | Single key namespace; **scoped by `settings.branch_id`** with **fallback to 0** on read. |
| **Public runtime** | Always evaluates **effective settings for the requested `branch_id`** (override + global). |
| **Admin runtime** | `SettingsController` now applies a branch-aware context for key domains (`online_booking`, `payments`, `cancellation`, `appointments`, `waitlist`, `security`, `notifications`) with global fallback when no branch context is selected. |
| **Separate “public allowlist” flag** | Exists as **`online_booking.public_api_enabled`** and is enforced by the anonymous-public gate (`requireBranchAnonymousPublicApi`) in public booking runtime (alongside branch validation and `online_booking.enabled`). |

**Out of scope for this map (but real in app):** Staff login, session, and password policy (including optional `users.password_changed_at` after migration 055) are enforced in core auth (`AuthMiddleware`, `AuthService`, `SessionAuth`). They do **not** apply to anonymous `/api/public/booking/*` routes.

---

## 2. Gaps vs original audit intent (updated to match current code)

**Closed relative to original §2 concerns**

- **Consent-check route vs slots/book policy** — `PublicBookingController::consentCheck` calls `requireBranchPublicBookability` like slots/book, then returns **410** without per-client consent data (PB-HARDEN-08); disabled online booking still rejects with **422** like slots/book.

**Still open / product notes**

1. **No `branches`-level flag** — “internal only” still maps to `settings` / `online_booking.enabled` (or misusing `deleted_at`), not a dedicated column on `branches`.
2. **Broader settings parity remains the gap** — branch-aware controller context exists, but Booker-style parity still depends on deeper domain coverage and operationalization breadth.
3. **Admin/runtime parity remains an ongoing concern** — `online_booking.public_api_enabled` exists and anonymous runtime enforces it; remaining parity work is broader than only online-booking branch read/write mechanics.

**Out of scope here (already documented elsewhere):** CSRF on `POST /book`, `$_POST`-only body, Phase 1 tokens — see `public-self-service-implementation-map.md`.

---

## 3. Implemented minimum shape (historical recommendation now fulfilled)

**Goal achieved:** Smallest completed change that gives **explicit per-branch control** of whether **anonymous public API** may use slots/book for that branch, without redesigning the whole settings UI.

### 3.1 Current implemented shape (source-of-truth)

- `online_booking.public_api_enabled` is already present in settings keys/service return shape.
- Anonymous public API runtime already enforces both:
  - `online_booking.enabled`, and
  - `online_booking.public_api_enabled`.
- `SettingsController` already applies branch context for online-booking read/write behavior.

### 3.2 Enforcement points (exact, implemented)

| Endpoint | Service method | Current enforcement |
|----------|----------------|---------------------|
| GET slots | `PublicBookingService::getPublicSlots` | `requireBranchAnonymousPublicBookingApi` (`enabled` + `public_api_enabled`) |
| POST book | `PublicBookingService::createBooking` | `requireBranchAnonymousPublicBookingApi` (`enabled` + `public_api_enabled`) |
| GET consent-check | `PublicBookingController::consentCheck` | same branch gate path, then **410** fixed response (no `ConsentService` / client probe) |

### 3.3 Runtime responses when not publicly bookable

Match existing patterns for consistency:

- **Unknown / deleted branch:** `422`, `{ "success": false, "error": "Branch not found or inactive." }` via `requireBranchPublicBookability` (slots, book, consent-check route).
- **Online booking disabled:** `422`, `{ "success": false, "error": "Online booking is not enabled for this branch." }`
- **Public API disabled for branch:** `{ "success": false, "error": "Public online booking is not available for this branch." }`
- **Consent-check when branch is bookable (PB-HARDEN-08):** `410 Gone` with fixed JSON error — not a `200` consent payload.

HTTP status codes: slots/book as before (`200`/`201` vs `422`); consent-check adds **`410`** when the branch gate passes (no per-client consent response).

### 3.4 Interaction with global online-booking settings

- **Read path:** Unchanged resolution order (`branch_id` row beats `0`).
- **Write path:** Any new key must be written with explicit `branch_id` when saving branch-specific policy; global `0` remains default template.
- **Appointment / staff UI:** Unaffected; public API is the only consumer of this audit’s enforcement.

---

## 4. Evidence map of files changed/used by completed implementation

| File | Change |
|------|--------|
| `system/core/app/SettingsService.php` | Includes `online_booking.public_api_enabled` in online-booking settings shape |
| `system/modules/online-booking/services/PublicBookingService.php` | Enforces anonymous public gate (`enabled` + `public_api_enabled`) and branch policy checks |
| `system/modules/online-booking/controllers/PublicBookingController.php` | `consentCheck` uses branch gate path and fixed 410 response (no consent probe) |
| `system/modules/settings/controllers/SettingsController.php` | Branch-scoped load/save for settings domains via selected context |
| `system/modules/settings/views/index.php` | Branch context wiring used by settings sections |
| `system/data/migrations/*.sql` | No `branches` schema flag required for implemented minimum shape |
| Seed scripts | Optional defaults per branch where operationally needed |

**Not required for minimal path:** `routes/web.php`, `AppointmentService`, `AvailabilityService` (unless extracting shared helper elsewhere).

---

## 5. Verification snapshot (historical checks now satisfied)

1. **Data:** For branch `B`, set effective `online_booking.enabled` = false (branch row or global only — document which). `GET /api/public/booking/slots?branch_id=B&...` → `success: false` and disabled message; `POST /book` same; `GET /api/public/booking/consent-check?branch_id=B&...` → **422** with the same gate as slots/book (not **410** — 410 only when booking is enabled and the route intentionally declines consent probing).
2. **Override:** Global `enabled` true, branch `B` row `enabled` false → public API for `B` disabled; branch `C` with no row → follows global (true).
3. **Branch deleted:** `deleted_at` set → existing `validateBranch` errors unchanged.
4. **Regression:** Branch with enabled true → slots return 200 when valid; book still subject to CSRF (unchanged).

---

## 6. Explicitly out of scope (at time of original audit)

- Further expansion beyond the completed minimum shape in this map.
- Phase 1 self-service tokens, cancel/reschedule by token.
- CSRF policy, JSON body parsing, rate-limit redesign.
- New `branches` columns **unless** a follow-up explicitly chooses that over `settings`.
- Marketing, payments, documents e-sign, UI redesign.

---

*Evidence inspected: `system/data/full_project_schema.sql` (`branches`, `settings`), `system/core/app/SettingsService.php` (`get`, `getOnlineBookingSettings`, `setOnlineBookingSettings`), `system/modules/settings/controllers/SettingsController.php`, `system/modules/online-booking/services/PublicBookingService.php`, `system/modules/online-booking/controllers/PublicBookingController.php`, `system/routes/web.php`.*
