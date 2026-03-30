# Foundation root-cause truth map — TENANCY + WIRING + PROOF (01)

**Date:** 2026-03-29  
**Task:** FOUNDATION-ROOT-CAUSE-TENANCY-AND-WIRING-TRUTH-MAP-01  
**Method:** Truth docs first (`TASK-STATE-MATRIX.md`, addenda), then repo grep/read verification. **Code wins** over narrative.

**Scope labels used here:** `IMPLEMENTED` | `PARTIAL` | `OPEN` | `REOPENED` | `AUDIT-ONLY` | `LEGACY-RISK` (local to this memo for bug-nursery classification).

**Execution priority (2026-03-29):** **No new pages / no UI expansion** until core hardening lanes are materially reduced — continue **root-cause repository + `OrganizationRepositoryScope` closure** and **Tier A** proof waves (`run_mandatory_tenant_isolation_proof_release_gate_01.php`) before Booker §5.C surface work. Inventory catalog trio closure is **`IMPLEMENTED`** (prior wave); settings-backed **VAT + payment method** repos are org-gated; **NotificationRepository** Tier A `verify_notifications_tenant_scope_closure_readonly_01.php`; **ClientMembershipRepository** predicate centralized (`clientMembershipVisibleFromBranchContextClause`, `verify_client_membership_tenant_scope_closure_readonly_01.php`); **GiftCardRepository** tenant paths use `giftCardVisibleFromBranchContextClause` + `giftCardGlobalNullClientAnchoredInResolvedOrgClause` with Tier A `verify_gift_card_tenant_scope_closure_readonly_01.php` (**wave 06 / FND-TNT-14**); **StaffGroupRepository** uses `staffGroupVisibleFromBranchContextClause` + org-gated assignable/name/scope checks with Tier A `verify_staff_group_tenant_scope_closure_readonly_01.php` (**wave 07 / FND-TNT-15**); **ProductCategoryRepository** residual duplicate/parent-repair SQL is union-backed (**wave 08 / FND-TNT-16**); **ProductBrandRepository** duplicate-name family is union-backed (**wave 09 / FND-TNT-17**); **ProductRepository** detach / FK reference counts / canonical relink use `resolvedTenantCatalogProductVisibilityClause` (**wave 10 / FND-TNT-18**); tenant **list/count** search + taxonomy substring filters use `taxonomyCatalogUnionBranchInOrgOrNullGlobalFromOperationBranchClause` (**wave 11 / FND-TNT-19**, `verify_inventory_product_repository_search_taxonomy_tenancy_closure_readonly_11.php`).

---

## A) Tenancy root-cause map

### A.1 Semantic classes (how data/runtime is shaped)

| Class | Meaning | Repo anchor |
|-------|---------|-------------|
| **Strict branch-owned** | `branch_id` non-null + org EXISTS / tenant clauses; fail-closed when context missing | `OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause()`, `SalesTenantScope::invoiceClause`, hardened repos in sales/clients/staff/services/appointments per matrix **CLOSED** rows |
| **Org-global but safe** | Global or template rows intentionally shared; still need explicit contract | VAT/payment method seeds, some settings keys, HQ/global catalog paths with dedicated helpers (e.g. `findGlobalCatalogProductForHqInvoiceSettlementInResolvedOrg`) |
| **Null-branch legacy / repair** | `branch_id IS NULL` allowed in SQL with compensating joins or repair-only paths | `OrganizationRepositoryScope::clientProfileOrgMembershipExistsClause()` (clients-only); membership reconcile / `OrUnscoped` naming in billing cycle + sale repos; `verify_null_branch_catalog_patterns.php` Tier A |
| **Unresolved / global fallback risk** | `globalAdminBranchColumnOwnedByResolvedOrganizationExistsClauseOrUnscoped()` and similar — **empty fragment** when org unresolved | Documented in `OrganizationRepositoryScope.php` header: **explicit global-admin / tooling only**; misuse = cross-tenant read/write class |

