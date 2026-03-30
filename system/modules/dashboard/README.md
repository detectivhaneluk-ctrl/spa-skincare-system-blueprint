# dashboard

Main dashboard and day summary.

## Responsibility
- Day summary, quick actions
- Notification center
- Today's schedule snapshot, KPIs

## Dependencies
- `/system/core`
- `/system/shared`
- Approved contracts: appointments, clients, sales, inventory, documents

## Boundaries
- Aggregates data from other modules via published service contracts only
- No direct repository access to other modules
- Uses shared calendar, charts, cards
