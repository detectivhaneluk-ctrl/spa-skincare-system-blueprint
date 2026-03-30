# MARKETING-CONTACT-LISTS-BACKEND-FOUNDATION-01 OPS NOTE

## Schema Added

- `100_marketing_contact_lists_foundation.sql`
  - `marketing_contact_lists` (branch-owned manual list header, archiveable)
  - `marketing_contact_list_members` (membership bridge to real `clients` rows)
  - Unique key on `(list_id, client_id)` to prevent duplicate memberships.

## Smart Lists Included

- `all_contacts`
- `marketing_email_eligible`
- `marketing_sms_eligible`
- `birthday_this_month`
- `first_time_visitors`
- `no_recent_visit_45_days`

These are backend definitions in `MarketingContactAudienceService`, resolved by query conditions in `MarketingContactAudienceRepository`.

## Eligibility Truth Used

- Source of truth is existing `clients` + `appointments`.
- Email/SMS eligibility currently uses:
  - non-empty channel field (`email` / `phone`)
  - `clients.marketing_opt_in = 1`
  - basic email format validation for email eligibility
- `unsubscribed` is surfaced as the inverse of current `marketing_opt_in`.
- `blocked` is currently always `false` because no dedicated contact-channel blocked table exists in current schema.

## Known Gaps / Deferred

- No dedicated per-channel unsubscribe/blocked model yet (email-specific or sms-specific suppression list).
- No additional legal-jurisdiction consent matrix exists in current schema; eligibility is conservative best-effort from available truth.
- Contact grid pagination is intentionally minimal (first page) for this foundation wave.

