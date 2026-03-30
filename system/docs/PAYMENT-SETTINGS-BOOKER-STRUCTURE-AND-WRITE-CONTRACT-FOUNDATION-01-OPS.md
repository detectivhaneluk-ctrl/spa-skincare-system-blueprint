# PAYMENT-SETTINGS-BOOKER-STRUCTURE-AND-WRITE-CONTRACT-FOUNDATION-01 — OPS memo

## Canonical subsections (main Payment Settings page)

1. **Checks** — Read-only preview derived from active `payment_methods` rows (heuristic: code/name suggests check/cheque). No `settings` keys; tender acceptance is configuration in **Custom Payment Methods**.
2. **Cash** — Same pattern for cash-like methods; **cash register** read-only summary from `hardware.use_cash_register` (see **Hardware** settings to change). No separate “accept cash” boolean in `settings`.
3. **Credit cards & other recorded methods** — Summarizes non-check, non-cash active methods; **organization**-scoped editable `payments.*`: `default_method_code`, `allow_partial_payments`, `allow_overpayments`, `receipt_notes`. No gateway, AVS, CVV, or processor keys exist in this codebase.
4. **Gift cards (public purchase)** — Branch-effective `public_commerce.allow_gift_cards`, `public_commerce.gift_card_min_amount`, `public_commerce.gift_card_max_amount` (same keys as **Public channels → Public commerce**). Redemption, tax treatment, cashback, expiry policies, and archive timing are **not** exposed as dedicated settings rows here.

## Branch context (`payments_branch_id`)

- **Gift card limits** read/write and **payment method preview** (`listForPaymentForm`) use the selected branch (or organization default when 0).
- **`payments.*` edits remain organization scope** (`patchPaymentSettings(..., null)`), matching prior behavior.

## Sibling surfaces (stay separate)

- **Custom Payment Methods** — `/settings/payment-methods`
- **VAT Types** — `/settings/vat-rates`
- **VAT distribution (guide)** — `/settings/vat-distribution-guide`

## Verification

- `php system/scripts/verify_payment_settings_control_plane_foundation_01.php` — static allowlist / wiring checks (no DB).

## Intentionally deferred

- Card processor / merchant integrations, AVS/CVV, stored card tokens.
- Gift-card lifecycle beyond public min/max (expiry, exchange, tax, inactive rules as dedicated settings).
- Merging VAT or custom method admin into this page.
