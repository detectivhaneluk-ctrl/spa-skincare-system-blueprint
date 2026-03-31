# Foundation active backlog — CHARTER-01 (normalized)

**Backbone Closure Mode — live execution queue:** This file is the **only** place that may list **LIVE** implementation work.  
**Master execution plan:** `BACKBONE-CLOSURE-MASTER-PLAN-01.md`  
**Full truth inventory (not a work-in-progress permit):** `TASK-STATE-MATRIX.md`  
**Platform facts + layer proofs:** `FOUNDATION-PRODUCTION-HARDENING-TRUTH-MAP-CHARTER-01.md`  
**Deferred product/polish and Phase 1 inventory not promoted here:** `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`

> **ARCHITECTURE RESET — 2026-03-31:**  
> The old ROOT-01 id-only closure wave (PLT-TNT-01 incremental module-by-module repository patching) has been **ARCHIVED / SUPERSEDED** by this reset.  
> All active work derived from the PLT-TNT-01 wave pattern — availability/public-booking cluster closure, id-only closure wave, follow-on wave-style repository patching, scattered verifier-first patch waves across appointments / inventory / memberships / notifications / packages / sales / services-resources / settings / staff — is **no longer the active roadmap**.  
> The new active roadmap is **FOUNDATION-A1..A7** (2026 Foundation Plan). See the LIVE, ROADMAP, and ARCHIVED sections below.  
> Old ROOT-01 marketing/media purge closure proof and SHA-pinned truth audit artifacts are **retained as sealed evidence only** and do not constitute active work items.

**Anti-drift (mandatory):**

- **At most one LIVE task at a time** — concurrent implementation threads against multiple backbone IDs without charter promotion are **out of policy**.
- **At most one PARKED / NEXT task** — the single approved successor when the LIVE task closes or is explicitly swapped.
- **`TASK-STATE-MATRIX.md` is the truth inventory** — many **`OPEN`** / **`REOPENED`** / **`PARTIAL`** rows exist simultaneously; that is **not** permission to implement them in parallel. It is **not** "implementation concurrency allowed." Promotion into **this** charter is required before work is **LIVE**.
- **Root-cause register** — `ROOT-CAUSE-REGISTER-01.md`: backbone work is **root-family-driven**. **Repeated bug-instance fixes** without naming a **ROOT-** id do **not** count as real closure. **No feature expansion** ahead of materially reducing the **relevant** root families for the **current phase** (see register + master plan freeze rules).

**Statuses (aligned with `TASK-STATE-MATRIX.md`):** `CLOSED` | `PARTIAL` | `OPEN` | `REOPENED` | `AUDIT-ONLY` | `PLANNED` — plus charter-local **`PROVISIONAL`** / **`DROPPED/OBSOLETE`** / **`ARCHIVED/SUPERSEDED`** where useful. Legacy **`COMPLETED`** = **`CLOSED`**.

---

## Phase 0 — **CLOSED** (2026-03-29)

Planning cleanup, backlog freeze, legacy banners, and **BACKBONE-CLOSURE-ACTIVE-SPINE-TIGHTENING-02** (single LIVE / single PARKED charter) are **complete**. Execution is now in the **2026 Foundation Plan** phase with **`FOUNDATION-A2`** as the sole **LIVE** task (see below).

| ID | Item | Evidence |
|----|------|----------|
| BC-PH0-01 | Backbone canonicalization + active spine tightening | `BACKBONE-CLOSURE-MASTER-PLAN-01.md`, `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md`, matrix header, legacy doc banners, this charter structure |

---

## FOUNDATION-A1 — **CLOSED** (2026-03-31)

**FOUNDATION-A1: TenantContext Kernel** — installed and verified.

| Item | Evidence |
|------|----------|
| Immutable `TenantContext` value object with all required fields | `system/core/Kernel/TenantContext.php` |
| `PrincipalKind`, `AssuranceLevel`, `ExecutionSurface` enums | `system/core/Kernel/` |
| `UnresolvedTenantContextException` — fail-closed signal | `system/core/Kernel/UnresolvedTenantContextException.php` |
| `RequestContextHolder` — per-request singleton | `system/core/Kernel/RequestContextHolder.php` |
| `TenantContextResolver` — single designated resolver entry point | `system/core/Kernel/TenantContextResolver.php` |
| `TenantContextMiddleware` — wired into global pipeline after OrganizationContextMiddleware | `system/core/middleware/TenantContextMiddleware.php`, `system/core/Router/Dispatcher.php` |
| Authorization kernel skeleton: `AuthorizerInterface`, `ResourceAction`, `ResourceRef`, `AccessDecision`, `DenyAllAuthorizer`, `AuthorizationException` | `system/core/Kernel/Authorization/` |
| Bootstrap registration of all kernel singletons | `system/bootstrap.php` |
| Kernel namespace added to PSR-4 autoloader | `composer.json` |
| 74/74 kernel contract assertions pass | `system/scripts/read-only/verify_kernel_tenant_context_01.php` |
| Architecture documentation | `system/docs/FOUNDATION-KERNEL-ARCHITECTURE-01.md` |

