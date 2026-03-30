# PAYMENT-METHODS-CATALOG-CANONICALIZATION-FOUNDATION-03 — OPS memo

## Canonical method families (operator-facing)

| Family | Meaning |
|--------|--------|
| **Checks** | Heuristic: `check` / `cheque` in code or name. |
| **Cash** | Heuristic: code `cash` or word `cash` (not check). |
| **Gift cards** | Code `gift_card` (system tender for redemption). |
| **Cards / recorded non-cash** | Heuristic: card brands / explicit card wording (Visa, credit card, etc.). |
| **Other recorded** | Everything else that is not check, cash, or gift_card. |

Implementation: `Modules\Sales\Support\PaymentMethodFamily` — single source for labels, catalog notes, and Payment Settings bucketing.

There is **no** `family` column in `payment_methods`; grouping is mapping-only and must stay aligned with `PaymentSettingsMethodBuckets`.

## What this catalog owns

- Global rows (`branch_id IS NULL`) in `payment_methods`: `code`, `name`, `is_active`, `sort_order`.
- Which tender **codes** exist and are **active** for a branch (merged with global in `PaymentMethodRepository::listActive`).
- Effective **Checks / Cash / recorded non-cash** summaries on **Settings → Payment Settings** via `PaymentMethodService::listForPaymentForm` + `PaymentSettingsMethodBuckets::bucket` (gift_card excluded from the form list).

## What stays outside this page

- Public gift-card **sale** limits (`public_commerce.*`) — **Payment Settings** (Gift cards subsection).
- Card **gateway**, **processor**, **AVS/CVV** — not in this product surface.
- **VAT** — VAT Types / guides; not merged here.
- **Payment engine** behavior — unchanged; this wave is catalog + UI + canonical mapping only.

## Proof

Run (requires PHP CLI):

`php system/scripts/verify_payment_methods_catalog_canonicalization_foundation_03.php`

## How Payment Settings consumes this

1. `SettingsController` loads active methods for the selected branch context through `PaymentMethodService::listForPaymentForm` (excludes `gift_card`).
2. `PaymentSettingsMethodBuckets::bucket` classifies each row using `PaymentMethodFamily::resolve` and fills Checks, Cash, and non-cash (`other`) buckets.

No duplicate regex/heuristics remain in `PaymentSettingsMethodBuckets`.
