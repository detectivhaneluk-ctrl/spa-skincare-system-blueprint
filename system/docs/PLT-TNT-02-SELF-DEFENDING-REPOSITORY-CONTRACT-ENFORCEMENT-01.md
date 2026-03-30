# PLT-TNT-02 Self-Defending Repository Contract Enforcement

Date: 2026-03-30  
Status: `PARTIAL`

## Self-defending enforcement model

- Central policy:
  `system/scripts/read-only/lib/repository_contract_policy.php`
- Central lock primitive:
  `Core\Repository\RepositoryContractGuard::denyMixedSemanticsApi(...)`
- Mandatory broad gate:
  `system/scripts/read-only/verify_repository_contract_canonicalization_gate_readonly_01.php`

## Hard-stop rules

- Protected repository families must expose explicit runtime-safe methods only through:
  `*InTenantScope(...)`, `*InResolvedTenantScope(...)`, `*InInvoicePlane(...)`
- Non-runtime methods must be explicit:
  `*ForRepair(...)`, `*ForControlPlane(...)`, `*ForPlatform(...)`, `*GlobalOps(...)`
- Banned generic verbs in protected families must be absent or locked through the central guard:
  `find`, `findForUpdate`, `list`, `count`, `update`, `softDelete`, `delete`
- Runtime-sensitive callers must not invoke explicit non-runtime methods.

## High-risk family migrated here

- `ClientMembershipRepository`
  Runtime and repair APIs are now explicit.
  Mixed generic methods are locked fail-closed.
- `MembershipBillingCycleRepository`
  Mutation contract is now explicit:
  `updateInInvoicePlane(...)` for runtime invoice-plane mutation and `updateForRepair(...)` for non-runtime repair.

## Mandatory gate integration

- Added to `run_mandatory_tenant_isolation_proof_release_gate_01.php`:
  `verify_membership_repository_contract_self_defense_readonly_01.php`
- Existing canonicalization gate now reads the central policy instead of a hardcoded local slice.

## Truthful limitation

- This task makes the project materially more hostile to contract drift, but it does not finish global migration.
- Remaining mixed-semantics families still need promotion into the protected policy and explicit runtime/non-runtime APIs.
