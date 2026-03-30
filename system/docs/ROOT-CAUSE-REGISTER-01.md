# Root-cause register — CHARTER-01 (canonical)

**Purpose:** Record **recurring backbone root families** so tenant and platform hardening are **root-cause-driven**, not bug-instance-driven. This file is **planning truth only** — it does **not** authorize parallel LIVE work.

**Policy:** **`FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`** remains the **only** LIVE queue (**one LIVE**, **one PARKED**). Root families are **classification and closure criteria**, not extra execution threads.

**Anti-drift (mandatory):**

- **Repeated bug fixes** that do not name and reduce a **ROOT-** family are **not** treated as real backbone closure.
- **Future hotspot tasks** (charter, audit, inventory updates) should **state which ROOT id(s)** they close or materially reduce.
- **No feature expansion** should be used to “paper over” scope gaps before **relevant root families** for the **current backbone phase** are reduced per each family’s **done** criteria and the **master plan** freeze rules (`BACKBONE-CLOSURE-MASTER-PLAN-01.md`).

**Cross-references:** `BACKBONE-CLOSURE-MASTER-PLAN-01.md`, `TENANT-SAFETY-INVENTORY-CHARTER-01.md`, `TASK-STATE-MATRIX.md`, `FOUNDATION-PRODUCTION-HARDENING-TRUTH-MAP-CHARTER-01.md` §3.

---

## ROOT-01 — Id-only repository load / lock / mutate patterns

| Field | Content |
|-------|---------|
| **Root id** | **ROOT-01** |
| **Short name** | Primary-key-only data plane |
| **Exact problem pattern** | Repository methods **`find($id)`**, **`findForUpdate($id)`**, **`update($id)`**, or equivalent **lock/mutate by id** with **no intrinsic tenant predicate** in SQL (org/branch/correlated satellite), so correctness depends entirely on every caller enforcing scope. |
| **Why it keeps reappearing** | ORMs and CRUD conventions default to id-keyed APIs; new features add call sites faster than repository contracts are tightened; cross-module reuse amplifies any missing predicate. |
| **Typical hotspot types** | Generic `*Repository::find`, worker/cron paths, “temporary” service shortcuts, merge/repair tooling. |
| **Current known examples (repo/docs)** | `TENANT-SAFETY-INVENTORY-CHARTER-01.md` § repository **`find(int $id)`** pattern; **`ORGANIZATION-SCOPED-REPOSITORY-COVERAGE-MATRIX-FOUNDATION-12.md`**; closed slices **PLT-TNT-01** (merge jobs), **FND-TNT-07**–**08** (scoped UPDATE/read on membership/commerce rows), **FND-TNT-11**–**13** (client membership id paths). |
| **What “done” means** | For **Phase 1 tenant boundary:** risky id-only surfaces either gain **intrinsic SQL scope** (org/branch/correlation) **or** are **explicitly** classified as repair/global with **named** API and proof; no silent id-guess in tenant HTTP/default service paths. |
| **Backbone phase** | **Phase 1** — Tenant boundary closure (primary); residual catalog/inventory modules per their matrices. |

---

## ROOT-02 — Null-branch / org-global / branch-bound tenant semantics ambiguity

| Field | Content |
|-------|---------|
| **Root id** | **ROOT-02** |
| **Short name** | NULL branch vs org-global vs “any branch in org” confusion |
| **Exact problem pattern** | **`branch_id IS NULL`** rows are interpreted inconsistently (org-global SKU, HQ visibility, orphan row, or repair-only), or **hand-rolled** `(branch_id = ? OR branch_id IS NULL)` trees diverge from **`OrganizationRepositoryScope`** unions. |
| **Why it keeps reappearing** | Product language mixes “global”, “HQ”, and “all branches”; schema allows NULL for legacy and catalog overlays; each module re-derives OR semantics. |
| **Typical hotspot types** | Catalog/taxonomy/settings-backed tables, client profile visibility, membership null-branch arms, intake/marketing overlays. |
| **Current known examples (repo/docs)** | Tier A **`verify_null_branch_catalog_patterns.php`**; inventory/membership waves using **`OrganizationRepositoryScope`** catalog unions; **`CLIENT-BACKEND-CONTRACT-FREEZE.md`** visibility rules. |
| **What “done” means** | Each table family has **one canonical SQL shape** (delegate to scope helpers or documented repair-only path); NULL-branch rows are **fail-closed** unless an explicit org anchor exists. |
| **Backbone phase** | **Phase 1** (tenant data plane + inventory/MGP matrices); **Phase 4** only where runtime storage/session unrelated. |

---

## ROOT-03 — Public / guest tenant bootstrap inconsistency

