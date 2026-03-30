# Organization resolution gap and unresolved-behavior truth audit (FOUNDATION-21)

**Wave:** MAINTAINABILITY-SAFE-UPGRADE-FOUNDATION-21 — ORGANIZATION-RESOLUTION-GAP-AND-UNRESOLVED-BEHAVIOR-TRUTH-AUDIT  
**Mode:** Read-only, proof-first — **no** implementation, enforcement, UI, schema, refactor, or new features.  
**Upstream:** FOUNDATION-06 through **FOUNDATION-20** accepted; org-scoped **repository** and **choke-point** surfaces as implemented through F-18 + F-11.

**Companion matrix:** `ORGANIZATION-UNRESOLVED-BEHAVIOR-SURFACE-MATRIX-FOUNDATION-21.md`

---

## 1) Canonical prerequisite — FOUNDATION-09 resolution layer

| Component | Role |
|-----------|------|
| `OrganizationContextResolver::resolveForHttpRequest` | Fills `OrganizationContext` each HTTP request (after `BranchContextMiddleware`). |
| `OrganizationContext::getCurrentOrganizationId()` | **`null`** when unresolved; positive int when resolved. |
| `OrganizationRepositoryScope::resolvedOrganizationId()` | Delegates to `getCurrentOrganizationId()`; **`> 0`** only counts as resolved. |

**Normative resolution rules (F-09 ops):**

1. **Branch context non-null:** Load `branches.organization_id` with active org join. Success → org id + `MODE_BRANCH_DERIVED`. Invalid link → **`DomainException`** (request fails — **not** “unresolved”).
2. **Branch context null:** Count active organizations. **0** → org **null**, `MODE_UNRESOLVED_NO_ACTIVE_ORG`. **>1** → org **null**, `MODE_UNRESOLVED_AMBIGUOUS_ORGS`. **Exactly 1** → org id + `MODE_SINGLE_ACTIVE_ORG_FALLBACK`.

**Non-HTTP:** `OrganizationContextMiddleware` does not run; context stays default unless something else sets it → **`resolvedOrganizationId()`** typically **null** for CLI unless a future entrypoint resolves it (F-09 ops).

**Shared SQL helper behavior:** `OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause` (and marketing/payroll wrappers) returns **`['sql' => '', 'params' => []]`** when **`resolvedOrganizationId()`** is **null** — documented as **legacy / unscoped** continuation.

---

## 2) Inventory — accepted org-scoped surfaces (F-10 through F-20)

| Wave | Surface class | Scope of “org-scoped” in this audit |
|------|---------------|-------------------------------------|
| **F-10** | Truth audit (pre-F-11) | Established **baseline**: org context existed but **was not** wired into most mutating paths; defines **organization predicate** vs branch-only (historical input to F-11). |
| **F-11** | `OrganizationScopedBranchAssert`, `BranchDirectory::updateBranch` / `softDeleteBranch`, `InvoiceService`, `PaymentService`, `ClientService`, `MarketingCampaignService::createCampaign`, `PayrollService::createRun`, `PayrollRuleController::store` | **Choke-point / service** use of assert: **no-op** when **`getCurrentOrganizationId()`** is **null** (see §3). `BranchDirectory::createBranch` **does not** use assert for org pin; uses resolved org or **`defaultOrganizationIdForNewBranch()`** (MIN active org). |
| **F-13** | `MarketingCampaignRepository`, `MarketingCampaignRunRepository`, `MarketingCampaignRecipientRepository` | **Repository** SQL branches on **`resolvedOrganizationId()`**; explicit **legacy** queries when **null** in several methods (matrix). |
| **F-14** | `PayrollRunRepository`, `PayrollCompensationRuleRepository`, `PayrollCommissionLineRepository` | Same pattern: org fragment + **explicit legacy** branches when **null** where coded (matrix). |
| **F-16 / F-18** | `ClientRepository::find`, `findForUpdate`, `list`, `count` | Org **EXISTS** fragment when resolved; **empty fragment** when unresolved → **legacy ID/list/count** semantics (alias/`c.` qualifiers aside). |
| **F-19 / F-20** | `ClientListProvider` consumers | **No new SQL** — inherits **`ClientRepository::list`**; F-20 **documents** unresolved org = **not** claimed as isolated for those dropdowns. |

Detailed per-method classification: **matrix doc**.

---

## 3) Separation of mechanisms

### 3.1 Repository-level (`OrganizationRepositoryScope`)

- **Depends on:** `OrganizationContext` only (no request params).
- **Resolved:** Org **EXISTS** / join fragments applied per F-13/F-14/F-16/F-18 designs.
- **Unresolved:** Empty fragment → queries behave as **pre–org-scoping** for the fragment’s role (often **global** within whatever other filters exist).

### 3.2 Choke-point / service (`OrganizationScopedBranchAssert`)

