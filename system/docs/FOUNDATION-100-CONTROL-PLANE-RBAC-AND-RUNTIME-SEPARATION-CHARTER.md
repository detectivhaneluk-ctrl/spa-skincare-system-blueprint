# FOUNDATION-100 Charter — Control-Plane RBAC and Runtime Separation

Date: 2026-03-23  
Status: CLOSED (runtime-proof accepted)  
Closure evidence: `FOUNDATION-100-CONTROL-PLANE-RBAC-AND-RUNTIME-SEPARATION-REPAIR-OPS.md`

## Exact problem statement

Control-plane versus tenant-plane separation is not yet a hard runtime invariant.  
The system currently decides platform capability primarily from permission strings, while legacy RBAC states can still carry contamination. This means founder/platform boundaries can be violated by role data quality rather than explicit plane identity guarantees.

## Code evidence

- `system/core/auth/AuthenticatedHomePathResolver.php`: route landing depends on `platform.organizations.view`.
- `system/modules/dashboard/controllers/DashboardController.php`: tenant dashboard redirects by the same permission test.
- `system/modules/organizations/controllers/PlatformControlPlaneController.php`: platform controller allow/deny hinges on permission presence.
- `system/docs/FOUNDATION-100-CONTROL-PLANE-RBAC-AND-RUNTIME-SEPARATION-REPAIR-OPS.md`: claims repair complete, but this depends on seed/repair execution state and does not independently harden plane identity at runtime.

## Required outcomes

1. Runtime plane separation is explicit, deterministic, and not only permission-string inferred.
2. Legacy RBAC contamination cannot grant platform-plane behavior to tenant-plane principals.
3. Platform routes reject contaminated tenant principals fail-closed.
4. Home routing and middleware plane selection are consistent with hardened separation contract.
5. Operational repair path remains idempotent and verifiable on existing databases.

## Non-goals

- No catalog/storefront/mixed-sales/features.
- No UI redesign.
- No broad framework refactor.
- No unrelated module cleanup.

## Acceptance criteria

- Platform access requires hardened platform-plane identity contract, not only legacy-permission coincidence.
- Tenant roles (`owner`, `admin`, `reception`) cannot reach platform runtime behavior under legacy-seeded states.
- Route/middleware/controller checks align on one plane contract with fail-closed default.
- Regression tests/scripts prove deny/allow matrix for founder vs tenant roles on both `/platform-admin` and `/dashboard`.
- Status can move to `DONE` only with runtime proof, not docs-only closure.

## Proof plan

- Extend and run targeted smoke script(s) for:
  - founder -> `/platform-admin` allowed, `/dashboard` redirected.
  - tenant roles -> `/dashboard` allowed, `/platform-admin` forbidden.
  - legacy contaminated role data -> still forbidden from platform plane.
- Add repeatable evidence artifacts under script outputs/logs and link from ops docs.
- Mark completion `RUNTIME-PROOF-MISSING` until automated checks are runnable and reproducible in release flow.

## Explicit work freeze warning

Do not start catalog, storefront, mixed-sales, or any new feature expansion until this charter is closed with runtime proof.
