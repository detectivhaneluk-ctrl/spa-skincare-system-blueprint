# Staff HTTP organization context — policy truth and design audit (FOUNDATION-22)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-22 — STAFF-HTTP-ORGANIZATION-CONTEXT-POLICY-TRUTH-AND-DESIGN-AUDIT  
**Mode:** Audit / design only — **no** implementation, middleware edits, repository edits, controller edits, UI, schema, refactor, or new features.  
**Upstream:** FOUNDATION-06 through **FOUNDATION-21** accepted. **Evidence base for unresolved consequences:** F-21 (`ORGANIZATION-RESOLUTION-GAP-AND-UNRESOLVED-BEHAVIOR-TRUTH-AUDIT-FOUNDATION-21-OPS.md` + matrix).

**Companion:** `STAFF-HTTP-ORG-RESOLUTION-POLICY-OPTIONS-MATRIX-FOUNDATION-22.md`

---

## 1) Scope boundary (strict)

- **In scope:** Staff-authenticated HTTP requests — meaning a session user exists **after** `AuthMiddleware` on a route that uses it; organization/branches are the same **request-scoped** `OrganizationContext` / `BranchContext` already set **earlier** in the global pipeline.
- **Out of scope:** Public/anonymous HTTP, CLI/cron, UI wireframes, RBAC redesign, new session keys (design may *mention* them as future options only).

**Critical pipeline truth (evidence):** `BranchContextMiddleware` and `OrganizationContextMiddleware` run in **global** middleware **before** per-route `AuthMiddleware` (```20:25:system/core/router/Dispatcher.php```). Therefore **organization is resolved (or not) before auth is known** for that request; “staff policy” is about **what the product should require** once the user is staff, not about changing that order in this wave.

---

## 2) Current truth — how staff HTTP gets branch, then org

### 2.1 `BranchContextMiddleware` (staff, `user` non-null)

Evidence: ```38:86:system/core/middleware/BranchContextMiddleware.php```

| Situation | `BranchContext` result |
|-----------|-------------------------|
| User row **`branch_id`** set and **active** | `allowedBranchIds = [that id]`; resolution prefers request `branch_id` (if allowed), else session `branch_id` (if allowed), else user branch → **`getCurrentBranchId()`** typically **non-null** |
| User row **`branch_id`** **null** (HQ-style) | `allowedBranchIds = null` → request or session **may** select **any** active branch id; if neither yields valid id, **`getCurrentBranchId()`** is **null** |
| User row **`branch_id`** points to **inactive/deleted** branch | `allowedBranchIds = []` → no request/session override can set branch; **`getCurrentBranchId()`** forced **null** (session key cleared) |

### 2.2 `OrganizationContextResolver::resolveForHttpRequest`

Evidence: ```29:62:system/core/organization/OrganizationContextResolver.php``` and F-09 ops.

| `BranchContext` | DB org count | `OrganizationContext` outcome |
|-----------------|--------------|------------------------------|
| **Non-null** branch | N/A | **Resolved** org id, **`MODE_BRANCH_DERIVED`**, unless branch has no active org link → **`DomainException`** (hard fail) |
| **Null** branch | **0** active orgs | Org **null**, **`MODE_UNRESOLVED_NO_ACTIVE_ORG`** |
| **Null** branch | **1** active org | **Resolved** org id, **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`** |
| **Null** branch | **≥2** active orgs | Org **null**, **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`** |

### 2.3 Staff-HTTP resolution modes (current, exhaustive)

For a **single HTTP request** after global middleware, `OrganizationContext` is exactly one of:

1. **`branch_derived`** — org resolved from current branch.  
2. **`single_active_org_fallback`** — branch null, exactly one active org.  
3. **`unresolved_ambiguous_orgs`** — branch null, multiple active orgs.  
4. **`unresolved_no_active_org`** — branch null, zero active orgs (or id load anomaly).  
5. **Request failure** — branch non-null but invalid org linkage (**`DomainException`** before controller).