- **Depends on:** `OrganizationContext::getCurrentOrganizationId()` and DB branch row.
- **Resolved + positive `branchId`:** Asserts branch exists and `organization_id` matches context.
- **Unresolved:** **Early return — no-op** → **no** org enforcement from this helper on that call (```31:32:system/core/Organization/OrganizationScopedBranchAssert.php```).
- **Null/non-positive `branchId`:** Also **no-op** (not an org isolation guarantee).

### 3.3 Controller / request prerequisites

- **Authenticated staff** does **not** imply resolved org: **HQ / null branch** in **multi-org** DB → **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`** → **`getCurrentOrganizationId()` null**.
- **Single active org** + null branch → **`MODE_SINGLE_ACTIVE_ORG_FALLBACK`** → org **resolved** without branch.
- **Branch selected** + valid org link → **`MODE_BRANCH_DERIVED`** → org **resolved**; invalid link → **exception** (not unresolved).

---

## 4) Required questions — explicit answers

**A) On which accepted org-scoped surfaces does unresolved organization still produce legacy / unscoped behavior?**

- **All** surfaces that use **`OrganizationRepositoryScope`** helpers with **empty fragment** when null: **ClientRepository** (`find`/`findForUpdate`/`list`/`count`), **MarketingCampaignRepository** (`find`/`list`/`count`/`update` with empty clause), **Payroll** repos where clause is empty on **`find`/`update`/`delete`** etc.
- **Marketing run/recipient** repos: **additional** explicit **`if (resolvedOrganizationId() === null)`** branches that run **global** SQL (ID-only or run-scoped without org join).
- **PayrollRunRepository::listRecent** with **`branchId === null`**: **fully unscoped** `SELECT * FROM payroll_runs …` when org unresolved.
- **PayrollCommissionLineRepository::deleteByRunId` / `listByRunId`**: **legacy** SQL when org unresolved.
- **F-11 assert-backed mutating paths:** **Legacy** in the sense that **org assert does not run** when context null — reliance falls back to **branch asserts elsewhere** / **permissions** / **legacy global** behavior depending on path.
- **F-19/F-20 client dropdowns:** Same as **`ClientRepository::list`** when unresolved.

**B) On which accepted org-scoped surfaces is unresolved behavior already fail-closed?**

- **Not fail-closed (org isolation):** Most F-11/F-13/F-14/F-16/F-18 paths above — **by design** they preserve legacy when org null.
- **Fail-closed in a different dimension:** If **branch context is set** but branch has **no** active organization, **resolver throws** — request fails (**not** “unresolved org id”).
- **Payroll rules (F-14):** When org **resolved**, **NULL** `branch_id` on rules is excluded via EXISTS clause (**fail-closed** for those rows). When org **unresolved**, that exclusion **does not** apply — **not** org-fail-closed.

**C) What exact authenticated / request / context conditions can still leave organization unresolved?**

1. **`BranchContext::getCurrentBranchId()`** is **null** (HQ operator, or session without current branch).
2. **Database** has **two or more** active organizations (`deleted_at IS NULL`) → **`MODE_UNRESOLVED_AMBIGUOUS_ORGS`**.
3. **Zero** active organizations → **`MODE_UNRESOLVED_NO_ACTIVE_ORG`** (degenerate).
4. **CLI / non-HTTP** entrypoints without custom resolution → context not set by middleware.
5. **Single** active org + null branch → org **is** resolved (**fallback**) — **not** unresolved.

**D) Is there a single smallest safe future hardening wave?**

- **No** single wave is **provably minimal and safe** for **all** listed surfaces **without** product decisions: repositories intentionally dual-path; **HQ multi-org** semantics are **ambiguous**; forcing fail-closed everywhere would **break** documented legacy behavior and **F-20** QA baselines.
- **Smallest conceptual choke (if one name is required):** **Staff HTTP organization resolution policy** (middleware/session/product: when authenticated staff + multi-org, **require** explicit org or branch before hitting org-scoped domains) — this is **context hardening**, not a repository-only patch. **Safety** depends on **product**, not code-only proof.

**E) If not, next step?**

- **Containment:** Keep **dual-path** documented (this audit + matrix); execute **domain smoke** under resolved vs unresolved fixtures where risk matters.
- **Context hardening:** Product + middleware/session design for **when** org may remain null for staff.
- **Per-surface / program work:** e.g. payroll **listRecent(null)** vs marketing **global** run paths — **separate** acceptance per domain.

---

## 5) Safest next-boundary recommendation (audit conclusion)

- **Do not** claim a **repository-only** “smallest” hardening wave that applies uniformly — **not** evidence-backed as safe.
- **Prefer** a **governance / product** decision on **staff unresolved-org** modes before implementation waves.
- Optional **future** technical waves (out of scope here): **context hardening**, **per-repository fail-closed** behind flags, or **per-controller** branch/org prerequisites — each needs **separate** acceptance.

---

## 6) Items intentionally not advanced

- No code, verifiers (optional skipped), no F-22 / implementation opening.

---

## 7) Checkpoint readiness

FOUNDATION-21 **read-only** audit artifacts are complete: this ops doc + **matrix**. **Next:** human/ZIP acceptance; **no** automatic implementation wave.
