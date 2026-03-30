# Architecture Truth Register

Date: 2026-03-23  
Authority: backend runtime/code truth over roadmap optimism  
Scope: multi-tenant backend architecture control

## Current maturity assessment

- Stage: advanced MVP with substantial backend breadth, but pre-production hardening for SaaS tenancy.
- Core business flows (appointments, sales, memberships, public booking/commerce, outbound queue) are implemented.
- Control-plane and tenant isolation guarantees are not yet closed as hard runtime invariants.
- Current readiness is feature-capable, not tenant-grade safe-by-default.

## Stage declaration (non-negotiable)

- The system is in **advanced MVP / pre-production hardening** stage.
- It is not acceptable to expand into new product waves before tenant boundary hardening closes.
- **Multi-tenant isolation is not yet a fully fail-closed invariant.**

## Architecture strengths

- Modular backend structure with explicit module registrars and route registrars under `system/modules` and `system/routes`.
- Strong recent hardening in public booking abuse controls and public commerce payment-trust cut (`awaiting_verification` path).
- Organization context and repository-scope utilities exist and can be evolved into strict invariants.
- Audit logging exists across major flows and supports operational forensic expansion.
- Existing CLI audit scripts establish a foundation for runtime proof culture.

## Architecture weaknesses (code-evidenced)

- FOUNDATION-100 closed: home/dashboard/platform route paths now use explicit platform-principal classification (`platform_founder`) plus permission checks for platform route access, with executed runtime smoke proof recorded in `FOUNDATION-100-CONTROL-PLANE-RBAC-AND-RUNTIME-SEPARATION-REPAIR-OPS.md`.
- TENANT-BOUNDARY-HARDENING-01 closed for protected tenant runtime: branch context no longer mutates from arbitrary request params, explicit tenant branch switch endpoint is enforced by allowlist, and unresolved tenant branch/org context is fail-closed (`TENANT-BOUNDARY-HARDENING-01-OPS.md`).
- SETTINGS-TENANT-ISOLATION-01 closed for settings foundation: organization-aware scope and precedence are active in `SettingsService` with migration/repair for legacy data, and cross-tenant settings inheritance is proven closed in smoke (`SETTINGS-TENANT-ISOLATION-01-OPS.md`).
- TENANT-ENTRY-FLOW-01 closed for tenant UX completion: unresolved tenant entry no longer dead-ends on raw plain-text denial and now routes through explicit resolver outcomes (single-branch auto-continue, multi-branch chooser, zero-context blocked/help) without weakening fail-closed checks (`TENANT-ENTRY-FLOW-01-OPS.md`).
- TENANT-OWNED-DATA-PLANE-HARDENING-01 closed for in-scope protected tenant modules (Clients/Staff/Services/Appointments): explicit tenant-owned repository read scope, scoped-by-id write guards, and cross-tenant linked-id rejection are now enforced with runtime proof (`TENANT-OWNED-DATA-PLANE-HARDENING-01-OPS.md` and scope matrix).
- Organization resolution still includes fallback modes (single-org and unresolved modes) and unresolved repository usage can remain broad when callers do not fail closed.
- Tenant lifecycle gating is incomplete end-to-end (org suspension/inactive entity enforcement is not universally hard-gated).
- Schema-compatibility shims keep runtime behavior dependent on migration state in selected paths.
- Automated tenant-isolation proof is not yet enforced as release gate.

## Hard truth directives

- Do not drift into feature expansion before hardening waves complete.
- Do not declare "DONE" from docs or audit-only evidence.
- Runtime must fail closed on unresolved tenant/branch/org context.
- Legacy fallback behavior must be intentionally burned down, not tolerated indefinitely.

## Evidence anchors

- `system/core/auth/AuthenticatedHomePathResolver.php`
- `system/core/middleware/BranchContextMiddleware.php`
- `system/core/Organization/OrganizationContextResolver.php`
- `system/core/Organization/OrganizationRepositoryScope.php`
- `system/core/app/SettingsService.php`
- `system/modules/public-commerce/services/PublicCommerceService.php`
- `system/modules/online-booking/services/PublicBookingService.php`
- `system/docs/FOUNDATION-100-CONTROL-PLANE-RBAC-AND-RUNTIME-SEPARATION-REPAIR-OPS.md`
