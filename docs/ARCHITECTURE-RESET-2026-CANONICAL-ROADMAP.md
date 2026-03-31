# Architecture Reset 2026 — Canonical Roadmap

**Date:** 2026-03-31  
**Status:** ACTIVE — this document is the authoritative strategy record for the 2026 Architecture Reset.  
**Live execution queue (exactly one LIVE task at a time):** `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`  
**Phase order and freeze rules:** `system/docs/BACKBONE-CLOSURE-MASTER-PLAN-01.md`  
**Full status inventory:** `system/docs/TASK-STATE-MATRIX.md`

---

## Why this reset exists

The previous strategy was wave-based hotspot closure: find id-only repository patterns (ROOT-01 family), patch them one module at a time, verify with read-only scripts, accumulate evidence.

That strategy produced real, useful, sealed evidence (CLOSURE-01..24). It is not wasted work. The marketing/media lane is closed and accepted on GitHub main with SHA-pinned proof. Those closures stand.

But the wave approach has a structural ceiling. Patching individual repository methods module-by-module cannot reach a fail-closed architecture on its own:

- Tenant scoping remains **opt-in** across too many surfaces. Each new feature or module is a new surface that needs manual disciplining.
- Some services still call `$this->db->fetchOne / fetchAll / query` directly, bypassing the repository contract entirely. That is a **ROOT-05** (service scope drift) problem that incremental repository patching cannot fix.
- The authorization model is scattered: every service does its own ownership checks at different abstraction levels.
- There is no immutable, resolved-at-entry TenantContext that protected flows can depend on.

**The root architectural gap:** tenant scoping is not a kernel — it is a convention. Conventions drift. Architecture does not.

---

## Architecture diagnosis

| Dimension | Current State | Target State |
|-----------|--------------|--------------|
| Tenant context | Resolved ad-hoc per service call; passed as raw IDs or inferred from session | Immutable `TenantContext` resolved once at entry, passed explicitly through all protected flows |
| Authorization | Scattered per-service ownership checks, HTTP verb-level in some places | Central policy enforcement layer; business-level canonical actions |
| Service DB access | Some services call `$this->db->*` directly (ROOT-05) | Services for protected domains may only call repository / query-command handlers |
| Repository API | Mix of raw `findById` / `updateById` with some scoped variants | Canonical scoped API: `loadVisible`, `loadForUpdate`, `mutateOwned`, `deleteOwned`, `countOwnedReferences` |
| Pilot rewrite lane | None yet for new kernel model | Media/client/marketing pilot lane first; preserve behavior, change architecture |
| Guardrails | Static verifiers per wave; no structural enforcement in CI | Mechanical CI guardrails: fail on direct service DB access, flag new raw id-only paths |
| Long-horizon target | Modular monolith with increasing discipline | Policy-centered modular monolith; optional future: RLS, ReBAC, cell isolation |

---

## What is superseded

The following active work is **no longer the active strategy**. It is archived as sealed evidence.

| Superseded Item | Reason |
|-----------------|--------|
| PLT-TNT-01 incremental module patching waves | Replaced by FOUNDATION-A4 canonical scoped repository API approach |
| Continued ROOT-01 id-only closure via per-module hotspot patches | Replaced by kernel-level architecture (FOUNDATION-A1..A4) |
| "Next best ROOT-01 hotspot" as primary task selection criterion | Replaced by FOUNDATION-A1 as the single LIVE task |
| Wave-style verifier expansion across appointments / inventory / memberships / notifications / packages / sales / services-resources / settings / staff | These domains will be migrated in FOUNDATION-A7 map order — not by continued wave patching |
| PLT-TNT-01 REPOSITORY-CONTRACT-CANONICALIZATION-GATE naming model (`*InTenantScope`, `*ForRepair`, etc.) | The concepts are partially still valid, but the naming model is superseded by the FOUNDATION-A4 canonical API family |

**Sealed evidence kept:**
- `docs/PLT-TNT-01-root-01-id-only-closure-wave.md` — all CLOSURE-01..24 proofs
- `system/docs/TENANT-SAFETY-INVENTORY-CHARTER-01.md` — hotspot inventory from wave era
- `system/docs/FOUNDATION-TENANT-REPOSITORY-CLOSURE-*` audit docs — per-wave closure records
- `system/scripts/read-only/verify_root_01_*` verifiers — historical proof scripts

---

## New canonical roadmap: FOUNDATION-A1..A8

**Execution rule:** Only FOUNDATION-A7 PHASE-2 is LIVE. Only FOUNDATION-A7 PHASE-3 is PARKED/NEXT. All A1..A8 Foundation tasks are CLOSED (including PHASE-1). PHASE-2 is the current active migration. See `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` for current live slot.

---

### FOUNDATION-A1 — TenantContext Kernel

**Status:** CLOSED (2026-03-31)  
**What it is:** Define and implement an immutable `TenantContext` / `RequestContext` capability.