---

## CLOSED (historical charter evidence — not Phase 0)

| ID | Item | Evidence |
|----|------|----------|
| CH01-A | Repo truth map (backend) | `BACKEND-ARCHITECTURE-TRUTH-MAP-CHARTER-01.md` |
| CH01-B | Cross-platform ZIP rule verifier | `system/scripts/read-only/verify_handoff_zip_rules_readonly.php` (parity with `handoff/HandoffZipRules.ps1`) |
| CH01-C | Migration baseline report CLI (+ strict alignment, shared `MigrationBaseline`) | `system/scripts/read-only/verify_migration_baseline_readonly.php`, `system/core/app/MigrationBaseline.php` — full contract: `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` |
| CH01-D | Request-scope memo: `SessionAuth::user()` | `system/core/auth/SessionAuth.php` |
| CH01-E | Request-scope memo: `SettingsService::get` / `all` + branch→org lookup | `system/core/app/SettingsService.php` |
| CH01-F | Tenant safety inventory (read-only) | `TENANT-SAFETY-INVENTORY-CHARTER-01.md` |
| FND-PKG-01 | Enforced handoff ZIP gate + release checklist | `handoff/build-final-zip.ps1` invokes `verify_handoff_zip_rules_readonly.php` after PS ZIP scan (PLT-PKG-08 + FND-PKG-01); operator checklist `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` § PLT-PKG-08 |
| FND-MIG-02 | Migration baseline deploy gate + operator runbook | `run_migration_baseline_deploy_gate_01.php`; `migrate.php --verify-baseline` documented; checklist `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` § FND-MIG-02 |

---

## FOUNDATION-A2 — **CLOSED** (2026-03-31 skeleton → 2026-03-31 full PolicyAuthorizer, BIG-04)

**FOUNDATION-A2: Authorization Kernel** — skeleton installed in BIG-01; real `PolicyAuthorizer` installed in BIG-04.

| Item | Evidence |
|------|----------|
| `AuthorizerInterface`, `ResourceAction` (22 actions), `ResourceRef`, `AccessDecision`, `AuthorizationException` | `system/core/Kernel/Authorization/` |
| `PolicyAuthorizer` replaces `DenyAllAuthorizer` as `AuthorizerInterface` binding | `system/bootstrap.php`, `system/core/Kernel/Authorization/PolicyAuthorizer.php` |
| PolicyAuthorizer: FOUNDER full-allow, SUPPORT_ACTOR read-only, TENANT permission-based, all else deny | `system/core/Kernel/Authorization/PolicyAuthorizer.php` |
| Deny-by-default preserved — unmapped actions and unresolved contexts return DENY | `PolicyAuthorizer::ACTION_PERMISSION_MAP`, `decideForTenantPrincipal` |
| Explainable decisions — every `AccessDecision` carries a reason string | `PolicyAuthorizer` all decision paths |
| 79/79 BIG-04 verification assertions include PolicyAuthorizer coverage | `system/scripts/read-only/verify_big_04_appointments_migration_01.php` |

---

## FOUNDATION-A3 + A4 + A5 — **CLOSED** (2026-03-31)

**BIG-02: Data-Plane Lockdown + Media Pilot Rewrite** — pilot lane fully migrated.

| Item | Evidence |
|------|----------|
| Direct `db->fetchOne / fetchAll / query` removed from both pilot services for protected operations | `MarketingGiftCardTemplateService.php`, `ClientProfileImageService.php` |
| `BranchContext` removed from both services; replaced by `RequestContextHolder` + `TenantContext` | Both service constructors |
| 9 canonical TenantContext-scoped methods on `MarketingGiftCardTemplateRepository` | `system/modules/marketing/repositories/MarketingGiftCardTemplateRepository.php` |
| 4 canonical TenantContext-scoped methods on `ClientProfileImageRepository` | `system/modules/clients/repositories/ClientProfileImageRepository.php` |
| All canonical methods call `requireResolvedTenant()` — fail-closed | Both repository files |
| Id-only acquisition patterns eliminated from both services (verified by assertion) | `verify_big_02_pilot_lane_migration_01.php` |
| Accepted ROOT-01 purge/ref-count behavior preserved (`purgeOrphanMediaAssetIfUnreferenced`, `countOwnedMediaAssetReferences`) | Both files + verification |
| Bootstrap registrations updated to inject `RequestContextHolder` | `system/modules/bootstrap/register_marketing.php` |
| 77/77 pilot lane migration assertions pass | `system/scripts/read-only/verify_big_02_pilot_lane_migration_01.php` |

