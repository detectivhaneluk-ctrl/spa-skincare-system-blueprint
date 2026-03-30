# Phase D — Waitlist Settings + Internal Notifications

Backend-first implementation. No UI redesign; minimal settings section and JSON notification endpoints.

---

## What was changed

### STEP D1 — Waitlist Settings foundation

- **SettingsService** (`system/core/app/SettingsService.php`): Added `WAITLIST_KEYS`, `getWaitlistSettings(?int $branchId)`, `setWaitlistSettings(array $data, ?int $branchId)` for: waitlist.enabled, waitlist.auto_offer_enabled, waitlist.max_active_per_client, waitlist.default_expiry_minutes. Defaults: enabled=true, auto_offer_enabled=false, max_active_per_client=3, default_expiry_minutes=30.
- **WaitlistRepository** (`system/modules/appointments/repositories/WaitlistRepository.php`): Added `countActiveByClient(int $clientId, ?int $branchId): int` — counts entries with status IN ('waiting','matched') for the client in the branch.
- **WaitlistService** (`system/modules/appointments/services/WaitlistService.php`): Injected SettingsService and NotificationService. In `create()`: after branch enforce, loads waitlist settings for branch; if waitlist.enabled is false throws "Waitlist is disabled for this branch."; if client_id is set, checks countActiveByClient vs max_active_per_client and throws if at or above limit. Notification hook added in convertToAppointment (D2).
- **SettingsController** and **Settings view**: Waitlist section (enabled, auto_offer_enabled, max_active_per_client, default_expiry_minutes); store block for waitlist.*; isGroupedKey and Other exclude waitlist.*.
- **Branch-write parity update (backend-only):** `SettingsController` now reuses the existing `online_booking_context_branch_id` context to load/save `waitlist.*` and `notifications.*` per selected branch (fallback global when context is 0), matching branch-aware runtime reads in `WaitlistService`, `AppointmentService`, and `PaymentService`.
- **Seed** (`system/data/seeders/007_seed_phase_d_waitlist_settings.php`): Sets waitlist defaults for branch_id 0 via setWaitlistSettings.
- **Bootstrap**: WaitlistService receives SettingsService and NotificationService.

### STEP D2 — Internal Notifications foundation

- **Migration** (`system/data/migrations/048_create_notifications_table.sql`): New table `notifications` (id, branch_id NULL, user_id NULL, type, title, message NULL, entity_type NULL, entity_id NULL, is_read DEFAULT 0, created_at, read_at NULL). Indexes and FKs to branches, users. Scope: branch_id NULL = global; user_id NULL = branch-level (all staff).
- **Full schema** (`system/data/full_project_schema.sql`): Added `notifications` table definition.
- **NotificationRepository** (`system/modules/notifications/repositories/NotificationRepository.php`): find(id), list(filters), count(filters), create(data), markRead(id), markAllReadForUser(userId), markAllReadForBranch(branchId), listForUser(userId, branchId, filters), countForUser(userId, branchId, filters).
- **NotificationService** (`system/modules/notifications/services/NotificationService.php`): create(data), listForUser(userId, branchId, filters), countForUser, list, count, find, markRead, markAllReadForUser, markAllReadForBranch. create() validates type and title.
- **Creation hooks (safe points only):**
  - **WaitlistService::convertToAppointment**: After successful conversion and audit logs, creates notification type `waitlist_converted`, branch_id from waitlist, user_id NULL, entity_type appointment, entity_id new appointment id. Wrapped in try/catch so notification failure does not roll back conversion.
  - **AppointmentService::cancel**: After successful cancel and audit log, creates notification type `appointment_cancelled`, title "Appointment cancelled" or "Appointment cancelled (override)" when cancelled_via_override, branch_id from appointment, user_id NULL, entity_type appointment, entity_id appointment id. Wrapped in try/catch.
  - **PaymentService::refund**: After successful refund and audit log, creates notification type `payment_refund`, branch_id from invoice, user_id NULL, entity_type payment, entity_id new refund payment id. Wrapped in try/catch.