### A.2 Highest-density `branch_id IS NULL` / OR-null SQL (modules only)

**Evidence:** ripgrep count mode on `(branch_id\s+IS\s+NULL|OR\s+.*branch_id\s+IS\s+NULL)` under `system/modules/**/*.php` → **~175 matches across 44 files** (2026-03-29 tree; sum of per-file counts).

**Worst multipliers (match count in file):**

| File | Approx matches | Risk note |
|------|----------------|------------|
| `inventory/repositories/ProductRepository.php` | *(still high grep volume)* | Stock/invoice `productCatalogUnion*`; detach/relink/count FK `resolvedTenantCatalogProductVisibilityClause` (FND-TNT-18); tenant list/count search+taxonomy `taxonomyCatalogUnion*` op-branch (FND-TNT-19); unscoped `list`/`count` + `genericSearchCondition` **residual** |
| `inventory/repositories/ProductCategoryRepository.php` | *(grep volume unchanged; semantics tightened)* | Duplicate-name + parent/child repair paths **union-backed** (FND-TNT-16) |
| `inventory/repositories/ProductBrandRepository.php` | *(grep volume unchanged; semantics tightened)* | Duplicate-name family **union-backed** (FND-TNT-17); unscoped `list` / duplicate report / id-only mutators **residual** |
| `sales/repositories/VatRateRepository.php` | *(reduced)* | Global vs branch overlay — **reads** org-gated via `OrganizationRepositoryScope::settingsBackedCatalog*` + Tier A `verify_settings_backed_vat_payment_tenant_scope_readonly_01.php` (2026-03-29 wave); id-only writes still caller-discipline |
| `notifications/repositories/NotificationRepository.php` | *(reduced)* | In-app notifications: branch ∪ global-null **org-gated** via `OrganizationRepositoryScope::notification*` delegates; Tier A `verify_notifications_tenant_scope_closure_readonly_01.php` (2026-03-29); `find` id-only **residual** |
| `sales/repositories/PaymentMethodRepository.php` | *(reduced)* | Same settings-overlay closure as VAT row above |
| `memberships/Repositories/ClientMembershipRepository.php` | *(reduced)* | Tenant paths use `OrganizationRepositoryScope::clientMembershipVisibleFromBranchContextClause()`; repair/global cron paths **named**; Tier A `verify_client_membership_tenant_scope_closure_readonly_01.php` |
| `gift-cards/repositories/GiftCardRepository.php` | 8 | Money-adjacent — **tenant slice closed** (scope fragments + FND-TNT-14); id-only `find`/`findByCode`/`list`/`count`/`update` remain explicitly unscoped |
| `staff/repositories/StaffGroupRepository.php` | *(reduced)* | RBAC — **tenant slice closed** (`staffGroupVisibleFromBranchContextClause`, FND-TNT-15); `staff_groups` has **no** `organization_id` — null-`branch_id` template rows remain **schema-limited** residual; `list`/`activeNameExists`/`find`/pivot mutators still **unscoped** where documented |
| `inventory/services/ProductGlobalSkuBranchAttributionAuditService.php` | 6 | Attribution truth |

**Modules with repeated null-branch / repair language:** **inventory**, **memberships**, **sales** (VAT/payment methods), **notifications**, **marketing** (lists/audience), **appointments** (availability/waitlist/blocked), **intake**, **documents**, **dashboard**, **reports** (fewer lines but invoice-scoped cross-module risk).

### A.3 `OrUnscoped` / repair naming (concentrated risk)

**Evidence:** case-insensitive grep `OrUnscoped|globalAdmin` under `system/**/*.php` — **low total** (~tens of hits), concentrated in **memberships** repositories/services, **inventory** `ProductInvoiceRefundReturnSettlementVisibilityAuditService`, **OrganizationRepositoryScope**, and **cross-module read-guard** verifier docs.

