# Tenant safety inventory — CHARTER-01 (read-only)

> **SEALED EVIDENCE — ARCHITECTURE RESET 2026-03-31**  
> This file records hotspot inventory and closed wave slices from the PLT-TNT-01 ROOT-01 id-only closure wave era.  
> It is **not** an active roadmap or a steering document for future work.  
> The active roadmap is **FOUNDATION-A1..A8** — see `docs/ARCHITECTURE-RESET-2026-CANONICAL-ROADMAP.md` and `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`.  
> Do not use residual open rows in this inventory to authorize new wave patching. Those domains will be migrated via FOUNDATION-A7 Migration Map after the kernel pilot is accepted.

**Method:** Persistence methods that load or mutate by **primary id** (or global keys) without an **intrinsic organization/tenant predicate** in SQL are **caller-scoped**: correctness depends on services/controllers always supplying the right branch/org context. This inventory classifies representative hotspots; it is **not** exhaustive line-by-line proof.

**Root-cause families:** Canonical definitions — **`ROOT-CAUSE-REGISTER-01.md`** (**ROOT-01** id-only patterns, **ROOT-02** null-branch semantics, **ROOT-03** public/guest bootstrap, **ROOT-04** repair/global fallback, **ROOT-05** service scope drift). Rows below tag **ROOT-xx** where the slice primarily reduces that family.

**Legend**

- **Safe (wrapped):** Typical mutations go through services that run `OrganizationScopedBranchAssert` or equivalent (see F-11 docs).  
- **Risky (public unscoped):** `*Repository::find` / `findForUpdate` / id-only updates usable from controllers or cross-tenant code paths.  
- **Unknown:** Requires call-graph review per feature.

## PLT-TNT-01 wave (2026-03-28) — closed slice

**ROOT:** **ROOT-01**

| Area | Change | Proof |
|------|--------|--------|
| Client merge async jobs (`client_merge_jobs`) | Repository **no longer** exposes id-only `find`/`update` for tenant paths; HTTP-visible load uses `findByIdForOrganization`; worker claim uses explicit `findByIdForWorker`; success/failure patches use `updateByIdForOrganization` or `updateByIdForWorker` when org unknown | `verify_client_merge_job_repository_org_scope_plt_tnt_01.php` (Tier A in `run_mandatory_tenant_isolation_proof_release_gate_01.php`) |

## PLT-LC-01 wave (2026-03-28) — closed slice

**ROOT:** **ROOT-03** (lifecycle/suspension vs tenant entry consistency; adjacent **ROOT-02** org/branch truth)

| Area | Change | Proof |
|------|--------|--------|
| Legacy tenant branch access (no `user_organization_memberships`) | Pinned branch is allowed only when joined org is non-deleted and **`suspended_at IS NULL`** (`legacyPinnedBranchInActiveOrganization`) | `verify_tenant_branch_access_legacy_suspended_org_plt_lc_01.php` (Tier A) |
| Exempt branch switch POST | After allowed-branch validation, **`BranchContextController`** rejects suspended org branches via **`OrganizationLifecycleGate`** (JSON `TENANT_ORGANIZATION_SUSPENDED` or `tenant-suspended.php`) | same verifier + `verify_lifecycle_suspension_hardening_wave_01_readonly.php` |

## FND-TNT-05 wave (2026-03-28) — closed slice

**ROOT:** **ROOT-01**, **ROOT-03**

| Area | Change | Proof |
|------|--------|--------|
| Public commerce purchases (`public_commerce_purchases`) by `invoice_id` | No id-only **`findByInvoiceId` / `findForUpdateByInvoiceId`**; **`findCorrelatedToInvoiceRow`** / **`findForUpdateCorrelatedToInvoiceRow`** require tenant-scoped invoice row first (`invoice_id` + **`branch_id`** when set, else **`INNER JOIN invoices`** live row) | `verify_public_commerce_purchase_invoice_correlation_fnd_tnt_05.php` (Tier A) |

## FND-TNT-06 wave (2026-03-28) — closed slice

**ROOT:** **ROOT-01**, **ROOT-05** (read envelope vs write path alignment)

| Area | Change | Proof |
|------|--------|--------|
| Sales invoice / cashier client satellite reads | **`InvoiceController`** — linked client for receipt/show, GET client prefill, membership staff checkout uses **`findLiveReadableForProfile($clientId, $branchEnvelope)`** instead of org-only **`find()`** (avoids same-org wrong-branch PII when invoice branch is set) | `verify_invoice_client_read_envelope_fnd_tnt_06.php` (Tier A) |