- **NotificationController** (`system/modules/notifications/controllers/NotificationController.php`): index() — GET, returns JSON list for current user (branch from BranchContext, visibility: user_id = current OR user_id IS NULL, branch_id = current OR branch_id IS NULL); optional query unread=1, limit, offset. markRead(id) — POST, marks one read for the authenticated user only (after visibility check). markAllRead() — POST, marks all visible as read for the authenticated user only (branch from context).
- **Routes**: GET /notifications (auth + notifications.view), POST /notifications/read-all (auth), POST /notifications/{id}/read (auth).
- **Permission** (`system/data/seeders/001_seed_roles_permissions.php`): Added notifications.view. Owner receives it via existing “all permissions” assignment.
- **Bootstrap**: NotificationRepository, NotificationService, NotificationController; AppointmentService and WaitlistService and PaymentService receive NotificationService where needed.

### Notification read-model hardening (per-user read state)

- **Findings:** Notifications are created with `user_id = NULL` (branch-level). The original implementation stored read state on the `notifications` row (`is_read`, `read_at`). That meant: (1) one user marking a notification read updated the single row, so it appeared read for all users; (2) `markAllReadForUser` did `UPDATE notifications SET is_read = 1 WHERE user_id = ?`, which matched zero rows for branch-level notifications. So read-all had no effect, and mark-read was wrong for multi-user.
- **Exact design decision:** Read state is **per-user** only. A new table `notification_reads` (notification_id, user_id, read_at) with PRIMARY KEY (notification_id, user_id) holds which user has read which notification. The `notifications` table is unchanged; `notifications.is_read` / `read_at` are no longer used by the API. Visibility is unchanged: (n.user_id = current OR n.user_id IS NULL) AND (n.branch_id = current OR n.branch_id IS NULL) when branch set; global user sees n.branch_id IS NULL. For list/count we LEFT JOIN `notification_reads` on (notification_id, user_id = current user); `is_read` in the response is `(nr.user_id IS NOT NULL)`. Mark-read inserts/updates a row in `notification_reads` for (notification_id, current user). Read-all inserts into `notification_reads` one row per visible notification that this user has not already read. Notifications remain non-blocking (creation in try/catch); one user's read state never affects another's; GET returns only notifications visible to the authenticated user in the active branch context; POST mark-read and read-all affect only the authenticated user's read state. Branch isolation: list/mark-read use BranchContext; read-all uses the same visibility as list for the user's branch.
- **Migration 049** (`system/data/migrations/049_create_notification_reads_table.sql`): New table `notification_reads`. Full schema snapshot updated.
- **NotificationRepository**: Replaced `markRead(id)` / `markAllReadForUser(userId)` / `markAllReadForBranch` with `markReadByUser(notificationId, userId)` and `markAllReadByUser(userId, branchId)`. `listForUser` and `countForUser` now LEFT JOIN `notification_reads` for the given user and expose `is_read` from that join; unread filter uses `nr.user_id IS NULL`, read filter uses `nr.user_id IS NOT NULL`.
- **NotificationService**: `markReadByUser(notificationId, userId)`, `markAllReadForUser(userId, branchId)`; removed `markAllReadForBranch`.
- **NotificationController**: markRead verifies visibility then calls `markReadByUser(id, userId)` (and requires authenticated user). markAllRead calls `markAllReadForUser(userId, branchId)` with branch from context.

---

## Files changed

| File | Change |
|------|--------|
| `system/core/app/SettingsService.php` | WAITLIST_KEYS; getWaitlistSettings, setWaitlistSettings. |
| `system/modules/settings/controllers/SettingsController.php` | waitlist in index; isGroupedKey waitlist.; store block waitlist.*. |
| `system/modules/settings/views/index.php` | Waitlist section; Other excludes waitlist. |
| `system/modules/appointments/repositories/WaitlistRepository.php` | countActiveByClient(). |
| `system/modules/appointments/services/WaitlistService.php` | SettingsService, NotificationService; create() enforces enabled + max_active_per_client; convertToAppointment notification hook. |
| `system/modules/appointments/services/AppointmentService.php` | NotificationService; cancel() notification hook. |
| `system/modules/sales/services/PaymentService.php` | NotificationService; refund() notification hook. |
| `system/data/seeders/007_seed_phase_d_waitlist_settings.php` | **New.** Waitlist defaults. |
| `system/scripts/seed.php` | require 007. |
| `system/data/seeders/001_seed_roles_permissions.php` | notifications.view permission. |
| `system/data/migrations/048_create_notifications_table.sql` | **New.** notifications table. |
| `system/data/migrations/049_create_notification_reads_table.sql` | **New.** notification_reads table (per-user read state). |
| `system/data/full_project_schema.sql` | notifications table; notification_reads table. |
| `system/modules/notifications/repositories/NotificationRepository.php` | **New.** listForUser/countForUser use notification_reads; markReadByUser, markAllReadByUser. |
| `system/modules/notifications/services/NotificationService.php` | **New.** markReadByUser, markAllReadForUser(userId, branchId). |
| `system/modules/notifications/controllers/NotificationController.php` | **New.** markRead uses markReadByUser(id, userId); markAllRead uses branchId. |
| `system/modules/bootstrap.php` | Notifications repo/service/controller; WaitlistService, AppointmentService, PaymentService with NotificationService. |
| `system/routes/web.php` | GET /notifications, POST /notifications/read-all, POST /notifications/{id}/read. |

