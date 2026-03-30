# Post-gate staff HTTP unresolved-organization surface recheck (FOUNDATION-27)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-27 — POST-GATE-STAFF-HTTP-UNRESOLVED-ORG-SURFACE-RECHECK  
**Mode:** Read-only audit — **no** implementation, enforcement, middleware, repositories, controllers, UI, schema, refactors, or new features.  
**Prerequisite:** FOUNDATION-26 accepted in the same working tree (gate truth: `STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE-R1-POST-IMPLEMENTATION-TRUTH-AUDIT-FOUNDATION-26-OPS.md`).  
**Upstream problem statement:** FOUNDATION-21 (`ORGANIZATION-RESOLUTION-GAP-AND-UNRESOLVED-BEHAVIOR-TRUTH-AUDIT-FOUNDATION-21-OPS.md` + matrix) — dual-path org-scoped surfaces when `resolvedOrganizationId()` is null.  
**Intervention audited:** FOUNDATION-25 post-auth **`StaffMultiOrgOrganizationResolutionGate`** (predicate per F-26: `countActiveOrganizations() > 1` ∧ unresolved org ∧ not exempt ∧ Auth success path).

**Companion matrix:** `POST-GATE-STAFF-HTTP-UNRESOLVED-ORG-SURFACE-MATRIX-FOUNDATION-27.md`

---

## 1) Method — what changed since FOUNDATION-21

FOUNDATION-21 catalogued **repository / assert / provider** behavior when organization context is **unresolved**, independent of whether a staff HTTP request could still reach those code paths. After FOUNDATION-25, **staff-authenticated HTTP** requests that match the **gate trigger** are **terminated with 403** inside `AuthMiddleware` **before** `PermissionMiddleware` or controllers run. Therefore, **legacy SQL branches** documented in F-21 are **not exercised** for that **entry combination** on **non-exempt** `AuthMiddleware` routes.

This wave **does not** re-prove every repository line; it **reconciles** the F-21 surface inventory with the **post-gate HTTP entry boundary** proven in F-26.

---

## 2) Files reviewed

| Path | Role |
|------|------|
| `system/docs/ORGANIZATION-RESOLUTION-GAP-AND-UNRESOLVED-BEHAVIOR-TRUTH-AUDIT-FOUNDATION-21-OPS.md` | Pre-gate unresolved problem |
| `system/docs/ORGANIZATION-UNRESOLVED-BEHAVIOR-SURFACE-MATRIX-FOUNDATION-21.md` | F-10–F-20 surface classification |
| `system/docs/STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE-R1-POST-IMPLEMENTATION-TRUTH-AUDIT-FOUNDATION-26-OPS.md` | Post-gate choke truth |
| `system/docs/STAFF-MULTI-ORG-GATE-R1-TRIGGER-ESCAPE-MATRIX-FOUNDATION-26.md` | Trigger / exemption details |
| `system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php` | Predicate + exemptions (confirm) |
| `system/core/middleware/AuthMiddleware.php` | Gate call site + password-expiry short-circuit |
| `system/routes/web/register_core_dashboard_auth_public.php` | `AuthMiddleware` vs `GuestMiddleware` vs `[]` public |
| `system/routes/web.php` | Route load order / module includes |
| `system/modules/intake/routes/web.php` | Example: staff vs `[]` public |
| `system/core/router/Dispatcher.php` | Global org middleware before per-route auth |

---

## 3) Explicit answers (audit questions A–E)

### A) After FOUNDATION-25, which previously documented unresolved-org **staff HTTP** paths are now blocked?

For **authenticated** requests that use **`AuthMiddleware`**, are **not** on gate-exempt paths, and satisfy **`countActiveOrganizations() > 1`** with **`getCurrentOrganizationId()`** null or **≤ 0**:

- **All** controller-bound staff HTTP work that F-21 assumed could run with **multi-org + unresolved org** — **blocked at 403** before controller. That **includes every F-21 matrix row** in sections **B–F** (F-11 assert call sites reached only via those controllers, F-13/F-14/F-16/F-18 repos, F-19/F-20 provider inheritance) **for that entry condition**.

**Concrete F-21 symptom addressed:** §3.3 — “Authenticated staff does **not** imply resolved org: HQ / null branch in **multi-org** DB → … **`getCurrentOrganizationId()` null**” — **no longer** reaches org-scoped domain code on routine **non-exempt** staff routes.

