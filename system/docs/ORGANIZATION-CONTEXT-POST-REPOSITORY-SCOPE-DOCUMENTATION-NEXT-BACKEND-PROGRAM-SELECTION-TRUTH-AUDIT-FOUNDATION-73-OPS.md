# ORGANIZATION CONTEXT — POST–REPOSITORY-SCOPE-DOCUMENTATION NEXT BACKEND PROGRAM SELECTION TRUTH AUDIT (FOUNDATION-73)

**Mode:** Read-only selection audit. **No** code, schema, routes, or reopening of **FOUNDATION-64** (membership/runtime truth lane), **FOUNDATION-68** (resolver `DomainException` → 403 classification), or **FOUNDATION-72** (repository-scope documentation closure) unless contradicted by current tree.

**Evidence read:** `StaffMultiOrgOrganizationResolutionGate.php`, `OrganizationScopedBranchAssert.php`, `OrganizationRepositoryScope.php`, `OrganizationContext.php`, `OrganizationContextResolver.php`, `BranchContextMiddleware.php`, `OrganizationContextMiddleware.php`, `AuthMiddleware.php` (F-25 call site), `HttpErrorHandler.php` (F-67/F-68 whitelist head), `BranchDirectory.php` (org + assert usage), `Dispatcher.php` (global middleware order), grep `assertBranchOwnedByResolvedOrganization` / `assertBranchBelongsToCurrentOrganization` / `getCurrentOrganizationId` in `system/**/*.php`; **F-64 OPS** §6–§7, **F-68 OPS** §3–§4, **F-72 OPS** verdict/waivers; **BOOKER-PARITY-MASTER-ROADMAP.md** §8 tail; **F-69 OPS** (prior deferral of assert hardening).

---

## Verdict

**A** — Baseline closures are **documented in-tree** and **consistent** with current code; **no** contradiction forces immediate reopen of **F-46–F-72**; **one** next program is selected with a **read-only** first phase.

### Waivers (post-closure posture, not selection blockers)

| ID | Waiver |
|----|--------|
| **W-73-1** | **F-64** residual items (**W-64-1–W-64-6**) remain **explicit follow-ups** (UX, ops, docs, DB hardening) — they do **not** invalidate lane closure or require resolver/membership edits in this selection. |
| **W-73-2** | **F-68** (**W-68-1–W-68-4**) documents **exact-message** coupling for **four** resolver `DomainException` strings only — **other** `DomainException` sources (including **`OrganizationScopedBranchAssert`** / **`OrganizationContext::assertBranchBelongsToCurrentOrganization`**) still follow the **generic** `handleException` tail (**500** class behavior) unless a **future** program explicitly widens classification (**F-68** charter would need reopening). |
| **W-73-3** | **F-72** (**W-72-1**, **W-72-2**) — no git delta proof in workspace for **F-71**; repository-scope **asymmetry** remains **documented**, not **SQL-hardened**. |

---

## 1. Baseline closures are explicit programs with waivers (not active blockers)

| Closure | In-tree proof |
|---------|----------------|
| **F-64** | **`USER-ORGANIZATION-MEMBERSHIP-AND-RUNTIME-TRUTH-LANE-CONSOLIDATED-PROGRAM-CLOSURE-TRUTH-AUDIT-FOUNDATION-64-OPS.md`** — **§7** “CLOSE THIS LANE AS COMPLETE WITH WAIVERS”; **§6** **W-64-1–W-64-6**. Roadmap §8 row records **Verdict A** + ZIP. |
| **F-68** | **`RESOLVER-ORGANIZATION-DOMAINEXCEPTION-HTTP-403-CLASSIFICATION-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-68-OPS.md`** — **F-67** = **`HttpErrorHandler` only**; **four** resolver messages whitelisted → **403**; **W-68-1–W-68-4**. Roadmap §8 row **Verdict A**. |
| **F-72** | **`ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-ENFORCEMENT-ASYMMETRY-DOCUMENTATION-ONLY-POST-IMPLEMENTATION-CONSOLIDATED-CLOSURE-TRUTH-AUDIT-FOUNDATION-72-OPS.md`** — **Verdict B** with **W-72-1** / **W-72-2**; scope doc + consumer PHPDoc cross-check. Roadmap §8 row. |

