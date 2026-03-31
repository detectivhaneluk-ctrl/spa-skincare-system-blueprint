# FOUNDATION-A8 — Long-Horizon Platform Direction

**Status:** ACTIVE — strategic direction; not immediate implementation scope  
**Installed:** 2026-03-31 (BIG-03)  
**Author note:** This document is strategic framing for decisions that will come after FOUNDATION-A1..A7. Nothing here is a current implementation task. It exists to make future architectural decisions coherent rather than reactive.

---

## What this document is (and is not)

**It IS:** A principled strategic direction that will guide architecture decisions as the platform matures beyond the 2026 Foundation Reset.

**It is NOT:**
- An implementation backlog
- A commitment to any specific technology or timeline
- Permission to start any of these directions before the Foundation is complete
- Speculation about future product features

The Foundation (A1..A7) must be complete and proven before any long-horizon direction below becomes an active roadmap item.

---

## Current target: Policy-Centered Modular Monolith

**Immediate-term target shape (post-A7):**

One deployable system with strong internal module boundaries and a central policy kernel.

- **Single deployment unit** — no microservices split, no distributed transactions, no service mesh
- **Modular internal boundaries** — modules do not reach into each other's internals; they communicate through defined service interfaces
- **Central policy kernel** — `TenantContext` + `AuthorizerInterface` as the single policy enforcement point; no per-service ownership checks scattered across the codebase
- **Canonical data-plane API** — `loadVisible / loadForUpdate / mutateOwned / deleteOwned / countOwnedReferences` as the enforced access pattern for tenant-owned data
- **Mechanical guardrails** — CI rules that prevent old patterns from regressing (FOUNDATION-A6 model)

**Why this target:**

Modular monoliths are significantly simpler to operate, debug, and evolve than distributed systems for a team at this scale. The discipline of modular internal boundaries — without the operational overhead of service mesh, distributed tracing, and cross-service deployment — gives most of the architectural benefit at a fraction of the operational cost.

The central policy kernel is the key differentiator from the pre-2026 state. Without it, tenant isolation is a convention. With it, tenant isolation is a structural property.

---

## Future direction 1: PostgreSQL Row-Level Security (RLS)

**Status:** Long-horizon target — not immediate scope

### What it is

PostgreSQL RLS allows database-level access policies that filter rows based on session variables. In the target model, the `organization_id` and `branch_id` from `TenantContext` would be set as session-level variables at query time, and RLS policies on tenant-owned tables would enforce that only rows belonging to the current tenant are visible — independent of application-layer filtering.

### Why it matters

Application-layer tenant isolation (what FOUNDATION-A1..A7 installs) is strong but has a gap: if application code has a bug that passes the wrong `organization_id` to a query, the wrong tenant's data is exposed. RLS adds a second enforcement layer at the database level that does not depend on application correctness.

### Preconditions

This direction requires:
1. FOUNDATION-A1..A7 complete — the kernel model must exist before RLS is meaningful. RLS without a stable `TenantContext` is incoherent.
2. All tenant-owned tables must have `organization_id` or `branch_id` columns (already true for most; verify coverage in PHASE-1..4 migration)
3. Connection pooling strategy must be compatible with per-request session variables (PgBouncer in session mode, or application-managed set/unset around transactions)
4. Migration from MySQL to PostgreSQL (if not already done) — or adopt PostgreSQL as target DB for new deployments

### What we are explicitly NOT doing now

- No DB migration while Foundation is in progress
- No RLS prototype until the kernel model is proven in production

---

## Future direction 2: Observability-First Decision Logging

**Status:** Long-horizon target — partial capability exists today

### What it is

Every authorization decision and tenant context resolution should produce a structured, auditable trace:
- Who requested it (actorId, principalKind)
- What context was resolved (organizationId, branchId, assuranceLevel)
- What action was requested
- What the authorization decision was (allow / deny / exception)
- Which policy rule fired

### Current state

The `TenantContext` object (FOUNDATION-A1) already carries all the fields needed for a structured trace. The `AuthorizerInterface` (FOUNDATION-A2) already produces `AccessDecision` objects with reasons. The infrastructure for decision logging exists at the contract level.

What does not yet exist: a structured audit logger that pipes these decisions to a queryable log store.

### Why it matters

Authorization decisions that are not logged are:
- Invisible in incident investigations
- Unverifiable by security auditors
- Unreplayable for debugging subtle tenant-isolation issues

Decision logging is not a product feature — it is a platform maturity signal.

### Preconditions