---

## 3) Questions A–G — explicit answers

### A) On staff-authenticated HTTP requests, in which exact situations may organization **currently** remain unresolved?

**Evidence-backed:**

1. **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`:** `BranchContext::getCurrentBranchId()` **null** and **≥2** active organizations. Typical staff shape: **HQ user** (`users.branch_id` null) **and** no valid `branch_id` in GET/POST/session for this request **or** user pinned to inactive branch (see below).  
2. **`MODE_UNRESOLVED_NO_ACTIVE_ORG`:** Branch **null** and **no** active organizations (or resolver could not read a positive id).

**Not “unresolved” but request fails:** Branch **non-null** and branch not linked to active org → **exception** (not a null org id state).

**Note:** For staff with **assigned inactive branch**, branch context is **null**; resolver then behaves like any other **null-branch** case (single-org → fallback resolved; multi-org → **ambiguous unresolved**).

### B) Which situations are **acceptable by design**, which are **unsafe/ambiguous**?

| Situation | Design intent (F-09) | Risk (F-21) |
|-----------|----------------------|-------------|
| **`single_active_org_fallback`** with branch null | **Acceptable** — single deployment; org is still resolved | Low for org isolation **if** only one org forever |
| **`branch_derived`** | **Acceptable** — canonical multi-tenant shape | Low at org layer **if** branch is trustworthy |
| **`unresolved_ambiguous_orgs`** on staff hitting org-scoped repos | **Logically ambiguous** — F-09 explicitly refuses to guess | **High** for F-13/F-14/F-16/F-18 dual-path: **legacy global / ID-only** behavior |
| **`unresolved_no_active_org`** | **Degenerate** data / migration state | **Unsafe** operationally |
| **Assigned inactive branch** (branch forced null) | **Safety** choice in `BranchContextMiddleware` (no pivot) | **Ambiguous** for org + UX; user may be “stuck” until data fix |

**Recommendation language:** “Acceptable” vs “unsafe” is **product + deployment** dependent; **technically**, only **resolved org** aligns with **org-scoped SQL** as implemented in F-13/F-14/F-16/F-18.

### C) Possible **policy models** for staff HTTP org resolution

See **matrix doc** for full comparison. Summary of **options**:

1. **Status quo** — keep F-09 resolver only; no staff-specific gate.  
2. **Staff fail-closed** — if authenticated staff and `getCurrentOrganizationId()` null → **block** entire request (or org-scoped subset).  
3. **Staff branch-mandatory (multi-org)** — if `active_orgs > 1`, require **`BranchContext` non-null** for staff (else block or redirect policy).  
4. **Explicit org session** — introduce persisted **staff org pivot** (separate from branch) resolved before work.  
5. **Route-tiered strictness** — strict gate only on **enumerated** org-scoped route groups; permissive elsewhere.  
6. **Mixed** — resolver unchanged; **post-auth** gate + optional route list + product waivers.

### D) **Single** policy model that is **safest and most coherent** with accepted foundation work

**Recommended (design, not implemented):** **Mixed staff multi-org org-mandatory gate with resolver as single source of truth.**

- **Coherence:** Keeps **F-09** `OrganizationContextResolver` as the **only** place that **computes** org from branch + DB counts (no second resolver).  
- **Safety:** For **authenticated staff** when the deployment is **multi-org** (`active org count > 1`), **do not** rely on **legacy repository paths** for routine work: **require** **`getCurrentOrganizationId() !== null`** (equivalently: require **`branch_derived`** or acceptable **single-org** case — in multi-org, that effectively means **non-null branch context** unless product adds **explicit org pivot**).  
- **Single-org:** **Continue** allowing **`single_active_org_fallback`** without extra friction (matches F-09 and current low-friction HQ behavior when only one org exists).

This is **one named model** with two **deployment branches** (single-org vs multi-org), not two competing global policies.

### E) Where should the policy **live** conceptually?

**Recommended: mixed model**

| Layer | Role |
|-------|------|
| **`OrganizationContextResolver`** | **Retain** canonical resolution **rules** (F-09); optional **future** tightening of *when* ambiguous is allowed should be **spec’d here first**, then coded — not scattered. |
| **Middleware** | **Post-auth** staff gate is the **natural choke** for **universal** enforcement (runs after `AuthMiddleware`, reads `OrganizationContext` + staff session + DB org count). **Not** replacing global pre-auth org middleware without a dedicated wave design. |
| **Controller precondition** | **Avoid** as the **primary** mechanism for universal policy (duplication, drift); **acceptable** for **exception** routes explicitly waived by product. |
| **Explicit branch/org selection prerequisite** | **Product prerequisite** for HQ users in multi-org: must set branch (today via GET/POST/session) **or** future org pivot. |
| **Route-tiered** | **Secondary** tool: strict list for **highest-risk** domains if universal gate is too blunt. |

### F) **Smallest** future implementation boundary **if** this policy were accepted (design name only)

**Superseded naming:** governance closure is **FOUNDATION-23**; the **code wave** is **FOUNDATION-24** — see `STAFF-HTTP-ORGANIZATION-CONTEXT-POLICY-DECISION-CLOSURE-FOUNDATION-23-OPS.md`. **Original label retained below for traceability:**

**`FOUNDATION-24 — STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE (minimal)`** — one implementation wave that adds **only**:

- A **post-`AuthMiddleware`** check (new middleware class **or** small shared guard invoked from a thin middleware) that: if **staff** **and** **active organization count > 1** **and** **`getCurrentOrganizationId()` is null** → **fail closed** (HTTP 403/409 with stable error **or** redirect-to-branch-picker policy **decided by product**).

**Explicitly out of that minimal boundary:** repository SQL edits, changing F-09 resolver semantics for guests, UI design, new `users.organization_id`, broad refactors.

### G) Flows requiring **explicit product waiver** (not auto-forced by foundation code)

- **Intentional cross-org HQ tooling** (if product insists on global lists while multi-org) — **contradicts** org-scoped repos unless **read** paths are re-audited.  
- **“Global” marketing/payroll reports** without branch — **waiver** per domain.  
- **Stranded users** (inactive assigned branch): **product** must define recovery (reassign user, admin fix, or temporary bypass).  
- **Single-org** deployments: **no waiver** needed for current fallback (already resolved).  
- **F-20 client dropdown QA baselines** under **resolved** org: **unaffected** by this policy; **unresolved** sessions should become **rare** if gate applies.

---

## 4) “Not recommended” alternatives (brief)

| Alternative | Why not recommended as **foundation-default** |
|-------------|-----------------------------------------------|
| **Repo-wide fail-closed when org null** | Breaks intentional F-13/F-14/F-16/F-18 dual-path; huge blast radius; duplicates policy in every method. |
| **Change resolver to “pick first org” when ambiguous** | Violates F-09 **explicit non-guess**; dangerous cross-org bleed. |
| **Controller-only checks** | Drift and omissions vs **one** gate. |
| **Universal staff block when branch null** in **single-org** | Unnecessary friction; **fallback** already resolves org. |

---

## 5) Evidence vs recommendation (discipline)

- **Evidence:** §2 pipeline order, middleware + resolver code paths, F-21 consequence inventory.  
- **Recommendation:** §3D–F (named policy model, mixed placement, F-23 boundary **name**).  
- **This wave does not implement or enforce** any recommendation.

---

## 6) Items intentionally not advanced

- No implementation code in this design wave; **closure** = **FOUNDATION-23** ops doc; **implementation id** = **FOUNDATION-24** (see F-23 closure).

---

## 7) Checkpoint readiness

Primary + matrix + checklist/roadmap traceability complete. **Next:** **FOUNDATION-23** policy closure doc; **FOUNDATION-24** for minimal gate when tasked.
