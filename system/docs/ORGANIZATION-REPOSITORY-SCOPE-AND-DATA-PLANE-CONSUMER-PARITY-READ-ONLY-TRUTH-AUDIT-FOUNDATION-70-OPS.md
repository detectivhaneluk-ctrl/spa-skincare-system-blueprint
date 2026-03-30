# ORGANIZATION `RepositoryScope` — DATA-PLANE CONSUMER PARITY READ-ONLY TRUTH AUDIT (FOUNDATION-70)

**Mode:** Read-only. **No** code, schema, routes, resolver, middleware, **`HttpErrorHandler`**, membership lane, or F-25 edits.

**Evidence read:** `OrganizationRepositoryScope.php`, `OrganizationContext.php`, `BranchDirectory.php`, all seven scoped repositories (full), `register_clients.php` / `register_marketing.php` / `register_payroll.php`, `system/bootstrap.php` (**`OrganizationRepositoryScope`** singleton), `MarketingCampaignService.php` (**`createCampaign`** org gate), grep `OrganizationRepositoryScope` in `system/**/*.php`, **FOUNDATION-64** / **FOUNDATION-68** closure docs (baseline only — **no** contradiction found).

---

## 1. Exact public semantics of `OrganizationRepositoryScope` (code-backed)

### 1.1 `resolvedOrganizationId(): ?int`

- Reads **`OrganizationContext::getCurrentOrganizationId()`** (```17:21:system/core/Organization/OrganizationRepositoryScope.php```).
- Returns **positive int** or **null** if id is null or ≤0.

### 1.2 `branchColumnOwnedByResolvedOrganizationExistsClause(string $tableAlias, string $branchColumn = 'branch_id'): array`

- Returns **`['sql' => '', 'params' => []]`** when **`resolvedOrganizationId()`** is **null** (```32:35:system/core/Organization/OrganizationRepositoryScope.php```).
- When org resolved: appends SQL meaning (```39:43:system/core/Organization/OrganizationRepositoryScope.php```):
  - **`{$alias}.{$branchColumn} IS NOT NULL`**
  - **`EXISTS (SELECT 1 FROM branches b INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL WHERE b.id = {$alias}.{$branchColumn} AND b.deleted_at IS NULL AND o.id = ?)`**
- Params: **`[$orgId]`**.

**Intent (class + inline SQL):** Row’s branch FK must point to an **active** branch row whose **active** organization matches the resolved org.

### 1.3 `marketingCampaignBranchOrgExistsClause(string $campaignTableAlias): array`

- Delegates to **`branchColumnOwnedByResolvedOrganizationExistsClause($campaignTableAlias, 'branch_id')`** (```53:55:system/core/Organization/OrganizationRepositoryScope.php```).

### 1.4 `payrollRunBranchOrgExistsClause(string $runTableAlias): array`

- Delegates to **`branchColumnOwnedByResolvedOrganizationExistsClause($runTableAlias, 'branch_id')`** (```63:65:system/core/Organization/OrganizationRepositoryScope.php```).

### 1.5 `payrollCompensationRuleBranchOrgExistsClause(string $ruleTableAlias): array`

- Delegates to **`branchColumnOwnedByResolvedOrganizationExistsClause($ruleTableAlias, 'branch_id')`** (```73:75:system/core/Organization/OrganizationRepositoryScope.php```).
- Class docblock: when org resolved, **nullable** `payroll_compensation_rules.branch_id` / “global” rules are **excluded** because the fragment requires **non-null** branch satisfying EXISTS (```68:75:system/core/Organization/OrganizationRepositoryScope.php```).

---

## 2. Null-org behavior (helpers and legacy-global posture)

| Helper / entry | When org **null** | Code |
|----------------|-------------------|------|
| All EXISTS-based clauses | **`sql` empty, `params` []** | ```32:35:system/core/Organization/OrganizationRepositoryScope.php``` |
| Class contract | Callers **keep legacy unscoped SQL** (no automatic org filter) | ```7:9:system/core/Organization/OrganizationRepositoryScope.php``` |

**Corollary:** Empty fragment **does not** narrow queries; **callers** must not assume org isolation unless they **also** branch explicitly (several repositories use **`if (resolvedOrganizationId() === null)`** alternate queries).

---

## 3. Complete in-tree repository consumers (runtime DI)

**`OrganizationRepositoryScope`** is a **core** singleton over **`OrganizationContext`** (```37:38:system/bootstrap.php```).

**Repositories that inject it (only these seven in `system/**/*.php`):**

| Repository | Wired in |
|------------|----------|
| `Modules\Clients\Repositories\ClientRepository` | `register_clients.php` line 5 |
| `Modules\Marketing\Repositories\MarketingCampaignRepository` | `register_marketing.php` line 5 |
| `Modules\Marketing\Repositories\MarketingCampaignRunRepository` | `register_marketing.php` line 6 |
| `Modules\Marketing\Repositories\MarketingCampaignRecipientRepository` | `register_marketing.php` line 7 |
| `Modules\Payroll\Repositories\PayrollCompensationRuleRepository` | `register_payroll.php` line 5 |
| `Modules\Payroll\Repositories\PayrollRunRepository` | `register_payroll.php` line 6 |
| `Modules\Payroll\Repositories\PayrollCommissionLineRepository` | `register_payroll.php` line 7 |

**Non-repository references:** verifier scripts under `system/scripts/verify_*_readonly.php` (not runtime consumers).

---

## 4–5. Per-method scope application and consistency with helper contract

**Helper contract:** fragments are **optional SQL**; **empty** when org unresolved; **non-empty** when resolved = branch-in-org EXISTS.