1. FOUNDATION-A2 PolicyAuthorizer must be installed (real allow-rules, not just DenyAllAuthorizer)
2. A structured log sink must be available (structured logging exists today via `slog()`; routing auth decisions to it is incremental)

---

## Future direction 3: ReBAC / OpenFGA (Conditional)

**Status:** Considered only if relationship complexity truly demands it

### What it is

Relationship-Based Access Control (ReBAC) models authorization as a graph of relationships between subjects and objects. OpenFGA is a popular open-source ReBAC system originally developed at Auth0.

In a ReBAC model: "can actor X access resource Y?" is answered by traversing a relationship graph (e.g., "X is a member of team T, T has viewer access to resource Y").

### When this would apply

ReBAC makes sense when:
- Authorization requirements become relationship-heavy (shared resources, delegated access, per-resource ACLs)
- The number of distinct permission rules is large enough that flat role/permission tables become unmanageable
- Relationship-level access control (e.g., "this staff member can only see appointments they are assigned to") becomes a first-class requirement

### Current posture

The platform's authorization model today is relatively flat: tenant-scoped access with role-level permissions. The `ResourceAction` vocabulary (FOUNDATION-A2) covers this well without a full ReBAC graph.

ReBAC is NOT adopted until:
1. FOUNDATION-A2 PolicyAuthorizer is proven insufficient for real requirements
2. Relationship complexity (resource-level sharing, delegated permissions) is a proven, specific requirement — not a speculative one

**What we are explicitly NOT doing:** Pre-emptively adopting OpenFGA for architectural elegance. Every ReBAC system adds operational complexity (a new service, graph traversal latency, schema migrations for the relationship store). That cost is justified only by genuine relationship complexity.

---

## Future direction 4: Cell-Based Isolation (Conditional)

**Status:** Considered only after tenancy kernel is mature and volume demands it

### What it is

Cell-based isolation shards the multi-tenant system into isolated cells where each tenant (or group of tenants) runs on a dedicated cell. Cells may share infrastructure (database cluster, deployment pipeline) but are logically isolated — a failure or performance issue in one cell does not affect others.

### Preconditions

This direction requires:
1. The tenancy kernel (FOUNDATION-A1..A7) is fully deployed and proven — you cannot shard a system where tenant isolation is still a convention
2. Per-tenant data volume or performance requirements justify the operational overhead of cell management
3. The deployment and migration tooling is mature enough to manage cell provisioning

### Current posture

Cell-based isolation is not appropriate until the single-cell modular monolith is architecturally clean. A sharded messy monolith is worse than an unsharded messy monolith — you have all the isolation problems plus the operational overhead of distributed coordination.

**What we are explicitly NOT doing:** Pre-emptive cell design while Foundation phases are open.

---

## What we are explicitly NOT doing (scope boundary)

These items are deferred until backbone phases are complete or are out of scope entirely:

| Item | Reason deferred / excluded |
|------|---------------------------|
| Full microservices decomposition | Operational overhead unjustified at current team/scale; modular monolith gives most benefits |
| New storage drivers or databases | Phase 4+ per `BACKBONE-CLOSURE-MASTER-PLAN-01.md` |
| MFA / step-up authentication | Phase 3 per backbone plan — independent lane |
| Async / queue control-plane (PLT-Q-01) | Phase 2 per backbone plan — independent lane |
| Bootstrap / portability / multi-cloud | Phase 5 per backbone plan |
| Any product feature expansion | Not in backbone scope |
| OpenFGA before authorization model is proven insufficient | Speculative adoption unjustified |
| RLS before kernel + PostgreSQL migration are confirmed | Premature optimization |

---

## Canonical direction hierarchy

When making future architecture decisions, consult in this order:

1. **Does the Foundation (A1..A7) give us the right answer already?** — Start here. The kernel model, canonical repo API, and guardrails cover most cases.

2. **Is this a decision covered by the long-horizon directions above?** — Use the direction as a framework. The preconditions are there for a reason.

3. **Does this require deviating from the policy-centered modular monolith target?** — If yes, the bar for justification is high. Document the case explicitly.

4. **Is this a product feature or an architectural concern?** — Product features are not backbone. Do not mix them.

---

## Related canonical references

- `docs/ARCHITECTURE-RESET-2026-CANONICAL-ROADMAP.md` — the reset this builds on
- `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md` — current live execution queue
- `docs/FOUNDATION-A7-MIGRATION-MAP-01.md` — the migration order that leads to the target state
- `system/docs/FOUNDATION-KERNEL-ARCHITECTURE-01.md` — kernel contracts as implemented
- `system/docs/BACKBONE-CLOSURE-MASTER-PLAN-01.md` — phase order and freeze rules
