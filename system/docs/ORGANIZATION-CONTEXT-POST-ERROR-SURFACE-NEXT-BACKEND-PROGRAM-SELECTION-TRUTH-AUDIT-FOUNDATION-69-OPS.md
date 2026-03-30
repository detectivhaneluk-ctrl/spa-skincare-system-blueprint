# ORGANIZATION CONTEXT — POST–ERROR-SURFACE NEXT BACKEND PROGRAM SELECTION TRUTH AUDIT (FOUNDATION-69)

**Mode:** Read-only selection audit after **FOUNDATION-68**. **No** code, schema, routes, resolver, middleware, **`HttpErrorHandler`**, or gate changes.

**Evidence read:** `StaffMultiOrgOrganizationResolutionGate.php`, `OrganizationRepositoryScope.php`, `OrganizationScopedBranchAssert.php`, `OrganizationContext.php`, `OrganizationContextResolver.php`, `BranchContextMiddleware.php`, `OrganizationContextMiddleware.php`, `BranchDirectory.php`, `HttpErrorHandler.php` (classification head), `AuthMiddleware.php` (F-25 call site), `Dispatcher.php` (middleware order pointer), grep `OrganizationRepositoryScope` / `resolvedOrganizationId` / `branchColumnOwnedByResolvedOrganizationExistsClause` / `getCurrentOrganizationId` in `system/modules/**/*.php`, **FOUNDATION-64** / **FOUNDATION-68** closure OPS + roadmap §8 tail.

---

## 1. FOUNDATION-64 and FOUNDATION-68 are closed baselines with explicit waivers (not active blockers)

| Program | Closure statement (doc) | Waivers named in doc |
|---------|---------------------------|----------------------|
| **F-64** | **§7:** “**CLOSE THIS LANE AS COMPLETE WITH WAIVERS**” — in-lane F-46→F-63 slices closed; residual **deferrable** | **W-64-1**–**W-64-6** (resolver `DomainException` HTTP UX not owned by lane; F-62 fail-open cases; doc lag; read load; optional DB enforcement) |
| **F-68** | **§12:** “**FOUNDATION-68 verdict: A**” — F-67 surface = **`HttpErrorHandler`** only; resolver/F-25 unchanged | **W-68-1**–**W-68-4** (message coupling; stray same-literal risk; duplicate status; F-25 vs F-67 JSON code split) |

**Code baseline (no contradiction with those closures):**

- **F-64** lane inventory still matches tree: **`OrganizationContextResolver`** is the **only** runtime HTTP resolver; membership **`assert*`** remains on branch-null single path only (```64:71:system/core/Organization/OrganizationContextResolver.php```) + F-62 alignment helper (```118:146:system/core/Organization/OrganizationContextResolver.php```) — same shape summarized in **`USER-ORGANIZATION-MEMBERSHIP-AND-RUNTIME-TRUTH-LANE-CONSOLIDATED-PROGRAM-CLOSURE-TRUTH-AUDIT-FOUNDATION-64-OPS.md`** §1.
- **F-68** whitelist still matches **four** resolver throw sites (```46:46```, ```66:69```, ```133:135```, ```142:144``` of **`OrganizationContextResolver.php`**) and **`HttpErrorHandler::isResolverOrganizationResolutionDomainException`** (```79:87:system/core/errors/HttpErrorHandler.php```).
- **F-25** remains **`exit`** after **403** with JSON **`ORGANIZATION_CONTEXT_REQUIRED`** when **`Accept`** contains `application/json` (```122:140:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php```) — as **F-68** §8 documents.

---

## 2. No code truth forces reopening FOUNDATION-46–68 immediately

- **Resolver:** Only the **four** classified **`DomainException`** messages exist on the resolver org-resolution path; **no** fifth throw site appears in **`OrganizationContextResolver.php`** on read.
- **F-25 vs F-67:** Intentional **JSON `error.code`** split (**`ORGANIZATION_CONTEXT_REQUIRED`** vs **`FORBIDDEN`**) is **documented** as **W-68-4**, not an unowned bug.
- **F-64** deferred items (**W-64-1** UX, picker, DB constraints, F-25/scope policy) are **explicitly outside** the closed lane unless a **named** reopen — this audit **does not** assert new resolver/membership defects.

---

## 3. Remaining organization-adjacent backend candidates (grouped by risk)

