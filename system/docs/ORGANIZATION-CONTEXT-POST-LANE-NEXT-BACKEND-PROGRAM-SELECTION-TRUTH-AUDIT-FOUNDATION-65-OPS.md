# ORGANIZATION CONTEXT — POST-LANE NEXT BACKEND PROGRAM SELECTION TRUTH AUDIT (FOUNDATION-65)

**Mode:** Read-only selection audit after **FOUNDATION-64**. **No** code, schema, routes, or reopening of the closed membership/runtime truth lane without **code-proven** contradiction.

**Evidence read:** `StaffMultiOrgOrganizationResolutionGate.php`, `OrganizationRepositoryScope.php`, `OrganizationScopedBranchAssert.php`, `OrganizationContext.php`, `OrganizationContextResolver.php` (throw sites + baseline), `BranchContextMiddleware.php` (head), `OrganizationContextMiddleware.php`, `Dispatcher.php` (global order), grep `OrganizationRepositoryScope` / `OrganizationScopedBranchAssert` / `getCurrentOrganizationId` across `system/**/*.php` (consumer counts), **F-64 ops** §6–§8 (closure + waivers + outside-lane list).

---

## 1. FOUNDATION-64 closes the membership/runtime truth lane (baseline)

**F-64 §7** states **CLOSE THIS LANE AS COMPLETE WITH WAIVERS**; **§1** inventories closed slices (safe reads without 087, backfill, strict gate, verifiers, F-57 + F-62 resolver paths). **§4** limits membership **INSERT** to backfill service. **No** code read in this audit contradicts that closure narrative.

---

## 2. No code-proven in-lane blocker forcing immediate reopen of F-46–F-64

**F-64 §6** lists **W-64-1–W-64-6** as **deferrable** waivers (HTTP/`DomainException` mapping, defensive fail-opens, PHPDoc lag, perf, optional DB hardening). None are framed as **incorrect** resolver/membership implementation relative to the **closed** lane contract.

**Reopening** the lane would require a **new** task proving **spec/contract** violation in **`UserOrganizationMembershipReadRepository`**, **`OrganizationContextResolver`** membership/F-62 blocks, backfill, strict gate, or verifiers — **not** evidenced here.

---

## 3. Organization-adjacent candidate programs (grouped by risk)

| Bucket | Risk | Why |
|--------|------|-----|
| **A — F-25 gate semantics / enforcement** | **Medium–high** if **changing** code | **`StaffMultiOrgOrganizationResolutionGate`** owns **403** JSON/plain exits and **path exemptions** (F-43/F-44). Any behavior change intersects **staff HTTP** surface (`enforceForAuthenticatedStaff` lines 30–45). |
| **B — `OrganizationRepositoryScope` semantics** | **High** | SQL fragments consumed by **clients, marketing, payroll, sales** modules (grep footprint). New fail-closed clauses or changed `resolvedOrganizationId` semantics → **broad data-plane** impact. |
| **C — `OrganizationScopedBranchAssert` hardening** | **Medium** | Many **`assertBranchOwnedByResolvedOrganization`** call sites (services/controllers). Adding/changing throws without a **prior** coverage map risks **inconsistent** mutation paths. |
| **D — Narrow downstream runtime consumer cluster** | **High** | Treating **ClientService**, **InvoiceService**, **PayrollService**, etc. as one “program” duplicates **tenant policy** already centralized in resolver + scope + assert. |
| **E — None yet** | **Low** | Only valid if **no** deferrable waiver justified work — **false**: **W-64-1** explicitly defers **error surface** work (**F-64 §6**). |

---

## 4. Single recommended next backend program (exact choice)

### **Program: F-25 vs resolver `DomainException` error-surface read-only truth audit**

**Objective:** Map **how** `OrganizationContextResolver` failures (**F-57** membership message, **F-62 M1/M2**, branch unlink, etc.) propagate through **global middleware order** relative to **`AuthMiddleware`** and **`StaffMultiOrgOrganizationResolutionGate`**, and how **`ErrorHandlerMiddleware`** (or equivalent) presents them — **read-only**, **no** behavior change in the audit wave.

**Ties to closure:** Directly addresses **W-64-1** (**F-64 §6**) without editing **`OrganizationContextResolver`** (lane baseline) or **membership** code.

