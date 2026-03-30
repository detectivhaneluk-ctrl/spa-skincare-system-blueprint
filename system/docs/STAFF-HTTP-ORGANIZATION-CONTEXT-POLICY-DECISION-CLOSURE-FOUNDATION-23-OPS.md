# Staff HTTP organization context — policy decision closure (FOUNDATION-23)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-23 — STAFF-HTTP-ORGANIZATION-CONTEXT-POLICY-DECISION-CLOSURE-AND-EXCEPTION-MAP  
**Mode:** Governance / design closure only — **no** implementation, enforcement, middleware, repository, controller, UI, schema, or refactor.  
**Prerequisite:** `STAFF-HTTP-ORGANIZATION-CONTEXT-POLICY-TRUTH-AND-DESIGN-AUDIT-FOUNDATION-22-OPS.md` + options matrix (FOUNDATION-22).  
**Evidence chain:** FOUNDATION-09 (resolver + modes), FOUNDATION-21 (unresolved → legacy SQL / assert no-op), FOUNDATION-22 (staff branch pipeline + recommended mixed model).

**Companion:** `STAFF-HTTP-ORG-RESOLUTION-EXCEPTION-MAP-FOUNDATION-23.md`

**Naming note:** FOUNDATION-22 §3F used the label “FOUNDATION-23” for a **future code wave**. **This document is FOUNDATION-23 = closure only.** The **minimal implementation** slice is **renamed** to **FOUNDATION-24** below to keep wave IDs unambiguous.

---

## A) Chosen baseline policy (staff-authenticated HTTP)

**Adopted baseline — “Mixed staff multi-org organization-mandatory gate”:**

