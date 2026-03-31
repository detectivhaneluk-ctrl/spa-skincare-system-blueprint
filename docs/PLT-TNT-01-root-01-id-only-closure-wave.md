# PLT-TNT-01 ROOT-01 Closure Wave

> **ARCHIVED / SEALED EVIDENCE — SUPERSEDED BY ARCHITECTURE RESET 2026-03-31**  
> This document records completed work from the PLT-TNT-01 ROOT-01 id-only closure wave. It is **not** an active roadmap item.  
> The active roadmap is now **FOUNDATION-A1..A7** — see `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`.  
> This file is retained as **sealed proof** of closures made during the wave. Do not treat any residual items here as open work to continue.  
> **Active roadmap authority:** `system/docs/FOUNDATION-ACTIVE-BACKLOG-CHARTER-01.md`

Scope: `ROOT-01` eradication for the high-risk appointment, settings-catalog, membership-definition, services-resources catalog, staff schedule/break, staff-group, notifications, and package-usage hotspots touched in this wave.

Canonical tenant-scoped closures:
- `AppointmentRepository::findForUpdate()` now carries the same tenant predicate as `find()` / `update()` / `softDelete()`.
- `AppointmentSeriesRepository::{find,findForUpdate,update}` now scope on intrinsic `appointment_series.branch_id` ownership in the resolved tenant org.
- `AppointmentSeriesRepository::{listExistingStartAts,countMaterializedOccurrences,listCancellableAppointmentIds}` now inherit the same series tenant anchor instead of trusting a bare `series_id`.
- `WaitlistRepository::{find,update}` now require tenant-visible branch/global-null membership in SQL.
- `BlockedSlotRepository::{find,softDelete}` now require tenant-visible branch/global-null membership in SQL.
- `MembershipDefinitionRepository::updateInTenantScope()` is the tenant-default mutation path for branch-owned membership definitions.
- `ServiceCategoryRepository::{find,update,softDelete}` now use `taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause` for intrinsic org scope (was bare id-only).
- `EquipmentRepository::{find,update,softDelete}` now use `taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause` for intrinsic org scope (was bare id-only).
- `RoomRepository::{find,update,softDelete}` now use `taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause` for intrinsic org scope (was bare id-only).
- `StaffGroupRepository::{find,update,softDelete}` now use `taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause` for intrinsic org scope (was bare id-only with caller-enforced scope).
- `StaffBreakRepository::{find,update,delete}` now scope via INNER JOIN on `staff` with `branchColumnOwnedByResolvedOrganizationExistsClause` (table has no branch_id column; anchor is the staff FK).
- `StaffScheduleRepository::{find,update,delete}` same JOIN-based scope as StaffBreakRepository.
- `NotificationRepository::find()` now uses `notificationTenantWideBranchOrGlobalNullClause` (was bare id-only with HTTP post-load PHP visibility check).
- `PackageUsageRepository::find()` now scopes via INNER JOIN on `client_packages` with `branchColumnOwnedByResolvedOrganizationExistsClause` (was bare id-only, called from HTTP `reversePackageUsage` path).
- `ProductBrandRepository::updateInResolvedTenantCatalogScope()` is the tenant-default mutation path; services call this instead of the now-deprecated bare `update()`.
- `ProductCategoryRepository::updateInResolvedTenantCatalogScope()` is the tenant-default mutation path; services call this instead of the now-deprecated bare `update()`.

Bootstrap registrations updated:
- `register_services_resources.php`: `ServiceCategoryRepository`, `RoomRepository`, `EquipmentRepository` now inject `OrganizationRepositoryScope`.
- `register_staff.php`: `StaffBreakRepository`, `StaffScheduleRepository` now inject `OrganizationRepositoryScope`.
- `register_packages.php`: `PackageUsageRepository` now injects `OrganizationRepositoryScope`.