---

## 5. Why rejected candidates must wait

| Candidate | Wait because |
|-----------|----------------|
| **F-25 enforcement change** | **Premature** before **W-64-1** is **mapped**; risk of **double denial**, wrong **403** vs **4xx/5xx** split, or **exemption** regressions. |
| **`OrganizationRepositoryScope` change** | **Highest** blast radius across repositories; needs **product** rules per domain, not a post-lane default. |
| **`OrganizationScopedBranchAssert` hardening** | Needs **coverage inventory** (existing F-11-style verifiers as **input**) before **any** new throw paths; otherwise **patchy** staff UX. |
| **Downstream consumer cluster** | **Violates** F-64 **§8** spirit (“do not casually reopen” broad cross-module work); duplicates resolver/scope responsibilities. |
| **No action** | Ignores an **explicit** closed-lane waiver (**W-64-1**) that is **customer-visible**. |

---

## 6. How the next program should begin

**Begin with: read-only truth audit** (mandatory first slice).

**Narrow implementation** (e.g. stable error **codes** for **M1/M2**, JSON shape) is **out of scope** for the **first** wave and belongs to a **separate** task **after** the audit produces a matrix.

**No action yet** applies only to **implementation** — **not** to **planning**; the audit program **is** the sanctioned next step.

---

## 7. Minimal boundary the next program must obey

1. **Read-only** first deliverable: ops doc + matrix + roadmap row (pattern of F-58/F-63/F-64).
2. **In scope for reads:** `OrganizationContextResolver` (**throw messages/sites only**, no body edits), `OrganizationContextMiddleware`, `Dispatcher` global order, **`StaffMultiOrgOrganizationResolutionGate`** (contract + exemptions list), **`AuthMiddleware`** invocation order reference; **extend** to `Core\Middleware\ErrorHandlerMiddleware` / `Core\Errors\*` **only** as needed to explain **`DomainException`** handling (not listed in F-65 scope file list but **required** to close **W-64-1** honestly).
3. **Out of scope for first wave:** edits to **`OrganizationContextResolver`**, **F-25** body, **`OrganizationRepositoryScope`**, **`OrganizationScopedBranchAssert`**, **`BranchContextMiddleware`**, routes, schema, UI.
4. **Do not** reopen F-46–F-64 lane **unless** audit discovers **code** contradiction with F-64 closure claims (burden of proof on finder).

---

## 8. Surfaces that must remain untouched in the first (audit) wave

- **`OrganizationContextResolver`** behavior (baseline only).
- **`StaffMultiOrgOrganizationResolutionGate`** implementation.
- **`OrganizationRepositoryScope`**, **`OrganizationScopedBranchAssert`**, **`BranchContextMiddleware`** implementation.
- **Controllers, services, repositories** outside **read** for error-handler tracing.
- **Schema, routes.**

---

## 9. Waivers / risks (selection audit)

| Id | Waiver / risk |
|----|----------------|
| **W-65-1** | Audit may conclude **current** `DomainException` handling is **acceptable** — **no** second-phase implementation required. |
| **W-65-2** | Error-handler code may treat **all** `DomainException` uniformly — product may still want **distinct** UX for **M1/M2** vs generic errors. |
| **W-65-3** | **Second phase** (if any) could touch **global** middleware — must stay **narrow** and **separate** from membership lane. |
| **W-65-4** | **W-64-4** ( **`OrganizationContext` PHPDoc** lag on F-62) remains; could be a **tiny** parallel doc-only task — **not** selected here to keep **one** primary next program. |

---

## 10. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | Selection **sound**; **one** program; boundaries **clear**. |
| **B** | Selection **sound** with **material** caveat. |
| **C** | Unsupported. |

**FOUNDATION-65 verdict: A**

---

## 11. STOP

**FOUNDATION-65** ends here — **FOUNDATION-66** is **not** opened.

**Companion:** `ORGANIZATION-CONTEXT-POST-LANE-NEXT-BACKEND-PROGRAM-SURFACE-MATRIX-FOUNDATION-65.md`.

**ZIP:** `distribution/spa-skincare-system-blueprint-FOUNDATION-65-POST-LANE-NEXT-BACKEND-PROGRAM-SELECTION-CHECKPOINT.zip`.
