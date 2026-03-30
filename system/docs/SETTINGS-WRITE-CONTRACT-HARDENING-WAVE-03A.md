# SETTINGS-WRITE-CONTRACT-HARDENING-WAVE-03A

OPS/task memo: honest read/write contract for three Settings subsections (save correctness only).

## Public channels (`section=public_channels`)

**Controlled keys (POST `settings[...]`, one combined surface):**

- **online_booking.*** — same list as `SettingsService::ONLINE_BOOKING_KEYS` (enabled, public API, lead, horizon, new clients).
- **intake.public_enabled** — `SettingsService::INTAKE_KEYS`.
- **public_commerce.*** — `SettingsService::PUBLIC_COMMERCE_KEYS`.

**Save path:** `SettingsController::store()` runs `patchOnlineBookingSettings`, `patchIntakeSettings`, and `patchPublicCommerceSettings` only when the active section is `public_channels`, using branch context from `online_booking_context_branch_id` (0 = organization default row).

**Read path:** `SettingsController::index()` loads the same three groups with the same branch selector (`online_booking_branch_id` query / normalized id).

**Intentionally not on this page:** establishment, appointments operational UI, cancellation, payments, platform kill switches, commerce catalog SKUs.

**Unchanged:** stored key names; public booking/commerce runtime enforcement outside this controller.

## Notifications (`section=notifications`)

**Controlled keys:** `SettingsService::NOTIFICATIONS_KEYS` (`notifications.appointments_enabled`, `notifications.sales_enabled`, `notifications.waitlist_enabled`, `notifications.memberships_enabled`).

**Semantics:** In-app routing and outbound behavior remain as documented on `SettingsService::NOTIFICATIONS_KEYS` / `patchNotificationSettings` (Sales does not gate outbound payment email in-repo).

**Scope:** Patches use organization default row (`branch_id` 0); UI read uses `getNotificationSettings(null)`.

**Intentionally not here:** delivery engines, templates, per-channel SMS/email product work.

## Membership defaults (`section=memberships`)

**Controlled keys:** `SettingsService::MEMBERSHIPS_KEYS` (`memberships.terms_text`, `memberships.renewal_reminder_days`, `memberships.grace_period_days`).

**Scope:** Organization-wide defaults (`patchMembershipSettings` / `getMembershipSettings` with `null` branch).

**Intentionally not here:** `/memberships` catalog, recurring billing engine, client subscription lifecycle beyond these stored defaults.

## Shared write hardening (this wave)

- POST `settings` is filtered per section via `SECTION_ALLOWED_KEYS`; keys outside the active section are **never** merged into `$post` and are audited as `settings_stripped_keys_ignored` when present in raw POST.
- `SettingsService::onlyPatchKeys` restricts each targeted patch method to documented short keys so stray array entries have no effect.
- **Unchanged:** other settings sections’ merge behavior; branch vs global rules for domains that already used branch scope.

## Verification

```bash
php system/scripts/verify_settings_write_contract_wave_03a.php
```

## Deferred (explicitly out of scope)

- Branch-scoped notification or membership UI (if added later, contract must be revisited).
- Broader allowlist refactors for sections outside the three above.
- Any outbound notification pipeline or public booking rule changes.