**None** of these waivers assert an **open correctness defect** inside the **closed charter** of each program; they record **deferrable** or **attestation** gaps.

---

## 2. No code contradiction forcing reopen of F-46–F-72 (scoped sanity check)

- **`OrganizationContextResolver`** — F-57 / F-62 throw sites and messages remain aligned with **F-68** whitelist (see ```46:46```, ```66:69```, ```133:135```, ```142:144``` of `OrganizationContextResolver.php` vs ```79:86``` of `HttpErrorHandler.php`).
- **`StaffMultiOrgOrganizationResolutionGate`** — still **`exit`** after **403** with **`ORGANIZATION_CONTEXT_REQUIRED`** when JSON **`Accept`** (```122:140```); **not** classified via **`HttpErrorHandler`** — matches **F-66/F-68** documented split.
- **`AuthMiddleware`** — post-auth **`enforceForAuthenticatedStaff()`** (```51:51```) unchanged relative to F-25 narrative.
- **`OrganizationRepositoryScope`** — semantics unchanged from **F-70/F-72** documentation; **no** new executable drift asserted here.
- **Pipeline order** — `Dispatcher`: … **`BranchContextMiddleware`** → **`OrganizationContextMiddleware`** → … (```20:25``` `Dispatcher.php`); **`OrganizationContextMiddleware`** calls **`resolveForHttpRequest`** only (```21:27```).

**Conclusion:** Current tree **does not** contradict **F-64**, **F-68**, or **F-72** closure stories.

---

## 3. Remaining organization-adjacent backend candidates (by risk)

| Bucket | Risk if **changed** now | Status after F-72 |
|--------|-------------------------|-------------------|
| **A — F-25** (`StaffMultiOrgOrganizationResolutionGate`) | **High** — post-auth **`exit`**, JSON shape, path exemptions (**F-43/F-44** registry) must stay aligned with registrars | **Stable**; further work needs **narrow** charter + route-registry sync |
| **B — `OrganizationScopedBranchAssert` / `OrganizationContext` assert hardening** | **High** for **HTTP UX** — new or renamed `DomainException` strings are **outside** **F-68** whitelist → **generic** exception path unless **`HttpErrorHandler`** is reopened | **Deferred** in **F-69**; **needs coverage map before implementation** |
| **C — Narrow downstream runtime consumer cluster** | **Low** for **read-only** audit — inventory only | **Recommended** as **next** program (see §4) |
| **D — `OrganizationRepositoryScope` parity-hardening (SQL)** | **High** — tenant visibility / mutability; **F-72 W-72-2** | **Premature** without product/caller review |
| **E — `BranchContextMiddleware` policy** | **High** — affects branch resolution for all authenticated staff | **Premature** without read-only policy audit if ever prioritized |
| **F — `OrganizationContextResolver` / membership** | **Lane closed** **F-64** | **No reopen** from this audit |
| **G — None yet** | N/A | **Rejected** — **F-69** already identified assert cluster gap; **read-only** follow-up is **justified** and **low blast radius** |

---

## 4. Single recommended next backend program (exactly one)

**Name:** **`ORGANIZATION-SCOPED-BRANCH-ASSERT-DOWNSTREAM-CONSUMER-READ-ONLY-TRUTH-AUDIT`**

**Objectives (read-only):**