### B) Which staff HTTP paths, if any, still remain **unresolved** and **reachable**?

1. **`countActiveOrganizations() <= 1`** (includes **zero** active orgs): gate **does not** apply the multi-org block. Staff **`AuthMiddleware`** routes still run; **`MODE_UNRESOLVED_NO_ACTIVE_ORG`** (0 orgs) leaves org **null** — **full F-21 legacy dual-path exposure remains reachable** on staff HTTP for that degenerate deployment shape. Single-org (`count === 1`) normally resolves org via F-09 fallback; edge cases remain resolver-owned, not gate-owned.

2. **Gate-exempt authenticated routes** (normalized paths): **`POST /logout`**, **`GET` / `POST /account/password`**. With **multi-org** + unresolved org, controllers **still run**; scope is **session teardown** or **password change** (not the broad invoice/client/marketing/payroll surfaces).

3. **`AuthMiddleware` password-expired exempt short-circuit** (F-26): same two path families may call `$next()` **without** invoking the org gate — **same exemption class** as (2) for expiry scenarios.

4. **No evidence** in sampled registrars of **staff** business routes **without** `AuthMiddleware`; public/empty-stack routes are **not** “staff authenticated HTTP” for this audit.

### C) Which remaining unresolved cases are **outside** staff HTTP?

- **CLI / non-HTTP:** F-21 §1 — no `OrganizationContextMiddleware`; **`resolvedOrganizationId()`** typically null unless another entrypoint sets context — **unchanged**; **not** gated by F-25.
- **Guest / public HTTP:** `GuestMiddleware` or **`[]`** middleware (e.g. `/login`, `/api/public/*`, `/public/intake/*`) — **no** `AuthMiddleware` success path → **org gate not invoked**; unresolved-org **semantics** may still exist on global org middleware but **not** the F-21 “authenticated staff HQ multi-org” story.
- **F-23 exception buckets** not implemented as extra allowlists (e.g. HQ cross-org tooling): **not** re-opened here; only **R1** exemptions apply in code.

### D) Can the staff-HTTP unresolved-org **foundation** problem now be considered closed?

**Split answer:**

- **Closed (for the F-23 / F-21 headline risk):** **Multi-org** deployments — **routine staff HTTP** cannot proceed with **unresolved** organization into org-scoped **business** surfaces behind **`AuthMiddleware`** (except the **narrow** exempt paths). Foundation intent “**post-auth gate** for staff multi-org” is **met** in code for that slice.

- **Not closed (absolute):** **Zero active organizations** still allows **unresolved** org on **all** staff `AuthMiddleware` routes; **exempt** routes still run with unresolved org under multi-org; **repository dual-path code** remains (**dormant** for multi-org+unresolved staff HTTP, **active** for ≤1-org unresolved cases).

### E) If not fully closed, what is the **smallest** remaining gap?

1. **Optional product wave:** Extend policy to **fail-closed on staff HTTP** when **`countActiveOrganizations() === 0`** (F-25 ops already notes gate does not fire). Smallest **code** change would be a **predicate tweak** in the existing gate class — **out of scope** for this audit wave.

2. **Operational:** Treat **zero-org** DB as **deployment error** (F-23 degenerate bucket) without new code.

3. **Accept** R1 exemptions as **intentional** session-lifecycle surface.

**No** additional **repository** sweep is required **solely** to close the **multi-org staff HTTP unresolved** problem — F-25 gate already **subsumes** that at the HTTP boundary.

---

## 4) Closure recommendation (foundation program)

| Scope | Verdict |
|-------|---------|
| **F-23 baseline — multi-org staff HTTP org-mandatory** | **Achieved** at HTTP choke (F-25 + F-26 proof). |
| **F-21 dual-path SQL** | **Still present** as **defense-in-depth / CLI / ≤1-org** behavior; **not** removed. |
| **“Staff HTTP unresolved org” as umbrella** | **Narrow** to: **(i)** multi-org routine work → **closed**; **(ii)** zero-org / exemptions → **documented residual**. |

---

## 5) Items intentionally not advanced

- No manual runtime smoke; no verifier; no FOUNDATION-28 / code wave opened from this document.

---

## 6) Checkpoint readiness

FOUNDATION-27 **read-only** recheck is **complete**: F-21 surfaces are **mapped** to **post-F-25** staff HTTP reachability. **ZIP / program acceptance** and **manual smoke** (F-25 §8) remain **outside** this wave.