---

## FOUNDATION-A6 + A7 + A8 — **CLOSED** (2026-03-31)

**BIG-03: Mechanical Guardrails + Migration Map + Long-Horizon Platform Direction**

| Item | Evidence |
|------|----------|
| Guardrail 1 — service layer DB ban: CI script fails on `->fetchOne/fetchAll/query/insert/lastInsertId` in protected service files | `system/scripts/ci/guardrail_service_layer_db_ban.php` |
| Guardrail 2 — id-only repo API freeze: CI script fails on new non-allowlisted `int $branchId` public methods in protected repository files | `system/scripts/ci/guardrail_id_only_repo_api_freeze.php` |
| Both guardrails PASS on the migrated media pilot lane | `system/scripts/read-only/verify_big_03_guardrails_01.php` |
| Guardrail policy document (how to expand scope for A7 migration phases) | `system/docs/FOUNDATION-A6-GUARDRAILS-POLICY-01.md` |
| Migration map: PHASE-1 (Appointments), PHASE-2 (Online-booking), PHASE-3 (Sales), PHASE-4 (Client-owned resources) — all phases defined with blocking conditions | `docs/FOUNDATION-A7-MIGRATION-MAP-01.md` |
| Long-horizon platform direction: policy-centered modular monolith, RLS, observability, ReBAC (conditional), cell-based isolation (conditional) | `docs/FOUNDATION-A8-PLATFORM-DIRECTION-01.md` |
| Verification: 58/58 guardrail assertions pass | `system/scripts/read-only/verify_big_03_guardrails_01.php` |

---

## FOUNDATION-A7 PHASE-1 — **CLOSED** (2026-03-31)

**BIG-04: Authorization Core Completion + Appointments Phase-1 Migration + Drift Reconciliation**

| Item | Evidence |
|------|----------|
| `PolicyAuthorizer` installed as real `AuthorizerInterface` binding (replaces `DenyAllAuthorizer`) | `system/core/Kernel/Authorization/PolicyAuthorizer.php`, `system/bootstrap.php` |
| PolicyAuthorizer: FOUNDER full-allow, SUPPORT_ACTOR read-only, TENANT permission-map, deny-by-default | `PolicyAuthorizer::ACTION_PERMISSION_MAP`, all decision branches |
| `AppointmentRepository`: canonical `loadVisible(TenantContext, int)` + `loadForUpdate(TenantContext, int)` | `system/modules/appointments/repositories/AppointmentRepository.php` |
| `BlockedSlotRepository`: canonical `loadOwned(TenantContext, int)` | `system/modules/appointments/repositories/BlockedSlotRepository.php` |
| `WaitlistRepository`: canonical `loadOwned(TenantContext, int)` | `system/modules/appointments/repositories/WaitlistRepository.php` |
| `AppointmentService`: `BranchContext` removed; `RequestContextHolder` + canonical repo methods used | `system/modules/appointments/services/AppointmentService.php` |
| `BlockedSlotService`: `BranchContext` removed; `RequestContextHolder` + canonical repo methods used | `system/modules/appointments/services/BlockedSlotService.php` |
| `WaitlistService`: `BranchContext` removed; `RequestContextHolder` + canonical repo methods used | `system/modules/appointments/services/WaitlistService.php` |
| `AppointmentSeriesService`: `BranchContext` removed; explicit `TenantContext` branch comparison + `AccessDeniedException` | `system/modules/appointments/services/AppointmentSeriesService.php` |
| Bootstrap DI updated — all migrated services inject `RequestContextHolder` | `system/modules/bootstrap/register_appointments_online_contracts.php` |
| Guardrail 1 (service DB ban) expanded to Appointments domain; `WaitlistService` explicitly excepted for advisory locks | `system/scripts/ci/guardrail_service_layer_db_ban.php` |
| Guardrail 2 (id-only repo API freeze) expanded to all four Appointments repositories | `system/scripts/ci/guardrail_id_only_repo_api_freeze.php` |
| Drift from prior PLT-TNT-01 wave classified, absorbed, and integrated (not discarded blindly) | Audit performed; OrganizationRepositoryScope usage kept and extended |
| 79/79 verification assertions pass | `system/scripts/read-only/verify_big_04_appointments_migration_01.php` |

