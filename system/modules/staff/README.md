# staff

Staff management.

## Responsibility
- Staff list, staff card
- Schedules, time off
- Clock in/out
- Commissions, payroll rules
- Performance dashboard

## Dependencies
- `/system/core` (auth, permissions, audit)
- `/system/settings`
- `/system/shared`
- Approved contracts: auth (user linkage)

## Boundaries
- Extends auth users; does not replace auth
- Does not import from other business modules except auth contract
- Core: branches