## FND-TNT-07 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-01 Tier A)

**ROOT:** **ROOT-01**, **ROOT-05**

| Area | Change | Proof |
|------|--------|-------|
| Public commerce purchase **mutations** by bare `id` | **`PublicCommercePurchaseRepository::update`** (+ recovery helpers) append **`OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause('p','branch_id')`** — `UPDATE public_commerce_purchases p … WHERE p.id = ?` | `verify_tenant_closure_wave_fnd_tnt_07_readonly_01.php` (Tier A) |
| Membership sale **updates** by bare `id` | **`MembershipSaleRepository::update`** uses same pattern on alias **`ms`** | same verifier |

## FND-TNT-08 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-02 Tier B)

**ROOT:** **ROOT-01**, **ROOT-02** (null-branch invoice arm), **ROOT-05**

| Area | Change | Proof |
|------|--------|-------|
| Membership sale **reads / locks** by bare `id` | **`find` / `findForUpdate`** use `SELECT ms.* … WHERE ms.id = ?` + **`branchColumnOwnedByResolvedOrganizationExistsClause('ms')`** (same predicate family as **`update`**) | `verify_tenant_closure_wave_fnd_tnt_08_readonly_01.php` (Tier A in PLT-REL-01) |
| Duplicate initial-sale block | **`findBlockingOpenInitialSale`**: branch path adds org EXISTS on **`ms`**; **`branch_id` NULL** path uses **invoice INNER JOIN** + **`invoicePlaneExistsClauseForMembershipReconcileQueries`** | same |
| Refund-review operator + invoice settlement | **`MembershipSaleService`**: **`findForUpdateInTenantScope` / `findInTenantScope`** when branch known from tenant or invoice | same |

## FND-TNT-09 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-03)

**ROOT:** **ROOT-01**, **ROOT-02**, **ROOT-05**

| Area | Change | Proof |
|------|--------|--------|
| Refund-review **wide lists** | **`MembershipSaleRepository::listRefundReview`** / **`MembershipBillingCycleRepository::listRefundReviewQueue`**: **`resolvedOrganizationId()`** required; org EXISTS on sale/membership **branch** or **invoice.branch_id** for null-branch rows; **no** raw `SELECT * … membership_sales WHERE status = refund_review` | `verify_tenant_closure_wave_fnd_tnt_09_readonly_01.php` (Tier A in PLT-REL-01) |
| Cashier membership definition pick list | **`MembershipDefinitionRepository::listActiveForInvoiceBranch`**: **`branchColumnOwnedByResolvedOrganizationExistsClause('md')`** on `md.branch_id` | same |

## FND-TNT-10 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-04)

| Area | Change | Proof |
|------|--------|--------|
| Membership definition **find / list / count / listActiveForBranch** | **`branchColumnOwnedByResolvedOrganizationExistsClause('md')`** (or **`AccessDeniedException` → empty)**; **`findBranchOwnedInResolvedOrganization`** + **`findForClientMembershipContext`** (branch or client-profile org anchor); **`branch_scope=global`** list/count empty | `verify_tenant_closure_wave_fnd_tnt_10_readonly_01.php` (Tier A in PLT-REL-01) |
| Membership billing cycle **find / findForUpdate** | **`findInInvoicePlane`** / **`findForUpdateInInvoicePlane`** + **`findForInvoice`** / **`findForUpdateForInvoice`** (`INNER JOIN invoices` + invoice-plane EXISTS); **`MembershipBillingService`** uses **`findForClientMembershipContext`** + invoice-correlated cycle reads | same |

## FND-TNT-11 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-05)

**ROOT:** **ROOT-01**, **ROOT-04** (repair fallback), **ROOT-05**

| Area | Change | Proof |
|------|--------|--------|
| **`ClientMembershipRepository` id-read/lock** | **`find` / `findForUpdate`:** org anchor via **`getAnyLiveBranchIdForResolvedTenantOrganization`** → **`findInTenantScope` / `findForUpdateInTenantScope`**; repair/cron raw fallback when org unset | `verify_tenant_closure_wave_fnd_tnt_11_readonly_01.php` (Tier A in PLT-REL-01) |
| **Billing + benefit locks** | **`lockWithDefinitionInTenantScope`**, **`lockWithDefinitionForBillingInTenantScope`**, due list returns **`branch_id`** pin; **`MembershipBillingService`** **`BranchContext`** + settlement reads; **`MembershipService`** benefit lock branch pin | same |