| Bucket | Risk if touched now | Tree fact |
|--------|---------------------|-----------|
| **A — F-25 gate semantics / enforcement** | **High** — post-auth **HTTP** contract, **`exit`**, path regex drift vs platform routes, JSON shape vs **`HttpErrorHandler`** family | **`AuthMiddleware`** calls **`enforceForAuthenticatedStaff()`** after auth (```51:51:system/core/middleware/AuthMiddleware.php```); exemptions + **`denyUnresolvedOrganization`** in ```30:146:system/core/Organization/StaffMultiOrgOrganizationResolutionGate.php``` |
| **B — `OrganizationRepositoryScope` semantics** | **Medium** (implementation); **Low** (read-only audit) — every fragment change fans out to **clients / marketing / payroll** SQL | Class **documents** null org → empty fragment / legacy unscoped callers (```7:22:system/core/Organization/OrganizationRepositoryScope.php```); shared core = **`branchColumnOwnedByResolvedOrganizationExistsClause`** (```30:46:system/core/Organization/OrganizationRepositoryScope.php```) |
| **C — `OrganizationScopedBranchAssert` hardening** | **High** for error-surface — throws **`DomainException`** with messages **not** in **F-67** whitelist → still **generic** **`handleException`** tail (**500** class behavior) unless **F-68** scope is **explicitly** reopened | ```26:48:system/core/Organization/OrganizationScopedBranchAssert.php``` + **`OrganizationContext::assertBranchBelongsToCurrentOrganization`** (```68:78:system/core/Organization/OrganizationContext.php```) |
| **D — Narrow downstream runtime consumer cluster** | **Medium** — many repos/services; **read-only** mapping is safe | **Direct `OrganizationRepositoryScope` injectors (grep):** `ClientRepository`, `MarketingCampaignRepository`, `MarketingCampaignRunRepository`, `MarketingCampaignRecipientRepository`, `PayrollRunRepository`, `PayrollCommissionLineRepository`, `PayrollCompensationRuleRepository`. **Parallel pattern:** `BranchDirectory` uses **`OrganizationContext::getCurrentOrganizationId()`** inline SQL, **not** **`OrganizationRepositoryScope`** (```52:113:system/core/Branch/BranchDirectory.php```). **Services** also read **`OrganizationContext`** for gates: `MarketingCampaignService`, `PayrollService`, `PayrollRuleController` (grep `getCurrentOrganizationId` under `system/modules`). |
| **E — None yet** | N/A | **Rejected** — **F-64** §8 already named **`OrganizationRepositoryScope`** as future cross-cutting work; a **read-only** first step is **available** and **does not** overlap closed lanes. |

---

## 4. Exactly one recommended next backend program

**Name:** **`ORGANIZATION-REPOSITORY-SCOPE-AND-DATA-PLANE-CONSUMER-PARITY-READ-ONLY-TRUTH-AUDIT`** (implementation wave **not** opened as **FOUNDATION-70** here).

**Goal:** Produce a **code-backed** inventory and parity notes for (1) **`OrganizationRepositoryScope`** fragment semantics, (2) **all** repositories that inject it (per-method: when fragments apply, when `resolvedOrganizationId() === null` preserves legacy global SQL), and (3) **documented** relationship to **`BranchDirectory`**’s inline org predicates and to **service-layer** `OrganizationContext` checks — **without** changing runtime behavior.

---

## 5. Why rejected candidates must wait

| Candidate | Wait reason |
|-----------|-------------|
| **F-25 changes** | Re-enters **post-auth HTTP** product contract; **F-68** **W-68-4** encodes intentional **403 JSON** divergence vs resolver path; any “unification” is a **new** program, not a side effect of org-context selection. |
| **`OrganizationScopedBranchAssert` / `OrganizationContext` assert hardening** | New or renamed **`DomainException`** strings **either** stay off **F-67** whitelist (**behavior/UX ambiguity**) **or** force **`HttpErrorHandler`** edits — that **reopens** the **F-68** closed charter unless explicitly rescoped. |
| **`OrganizationContextResolver` / membership / F-62 / F-57** | **F-64** closed the lane **with waivers**; **no** new code contradiction found in this audit; reopen only via **named** lane/program. |
| **`BranchContextMiddleware` policy** | **F-64** §3 / §8: branch selection **untouched** by membership lane; changes are **high coupling** (session + request + inactive-branch rules). |
| **Narrow implementation** of new scope rules **before** the read-only parity audit | **Premature** — risks wrong **fail-open/fail-closed** SQL under multi-org + unresolved context without a **single** consolidated consumer matrix. |
| **`HttpErrorHandler` expansion** | **F-68** closure scope; **forbidden** as casual follow-up to this selection audit. |