**Specification scope:**
- Fields: `actor_id`, `organization_id`, `branch_id`, principal/role class, support/impersonation mode, assurance level, request source
- Contract: resolved exactly once at request entry by a designated resolver
- Passed explicitly through all protected flows — not re-derived mid-call
- Fail-closed: if a protected operation requires a resolved tenant context and one is absent, the operation must reject, not silently degrade
- Support/impersonation mode must be first-class, not a boolean flag bolted on

**Why it is first:** Everything else (authorization, scoped repositories, guardrails) depends on a stable, immutable context object. Without it, every downstream step fights the same missing-foundation problem.

**Done criteria:**
- Interface and factory specification documented
- Resolver contract defined (where in the request lifecycle context is resolved)
- Fail-closed contract specified (what exception / response when context is required but absent)
- Pilot integration path identified for FOUNDATION-A5 media lane

---

### FOUNDATION-A2 — Authorization Kernel

**Status:** CLOSED (2026-03-31 skeleton; full PolicyAuthorizer BIG-04 2026-03-31)  
**What it is:** A central policy enforcement layer for tenant-owned resource actions.

**Skeleton (BIG-01):** `DenyAllAuthorizer` registered as `AuthorizerInterface` binding. Denied all actions.

**Full implementation (BIG-04):** `PolicyAuthorizer` installed:
- FOUNDER principal: full-allow for all tenant-scoped actions, plus platform-only actions
- SUPPORT_ACTOR: read-only allow for view actions only; writes blocked
- TENANT principal: permission-map based (integrates with `PermissionService`)
- All unresolved contexts: deny with reason
- Unmapped actions: deny-by-default (not an allow-all fallback)
- Explainable: every `AccessDecision` carries a reason string
- 79/79 BIG-04 verification assertions pass including PolicyAuthorizer coverage

---

### FOUNDATION-A3 — Service Layer DB Ban

**Status:** CLOSED (2026-03-31 — media pilot lane)  
**What it is:** An explicit architecture rule banning direct DB access from protected-domain services.

**Scope:**
- Protected-domain services must not call `$this->db->fetchOne` / `fetchAll` / `query` (or equivalent) directly
- Service layer may only use repositories or command-query handlers
- Static guardrail script that fails CI if this rule is violated in protected modules
- Rule documented in architecture conventions; not "best effort"

**Why it matters:** ROOT-05 (service scope drift) cannot be closed by repository patching alone. If services can bypass the repository contract at any time, the scoped repository API provides no real guarantee.

---

### FOUNDATION-A4 — Canonical Scoped Repository API

**Status:** CLOSED (2026-03-31 — media pilot lane)  
**What it is:** Replace the scattered `findById` / `updateById` / `deleteById` pattern for tenant-owned entities with a canonical API family.

**Canonical method family:**
```
loadVisible(TenantContext $ctx, ResourceId $id): ?Resource
loadForUpdate(TenantContext $ctx, ResourceId $id): ?Resource
mutateOwned(TenantContext $ctx, ResourceId $id, Command $cmd): void
deleteOwned(TenantContext $ctx, ResourceId $id): void
countOwnedReferences(TenantContext $ctx, ResourceId $id): int
```

**Rules:**
- New tenant-owned repository paths must use this family
- Raw `findById` / `updateById` / `deleteById` are no longer added for tenant-owned rows
- Old compatibility methods deprecated with clear upgrade path

**Relationship to superseded work:** This replaces the PLT-TNT-01 contract naming model (`*InTenantScope`, `*ForRepair`, etc.) with a context-passing API that is structurally enforced rather than naming-convention-enforced.

---

### FOUNDATION-A5 — Media Pilot Rewrite

**Status:** CLOSED (2026-03-31 — 77/77 verification assertions pass)  
**What it is:** The first full implementation lane using the new kernel model.

**Pilot files:**
```
system/modules/clients/services/ClientProfileImageService.php
system/modules/marketing/services/MarketingGiftCardTemplateService.php
system/modules/marketing/repositories/MarketingGiftCardTemplateRepository.php
```

**Rules:**
- No direct DB access from services in these files
- Repository access only through canonical scoped API (FOUNDATION-A4)
- Existing behavior preserved — architecture changes, behavior does not
- This lane is chosen because it is smaller, already partially touched, and the marketing/media ROOT-01 closure is already sealed evidence

**Why pilot-first:** Proves the kernel model works end-to-end in real code before opening wider migration waves.

---

### FOUNDATION-A6 — Mechanical Guardrails

**Status:** CLOSED (2026-03-31 — both CI guardrail scripts installed and passing)  
**What it is:** CI/static verification guardrails that make the new architecture self-defending.

**Guardrails to implement:**
1. Fail on direct service-layer DB access (`$this->db->*`) in protected modules
2. Flag new raw id-only mutations/loads for tenant-owned entities (patterns: `findById`, `updateById`, `deleteById` outside the canonical family)

**Scope discipline:**
- Start with explicitly defined protected domains — not an unrealistic perfect detector on day one
- Expand scope incrementally as each domain is migrated

