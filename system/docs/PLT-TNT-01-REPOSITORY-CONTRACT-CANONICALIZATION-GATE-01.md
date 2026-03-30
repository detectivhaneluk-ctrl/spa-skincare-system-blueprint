# PLT-TNT-01 Repository Contract Canonicalization Gate

Date: 2026-03-30  
Status: `PARTIAL` until remaining mixed-semantics repository families are migrated

## Canonical repository contract model

- `*InTenantScope(...)`
  Runtime-safe tenant data-plane entrypoint.
  May not widen on invalid or unresolved tenant context.
  Must fail closed by returning empty/null or throwing a tenant-scope exception.

- `*InResolvedTenantScope(...)`
  Runtime-safe tenant entrypoint when the contract is resolved-org rather than branch-pinned.
  May not silently downgrade to global/unscoped behavior.

- `*InInvoicePlane(...)`
  Runtime-safe invoice/payment entrypoint.
  Must require the invoice-plane tenant contract explicitly before SQL.

- `*ForRepair(...)`
  Non-runtime repair, cron, or tooling path.
  May be unscoped only when the name says so and runtime callers are forbidden.

- `*ForControlPlane(...)` / `*ForPlatform(...)`
  Explicit cross-tenant or platform/control-plane path.
  Never a default tenant runtime API.

## Illegal mixed-semantics surface

- Generic verbs are illegal as live mixed-semantics APIs inside a canonicalized repository family:
  `find`, `findForUpdate`, `list`, `count`, `update`, `softDelete`, `delete`
- If a repository keeps these names temporarily, they must be compatibility-only and fail closed.
- Runtime-sensitive callers must not invoke `*ForRepair`, `*ForControlPlane`, `*ForPlatform`, `*GlobalOps`, or locked generic compatibility methods.

## First enforcement slice

- `PackageRepository`
- `ClientPackageRepository`
- `GiftCardRepository`

These repositories previously exposed both explicit tenant-safe methods and still-live generic weak methods. They now lock the generic mixed-semantics surface fail-closed and use explicit scoped mutation helpers instead.

## Mechanical enforcement

- Broad Tier A verifier:
  `system/scripts/read-only/verify_repository_contract_canonicalization_gate_readonly_01.php`
- Mandatory gate integration:
  `system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php`

## Remaining migration shape

- Other mixed-semantics families still exist and must be migrated in later slices.
- This task establishes the contract model, the gate, and one real runtime-sensitive enforcement slice so future closure waves are boundary-driven instead of hotspot-driven.
