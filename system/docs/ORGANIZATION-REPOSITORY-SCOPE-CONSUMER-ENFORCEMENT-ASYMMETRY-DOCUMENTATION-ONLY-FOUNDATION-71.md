# ORGANIZATION `RepositoryScope` — CONSUMER ENFORCEMENT ASYMMETRY DOCUMENTATION ONLY (FOUNDATION-71)

**Mode:** PHPDoc and maintainer comments **only**. **No** SQL, control flow, signatures, DI, resolver, middleware, auth, routes, schema, or UI changes.

**Parent audit:** **FOUNDATION-70** recommended this wave before any parity-hardening so maintainers see **scoped vs unscoped** paths **in-tree** without altering runtime behavior.

---

## 1. Files that received docblock / comment updates

| File |
|------|
| `system/core/Organization/OrganizationRepositoryScope.php` |
| `system/modules/clients/repositories/ClientRepository.php` |
| `system/modules/marketing/repositories/MarketingCampaignRepository.php` |
| `system/modules/marketing/repositories/MarketingCampaignRunRepository.php` |
| `system/modules/marketing/repositories/MarketingCampaignRecipientRepository.php` |
| `system/modules/payroll/repositories/PayrollRunRepository.php` |
| `system/modules/payroll/repositories/PayrollCommissionLineRepository.php` |
| `system/modules/payroll/repositories/PayrollCompensationRuleRepository.php` |

---

## 2. Proof: no executable behavior changed

- Edits are confined to **PHPDoc blocks** and **comment lines** outside executable PHP.
- **No** string literals inside `query` / `fetchOne` / `fetchAll` / `insert` calls were modified.
- **No** `if` / `return` / merge order / parameter lists were changed.

Verification: diff review and `php -l` on each touched file (syntax unchanged).

---

## 3. How documentation exposes asymmetries

| Area | What maintainers now see |
|------|---------------------------|
| **`OrganizationRepositoryScope`** | Explicit **positive id vs null**, **empty fragment = legacy-global**, **caller responsibility** for paths that skip the helper, **NULL `branch_id`** excluded when EXISTS fragment applies. |
| **`ClientRepository`** | Class doc: only **four** methods use scope; all other public API **unscoped** at repo layer. **Method** note on **`update`**: id-only mutation pattern shared with **softDelete** / **restore**. |
| **Marketing** | Campaign: **insert** unscoped here; service gates branch. Run / recipient: **dual paths** when org null vs resolved. Recipient **findForUpdate** doc aligned to **`resolvedOrganizationId()`** (code truth). |
| **Payroll** | Run: **listRecent** global vs org-scoped branch; **delete** id-only fallback. Commission lines: **allocatedSourceRefsExcludingRun** with empty fragment. Rules: list branch-filter split + fragments. |

Canonical detail tables remain in **FOUNDATION-70** companion docs (linked via `@see`).

---

## 4. Why documentation-only preceded parity hardening

**FOUNDATION-70** (§7–8) deferred **narrow SQL / predicate hardening** because it changes **tenant visibility and mutability** (e.g. org predicates on id-keyed updates) and requires **caller and product** review. This wave records **current truth** so later implementation programs start from an **explicit** baseline, not inferred behavior.

---

## 5. `@see` targets (canonical)

- `system/docs/ORGANIZATION-REPOSITORY-SCOPE-AND-DATA-PLANE-CONSUMER-PARITY-READ-ONLY-TRUTH-AUDIT-FOUNDATION-70-OPS.md`
- `system/docs/ORGANIZATION-REPOSITORY-SCOPE-CONSUMER-MATRIX-FOUNDATION-70.md`

---

## 6. STOP

**FOUNDATION-71** ends here — **FOUNDATION-72** is **not** opened.