**Relationship to existing guardrails:** `system/docs/SELF-DEFENDING-PLATFORM-GUARDRAILS-2026-01.md` documents existing CI workflows. FOUNDATION-A6 adds new static analysis rules specifically for the architecture kernel patterns.

---

### FOUNDATION-A7 — Migration Map

**Status:** CLOSED as document (2026-03-31) — PHASE-1 Appointments CLOSED (BIG-04, 2026-03-31) — PHASE-2 Online-booking is now LIVE  
**What it is:** After media pilot is accepted, define the next migration order.

**Migration order:**
1. appointments — **CLOSED** (BIG-04, 2026-03-31): services + repos migrated, guardrails expanded, 79/79 assertions pass
2. online-booking — **LIVE**
3. sales — PARKED/NEXT
4. client-owned resources — PLANNED

---

### FOUNDATION-A8 — Long-Horizon Platform Direction

**Status:** CLOSED (2026-03-31) — strategic direction documented  
**What it is:** Strategic direction document for the 2026+ platform target shape.

**Topics to document:**
- **Policy-centered modular monolith**: the immediate-term target; one deployable, modular internal boundaries, strong policy kernel
- **PostgreSQL + row-level-security**: target DB-level enforcement where the kernel's tenancy decisions are reflected in RLS policies; reduces attack surface if application-level enforcement has gaps
- **Observability-first decision logging**: authorization and tenant resolution decisions should produce auditable traces; not an afterthought
- **Future ReBAC / OpenFGA**: considered only if relationship complexity genuinely demands it (e.g. resource-level sharing, delegated permissions) — not adopted speculatively
- **Future cell-based isolation**: considered only after the core tenancy kernel is mature and multi-tenancy volume demands physical separation
- **What we are explicitly NOT doing**: full microservices decomposition, new storage drivers, new frameworks — deferred until backbone is closed

**Why A8 is last:** It is strategic framing for decisions that will come after A1..A7. It does not block any FOUNDATION task.

---

## Roadmap order (quick reference)

| Order | ID | Name | Status |
|-------|----|------|--------|
| 1 | FOUNDATION-A1 | TenantContext Kernel | **CLOSED** (2026-03-31) |
| 2 | FOUNDATION-A2 | Authorization Kernel | **CLOSED** (2026-03-31, full PolicyAuthorizer BIG-04) |
| 3 | FOUNDATION-A3 | Service Layer DB Ban | **CLOSED** (2026-03-31) |
| 4 | FOUNDATION-A4 | Canonical Scoped Repository API | **CLOSED** (2026-03-31) |
| 5 | FOUNDATION-A5 | Media Pilot Rewrite | **CLOSED** (2026-03-31) |
| 6 | FOUNDATION-A6 | Mechanical Guardrails | **CLOSED** (2026-03-31) |
| 7 | FOUNDATION-A7 | Migration Map (document) | **CLOSED** (2026-03-31) |
| 7a | FOUNDATION-A7 PHASE-1 | Appointments migration | **CLOSED** (BIG-04, 2026-03-31) |
| 7b | FOUNDATION-A7 PHASE-2 | Online-booking migration | **LIVE** |
| 7c | FOUNDATION-A7 PHASE-3 | Sales migration | PARKED/NEXT |
| 7d | FOUNDATION-A7 PHASE-4 | Client-owned resources migration | PLANNED |
| 8 | FOUNDATION-A8 | Long-Horizon Platform Direction | **CLOSED** (2026-03-31) |

---

## What is NOT in scope for this reset

- Full microservices decomposition
- New storage drivers (Phase 4)
- MFA / step-up authentication (Phase 3 per backbone plan — independent lane)
- Async/queue control-plane (PLT-Q-01 Phase 2 per backbone plan — independent lane)
- Bootstrap / portability (Phase 5 per backbone plan)
- Any product feature expansion (Booker parity, storefront, catalog expansion — deferred)

---

## Canonical references

| Role | File |
|------|------|
| **Live execution queue** (single LIVE/PARKED slot) | `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` |
| **Phase order and freeze rules** | `system/docs/BACKBONE-CLOSURE-MASTER-PLAN-01.md` |
| **Full status inventory** | `system/docs/TASK-STATE-MATRIX.md` |
| **Root-cause register** | `system/docs/ROOT-CAUSE-REGISTER-01.md` |
| **Sealed wave evidence** | `docs/PLT-TNT-01-root-01-id-only-closure-wave.md` |
| **Sealed hotspot inventory** | `system/docs/TENANT-SAFETY-INVENTORY-CHARTER-01.md` |
| **Existing CI guardrails** | `system/docs/SELF-DEFENDING-PLATFORM-GUARDRAILS-2026-01.md` |
| **Kernel architecture** | `system/docs/FOUNDATION-KERNEL-ARCHITECTURE-01.md` |
| **A6 Guardrail policy** | `system/docs/FOUNDATION-A6-GUARDRAILS-POLICY-01.md` |
| **A7 Migration map** | `docs/FOUNDATION-A7-MIGRATION-MAP-01.md` |
| **A8 Platform direction** | `docs/FOUNDATION-A8-PLATFORM-DIRECTION-01.md` |
