# ZIP-TRUTH-RECONCILIATION-SEAL-01

**Purpose:** Record **codebase-verified** alignment between prior roadmap/cleanup claims and the **current full project tree**. This document is the canonical answer when a handoff ZIP is suspected of missing sealed work.

**Verified (this workspace):** `system/modules/` contains **21** top-level module directories (same list as `system/modules/README.md`). No duplicate implementation was required for the items below — they were **already present** in PHP sources.

---

## A) Core / runtime (timezone + router)

| Prior claim | ZIP evidence |
|-------------|----------------|
| `ApplicationTimezone::syncAfterBranchContextResolved()` after branch resolution | Implemented in `system/core/app/ApplicationTimezone.php` (`syncAfterBranchContextResolved` → `resolveAndSetDefaultTimezone` using `BranchContext::getCurrentBranchId()`). |
| `BranchContextMiddleware` calls timezone resync safely | `system/core/middleware/BranchContextMiddleware.php` — guests: lines 30–32; authenticated: lines 92–98. Always after `setCurrentBranchId`. |
| Router: no misleading generic-before-specific `error_log` noise | `system/core/router/Router.php` contains **no** `error_log`; matching uses scored `usort` only. |

**Behavior preserved:** Route selection algorithm unchanged; timezone still falls back to config/UTC when settings unavailable (`ApplicationTimezone` try/catch).

---

## B) Doc / tree (module count)

| Prior claim | ZIP evidence |
|-------------|----------------|
| Canonical module count **21**, not **14** | `system/README.md` and `system/modules/README.md` state **21** modules and enumerate directories. |
| Stale “14 modules” in non-canonical copies | Reconciled in `archive/blueprint-reference/ARCHITECTURE-SUMMARY.md` (banner + §1.2/§1.3) and disclaimer on `system/docs/archive/system-root-summaries/SKELETON-SUMMARY.md`. |

---

## C) Membership assign (HQ / null context)

| Prior claim | ZIP evidence |
|-------------|----------------|
| HQ manual assign infers issuance `branch_id` from client + optional `assign_branch_id` | `system/modules/memberships/controllers/ClientMembershipController.php` — `mergeHqIssuanceBranchIntoPayload`, `resolveAssignListScope`, `parseOptionalActiveAssignBranchFromRequest`. |

**Behavior preserved:** Branch-scoped operators still pin list scope to `BranchContext`; authoritative issuance remains `MembershipService::assignToClientAuthoritative`.

---

## D) VAT admin + service write validation

| Prior claim | ZIP evidence |
|-------------|----------------|
| VAT Settings UI: global row guard on edit/update | `system/modules/settings/controllers/VatRatesController.php` — `isGlobalVatCatalogRow` on `edit` / `update`. |
| Service create/update: same branch-scope predicate as catalog | `system/modules/services-resources/services/ServiceService.php` — `assertActiveVatRateAssignableToServiceBranch` on create (post-`enforceBranchOnCreate`) and update (post-merge `branch_id` / `vat_rate_id`). Helpers: `VatRateRepository::isActiveIdInServiceBranchCatalog`, `VatRateService::assertActiveVatRateAssignableToServiceBranch`. |
| Controller maps VAT errors | `ServiceController` catches `DomainException` and `mapServiceDomainExceptionToErrors` (includes `vat_rate_id`). |

**Behavior preserved:** Invoice line canonicalization and `getRatePercentById` semantics unchanged.

---

## E) Service staff groups (post-merge + branch-only move)

| Prior claim | ZIP evidence |
|-------------|----------------|
| Update validates `staff_group_ids` against **post-merge** branch | `ServiceService::update` — `assertIdsAssignableToService` with `branchIdFromRow($mergedForVat)` when payload includes groups. |
| `ServiceController::validate` merges `branch_id` for group check | `ServiceController::validate` — `array_merge($existingService, ['branch_id' => $data['branch_id']])` when `branch_id` in data. |
| Branch change without `staff_group_ids` prunes non-assignable pivots | `ServiceService::update` lines 96–104 — `filterIdsAssignableToServiceBranch` + optional `payload['staff_group_ids']`; audit `service_staff_groups_replaced`. |

---

## F) Read-only verifier scripts

| Script | Role | Syntax |
|--------|------|--------|
| `system/scripts/verify_core_schema_compat_readonly.php` | Shim target tables/columns (read-only) | Valid PHP (no heredoc bug). |
| `system/scripts/verify_services_vat_rate_drift_readonly.php` | `services.vat_rate_id` vs `isActiveIdInServiceBranchCatalog` | Uses **`<<<'SQL'`** nowdoc. |
| `system/scripts/verify_service_staff_group_pivot_drift_readonly.php` | `service_staff_groups` vs `assertIdsAssignableToService` rules | Uses **`<<<'SQL'`** nowdoc. |

Run from **`system/`**: `php scripts/<name>.php` (`--json`, `--fail-on-drift` optional). Requires DB.

---

## G) Payment branch-scope (locked invoice row)

| Prior claim | ZIP evidence |
|-------------|----------------|
| Inner transaction uses **`findForUpdate`** invoice row for branch-effective settings | `PaymentService::create` — after `findForUpdate`, `$branchId` from `$inv`; `getPaymentSettings($branchId)`, `getHardwareSettings($branchId)`, `receiptPrintSettingsForAudit($branchId)`. Documented in `system/docs/SETTINGS-READ-SCOPE.md` §6. |

**Behavior preserved:** `assertBranchMatch`, overpayment/partial rules, register session rules unchanged.

---

## H) Contradiction summary (claimed missing vs this ZIP)

For every roadmap item in **A–G**, **this ZIP already contained the implementation**; the only **material contradiction** found was **stale documentation** still stating **14** modules in archived/blueprint copies, while the live tree and `system/README.md` / `modules/README.md` correctly state **21**.

---

## Remaining runtime-only / unproven areas

- **Production DB state** — verifiers report live drift only when executed against a real database.
- **Other settings call sites** — `SETTINGS-READ-SCOPE.md` / roadmap still note some callers on implicit `null` context where not yet audited.
- **Physical receipt hardware** — dispatch provider remains no-op by default; no device driver in-repo.

---

## Safest next task

Scoped **catalog / retail / mixed checkout / POS** work **only** when explicitly tasked — or optional **data repair** if drift verifiers report non-zero problematic rows.

**Governance:** Long-term sequencing for SaaS/platform concerns (bootstrap/routes modularization, tenancy, packages, ops gates) lives in **`BOOKER-PARITY-MASTER-ROADMAP.md` §6**, not in this seal doc.