## FND-TNT-12 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-06)

**ROOT:** **ROOT-01**, **ROOT-04**, **ROOT-05**

| Area | Change | Proof |
|------|--------|--------|
| Client membership **unscoped catalog** | **`list` / `count` removed**; HTTP uses **`listInTenantScope` / `countInTenantScope`** only | `verify_tenant_closure_wave_fnd_tnt_12_readonly_01.php` (Tier A in PLT-REL-01) |
| **Issuance overlap probe** | **`findBlockingIssuanceRowInTenantScope`** — intrinsic org predicate on **`cm`**; service resolves branch pin or throws | same |
| **Billing cycle period lookup** | **`findByMembershipAndPeriod`** joins **`client_memberships cm`** + org predicate when branch/org pin resolves; repair id-only when unset | same |

## FND-TNT-13 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-07)

**ROOT:** **ROOT-01**, **ROOT-04**, **ROOT-05**

| Area | Change | Proof |
|------|--------|--------|
| Client membership **`UPDATE`** | **`updateInTenantScope`** + **`updateRepairOrUnscopedById`**; no ambiguous **`update(int $id)`** | `verify_tenant_closure_wave_fnd_tnt_13_readonly_01.php` (Tier A in PLT-REL-01) |
| Billing / lifecycle call sites | **`MembershipBillingService`** / **`MembershipLifecycleService`** use scoped updates or explicit repair | same |
| Renewal reminder scan | **`listActiveNonExpiredForRenewalScanGlobalOps`** — documented cross-tenant cron read | same |

## FND-TNT-14 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-08)

**ROOT:** **ROOT-01**, **ROOT-04** (GlobalOps listing), **ROOT-05**

| Area | Change | Proof |
|------|--------|-------|
| **Expiry terminal cron (`runExpiryPass`)** | **`listExpiryTerminalCandidatesForGlobalCron`**: operational definition join + **`clientMembershipRowAnchoredToLiveOrganizationSql`** (live branch + org); **`lock_branch_id`** pin; **`runExpiryPass`** locks only via **`findForUpdateInTenantScope`** (no wide unscoped candidate **`SELECT`**, no id-only **`findForUpdate`** in this path) | `verify_tenant_closure_wave_fnd_tnt_14_readonly_01.php` (Tier A) |

## FND-TNT-15 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-09)

**ROOT:** **ROOT-01**, **ROOT-03**, **ROOT-04** (`AccessDeniedException` fallback contract)

| Area | Change | Proof |
|------|--------|-------|
| **Anonymous public commerce invoice read** | **`InvoiceRepository::findForPublicCommerceCorrelatedBranch`**: `i.id` + **`i.branch_id`** pin + live branch/org **`EXISTS`** (no **`SalesTenantScope::invoiceClause`** / branch-derived session). **`PublicCommerceService`** initiate/finalize/status use purchase/initiate branch. **`findBranchIdPinByInvoiceId`** + **`loadInvoiceRowForPublicCommerceReconcile`** ( **`find`** first; **`AccessDeniedException`** → pin + correlated read) for reconciler + recovery | `verify_tenant_closure_wave_fnd_tnt_15_readonly_01.php` (Tier A) |

## FND-TNT-16 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-10)

**ROOT:** **ROOT-01**, **ROOT-03**

| Area | Change | Proof |
|------|--------|-------|
| **Public client email lock** | **`ClientRepository::lockActiveByEmailBranch`**: **`$branchId <= 0`** → no row; **`publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause('c')`** (live branch + org; no session org) on both SQL paths | `verify_tenant_closure_wave_fnd_tnt_16_readonly_01.php` (Tier A) |

## FND-TNT-17 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-11)

**ROOT:** **ROOT-03**, **ROOT-05**, **ROOT-01**

| Area | Change | Proof |
|------|--------|-------|
| **Public client phone lock + excluding read** | **`lockActiveByPhoneDigitsBranch`** / **`findActiveClientIdByPhoneDigitsExcluding`**: **`$branchId <= 0`** → no rows / **`null`**; **`publicClientResolutionBranchColumnLiveInLiveOrganizationExistsClause('c')`** on both SQL paths (parity with **`lockActiveByEmailBranch`**) | `verify_tenant_closure_wave_fnd_tnt_17_readonly_01.php` (Tier A) |

