# PAYMENT-SETTINGS-BOOKER-GAP-CLOSURE-FOUNDATION-04 — OPS memo

Repo audit date: working tree (ZIP-truth). No `refund.*` or `price_modification*` settings keys in `SettingsService` / `SettingsController` allowlists. No migration or repository for “price modification reasons.”

## Receipt / invoice (repo truth)

- `payments.receipt_notes` — org-level string (`SettingsService::PAYMENT_KEYS`, `SettingsController::PAYMENT_WRITE_KEYS`).
- `hardware.use_receipt_printer` — saved from `section=payments` POST alongside receipt notes (existing allowlist).
- No receipt header/footer/client-info toggles found in settings.

## Refund policy (repo truth)

- No organization-wide refund policy content setting.
- Invoice `refunded` status / `PaymentService::refund` are operational code paths, not a configurable “policy” row here.
- **Linked:** `/memberships/refund-review` — membership billing refund **review queue** (`memberships.manage`), not a policy editor.

## Price modification reasons (repo truth)

- No table, service, route, or settings keys for adjustment/override reason catalogs.

## Final subsection classification

| # | Subsection | Mode | Backing truth |
|---|------------|------|----------------|
| 1 | Checks | Derived + **LINKED_DOMAIN** | `PaymentMethodRepository::listActive` + `PaymentSettingsMethodBuckets`; manage → `/settings/payment-methods` |
| 2 | Cash | Derived + **LINKED_DOMAIN** | Same + hardware register summary; methods + `/settings?section=hardware` |
| 3 | Credit cards / recorded non-cash | **EDITABLE_SETTINGS** | `payments.default_method_code`, `payments.allow_partial_payments`, `payments.allow_overpayments` |
| 4 | Gift cards | **EDITABLE_SETTINGS** + optional **LINKED_DOMAIN** | `public_commerce.allow_gift_cards`, min/max; optional `/gift-cards` |
| 5 | Prepaid series | **LINKED_DOMAIN** | `/packages` (packages module); no prepaid settings keys |
| 6 | Deposits | **HONEST_DEFERRED** | None in repo |
| 7 | PMS | **HONEST_DEFERRED** | None in repo |
| 8 | Direct debit / bank debit | **HONEST_DEFERRED** | None in repo |
| 9 | PayPal | **HONEST_DEFERRED** | None in repo |
| 10 | PayPal mobile | **HONEST_DEFERRED** | None in repo |
| 11 | Client account | **HONEST_DEFERRED** | None in repo |
| 12 | Split order / split invoice | **HONEST_DEFERRED** | None in repo |
| 13 | Spa points / minutes | **HONEST_DEFERRED** | None in repo |
| 14 | Tips / service fee | **HONEST_DEFERRED** | None in repo |
| 15 | Price modification reasons | **HONEST_DEFERRED** | None in repo |
| 16 | Refund policy | **HONEST_DEFERRED** + optional **LINKED_DOMAIN** | No policy setting; link membership refund review if `memberships.manage` |
| 17 | Receipt & invoice | **EDITABLE_SETTINGS** | `payments.receipt_notes`, `hardware.use_receipt_printer` |
| 18 | Related finance surfaces | **LINKED_DOMAIN** | Payment methods, VAT rates, VAT distribution guide |

## Intentionally deferred (no fake backend)

Deposits, PMS, bank debit, PayPal variants, client account, split invoice, spa points, tips/service fee, price modification reasons, global refund policy text.

## Permission flag added

- `canManageMembershipsLink` — `memberships.manage` (for refund review link). Exposed via `SettingsShellSidebar::permissionFlagsForUser` and `settings/views/index.php`.