| Field | Content |
|-------|---------|
| **Root id** | **ROOT-03** |
| **Short name** | Anonymous public scope vs staff session scope |
| **Exact problem pattern** | Guest/public flows lack **`OrganizationContext::MODE_BRANCH_DERIVED`** (or equivalent), but code **reuses** staff repository methods that **throw** or **silently widen** when org context is missing; alternately, **branch-only** pins without proving **live branch/org** rows. |
| **Why it keeps reappearing** | Public APIs are added by copying tenant services; session and anonymous gates evolve separately; proofs focus on logged-in paths first. |
| **Typical hotspot types** | Public booking/commerce JSON, anonymous invoice/client resolution, token self-service, webhooks. |
| **Current known examples (repo/docs)** | **FND-TNT-05**, **FND-TNT-15**, **FND-TNT-16**, **FND-TNT-17** waves; **`FOUNDATION-TENANT-REPOSITORY-CLOSURE-09-AUDIT.md`** through **`FOUNDATION-TENANT-REPOSITORY-CLOSURE-11-AUDIT.md`**; residual anonymous surfaces outside **`PublicClientResolutionService`** repo trio per inventory. |
| **What “done” means** | Each anonymous entrypoint documents **explicit** contract: **branch pin + live row proof** and/or **correlated satellite read**, with **no** dependency on undeclared session org; Tier A verifiers lock the contract. |
| **Backbone phase** | **Phase 1** — Tenant boundary closure. |

---

## ROOT-04 — Repair / global / control-plane fallback ambiguity

| Field | Content |
|-------|---------|
| **Root id** | **ROOT-04** |
| **Short name** | OrUnscoped / GlobalOps / cron escape hatches |
| **Exact problem pattern** | **`AccessDeniedException`** handlers, **`globalAdmin*OrUnscoped`**, or cron/list “repair” paths **widen** scope **without** a **named**, **auditable** contract — same SQL fragment serves tenant and repair callers. |
| **Why it keeps reappearing** | Operators need break-glass and crons need cross-row scans; quick fixes add fallback branches beside tenant paths. |
| **Typical hotspot types** | Membership reconcile, inventory audit, invoice-plane helpers, renewal/expiry **GlobalOps** listings. |
| **Current known examples (repo/docs)** | **`MembershipSaleRepository`** / **`MembershipBillingCycleRepository`** invoice-plane **`OrUnscoped`** (documented); **`listActiveNonExpiredForRenewalScanGlobalOps`**; **`updateRepairOrUnscopedById`** patterns in closure audits **05**–**07**. |
| **What “done” means** | Repair/global entrypoints are **explicitly named** in code and docs; tenant default path **fail-closed**; no silent fallback from tenant to global in HTTP handlers. |
| **Backbone phase** | **Phase 1** (tenant/repair split); **Phase 3** for platform/founder control-plane (separate register notes as those tasks promote). |

---

## ROOT-05 — Service-layer scope selection drift across repository methods

| Field | Content |
|-------|---------|
| **Root id** | **ROOT-05** |
| **Short name** | Multi-method scope mismatch |
| **Exact problem pattern** | **`find`** is org-safe but **`update`** id-only, or **list** uses scope A and **findForUpdate** uses scope B; services **pick** different repository methods across flows so **read/write/lock** bases diverge. |
| **Why it keeps reappearing** | Features evolve one method at a time; refactors complete “happy path” only; tests cover single operations, not cross-method invariants. |
| **Typical hotspot types** | Membership/client/sales services calling mix of **`find`**, **`findForUpdate`**, **`list`**, **`update`** from same aggregate root. |
| **Current known examples (repo/docs)** | Closure waves **FND-TNT-07**–**12** explicitly aligning **UPDATE** with **find** predicates; **`MembershipBillingService`** / **`MembershipLifecycleService`** call-site notes in inventory. |
| **What “done” means** | For each aggregate, **documented invariant:** read / lock / mutate paths share the **same canonical scope basis** (or explicit exception list with proof). |
| **Backbone phase** | **Phase 1** — Tenant boundary closure. |

---

## Mapping: LIVE tenant program (`PLT-TNT-01`)

**LIVE** work remains **single-threaded** per charter. The tenant closure program **`PLT-TNT-01`** primarily reduces **ROOT-01**, **ROOT-02**, **ROOT-03**, **ROOT-04**, and **ROOT-05** in **Phase 1**. Individual **`FOUNDATION-TENANT-REPOSITORY-CLOSURE-*`** slices should name their **ROOT** target(s) in audit headers or inventory rows.

**Next promoted slice (inventory):** next **`TENANT-SAFETY-INVENTORY-CHARTER-01.md`** “Highest-risk areas” row (e.g. **`PaymentRepository`** aggregate/existence paths) after **`FOUNDATION-TENANT-REPOSITORY-CLOSURE-21`** (**`PaymentRepository::getByInvoiceId`**, **FND-TNT-32**).
