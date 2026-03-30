# Gift Cards Module (Phase 5A)

Gift Cards foundation module for:
- issuing cards
- redeeming cards
- manual balance adjustments
- cancellation
- explicit expiration handling
- transaction history

## Business rules implemented

- `gift_card_transactions` is the source of truth for balance.
- Current balance is derived from latest `balance_after`.
- Redeem amount must be greater than zero.
- Issue amount must be greater than zero.
- Adjustment may be positive or negative, but cannot reduce balance below zero.
- Cancelled or expired cards cannot be redeemed.
- Status transitions:
  - `active` on issue
  - `used` when balance reaches zero
  - `expired` when `expires_at` is reached and explicit expiration handling runs
  - `cancelled` on cancel action
- All balance-changing operations run in DB transactions.

## Branch behavior (explicit)

- `branch_id` is nullable (global card support).
- List filters support:
  - specific branch
  - global only
  - explicit mixed view
- Redeem/adjust validate branch match when card has branch ownership.
- No hidden automatic branch context is assumed.

## Permissions

- `gift_cards.view`
- `gift_cards.create`
- `gift_cards.issue`
- `gift_cards.redeem`
- `gift_cards.adjust`
- `gift_cards.cancel`

## Integration boundaries

- No direct coupling to sales repositories.
- No automatic checkout integration yet.
- Client lookup uses provider contract (`ClientListProvider`), not direct cross-module repository access.