**Interpretation:** not volumetric like `NULL` branches; **each occurrence is architectural** (explicit escape hatch).

### A.4 Matrix alignment

`TASK-STATE-MATRIX.md`: **REOPENED** universal fail-closed; **PARTIAL** org context + repo scoping; inventory/memberships matrices **PARTIAL**/**OPEN**. This audit **confirms** inventory + memberships + sales adjacency as **primary bug nurseries** for tenant mistakes.

---

## B) Wiring / bootstrap / container drift map

### B.1 Bootstrap

- **Orchestrator:** `system/modules/bootstrap.php` — ordered `require` of **20** registrars under `system/modules/bootstrap/*.php`.
- **Binding surface:** ripgrep `singleton\(|bind\(` in `system/modules/bootstrap/*.php` → **~339** occurrences (sum of per-file counts 2026-03-29).
- **Ordering hazard:** comment **A-001** — `OrganizationContextResolver` singleton + `StaffMultiOrgOrganizationResolutionGate` **must** follow module registrars that register org membership services. New registrars inserted before org-dependent services → **runtime resolution failures**.

### B.2 Routes

- **Orchestrator:** `system/routes/web.php` — **15** named registrar files under `system/routes/web/`, then **4** module-local `modules/*/routes/web.php` (intake, gift-cards, packages, memberships).
- **Registration surface:** ripgrep `\$router->(get|post|put|delete|patch)` under `system/routes/**/*.php` → **~394** registrations (sum of per-file counts).
- **Drift patterns:** mixed registration locus (central `web/` vs module `routes/web.php`); **strict order** documented in `ROUTE-REGISTRATION-TOPOLOGY-TRUTH-OPS.md` — **AUDIT-ONLY** topology; **no** automated proof that order stayed stable beyond spot verifiers (e.g. `verify_auth_public_routed_controllers_container_a002_hotfix_01.php`).

### B.3 Failure modes (evidence-backed)

- **Class string in route array vs container:** risk of **route registered, container missing binding** — mitigated only by **partial** static verifiers, not full CI matrix.
- **Duplicate / split domains:** e.g. dashboard routes live in `register_core_dashboard_auth_public.php` while `register_dashboard.php` exists in **bootstrap** only (not a second route file in `web.php` list) — **verify** when adding features: bootstrap `register_dashboard.php` wires services; routes concentrated in core registrar.

**Status:** **PARTIAL** mechanical decomposition (bootstrap fragments exist); **OPEN** full modular safety nets.

---

## C) Proof model vs CI model

| Item | Count / fact |
|------|----------------|
| PHP under `system/scripts/` (recursive) | **299** files (glob 2026-03-29) |
| Of which `system/scripts/read-only/` | **137** files |
| PLT-REL-01 Tier A steps | **23** (enumerated in `run_mandatory_tenant_isolation_proof_release_gate_01.php`) |
| GitHub Actions workflows | **1** — `.github/workflows/tenant-isolation-gate.yml` runs **only** Tier A gate |
| Tier B integration | **OPEN** in CI — documented as runbook / `--with-integration` |

**Regression-blind spots:** marketing automations deep verifiers, media pipeline runtime proofs, settings write waves, client merge async, payroll edge flags, **smoke_*** scripts, **dev-only/** trees — **not** in default CI.

**Status:** Proof surface **IMPLEMENTED** and broad; CI coverage **PARTIAL** (single workflow); **OPEN** harness parity (`FND-TST-04`).

---

## D) Privileged-path audit

| Component | Status |
|-----------|--------|
| `FounderSupportEntryService::startForFounderActor` | **IMPLEMENTED** — session user = founder, `PrincipalPlaneResolver::isControlPlane`, target tenant user, branch allowlist, audit correlation |
| `PlatformFounderSupportEntryController::postStart` | **IMPLEMENTED** — CSRF, `FounderSafeActionGuardrailService` reason + high-impact confirmation |
| `SupportEntryController` / `SessionAuth` support keys | **IMPLEMENTED** |
| `FounderImpersonationAuditService` | **IMPLEMENTED** |
| MFA / TOTP / WebAuthn / step-up | **OPEN** — **no** matches in `system/modules/organizations/**/*.php` for `mfa|totp|webauthn|step-up|second.factor` (grep 2026-03-29) |

**Single-factor exposure:** Any platform principal who can pass **password session + CSRF + guardrail reason/confirm** can start support-entry — **no second factor** in code path.

---

## E) Async / queue maturity

| Island | Status |
|--------|--------|
| Image pipeline + `media_jobs` | **IMPLEMENTED** — Node worker, `SKIP LOCKED`, stale reclaim |
| `runtime_execution_registry` + cron wrappers | **PARTIAL** — heartbeats, exclusive runs, **no** in-repo supervisor |
| Outbound email dispatch | **IMPLEMENTED** (email); SMS **OPEN** |
| Client merge jobs | **PARTIAL** — org-scoped wave + Tier A proof |
| Memberships / marketing cron | **PARTIAL** — flock + registry |

**Missing (generalized platform):** unified DLQ, poison-job policy, cross-workload metrics — **OPEN** (`PLT-Q-01`).

---

## F) Additional structural causes (evidenced)

- **Settings scope:** `SettingsService` + branch/global merge — large surface; tenant isolation **CLOSED** for a slice; Lane 01 **PARTIAL** — ambiguity remains for launched vs stored truth.
- **Error/permission contracts:** dedicated verifiers (`verify_access_denied_http_status_h003_01.php`, API JSON contract reports) — **PARTIAL** consistency, not unified.
- **No standard PHPUnit harness at repo root** — **OPEN**; proof is script-heavy → **selection bias** (what gets scripted gets guarded).

---

## G) Top 5 bug nurseries (future risk rank)

1. **Null-branch + org-scoped SQL in inventory/catalog/memberships** — ~175 module SQL hits (pattern above) + money adjacency.  
2. **Universal repository fail-closed not proven** — **REOPENED** matrix; new code paths bypass scope helpers.  
3. **Bootstrap/route ordering + ~339 bindings / ~394 routes** — silent mis-wiring.  
4. **CI = Tier A only vs 299 script files** — regressions ship without running most proofs.  
5. **Privileged support-entry without MFA** — small call count, extreme blast radius.

---

## H) Prior audit: confirm / refine

| Prior claim | This audit |
|-------------|------------|
| Tenant/org/branch/null-branch semantics dominate | **Confirmed** — 174+ null-branch SQL matches in modules; `OrganizationRepositoryScope` documents dual contracts |
| Manual bootstrap/routes drift | **Confirmed** — counts above; split intake/memberships route loading |
| Many proofs, thin CI | **Confirmed** — 299 scripts, 1 workflow, 23 Tier A steps |
| Support-entry live, MFA open | **Confirmed** — code read + zero MFA grep in organizations module |
| Fragmented async | **Confirmed** — islands listed; no unified DLQ |
| Storage / PSP / SMS gaps | **Confirmed** — unchanged from matrix; not re-proven here |

**Refinement:** **OrUnscoped** is **low count, high leverage** (separate from null-branch volume); treat as **LEGACY-RISK** hotspot in code review, not just “many lines.”

---

## I) Single best next backend task (root cause)

**Continue `PLT-TNT-01` with the next mechanical tenant-repository closure slice** (per `FOUNDATION-PRODUCTION-HARDENING-TRUTH-MAP-CHARTER-01.md` §3 and `BACKLOG-…-RECONCILIATION-01.md` §B), extending Tier A proof — **null-branch + org SQL volume** is the dominant *recurring* bug nursery; narrowing it beats treating individual symptom bugs. **`PLT-MFA-01`** remains the correct **parallel** workstream for **privilege** blast radius (see §D), not a substitute for tenant closure breadth.