## FND-TNT-18 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-12)

**ROOT:** **ROOT-01**, **ROOT-05**; **ROOT-02** adjacent (null **`rooms.branch_id`** excluded by org fragment)

| Area | Change | Proof |
|------|--------|-------|
| **Room row lock before overlap check** | **`AppointmentRepository::lockRoomRowForConflictCheck`**: **`SELECT r.id FROM rooms r … FOR UPDATE`** + **`branchColumnOwnedByResolvedOrganizationExistsClause('r')`** (not bare global **`rooms.id`**) | `verify_tenant_closure_wave_fnd_tnt_18_readonly_01.php` (Tier A) |

## FND-TNT-19 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-13)

**ROOT:** **ROOT-01**, **ROOT-05**; **ROOT-02** (explicit **`$branchId === null`** legacy arm only)

| Area | Change | Proof |
|------|--------|-------|
| **Room overlap scan (branch-scoped)** | **`hasRoomConflict`** when **`$branchId !== null`**: **`appointments a`** + **`a.branch_id = ?`** + **`branchColumnOwnedByResolvedOrganizationExistsClause('a')`**; **`$branchId === null`** path unchanged (**`a.branch_id IS NULL`**) | `verify_tenant_closure_wave_fnd_tnt_19_readonly_01.php` (Tier A) |

## FND-TNT-21 wave (2026-03-29) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-14)

**ROOT:** **ROOT-01**, **ROOT-05**; **ROOT-02** (null **`appointments.branch_id`** excluded by org fragment)

| Area | Change | Proof |
|------|--------|-------|
| **Staff overlap scan** | **`hasStaffConflict`**: **`appointments a`** + **`branchColumnOwnedByResolvedOrganizationExistsClause('a')`**; org-wide per **`staff_id`** within resolved tenant only (not cross-tenant global) | `verify_tenant_closure_wave_fnd_tnt_21_readonly_01.php` (Tier A) |

## FND-TNT-26 wave (2026-03-30) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-15 / PLT-TNT-01)

**ROOT:** **ROOT-05** (primary), **ROOT-04** (secondary — explicit non-use of legacy global depot / no silent org-resolution downgrade)

| Area | Change | Proof |
|------|--------|-------|
| **Per-organization invoice counter (`invoice_number_sequences`)** | **`allocateNextInvoiceNumber`**: **`requireBranchDerivedOrganizationIdForInvoicePlane()`** — same **branch-derived** org basis as **`SalesTenantScope::invoiceClause()`**; removed **`assertProtectedTenantContextResolved()`**-only guard (org id without branch derivation could allocate while invoice reads fail-closed). Legacy **`(organization_id=0, …)`** depot row remains **unused** by allocator (explicit contract in repository docblock). **`OrganizationRepositoryScope::requireBranchDerivedOrganizationIdForDataPlane()`** named delegate for other org-keyed rows without **`branch_id`**. | `verify_invoice_number_sequence_hotspot_readonly_01.php` (Tier A in PLT-REL-01) |

## FND-TNT-27 wave (2026-03-30) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-16 / PLT-TNT-01)

**ROOT:** **ROOT-01** (primary — **`register_session_id`**-keyed aggregate), **ROOT-05** (aggregate vs **`RegisterSessionRepository`** / invoice-plane basis); **ROOT-04** (secondary — **`paymentByInvoiceExistsClause`** empty fragment without branch-derived org)

| Area | Change | Proof |
|------|--------|-------|
| **Register close cash aggregate** | **`PaymentRepository::getCompletedCashTotalsByCurrencyForRegisterSession`**: **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before query; **`INNER JOIN register_sessions rs`** + **`registerSessionClause('rs')`** + **`paymentByInvoiceExistsClause('p','si')`**; **`register_session_id <= 0`** → **`[]`**. Aligns lock/read path in **`RegisterSessionService::closeSession`** with same tenant SQL basis as session **`findForUpdate`**. | `verify_payment_register_session_cash_aggregate_closure_16_readonly_01.php` (Tier A in PLT-REL-01) |

## FND-TNT-28 wave (2026-03-30) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-17 / PLT-TNT-01)