---

## Backward compatibility

- **Waitlist settings:** New keys; getWaitlistSettings() defaults (enabled=true, auto_offer_enabled=false, max_active_per_client=3, default_expiry_minutes=30) preserve previous behaviour. If no seed has run, waitlist remains enabled and limit 3 per client.
- **Waitlist create:** When waitlist.enabled is false, create throws; when client has already max_active_per_client active entries, create throws. Entries without client_id are not limited by max_active_per_client.
- **Notifications:** New table and endpoints; no change to existing appointment/payment/waitlist behaviour. Notification creation is best-effort (try/catch); failures are logged and do not affect the main operation.
- **default_expiry_minutes:** Stored and available via getWaitlistSettings(); not yet applied (no expiry/reservation column on waitlist in this phase). Reserved for future use (e.g. reservation_until or offer_expires_at).
- **auto_offer_enabled:** Stored only; no auto-offer workflow in this phase. Backend foundation/hooks for future use.

---

## Where waitlist settings are enforced

- **waitlist.enabled:** `WaitlistService::create()` — at start of create, after enforceBranchOnCreate; branch from `$data['branch_id']`. If false, throws DomainException "Waitlist is disabled for this branch."
- **waitlist.max_active_per_client:** `WaitlistService::create()` — when `$data['client_id']` is set; uses `WaitlistRepository::countActiveByClient($clientId, $branchId)`; if count >= getWaitlistSettings()['max_active_per_client'], throws DomainException "Maximum active waitlist entries per client (N) reached." Active = status IN ('waiting','matched').
- **waitlist.default_expiry_minutes / waitlist.auto_offer_enabled:** Enforced in `WaitlistService` (offer timestamps, auto-offer on slot freed when enabled) and `expireDueOffers`; see migration `056_appointment_waitlist_offer_columns.sql`.

---

## Where internal notifications are created

1. **waitlist_converted** — `WaitlistService::convertToAppointment()` after repo update and audit logs; branch_id from conversion, user_id NULL; entity_type appointment, entity_id new appointment id.
2. **appointment_cancelled** — `AppointmentService::cancel()` after repo update and audit log; branch_id from appointment; title includes "(override)" when cancelled within min notice with override; entity_type appointment, entity_id appointment id.
3. **payment_refund** — `PaymentService::refund()` after recompute and audit log; branch_id from invoice; entity_type payment, entity_id new refund payment row id.
4. **membership_renewal_reminder** — `MembershipService::dispatchRenewalReminders()` (CLI/cron); gated by `notifications.memberships_enabled` for the client membership’s `branch_id`; user_id NULL; entity_type `client_membership`.

---

## Manual QA checklist

### Waitlist settings (D1)

1. Run migrations (including 048) and seed (including 007). Open /settings; confirm Waitlist section: Waitlist enabled, Auto-offer enabled (foundation), Max active entries per client (e.g. 3), Default expiry (minutes) (e.g. 30). Save.
2. Create a waitlist entry with a client and branch; confirm it succeeds. Set Waitlist enabled OFF; save. Try to create another waitlist entry (same or different client) — should fail with "Waitlist is disabled for this branch." Set enabled ON again.
3. Set Max active entries per client to 2; save. Create two waitlist entries for the same client (same branch), both status waiting or matched — should succeed. Create a third for the same client — should fail with "Maximum active waitlist entries per client (2) reached."
4. Create waitlist entry without client (client_id empty) — should not be blocked by max_active_per_client (limit applies per client). Confirm entries with status booked or cancelled do not count toward the limit.
5. Convert a waitlist entry to appointment — should succeed; no change to existing behaviour except notification created (check notifications table or GET /notifications).

