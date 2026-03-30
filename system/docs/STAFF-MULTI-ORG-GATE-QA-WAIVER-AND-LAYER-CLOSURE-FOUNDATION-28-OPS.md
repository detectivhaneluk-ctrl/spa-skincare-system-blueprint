# Staff multi-org gate — QA waiver and layer closure (FOUNDATION-28)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-28 — STAFF-MULTI-ORG-GATE-QA-WAIVER-AND-LAYER-CLOSURE  
**Mode:** Documentation / QA / governance closure only — **no** implementation, code edits, enforcement changes, middleware, repositories, controllers, UI, schema, refactors, or new features.  
**Prerequisites:** FOUNDATION-26 and FOUNDATION-27 completed in the same working tree.

**Companion:** `STAFF-MULTI-ORG-GATE-MANUAL-SMOKE-MATRIX-FOUNDATION-28.md` (executable manual smoke pack for operators)

---

## Purpose

Close the **staff multi-org organization resolution gate** foundation **line** (policy F-22/F-23 → boundary F-24 → implementation F-25 → truth audit F-26 → surface recheck F-27) by:

1. Fixing the **manual runtime smoke pack** that ZIP/docs alone cannot execute.  
2. Listing **explicit product/program waivers** that remain **outside** this foundation line.  
3. Stating **closure** vs **named future programs** for residual concerns.

---

## A) Exact accepted code baseline (FOUNDATION-25)

**Normative implementation summary** (see `STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE-R1-FOUNDATION-25-OPS.md`):

- **`Core\Organization\StaffMultiOrgOrganizationResolutionGate`** — fail-closed when **`countActiveOrganizations() > 1`** and organization context is unresolved (`getCurrentOrganizationId()` **null** or **≤ 0**), after exempt-path check.  
- **`AuthMiddleware::handle`** — invokes gate on the **authenticated success path**, **before** `$next()` (post-auth, pre-permission/controller).  
- **`OrganizationContextResolver::countActiveOrganizations()`** — public read-only accessor; **same** SQL as internal active-org count; **`resolveForHttpRequest`** rules **unchanged**.  
- **`system/bootstrap.php`** — DI registration for the gate.  
- **Response:** **403**; JSON when `Accept` contains `application/json`; else **text/plain**; stable message and `ORGANIZATION_CONTEXT_REQUIRED`.  
- **Exemptions (R1):** **`POST /logout`**, **`GET` / `POST /account/password`** only.  
- **Non-target:** repositories, controllers, views, schema, org/branch middleware logic, resolver resolution branching.

---

## B) Exact post-implementation truth (FOUNDATION-26)

**Normative audit summary** (see `STAFF-MULTI-ORG-ORGANIZATION-RESOLUTION-GATE-R1-POST-IMPLEMENTATION-TRUTH-AUDIT-FOUNDATION-26-OPS.md` + `STAFF-MULTI-ORG-GATE-R1-TRIGGER-ESCAPE-MATRIX-FOUNDATION-26.md`):

- **Runtime boundary:** Gate runs **only** after **`AuthMiddleware`** has accepted the session (and not on auth deny, inactivity deny, or password-expired deny on non-exempt paths).  
- **Pipeline order:** Global **`BranchContextMiddleware`** → **`OrganizationContextMiddleware`** (F-09 resolution) **before** per-route **`AuthMiddleware`**.  
- **Additional escape (Auth layer):** Password-expired users on **`POST /logout`** or **`GET`/`POST /account/password`** may receive **`$next()`** **without** the org gate (F-26 §3).  
- **Non-trigger:** **`countActiveOrganizations() <= 1`** (includes **zero** active orgs) → gate **does not** apply multi-org block.  
- **Governance vs code:** Docs say “staff”; code gates **any** identity passing **`AuthMiddleware`** (no `isStaff()` flag).  
- **Drift notes:** F-23/F-24 allowed multiple HTTP outcomes; shipped **403-only** (F-25 product choice).

---

## C) Exact post-gate containment truth (FOUNDATION-27)

**Normative recheck summary** (see `POST-GATE-STAFF-HTTP-UNRESOLVED-ORG-SURFACE-RECHECK-FOUNDATION-27-OPS.md` + `POST-GATE-STAFF-HTTP-UNRESOLVED-ORG-SURFACE-MATRIX-FOUNDATION-27.md`):

- **Contained at HTTP boundary:** **`count > 1`** + unresolved org + **non-exempt** **`AuthMiddleware`** success → **403**; F-21 org-scoped **legacy dual-path** surfaces (F-11–F-20) are **not reached** for that **entry combination**.  
- **Still reachable on staff HTTP (by design or degenerate data):**  
  - **`count ≤ 1`** (especially **zero** active organizations) — legacy dual-path **can** still run.  
  - **R1 exempt routes** — **`POST /logout`**, **`/account/password`** — controllers run with unresolved org possible under multi-org.  