---

## LIVE (exactly one)

| ID | Item | Notes |
|----|------|-------|
| **FOUNDATION-A7 PHASE-2** | Online-booking domain migration | **Successor to PHASE-1 (CLOSED 2026-03-31).** Migrate online-booking services and repositories to TenantContext + canonical scoped API. Follow BIG-04 pattern. Expand guardrails to online-booking scope. See `docs/FOUNDATION-A7-MIGRATION-MAP-01.md`. |

---

## PARKED / NEXT (exactly one — do not start until LIVE task closes or is explicitly swapped)

| ID | Item | Notes |
|----|------|-------|
| **FOUNDATION-A7 PHASE-3** | Sales domain migration | **Successor to PHASE-2.** Migrate sales services and repositories. Blocked on PHASE-2 complete. |

---

## FOUNDATION ROADMAP (A1–A8) — 2026 Foundation Plan

Full ordered roadmap. **FOUNDATION-A1..A7 PHASE-1** are CLOSED. **FOUNDATION-A7 PHASE-2** is LIVE. Remaining tasks are **PLANNED** inventory — not in-progress, not concurrent.

| Order | ID | Name | Status | Notes |
|-------|----|------|--------|-------|
| 1 | **FOUNDATION-A1** | TenantContext Kernel | **CLOSED** (2026-03-31) | Immutable TenantContext / RequestContext: actor_id, organization_id, branch_id, role/principal class, support/impersonation mode, assurance level. Resolved once at entry, stored in RequestContextHolder. Fail closed. Authorization kernel skeleton (DenyAllAuthorizer, AuthorizerInterface, ResourceAction). 74/74 verification assertions pass. |
| 2 | **FOUNDATION-A2** | Authorization Kernel | **CLOSED** (2026-03-31, full PolicyAuthorizer BIG-04) | Skeleton installed in BIG-01 (DenyAllAuthorizer). Full `PolicyAuthorizer` installed in BIG-04: FOUNDER full-allow, SUPPORT_ACTOR read-only, TENANT permission-map, deny-by-default preserved. 79/79 verification assertions pass. |
| 3 | **FOUNDATION-A3** | Service Layer DB Ban | **CLOSED** (2026-03-31) | Direct `db->fetchOne / fetchAll / query` removed from `MarketingGiftCardTemplateService` and `ClientProfileImageService` for protected operations. DB retained in services for transaction management only. |
| 4 | **FOUNDATION-A4** | Canonical Scoped Repository API | **CLOSED** (2026-03-31) | 9 canonical methods on `MarketingGiftCardTemplateRepository` (`loadVisibleTemplate`, `loadVisibleImage`, `loadSelectableImageForTemplate`, `loadUploadedMediaAssetInScope`, `mutateUpdateTemplate`, `mutateArchiveTemplate`, `deleteOwnedImage`, `clearArchivedTemplateImageRef`, `countOwnedMediaAssetReferences`) + 4 canonical methods on `ClientProfileImageRepository` (`loadVisibleImage`, `loadVisibleEnrichedImage`, `loadUploadedMediaAssetInScope`, `deleteOwned`). All take TenantContext as first param. All call requireResolvedTenant() — fail-closed. |
| 5 | **FOUNDATION-A5** | Media Pilot Rewrite | **CLOSED** (2026-03-31) | `ClientProfileImageService` and `MarketingGiftCardTemplateService` fully migrated to TenantContext + canonical scoped repository API. BranchContext replaced. Id-only acquisition patterns eliminated. Purge/ref-count behavior preserved. 77/77 verification assertions pass. |
| 6 | **FOUNDATION-A6** | Mechanical Guardrails | **CLOSED** (2026-03-31) | `guardrail_service_layer_db_ban.php`: fails on direct DB data access in protected services. `guardrail_id_only_repo_api_freeze.php`: fails on new non-allowlisted id-only repo methods in protected repos. Both PASS on media pilot lane. Policy documented in `FOUNDATION-A6-GUARDRAILS-POLICY-01.md`. |
| 7 | **FOUNDATION-A7** | Migration Map | **CLOSED** (2026-03-31) | 4-phase migration order defined: PHASE-1 Appointments, PHASE-2 Online-booking, PHASE-3 Sales, PHASE-4 Client-owned resources. Each phase has migration goal, blocking condition, and out-of-scope definition. `docs/FOUNDATION-A7-MIGRATION-MAP-01.md`. PHASE-1 CLOSED (BIG-04, 2026-03-31). PHASE-2 is now LIVE. |
| 8 | **FOUNDATION-A8** | Long-Horizon Platform Direction | **CLOSED** (2026-03-31) | Policy-centered modular monolith target documented. Future directions (RLS, observability, ReBAC, cell isolation) documented with preconditions. Explicit NOT-doing list. `docs/FOUNDATION-A8-PLATFORM-DIRECTION-01.md`. |

