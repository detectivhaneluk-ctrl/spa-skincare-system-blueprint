# Backend Hardening Wave Roadmap

> **HISTORICAL REFERENCE ONLY** — Wave narrative preserved; **current** phase order is `system/docs/BACKBONE-CLOSURE-MASTER-PLAN-01.md`. Strict status: `system/docs/TASK-STATE-MATRIX.md`.

Date: 2026-03-23  
Directive: backend-first, fail-closed tenancy first, no feature expansion until hardening gates close.

## Ordered execution waves (locked)

### Wave 1 — FOUNDATION-100 — CONTROL-PLANE-RBAC-AND-RUNTIME-SEPARATION-REPAIR (`CLOSED`)

- Why it exists: current platform-vs-tenant runtime identity remains vulnerable to RBAC contamination and permission-string coupling.
- Scope: role-plane contract hardening, runtime gate hardening, legacy contamination burn-down, proof hooks.
- Out of scope: catalog/storefront/mixed-sales/public UX features.
- Why before expansion: any new feature built on mixed plane identity compounds privilege leakage risk.

### Wave 2 — TENANT-BOUNDARY-HARDENING-CHARTER (`CLOSED` as TENANT-BOUNDARY-HARDENING-01)

- Why it exists: unresolved org/branch context can still broaden behavior in parts of the system.
- Scope: fail-closed context resolution policy, branch pivot constraints, unresolved-context deny strategy, shim reduction plan.
- Out of scope: settings redesign internals and feature work.
- Why before expansion: tenant boundary ambiguity invalidates trust in all downstream business logic.

### Wave 3 — SETTINGS-TENANT-ISOLATION-REDESIGN-CHARTER (`CLOSED` as SETTINGS-TENANT-ISOLATION-01)

- Why it exists: branch/global settings merge is not sufficient as SaaS tenant default semantics.
- Scope: organization-owned settings hierarchy contract, migration strategy, runtime read/write rules.
- Out of scope: UI redesign, new settings feature expansion.
- Why before expansion: global/branch ambiguity can produce cross-tenant configuration contamination.

### Wave 4 — TENANT-OWNED-DATA-PLANE-HARDENING (`PARTIAL`; TENANT-OWNED-DATA-PLANE-HARDENING-01 CLOSED for in-scope modules)

- Why it exists: repository scoping is uneven and partly caller-discipline based.
- Scope: repository-by-repository fail-closed scoping enforcement and invariant checks.
- Out of scope: broad refactors unrelated to tenant scoping invariants.
- Why before expansion: mixed-scope data access risks cross-tenant data leakage.

### Wave 5 — LIFECYCLE-AND-SUSPENSION-ENFORCEMENT

- Why it exists: suspended/inactive tenant and actor lifecycle gates are not consistent end-to-end.
- Scope: org suspension, inactive user/staff, and public exposure enforcement across runtime paths.
- Out of scope: new lifecycle product features.
- Why before expansion: inactive tenants/entities must not remain operational through gaps.

### Wave 6 — AUTOMATED-TENANT-PROOF-LAYER

- Why it exists: current proof posture is mostly audit/script driven, not mandatory release-grade verification.
- Scope: automated tenant-isolation proof suite, CI gate criteria, release hygiene requirements.
- Out of scope: unrelated perf/UI work.
- Why before expansion: expansion without automated isolation proof increases regression risk and trust debt.

### Precision triage checkpoint — OUT-OF-SCOPE-MODULE-SCOPE-MATRIX-AND-HARDENING-PLAN-01 (`DONE`, `AUDIT-ONLY`)

- Why it exists: choose next hardening lane by code-truth blast radius, not optimistic sequencing.
- Scope: remaining module exposure matrix and ranked next-wave order.
- Output: `OUT-OF-SCOPE-MODULE-SCOPE-MATRIX-AND-HARDENING-PLAN-01-OPS.md` and `OUT-OF-SCOPE-MODULE-SCOPE-MATRIX-AND-HARDENING-PLAN-01-MATRIX.md`.
- Next implementation wave locked by evidence: `SALES-TENANT-DATA-PLANE-HARDENING-01`.

### Wave execution result — SALES-TENANT-DATA-PLANE-HARDENING-01 (`DONE`)

- Why it was next: highest remaining financial/confidentiality blast radius with mixed scoping and caller-discipline dependency in sales runtime.
- Scope closed in this wave:
  - protected sales runtime org-owned scope contract (`SalesTenantScope`)
  - invoice/payment/register/invoice-item/cash-movement repository scoping on tenant-resolved runtime
  - protected `/sales*` runtime fail-closed when tenant context is unresolved
  - focused runtime proof script execution (`14 passed, 0 failed`)
- Out of scope kept closed: no broad storefront/commerce expansion, no unrelated inventory/memberships/packages hardening expansion.

### Wave execution result — INVENTORY-TENANT-DATA-PLANE-HARDENING-01 (`PARTIAL`)

- Prior OPS narrative may read “closed” for stock/product/supplier slices; **authoritative matrix:** `INVENTORY-TENANT-DATA-PLANE-HARDENING-01-MATRIX.md` (2026-03-28 taxonomy wave + remaining index/internal gaps). Legacy: `INVENTORY-TENANT-DATA-PLANE-HARDENING-01-OPS.md`.

### Wave execution result — MEMBERSHIPS-GIFTCARDS-PACKAGES-HARDENING-01 (`CLOSED`)

- Why it was next: remaining high operational/financial risk in entitlement and stored-value surfaces after sales and inventory closure.
- Scope closed: protected tenant runtime for membership definitions/client memberships (touched sale/refund paths), gift cards, packages; minimal sales coupling (invoice gift redeem / gift-card refund guards); scoped `getBalanceSummary` for invoice path.
- Proof: `smoke_memberships_giftcards_packages_hardening_01.php` (`16 passed, 0 failed`) plus `smoke_tenant_owned_data_plane_hardening_01.php` regression (`14 passed, 0 failed`).
- Details: `MEMBERSHIPS-GIFTCARDS-PACKAGES-HARDENING-01-OPS.md` and `-MATRIX.md`.

## Global stop rule

- No catalog, storefront, mixed-sales architecture expansion, or new feature lane begins until Waves 1-5 are runtime-accepted and Wave 6 gate is active for releases.
- **No new pages / no UI surface expansion** (routes, controllers, views, design polish) until **Backbone Closure** phases in `BACKBONE-CLOSURE-MASTER-PLAN-01.md` and tenant root-cause waves (`FOUNDATION-ROOT-CAUSE-TRUTH-MAP-01.md`) allow it — prioritize **repository/org-scope closure + Tier A proof** over visible product expansion (**FOUNDATION-NO-NEW-PAGES-UNTIL-CORE-HARDENING-PLAN-CLOSURE-02**, 2026-03-29).