1. **`OrganizationContextResolver` (F-09) remains the single canonical computer** of `OrganizationContext` from `BranchContext` + active organization count. **No** “pick first org” when ambiguous.  
2. **Single active organization** in DB: staff may continue to operate with **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`** when branch is null — **no** additional foundation requirement to set branch solely for org resolution.  
3. **Multiple active organizations** in DB: **authenticated staff** must **not** routinely proceed with **`getCurrentOrganizationId() === null`** (`MODE_UNRESOLVED_AMBIGUOUS_ORGS` or `MODE_UNRESOLVED_NO_ACTIVE_ORG` for normal ops). **Foundation intent:** enforce via a **single post-auth gate** (implementation = **FOUNDATION-24**, not this wave).  
4. **HQ / null `users.branch_id` in multi-org** must obtain a **resolved org** through **existing** mechanisms (request/session `branch_id` per `BranchContextMiddleware`) **or** through **future product** org pivot — not through resolver guessing.

---

## B) Why this baseline fits FOUNDATION-09 through FOUNDATION-21

| Foundation | Fit |
|------------|-----|
| **F-09** | Preserves resolver semantics and **non-guess** rule; does not require changing global middleware order or guest behavior in this policy. |
| **F-11 / F-13 / F-14 / F-16 / F-18** | Org-scoped SQL and asserts **already assume** resolved org for isolation; baseline **reduces** reliance on **dual-path legacy** (F-21) for **staff multi-org** without rewriting every repository. |
| **F-19 / F-20** | Client list provider paths **inherit** `ClientRepository::list`; **resolved org** is the intended QA baseline; closure **narrows** when unresolved staff sessions are acceptable (**multi-org: not for routine work**). |
| **F-21** | Explicitly said **no repo-wide** safe hardening without **policy**; this closure **is** that policy decision for **staff HTTP**, not a repository edit. |

---

## C) Rejected alternatives (and why)

| Alternative | Rejection reason |
|-------------|------------------|
| **Status quo universal** (no staff gate) | Leaves **F-21** legacy paths active for **staff multi-org** — **high ambiguity** and inconsistent with org-scoped foundation intent. |
| **Repository-wide fail-closed when `resolvedOrganizationId()` null** | **Massive blast radius**, duplicates policy at every method, fights intentional F-13/F-14/F-16/F-18 dual-path design. |
| **Resolver picks arbitrary org when ambiguous** | **Violates F-09** explicit non-guess; **unacceptable** cross-org risk. |
| **Universal “branch must be non-null” for all staff including single-org** | **Unnecessary friction**; **F-09** already resolves org via **`single_active_org_fallback`**. |
| **Controller-only enforcement as primary** | **Drift** and **omission** risk vs **one** post-auth choke (F-22 §3E). |
| **Route-tiered strict as the only mechanism** | **Inventory maintenance** burden; acceptable **only** as **supplement** if universal gate is too blunt (exception map). |

---

## D) Exception buckets (product / program — not blanket foundation force)

Detailed mapping: **`STAFF-HTTP-ORG-RESOLUTION-EXCEPTION-MAP-FOUNDATION-23.md`**.

**Summary buckets:**

1. **Explicit product waivers** — cross-org HQ tooling, global analytics/report routes, domain-specific “all orgs” reads.  
2. **Operational / data recovery** — users with **inactive** assigned branch (`allowedBranchIds = []`); requires **user admin** or **support** process, not silent bypass.  
3. **Degenerate DB** — zero active organizations; **treat as error** / deployment fix, not normal policy.  
4. **Future explicit org pivot** — session-stored org selection; **product + implementation** program **outside** minimal F-24 gate.  
5. **Public / guest / CLI** — **out of scope** for this staff-HTTP baseline (F-09 already separates CLI).

---

## E) Smallest future implementation boundary **name** (not implemented here)

**Wave identifier:** **FOUNDATION-24 — STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE (minimal enforcement)**

**Scope (normative for a future code wave):**

- Add **one** enforcement point **after** `AuthMiddleware` on **staff** routes (new middleware **or** thin wrapper):  
  - If **authenticated staff** **and** **count(active organizations) > 1** **and** **`OrganizationContext::getCurrentOrganizationId()` is `null`** → **fail closed** (HTTP **403** or **409** with stable machine-readable hint, **or** **302** to a branch-selection entry — **product chooses**).  
- **Do not** in the same minimal wave: change `OrganizationContextResolver` rules, edit `ClientRepository` / marketing / payroll SQL, add `users.organization_id`, or redesign UI.

---

## F) Acceptance gates (for FOUNDATION-24 when implemented)

| Gate | Pass criteria |
|------|----------------|
| **G-F24-SINGLE-ORG** | With **exactly one** active org, staff requests behave **as today**: **`single_active_org_fallback`** still works; **no** new block solely for null branch. |
| **G-F24-MULTI-ORG-HQ** | With **≥2** active orgs, staff user with **null** `users.branch_id` and **no** valid session/request branch receives **fail closed** (or **redirect** per product), **not** silent legacy org-scoped reads. |
| **G-F24-BRANCH-DERIVED** | With valid **branch context**, org is **`branch_derived`** and **unaffected** by gate (already resolved). |
| **G-F24-GUEST-PUBLIC** | Routes **without** staff session **unchanged** by gate (gate is **post-auth** or no-op for guests). |
| **G-F24-EXCEPTION-LIST** | Any **documented** waiver route (from exception map) **either** excluded from gate **or** fails a **separate** explicit audit — **no** accidental hole. |
| **G-F24-STRANDED-USER** | Behavior for **inactive assigned branch** documented: **blocked** until data fix **or** explicit waiver path — **product sign-off**. |
| **G-F24-REGRESSION-SMOKE** | Manual smoke: login, branch pick, invoices/clients/marketing index in **multi-org** — **no** cross-org data bleed on happy path. |

---

## G) Remains out of scope (post F-23 closure)

- **Implementing** FOUNDATION-24.  
- Resolver “smart guessing,” repo dual-path removal, RBAC-by-org, `users.organization_id`, storage, subscriptions, **UI** for branch picker (product owns).  
- **Automatic** opening of F-24 in the same commit as this closure.

---

## Checkpoint readiness

FOUNDATION-23 **policy decision** and **exception map** are **closed** in documentation. **Next:** ZIP/governance acceptance; **FOUNDATION-24** only when explicitly tasked for **minimal gate** implementation.