Explicit control-plane/global catalog paths:
- `PaymentMethodRepository::{findGlobalCatalogMethodInResolvedTenantById,updateGlobalCatalogMethodInResolvedTenantById,archiveGlobalCatalogMethodInResolvedTenantById}`
- `VatRateRepository::{findGlobalCatalogRateInResolvedTenantById,updateGlobalCatalogRateInResolvedTenantById,archiveGlobalCatalogRateInResolvedTenantById}`

Tenant runtime read retained with explicit naming:
- `VatRateRepository::findTenantVisibleRateById()` is the tenant runtime by-id read used for invoice math.

Explicit exceptions (control-plane/repair/worker — not tenant-default HTTP):
- `MembershipDefinitionRepository::updateForControlPlaneById()` / `softDeleteForControlPlaneById()` — explicitly named.
- `MembershipBillingCycleRepository::updateForRepair()` / `findForRepair()` / `findForUpdateForRepair()` — explicitly named.
- `MembershipSaleRepository::updateForRepair()` / `findForRepair()` — explicitly named.
- `ClientMembershipRepository::updateForRepairById()` / `findForUpdateForRepair()` — explicitly named.
- `ProductRepository::update()` / `softDelete()` — class 4, documented "tooling/migration only", read-only gate FND-TNT-21.
- `ProductBrandRepository::updateInResolvedTenantCatalogScope()` is the tenant-default mutation path; bare `update()` is `@deprecated` (tooling only), `softDelete()` is `@deprecated` (tooling only).
- `ProductCategoryRepository::updateInResolvedTenantCatalogScope()` is the tenant-default mutation path; bare `update()` is `@deprecated` (tooling only), `softDelete()` is `@deprecated` (tooling only).
- `SupplierRepository::find()` / `update()` / `softDelete()` — `@deprecated`; HTTP paths use scoped variants exclusively.
- `InventoryCountRepository::find()` / `StockMovementRepository::find()` — `@deprecated`, no HTTP callers.
- `OutboundNotificationMessageRepository::find()` — worker/dispatch path only, no tenant HTTP callers.
- `FounderAccessManagementService` inline SQL — control-plane user/org management.
- `OrganizationRegistryMutationRepository` — control-plane org lifecycle.
- `ControlPlaneTotpUserRepository` — control-plane TOTP.
- `TenantUserProvisioningService` — control-plane user provisioning.
- `ClientMergeJobService::claimSpecificQueuedJob()` — internal worker/background job.
- `PublicBookingManageTokenRepository` — booking manage token (not tenant PII).

Service/controller contract updates:
- `AppointmentService` no longer locks `appointments` with raw id-only SQL; it delegates to the repository lock path.
- Settings controllers now use explicitly named global catalog service methods instead of ambiguous `getById` / `update` / `archive`.
- `MembershipService` now updates definitions through `MembershipDefinitionRepository::updateInTenantScope()`.

Residual open work inside ROOT-01:
- All known tenant-default reachable ROOT-01 unsafe instances have been resolved in this wave.
- Explicit repair/control-plane methods remain allowed only where their naming states that contract.
- Some class-4 bare mutations (ProductBrandRepository::update, ProductCategoryRepository::update) are now deprecated; scoped variants `updateInResolvedTenantCatalogScope` are used by all HTTP service paths.

Continuation status for the appointments/public-booking cluster:
- `AvailabilityService::{getActiveService,getActiveStaff,getServiceTiming}` now resolve tenant visibility in SQL before returning rows.
- Public booking slot and create paths use `getActiveServiceForScope()` / `getActiveStaffForScope()` only.
- Appointment-side helpers updated in this cluster: `BlockedSlotService::create()` no longer validates staff by bare id, and `AppointmentController::staffSelectOptionsForAppointment()` no longer performs a raw unscoped staff lookup.
- `PublicBookingService::validateBranch()` now resolves live branch + active organization in one SQL query instead of loading branch first and rejecting suspension later in PHP.

Cluster residuals after continuation:
- No unresolved `ROOT-01` issue is expected to remain inside the touched `AvailabilityService` + public-booking cluster.
- Wider repo cleanup is complete as of this wave — all tenant-default reachable ROOT-01 unsafe instances are resolved.
