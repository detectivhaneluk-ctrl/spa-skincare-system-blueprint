# Packages Module (Phase 5B Foundation)

This module implements prepaid service/session bundles with strict transactional safety and explicit branch-aware behavior.

## Scope Implemented

- Package definitions (`packages`)
- Client assignments (`client_packages`)
- Usage history (`package_usages`)
- Functional screens for definition CRUD and client package operations

## Branch Behavior

- Branch context is explicit for state-changing operations.
- For branch-owned `client_packages` (`branch_id != null`), service methods require a matching explicit branch context.
- Global rows (`branch_id = null`) can be operated without branch context.
- Filters intentionally expose explicit branch modes:
  - all branches (mixed)
  - global only
  - single branch by id

## Transactional Guarantees

State-changing operations are atomic and wrapped in a DB transaction:

- `assignPackageToClient`
- `usePackageSession`
- `adjustPackageSessions`
- `reversePackageUsage`
- `cancelClientPackage`
- `expireClientPackageIfNeeded`

For each operation, usage insert + snapshot/status updates commit or rollback together.

## History Source of Truth

- `package_usages` is the usage history source of truth.
- `client_packages.remaining_sessions` is maintained as a validated snapshot for fast reads.
- Service methods read latest history snapshot where available and keep table snapshots consistent.

## Integration Boundary

- No direct coupling to Appointments/Sales repositories.
- Cross-module client selection uses `Core\Contracts\ClientListProvider`.
- Future package consumption from appointments can be added through contracts/providers.