---

## ARCHIVED / SUPERSEDED BY ARCHITECTURE RESET (2026-03-31)

These items were the prior active roadmap. They are retained as **sealed evidence** of work completed. They are **not** active roadmap items. The ROOT-01 marketing/media purge closure proof, SHA-pinned truth audit proof, and related verifier/docs artifacts are historical proof only.

| ID | Item | Archived Reason |
|----|------|-----------------|
| **PLT-TNT-01** | Universal tenant fail-closed / mechanical repository closure (ROOT-01 id-only closure wave) | **ARCHIVED / SUPERSEDED BY ARCHITECTURE RESET 2026-03-31.** This task drove incremental module-by-module ROOT-01 closure via wave-style repository patching (appointments, inventory, memberships, notifications, packages, sales, services-resources, settings, staff). That approach is superseded by FOUNDATION-A1..A7 which installs a kernel-level TenantContext and canonical scoped repository API instead of continuing scattered hotspot patches. Evidence sealed in: `docs/PLT-TNT-01-root-01-id-only-closure-wave.md`, `FOUNDATION-TENANT-REPOSITORY-CLOSURE-*` audit docs, `system/scripts/read-only/verify_root_01_*` verifiers. Latest closed closure: **CLOSURE-24** (membership invoice-plane helpers). |
| — | availability/public-booking cluster closure wave | **ARCHIVED** — sub-task of PLT-TNT-01. Wave-style closure of `AvailabilityService` and `PublicBookingService` cluster. Superseded by FOUNDATION-A1..A7. |
| — | id-only closure wave (follow-on cross-module patches) | **ARCHIVED** — sub-task of PLT-TNT-01. Scattered verifier-first patch waves across appointments / inventory / memberships / notifications / packages / sales / services-resources / settings / staff. Superseded by FOUNDATION-A1..A7. |
| — | Next-best-task continuation of PLT-TNT-01 wave pattern | **ARCHIVED** — any task whose only purpose was to continue the old wave architecture by patching more hotspot modules individually. Superseded. |

---

## DEFERRED (later backbone phases — not affected by architecture reset)

| ID | Item | Notes |
|----|------|-------|
| **PLT-Q-01** | Unified async / queue control-plane | **Phase 2** entry — deferred, not superseded. Do not implement while FOUNDATION-A1..A7 is the active spine. Rotate per policy when foundation phases complete. |

---

## DROPPED / OBSOLETE (for this queue)

| ID | Item | Reason |
|----|------|--------|
| — | Duplicate "foundation hardening" bullets scattered in multiple audit docs | **Superseded** by this file + truth map + backbone plan; source docs remain historical |

---

## Related canonical references

- `ROOT-CAUSE-REGISTER-01.md` — **ROOT-01**–**ROOT-05** recurring backbone families; tie LIVE slices and inventory rows to **ROOT** ids  
- `TASK-STATE-MATRIX.md` — **full** status inventory; **`OPEN` ≠ LIVE** (see matrix header)  
- `BACKBONE-CLOSURE-MASTER-PLAN-01.md` — phase order, Phase 0 **CLOSED**, architecture reset note  
- `DEFERRED-AND-HISTORICAL-TASK-REGISTRY-01.md` — deferred work + Phase 1 items **not** in the live charter  
- `ZIP-TRUTH-RECONCILIATION-CHECKPOINT-01.md` — ZIP/build/migrate checkpoint  
- `FOUNDATION-HARDENING-WAVE-REPAIR-CLOSURE-OPS.md` — prior wave closure truth  
- `ORGANIZATION-SCOPED-REPOSITORY-COVERAGE-MATRIX-FOUNDATION-12.md` — repo vs F-11 matrix  
- `REPO-CLEANUP-NOTES.md` — env / ZIP / local tree policy  