**Consistency rule used in this audit:** A method **“matches helper intent”** if, whenever it applies the fragment with a **non-empty** `sql`, behavior aligns with §1. A method **“omits scope”** if it never calls the helper — that is **not** a helper bug; it may create **asymmetry** vs other methods on the same class (see §5.1).

**Detailed per-method tables:** companion **`ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-MATRIX-FOUNDATION-70.md`**.

### 5.1 Asymmetry finding (not a helper API violation)

**`ClientRepository`:** **`find`**, **`findForUpdate`**, **`list`**, **`count`** append **`branchColumnOwnedByResolvedOrganizationExistsClause('c')`** (always call helper; fragment may be empty). **Many other methods** (`update`, `softDelete`, `restore`, `create`, duplicate search, notes, linkage, **`lockActiveByEmailBranch`**, etc.) **never** use **`OrganizationRepositoryScope`**.

- **Meaning:** Scoped reads can **hide** cross-org rows when org resolved, but **ID-keyed writes** and **branch-keyed locks** do **not** themselves re-check org at the repository layer.
- **Helper contract:** Still satisfied — the helper **does not** state that every repository method must use it.
- **Tenant-safety posture:** Relies on **callers** (e.g. load via **`find`** first, correct **`branch_id`** for public locks). This is **material** for future hardening decisions, **not** proof that **`OrganizationRepositoryScope`** is misimplemented.

**`MarketingCampaignRepository::insert`:** **No** scope clause on INSERT — **`MarketingCampaignService::createCampaign`** enforces branch + **`OrganizationScopedBranchAssert`** when org resolved (```71:77:system/modules/marketing/services/MarketingCampaignService.php```). **Pattern:** service-layer gate + unscoped insert.

---

## 6. `BranchDirectory` inline org filtering vs `OrganizationRepositoryScope`

| Aspect | `BranchDirectory` | `OrganizationRepositoryScope` consumers |
|--------|-------------------|----------------------------------------|
| **Signal** | **`OrganizationContext::getCurrentOrganizationId()`** directly | Via **`resolvedOrganizationId()`** (same underlying context) |
| **When org null** | **Legacy global** listings / lookups / updates (explicit branches in code) | **Empty EXISTS fragment** and/or **explicit** unscoped query branches |
| **Mechanism** | Direct **`branches.organization_id = ?`** (or global `SELECT`) on **branch** rows | **EXISTS** from **fact** table’s **`branch_id`** to **`branches` + `organizations`**, enforcing **non-deleted** branch and org |
| **Parity judgment** | **Partial parity** | **Equivalent org-boundary intent** when both org-resolve: “only data tied to branches owned by this org.” **Meaningful divergence:** `BranchDirectory` never uses the EXISTS pattern; **`isActiveBranchId`** is **global** active check (```31:41:system/core/Branch/BranchDirectory.php```). EXISTS additionally **drops** rows with **NULL** `branch_id` when org resolved — important for **clients** / **campaigns** / **payroll** rules tied to nullable branch columns. |

---

## 7–8. Justified next program (exactly one)

| Option | Verdict |
|--------|---------|
| **No implementation** | **Rejected as sole outcome** — asymmetry (**§5.1**) and multi-path repos deserve **discoverable** maintainer guidance **in-repo**. |
| **Narrow parity-hardening implementation** | **Deferred** — would change **security/data** behavior (e.g. org predicates on **`ClientRepository::update`**) and needs **product + caller audit**; **not** justified **immediately** from this read-only pass alone. |
| **Narrow documentation-only cleanup** | **Selected** — add **class-level / method-level PHPDoc** (and **`@see`** to **`ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-MATRIX-FOUNDATION-70.md`**) on the **seven** repositories and optionally **`OrganizationRepositoryScope`**, **without** SQL or behavior changes. |

**Recommended next program (name when chartered; do not open FOUNDATION-71 here):** **`ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-ENFORCEMENT-ASYMMETRY-DOCUMENTATION-ONLY`**.

---

## 9. Waivers / risks (FOUNDATION-70)

| Id | Waiver / risk |
|----|----------------|
| **W-70-1** | **Static audit only** — no runtime request traces; CLI or future workers using the same repos were **not** executed. |
| **W-70-2** | **`ClientRepository`** **ID-keyed** mutators and **public** lock methods **omit** **`OrganizationRepositoryScope`** — **caller responsibility** for tenant correctness. |
| **W-70-3** | **`MarketingCampaignRecipientRepository::insertBatch`** has **no** org clause — assumes validated parent run/campaign (stated in **`findForUpdate`** docblock near ```28:31```). |
| **W-70-4** | **`PayrollRunRepository::listRecent`** with **`branchId === null`** and **null org** uses **global** `payroll_runs` listing (```63:66:system/modules/payroll/repositories/PayrollRunRepository.php```) — intentional “global operator” legacy per comment. |
| **W-70-5** | **F-64 / F-68** — this audit **does not** reopen membership lane or **`HttpErrorHandler`** classification; **no** code contradiction found vs those closures. |

---

## 10. Strict verdict

| Grade | Meaning |
|-------|---------|
| **A** | Inventory **complete**; semantics **code-backed**; one **clear** next program; waivers **explicit**. |
| **B** | **Material** evidence gap in audit scope. |
| **C** | Audit **unsupported**. |

**FOUNDATION-70 verdict: A**

---

## 11. STOP

**FOUNDATION-70** ends here — **FOUNDATION-71** is **not** opened.

**Companion:** `ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-MATRIX-FOUNDATION-70.md`.
