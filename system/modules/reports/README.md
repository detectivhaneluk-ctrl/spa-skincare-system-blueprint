# reports

Reports and analytics.

## Responsibility
- Financial reports, gross/net
- VAT reports, day closure
- Sales analysis
- Appointment statistics
- Staff performance
- Client analytics
- Inventory reports
- Export center

## Dependencies
- `/system/core`
- `/system/shared`
- Approved contracts: sales, appointments, inventory, staff

## Boundaries
- Read-only aggregation; no direct repository access to other modules
- Uses published query/report contracts
- Shared: charts, tables