1. Enumerate **every** runtime call site of **`OrganizationScopedBranchAssert::assertBranchOwnedByResolvedOrganization`** (current grep-backed set includes **`BranchDirectory`**, **`ClientService`**, **`InvoiceService`**, **`PaymentService`**, **`MarketingCampaignService`**, **`PayrollService`**, **`PayrollRuleController`** — plus verifier script reference).
2. Per call site: document **trigger conditions** (e.g. org resolved gate vs unconditional), **branch id source** (request, DB row, parameter), and **mutation vs read** path.
3. Map **`DomainException`** messages from **`OrganizationScopedBranchAssert`** and **`OrganizationContext::assertBranchBelongsToCurrentOrganization`** to **HTTP** handling: **not** in **F-68** whitelist (```79:86``` `HttpErrorHandler.php`) → document **observed** non-debug behavior (**generic** tail).
4. Cross-reference **`BranchDirectory`** org-scoped listing vs **`OrganizationRepositoryScope`** EXISTS pattern (**F-70** partial parity) **only** as context — **no** reopen of **F-72** matrix as primary deliverable.

---

## 5. Why rejected candidates wait

| Candidate | Why wait |
|-----------|----------|
| **F-25 implementation** | Touches **all** authenticated staff HTTP; exemption list must mirror **platform** routes; **`exit`** contract is **intentionally** separate from **`HttpErrorHandler`**. |
| **`OrganizationScopedBranchAssert` implementation hardening** | Without **inventory**, patches risk **inconsistent** deny timing; any new **`DomainException`** story intersects **F-68** unless explicitly scoped. |
| **`OrganizationRepositoryScope` SQL parity** | **F-72** closed **documentation** wave; **W-72-2** — hardening changes **data-plane** visibility. |
| **`BranchContextMiddleware` behavior change** | Broad session/request branch selection impact; needs **dedicated** policy audit before code. |
| **`HttpErrorHandler` expansion** | **Reopens** **F-68** closed program unless named as **F-68** successor charter. |
| **Documentation-only cleanup** (e.g. **F-64 W-64-4** `OrganizationContext` PHPDoc vs **F-62**) | Valuable but **narrow**; does not replace **assert** consumer map as the **highest-leverage** org-adjacent **backend** prerequisite. |
| **No action** | Leaves **F-69**-deferred assert surface **unmapped** after **F-70/F-71/F-72** completed repo-scope work — **unnecessary** deferral. |

---

## 6. First phase type

**Read-only truth audit** — **no** implementation in the first wave.

---

## 7. Minimal boundary the next program must obey

**In scope:** Static reads + grep-backed inventories of **`assertBranchOwnedByResolvedOrganization`** (and indirect **`assertBranchBelongsToCurrentOrganization`** via the assert helper only), optional extension to **call-graph notes** for **`getCurrentOrganizationId()`** guards adjacent to those calls; new **ops + matrix** docs under `system/docs/`.

**Out of scope:** Edits to **`OrganizationContextResolver`**, **`StaffMultiOrgOrganizationResolutionGate`**, **`HttpErrorHandler`**, **`ErrorHandlerMiddleware`**, **`BranchContextMiddleware`**, **`OrganizationContextMiddleware`**, **`OrganizationRepositoryScope`** SQL/methods, **routes**, **schema**, **DI** wiring, **membership** services, **new** assert sites or changed throw messages.

---

## 8. Surfaces that must remain untouched in that next program

- **F-64 lane:** resolver membership-single **`assert*`**, **`enforceBranchDerivedMembershipAlignmentIfApplicable`**, membership read/backfill services, verifiers.
- **F-68 surface:** **`HttpErrorHandler::handleException`** classification list and **`debug`** rethrow order.
- **F-25:** gate body, exemptions, **`denyUnresolvedOrganization`**.
- **F-72 / repo scope:** the **seven** consumer repositories + **`OrganizationRepositoryScope`** executable bodies (audit may **cite**, not **re-audit** F-70 matrix as primary work).

---

## 9. STOP

**FOUNDATION-73** ends here. **FOUNDATION-74** is **not** opened by this document (name reserved for the **read-only assert-consumer audit** implementation when tasked).

**Deliverables:** this OPS file; **`ORGANIZATION-CONTEXT-POST-REPOSITORY-SCOPE-DOCUMENTATION-NEXT-BACKEND-PROGRAM-SURFACE-MATRIX-FOUNDATION-73.md`**; **§8** row in **`BOOKER-PARITY-MASTER-ROADMAP.md`**; checkpoint ZIP per hygiene rules.
