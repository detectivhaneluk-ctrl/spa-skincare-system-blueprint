# giftcards-packages

Gift cards and service packages.

## Responsibility
- Gift card management
- Package definitions and client packages
- Package usage tracking

## Dependencies
- `/system/core`
- `/system/settings`
- `/system/shared`
- Approved contracts: clients, sales

## Boundaries
- Does not access sales or clients repositories directly
- Sales integration via published payment/redemption contract
- Core: codes (gift card codes), audit