---

## 6. How the next program should begin

**Read-only truth audit first** (inventory + per-consumer matrix + explicit “legacy unscoped when null” call-outs). **No** narrow implementation until that audit exists as a **named** deliverable. **No action yet** on **F-25**, **resolver**, **`HttpErrorHandler`**, **middleware**, or **assert** messages.

---

## 7. Minimal boundary the next program must obey

**In scope (read-only phase):**

- **`OrganizationRepositoryScope.php`** — document each public method’s SQL meaning and **null-org** behavior.
- **Repositories** that type-hint **`OrganizationRepositoryScope`** — per **public** method: which queries append which clause; branch table aliases; any **early return** when `resolvedOrganizationId()` is null.
- **Pointers** to existing verifiers **`verify_*_foundation_13/14/16/18_readonly.php`** as **partial** historical evidence (not a substitute for a **unified** matrix).
- **Optional same-phase appendix:** `BranchDirectory` vs **`OrganizationRepositoryScope`** — **equivalence intent** (org-resolved → limit to `organization_id = ?`), **not** a mandate to refactor.

**Out of scope (until a separately named program):**

- Any **edit** to **`OrganizationContextResolver`**, **`HttpErrorHandler`**, **`StaffMultiOrgOrganizationResolutionGate`**, **`BranchContextMiddleware`**, **`OrganizationContextMiddleware`**, membership services, **routes**, **schema**, or **controllers** except where the audit **cites** them as context.

---

## 8. Surfaces that must remain untouched in the next program

| Surface | Reason |
|---------|--------|
| **`HttpErrorHandler`** | **F-68** closed **F-67** implementation; **W-68-1** message coupling. |
| **`OrganizationContextResolver`** | **F-64** closed membership/runtime lane; resolver rules **out of scope** for scope-consumer audit. |
| **`StaffMultiOrgOrganizationResolutionGate`** | **F-25** HTTP **`exit`** contract; **F-68** cross-reference. |
| **`BranchContextMiddleware`**, **`OrganizationContextMiddleware`** | Pipeline order stable per **F-64** §2; not part of **repository SQL** audit. |
| **Membership write/read services** | **F-64** lane boundary. |
| **Routes / migrations / `.env`** | User charter + **F-69** mode. |

---

## 9. Waivers / risks for this selection audit (FOUNDATION-69)

| Id | Waiver / risk |
|----|----------------|
| **W-69-1** | Consumer inventory is **grep + scoped reads**; **dynamic** includes or **runtime-only** registration paths are **not** executed in this audit. |
| **W-69-2** | **`BranchDirectory`** SQL is **semantically parallel** to org-scoped listing, **not** byte-identical to **`OrganizationRepositoryScope`** fragments — equivalence claims in a follow-on doc must stay **careful**. |
| **W-69-3** | **`OrganizationScopedBranchAssert`** **`DomainException`**s remain **outside** **F-67** classification — **documented** tension with **F-68** closure; **no** recommendation here to widen **`HttpErrorHandler`**. |
| **W-69-4** | Legacy **global** SQL when **`resolvedOrganizationId()`** is **null** is **intentional** per **`OrganizationRepositoryScope`** docblock — a future **tightening** program would be **product/security** scoped, not implied by this audit. |

---

## 10. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | **F-64** / **F-68** accepted as baseline; **one** next program chosen; rejections **justified**; boundaries **explicit**. |
| **B** | Baseline accepted with **material** evidence gap. |
| **C** | Selection **unsupported**. |

**FOUNDATION-69 verdict: A**

**Rationale:** §1–§2 are **code-aligned** with closure docs; §4 isolates the **lowest coupling** next step (**data-plane scope consumers**) that **does not** reopen **F-64** or **F-68**; §5–§9 state **deferrals** and **waivers** explicitly.

---

## 11. STOP

**FOUNDATION-69** ends here — **FOUNDATION-70** is **not** opened.

**Companion:** `ORGANIZATION-CONTEXT-POST-ERROR-SURFACE-NEXT-BACKEND-PROGRAM-SURFACE-MATRIX-FOUNDATION-69.md`.