- **Outside this line:** CLI/non-HTTP; guest/public routes without auth success path; F-23 **exception map** buckets **not** implemented as extra allowlists beyond R1.

---

## D) Exact runtime manual smoke scenarios to execute later

**Authoritative checklist:** `STAFF-MULTI-ORG-GATE-MANUAL-SMOKE-MATRIX-FOUNDATION-28.md`.

**Minimum pack (from F-25 §8, expanded with pass criteria in matrix):**

| ID | Scenario (short) |
|----|------------------|
| S1 | Single active org, null branch → staff dashboard (or `/`) **200** |
| S2 | ≥2 orgs, HQ / no branch → **`GET /dashboard`** **403** (text or JSON per Accept) |
| S3 | ≥2 orgs, valid branch context → dashboard **200** |
| S4 | Guest **`GET /login`** — **unchanged** (no gate) |
| S5 | Stranded branch (inactive assigned branch), multi-org → typical auth route **403**; **`POST /logout`** **succeeds** |
| S6 | Auth-only route smoke: **`GET /`** or **`GET /dashboard`** |

**Extended rows** in the matrix: JSON **`Accept`** probe, exempt **`GET /account/password`**, optional password-expiry exempt alignment, one permissioned route spot-check.

**Execution rule:** Smoke is **operator / release QA** responsibility; FOUNDATION-28 **defines** the pack only — **does not** execute it.

---

## E) Exact waiver / exception items (product/program)

| ID | Item | Foundation stance |
|----|------|-------------------|
| **W1** | **Zero active organizations** — gate does not block; F-21 legacy paths **reachable** on staff HTTP | **Waiver / ops:** treat as **degenerate deployment** (F-23 bucket). **Future program (optional):** product may open a **small gate predicate extension** wave — **not** part of F-25–F-28 closure scope. |
| **W2** | **R1 path exemptions** — `POST /logout`, `GET`/`POST /account/password` | **Accepted intentional** session/password lifecycle (F-25 §4). |
| **W3** | **Password-expiry Auth short-circuit** on same paths (F-26) | **Accepted** alignment with password flow; **not** a foundation defect. |
| **W4** | **F-23 exception map E1–E6** (HQ cross-org tooling, reports, etc.) — **no** extra allowlist in R1 | **Explicitly out of scope** for this gate line; **future product/program** if routes need org bypass — each needs **separate** audit + implementation id. |
| **W5** | **Future org pivot / branch-picker UX** | **Product** program; **not** required to close F-25 line. |
| **W6** | **Repository dual-path SQL** left in codebase | **Defense in depth / CLI / ≤1-org** semantics per F-27; **not** removed by gate line. |
| **W7** | **“Staff” wording vs Auth-only gate** (F-26) | **Documented**; acceptable if all staff app routes use **`AuthMiddleware`**. |

---

## F) Exact closure recommendation for this line

| Question | Answer |
|----------|--------|
| Is the **staff multi-org gate foundation line** (F-22 → F-27 + this closure) **closed**? | **Yes** — for **documentation / governance / QA definition**: implementation (F-25), code truth (F-26), containment (F-27), smoke pack + waivers (F-28) are **complete**. |
| Is **runtime proof** closed by F-28 alone? | **No** — operators must **run** the manual smoke matrix and record results under their **release process**. |
| Is **all** staff-HTTP unresolved-org risk eliminated? | **No** — **W1** and **W2/W3** remain **documented**; **not** foundation blockers for **closing this line**. |
| **Future programs** (only if needed) | **Optional:** zero-org fail-closed wave; org-picker / session org; per-route waivers from F-23 map; CLI org-context strategy; repository dual-path tightening **per domain**. |

**Line closure statement:** The **FOUNDATION-25 R1 staff multi-org org-resolution gate** foundation stream is **closed** at the **layer** of **post-auth HTTP enforcement + audits + QA pack + waivers**. Residual items are **named** above and are **not** auto-scheduled.

---

## Items intentionally not advanced

- No code, ZIP build, verifier scripts, or automatic FOUNDATION-29.

---

## Checkpoint readiness

FOUNDATION-28 **QA/waiver/layer closure** artifacts are **complete**. **Next:** human **execution** of `STAFF-MULTI-ORG-GATE-MANUAL-SMOKE-MATRIX-FOUNDATION-28.md` + program sign-off; **no** automatic next foundation task from this wave.