**ROOT:** **ROOT-01** (primary — index **count** keyed only by filters without explicit named branch-derived entry before **CLOSURE-17**), **ROOT-05** (secondary — **`list`** pagination vs **count** must stay filter-aligned; **`count`** now mirrors **`invoiceClause`** + explicit **`requireBranchDerivedOrganizationIdForInvoicePlane()`**); **ROOT-04** (secondary — avoid relying on implicit empty tenant fragment semantics for aggregates)

| Area | Change | Proof |
|------|--------|-------|
| **Invoice index total (`InvoiceRepository::count`)** | **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before SQL; still **`invoiceClause('i')`**; **`LEFT JOIN clients`** only when **`client_name` / `client_phone`** filters need **`c.*`** (**`invoiceListRequiresClientsJoinForFilters`**). Parity with **`list()`**: **CLOSURE-18**. | `verify_invoice_repository_count_invoice_plane_closure_17_readonly_01.php` (Tier A in PLT-REL-01) |

## FND-TNT-29 wave (2026-03-30) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-18 / PLT-TNT-01)

**ROOT:** **ROOT-05** (primary — **`list`** vs **`count`** explicit entry + join policy drift), **ROOT-01** (secondary — invoice index **read** path contract); **ROOT-04** (secondary — explicit branch-derived entry vs implicit **`invoiceClause`**-only narrative)

| Area | Change | Proof |
|------|--------|-------|
| **Invoice index rows (`InvoiceRepository::list`)** | **`requireBranchDerivedOrganizationIdForInvoicePlane()`**; same **`invoiceListRequiresClientsJoinForFilters`** as **`count`**: **`LEFT JOIN clients`** when **`client_name` / `client_phone`**; else **`client_first_name` / `client_last_name`** via correlated **`clients cj`** subselects (stable columns, no broad **JOIN**). **`appendListFilters`** unchanged. | `verify_invoice_repository_list_invoice_plane_closure_18_readonly_01.php` (Tier A in PLT-REL-01) |

## FND-TNT-30 wave (2026-03-30) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-19 / PLT-TNT-01)

**ROOT:** **ROOT-01** (primary — single-record **`find` / `findForUpdate`** had only implicit **`invoiceClause`**-embedded guard), **ROOT-05** (secondary — parity with **`list` / `count` / `allocateNextInvoiceNumber`** named entry)

| Area | Change | Proof |
|------|--------|-------|
| **Invoice single-record read/lock (`InvoiceRepository::find`, `findForUpdate`)** | **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before SQL; unchanged **`invoiceClause('i')`** and query shapes (including **`find`** **`LEFT JOIN clients`**). | `verify_invoice_repository_find_find_for_update_invoice_plane_closure_19_readonly_01.php` (Tier A in PLT-REL-01) |

## FND-TNT-31 wave (2026-03-30) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-20 / PLT-TNT-01)

**ROOT:** **ROOT-01** (primary — payment **id** read/lock without explicit named invoice-plane entry), **ROOT-05** (secondary — parity with **CLOSURE-19** invoice id paths)

| Area | Change | Proof |
|------|--------|-------|
| **Payment id read/lock (`PaymentRepository::find`, `findForUpdate`)** | **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before SQL; unchanged **`paymentByInvoiceExistsClause('p','si')`**. | `verify_payment_repository_find_find_for_update_invoice_plane_closure_20_readonly_01.php` (Tier A in PLT-REL-01) |

## FND-TNT-32 wave (2026-03-30) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-21 / PLT-TNT-01)

**ROOT:** **ROOT-01** (primary — invoice-keyed **list** read without explicit named invoice-plane entry), **ROOT-05** (secondary — parity with **CLOSURE-20**)

| Area | Change | Proof |
|------|--------|-------|
| **Payments by invoice (`PaymentRepository::getByInvoiceId`)** | **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before SQL; unchanged **`paymentByInvoiceExistsClause('p','si')`** and **`ORDER BY p.created_at`**. | `verify_payment_repository_get_by_invoice_id_invoice_plane_closure_21_readonly_01.php` (Tier A in PLT-REL-01) |

## CLOSURE-22 wave (2026-03-30) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-22 / PLT-TNT-01)

**ROOT:** **ROOT-01** (primary — invoice-keyed **aggregate** read without explicit named invoice-plane entry), **ROOT-05** (secondary — parity with **CLOSURE-21**)