### Internal notifications (D2)

1. Run migrations 048 and 049. Confirm tables `notifications` and `notification_reads` exist.
2. Convert a waitlist entry to appointment; query `SELECT * FROM notifications WHERE type = 'waitlist_converted'` — one row, entity_type appointment, entity_id = new appointment id.
3. Cancel an appointment (optionally within min notice with override); query `SELECT * FROM notifications WHERE type = 'appointment_cancelled'` — one row; when override used, title should contain "override".
4. Record a refund for a payment; query `SELECT * FROM notifications WHERE type = 'payment_refund'` — one row, entity_id = refund payment id.
5. As a user with notifications.view, GET /notifications — JSON with notifications array (each has is_read from notification_reads for this user), total, limit, offset. GET /notifications?unread=1 — only unread for this user. POST /notifications/{id}/read — mark one read for this user; GET again as same user to confirm is_read true; as another user, same notification still unread. POST /notifications/read-all — mark all visible as read for this user only.

### Multi-user same-branch (read-model QA)

1. Create at least one branch-level notification (e.g. convert waitlist to appointment for that branch). Log in as **User A** (branch X). GET /notifications — see the notification, is_read false. POST /notifications/{id}/read for that id. GET again as User A — is_read true.
2. Log in as **User B** (same branch X). GET /notifications — same notification appears with is_read **false** (User B has not read it). Do not mark read as User B.
3. Log in as **User A** again. GET /notifications — notification still is_read true. Confirm one user marking read did not mark it for the other.
4. As **User B**, POST /notifications/read-all. GET as User B — all visible notifications now is_read true. GET as **User A** — unchanged (still only the ones A had read). Confirm read-all affects only the authenticated user.

### Cross-branch isolation

1. Create notifications in **Branch X** (e.g. convert waitlist in branch X, cancel appointment in branch X). Create notifications in **Branch Y** (e.g. same actions in branch Y).
2. As user scoped to **Branch X**, GET /notifications — only notifications for branch X (or global). Must not see branch Y notifications.
3. As user scoped to **Branch Y**, GET /notifications — only notifications for branch Y (or global). Must not see branch X notifications.
4. As **User A** in branch X, POST /notifications/{id}/read for a branch X notification. As **User A** with context switched to branch Y (if supported), or as user in branch Y, GET /notifications — must not include branch X notification; or if same user can see multiple branches, branch Y list must not show branch X notification. Mark-read for a notification id that belongs to another branch must return 404.

---

## Postponed / limitations (historical vs current repo)

**Current truth (2026-03-21):** Waitlist **offer expiry columns**, **auto-offer on slot freed**, **outbound enqueue on offer** (`WaitlistService` + migration `056`+), and **outbound queue** (`072`) **are shipped**. See **§ “Where waitlist settings are enforced”** above and **`BOOKER-PARITY-MASTER-ROADMAP.md` §5**.

The bullets below are **Phase D original scope notes** — some are **superseded**; keep for audit history only.

- **default_expiry_minutes / auto_offer:** **Superseded** — enforced in runtime (see § “Where waitlist settings are enforced”).
- **Broader backlog:** External **transport** maturity (real SMTP/provider, SMS), public **commerce payment trust**, marketing/payroll depth — **`BOOKER-PARITY-MASTER-ROADMAP.md` §5.C** / **§5.D**.
- **Notification UI:** Staff JSON list remains primary; no first-party bell widget required by this doc.
- **Email/SMS delivery:** Outbound rows + dispatch script exist; **production provider + deliverability** remain **§5.C P1**.
- **User-targeted notifications:** Creation hooks often use `user_id` NULL (branch-level); user-specific targeting remains a future option.

---

## Phase D acceptance readiness

**Phase D is now acceptance-ready.** The notification read model is correct: read state is per-user via `notification_reads`; one user cannot mark a notification as read for all users; GET /notifications returns only notifications visible to the authenticated user in the active branch context; POST mark-read and read-all affect only the authenticated user. Branch isolation is preserved. No new UI or extra features were added in the hardening pass.
