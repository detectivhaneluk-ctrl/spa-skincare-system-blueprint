# ORGANIZATION `RepositoryScope` — DOCUMENTATION-ONLY WAVE POST-IMPLEMENTATION CONSOLIDATED CLOSURE TRUTH AUDIT (FOUNDATION-72)

**Mode:** Read-only closure audit. **No** code, SQL, schema, routes, resolver, membership lane, F-70 matrix re-audit, or F-68 error-surface program reopened unless contradicted by current tree (none found).

**Parent waves:** **FOUNDATION-71** (documentation-only PHPDoc/`@see` + ops note); **FOUNDATION-70** (read-only consumer parity truth; companion docs remain canonical for per-method matrices).

---

## Verdict

**B** — Documentation and code are **cross-consistent** and asymmetries are **explicit** in-tree; **one** concrete waiver applies to **historical delta** proof.

### Waivers

| ID | Waiver |
|----|--------|
| **W-72-1** | This workspace is **not** a git repository. **FOUNDATION-71**’s claim of “docblocks/comments only” cannot be **re-proven** here via `git diff`. Closure accepts **(a)** `ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-ENFORCEMENT-ASYMMETRY-DOCUMENTATION-ONLY-FOUNDATION-71.md` as attestation, **(b)** static review of the eight PHP files in audit scope showing executable bodies aligned with the new class/method documentation, and **(c)** absence of contradictory evidence in the current tree. |
| **W-72-2** | **Residual product posture (explicit):** Scoped vs unscoped **asymmetry remains intentional** at the repository layer; F-71/F-72 **document** rather than **harden**. Any future SQL/predicate parity is a **separate** implementation program (per F-70/F-71 narrative), not implied closed by this audit. |

---

## 1. FOUNDATION-71 scope vs current tree (static)

**F-71 listed files** match the audit set. **`ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-ENFORCEMENT-ASYMMETRY-DOCUMENTATION-ONLY-FOUNDATION-71.md`** states PHPDoc/comment-only edits and no SQL/control-flow/DI changes; **W-72-1** governs historical proof.

---

## 2. `OrganizationRepositoryScope` — documentation vs code truth

| Claim in class docblock | Code proof |
|-------------------------|------------|
| **`resolvedOrganizationId()`** returns **positive int** or **`null`** when context unset or not positive | `resolvedOrganizationId()` returns `$id` only when `$id !== null && $id > 0`; else `null`. |
| **Empty fragment** when org unresolved: `sql === ''`, `params === []`; **legacy-global** if caller concatenates unchanged | `branchColumnOwnedByResolvedOrganizationExistsClause` returns `['sql' => '', 'params' => []]` when `$orgId === null`. |
| **Caller responsibility** for paths that never invoke the helper | Documented as policy; helpers do not auto-guard callers that omit fragments. |
| **Active EXISTS:** resolved org requires **non-null** `branch_id`; **NULL** `branch_id` rows **do not match** while fragment applies | SQL includes `AND {$a}.{$c} IS NOT NULL AND EXISTS (...)` when `$orgId !== null`. |

**`@see` targets** (lines 22–23 of `OrganizationRepositoryScope.php`):

- `system/docs/ORGANIZATION-REPOSITORY-SCOPE-AND-DATA-PLANE-CONSUMER-PARITY-READ-ONLY-TRUTH-AUDIT-FOUNDATION-70-OPS.md` — **present**
- `system/docs/ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-MATRIX-FOUNDATION-70.md` — **present**

---

## 3. Seven consumers — class docs vs `orgScope` usage (grep-backed)

Cross-check: every consumer injects `OrganizationRepositoryScope` and documents **scoped** vs **unscoped** public surfaces without asserting universal org safety.

| Repository | Doc says | Executable check |
|------------|----------|------------------|
| **`ClientRepository`** | Scope on **find**, **findForUpdate**, **list**, **count**; other public methods unscoped; **update** note = id-only mutators | `branchColumnOwnedByResolvedOrganizationExistsClause` only in those four methods; **update** docblock matches id-only `UPDATE`. |
| **`MarketingCampaignRepository`** | **find**, **list**, **count**, **update** append scope; **insert** unscoped | `marketingCampaignBranchOrgExistsClause` on find/list/count/update; **insert** = plain `insert`. |
| **`MarketingCampaignRunRepository`** | Unscoped when `resolvedOrganizationId() === null` for id/campaign paths; scoped join + fragment when resolved; **insert** unscoped | Branches match `resolvedOrganizationId() === null` in find/findForUpdate/listByCampaign/update; **insert** unscoped. |
| **`MarketingCampaignRecipientRepository`** | **insertBatch** never scoped; others branch null vs resolved | **insertBatch** has no scope; other methods match branching pattern. |
| **`PayrollRunRepository`** | **find**, **listForBranch**, **listRecent** (when org resolved and no branch), **update**, **delete** use fragment; **create** unscoped; **delete** id-only if empty fragment | Matches implementation (`listRecent` global `SELECT *` when branch null and org unresolved). |
| **`PayrollCommissionLineRepository`** | **deleteByRunId** / **listByRunId** alternate; **allocatedSourceRefsExcludingRun** always appends run fragment (empty ⇒ no org EXISTS); **insert** unscoped | Matches; join + fragment concatenation visible in `allocatedSourceRefsExcludingRun`. |
| **`PayrollCompensationRuleRepository`** | **find**, **listActive**, **listAllForBranchFilter**, **update** + branch-filter SQL split; **create** unscoped | Matches `resolvedOrganizationId()`-gated branch predicates plus fragments. |

Each file’s **`@see`** pair matches the same two **F-70** paths as `OrganizationRepositoryScope`.

---

## 4. Placeholder / aspirational language

Spot check on scope files: **no** `TODO` / `FIXME` / `TBD` / obvious placeholder tokens in the audited PHP docblocks (grep on representative files). Wording describes **current** behavior (e.g. “legacy-global”, “caller responsibility”, “unscoped at repository level”).

---

## 5. Runtime / data-plane drift in this wave

No executable edits were performed in **FOUNDATION-72**. Under **W-72-1**, **FOUNDATION-71** runtime drift is attested by F-71 ops + external process; **F-72** read-only review finds **no** internal contradiction between docs and code in the listed repositories.

---

## 6. STOP

**FOUNDATION-72** closes here. **FOUNDATION-73** is **not** opened by this document.

**Deliverables:** this OPS file; **`ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-ENFORCEMENT-ASYMMETRY-SURFACE-MATRIX-FOUNDATION-72.md`**; **§8** row in **`BOOKER-PARITY-MASTER-ROADMAP.md`**; checkpoint ZIP per roadmap hygiene.