| Area | Change | Proof |
|------|--------|-------|
| **Completed net by invoice (`PaymentRepository::getCompletedTotalByInvoiceId`)** | **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before SQL; unchanged **`paymentByInvoiceExistsClause('p','si')`** and signed net **`CASE`** for refunds. | `verify_payment_repository_get_completed_total_by_invoice_id_invoice_plane_closure_22_readonly_01.php` (Tier A in PLT-REL-01) |

## CLOSURE-23 wave (2026-03-30) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-23 / PLT-TNT-01)

**ROOT:** **ROOT-05** (primary — helper-method invoice-plane contract drift inside `PaymentRepository`), **ROOT-01** (secondary — invoice/payment helper reads without explicit named branch-derived entry)

| Area | Change | Proof |
|------|--------|-------|
| **Payment helper trio (`PaymentRepository::existsCompletedByInvoiceAndReference`, `getCompletedRefundedTotalForParentPayment`, `hasCompletedRefundForInvoice`)** | **`requireBranchDerivedOrganizationIdForInvoicePlane()`** before SQL on all three methods; unchanged **`paymentByInvoiceExistsClause('p','si')`** and existing query semantics. | `verify_payment_repository_helper_invoice_plane_closure_23_readonly_01.php` (Tier A in PLT-REL-01) |

## CLOSURE-24 wave (2026-03-30) — closed slice (FOUNDATION-TENANT-REPOSITORY-CLOSURE-24 / PLT-TNT-01)

**ROOT:** **ROOT-04** (primary — strict-vs-repair split for membership invoice-plane helpers), **ROOT-05** (secondary — ambiguous helper contract drift)

| Area | Change | Proof |
|------|--------|-------|
| **Membership invoice-plane helper split (`MembershipSaleRepository`, `MembershipBillingCycleRepository`, `OrganizationRepositoryScope`)** | Removed hidden `AccessDeniedException` => `OrUnscoped` helper widening from membership invoice-plane helpers. Added explicit strict helper family (`strictTenantInvoicePlaneBranchScope`) and explicit repair/global helper family (`resolvedOrganizationRepairInvoicePlaneBranchScopeIfAvailable`). `findByMembershipAndPeriod()` now fails closed and no longer falls back to raw `SELECT *`. | `verify_root_04_strict_repair_split_membership_invoice_plane_readonly_01.php`, `verify_cross_module_invoice_payment_read_guard_readonly_01.php`, `verify_tenant_closure_wave_fnd_tnt_08_readonly_01.php`, `verify_tenant_closure_wave_fnd_tnt_12_readonly_01.php` |

## Highest-risk areas (next wave)

| Area | Why | Primary files | Root family(ies) |
|------|-----|---------------|------------------|
| Explicit global/control-plane compatibility helper name still contains legacy **`OrUnscoped`** wording outside the membership split wave | Membership helper family is now split, but one compatibility wrapper name remains in `OrganizationRepositoryScope` for non-target call sites until the remaining control-plane/global inventory is rotated in | `OrganizationRepositoryScope.php` | **ROOT-04** |

## Repository `find(int $id)` pattern (illustrative)

Most `system/modules/**/repositories/*Repository.php` expose **id-only** `find` / `findForUpdate`. **Classification:** **Risky** at the repository layer; **Safe** only where every caller is proven branch/org-filtered (see F-12 matrix). **Root family:** **ROOT-01**.

**Already partially hardened (examples):** Marketing campaign repositories (F-13) use `OrganizationRepositoryScope`-aware SQL for listed methods — treat those methods as **Safer** than raw id-only finds.

## Follow-up execution order

**Canonical sequencing with foundation + data-plane waves:** `BACKLOG-CANONICALIZATION-AND-HARDENING-QUEUE-RECONCILIATION-01.md` §B.

1. Reuse **`ORGANIZATION-SCOPED-REPOSITORY-PATTERN-TRUTH-AUDIT-FOUNDATION-12-OPS.md`** phased plan (R1–R9).  
2. Add **read-path** closure for invoice/payment **GET** handlers if IDs are only guarded at service layer on writes. **Partial (FND-TNT-06):** invoice **show** + related cashier client satellite reads use **`findLiveReadableForProfile`** — other F-12 surfaces remain.  
3. Run existing footgun verifiers in CI: `verify_tenant_repository_footguns.php`, `verify_null_branch_catalog_patterns.php` — both are included in **Tier A** of **`system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php`** (PLT-REL-01).
