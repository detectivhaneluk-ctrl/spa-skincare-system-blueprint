<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 / ROOT-01 — targeted closure proof for the appointment + settings catalog + membership-definition
 * + services-resources catalog + staff schedule/break + staff-group + notifications + package-usage wave.
 *
 * Usage:
 *   php system/scripts/read-only/verify_root_01_id_only_closure_wave_plt_tnt_01.php
 */

$root = dirname(__DIR__, 3);
$system = $root . '/system';
$docs = $root . '/docs';

/**
 * @return string
 */
function readOrFail(string $path): string
{
    $src = @file_get_contents($path);
    if (!is_string($src) || $src === '') {
        fwrite(STDERR, "FAIL: unreadable file {$path}\n");
        exit(1);
    }

    return $src;
}

$appointmentRepo = readOrFail($system . '/modules/appointments/repositories/AppointmentRepository.php');
$seriesRepo = readOrFail($system . '/modules/appointments/repositories/AppointmentSeriesRepository.php');
$waitlistRepo = readOrFail($system . '/modules/appointments/repositories/WaitlistRepository.php');
$blockedRepo = readOrFail($system . '/modules/appointments/repositories/BlockedSlotRepository.php');
$appointmentSvc = readOrFail($system . '/modules/appointments/services/AppointmentService.php');
$seriesSvc = readOrFail($system . '/modules/appointments/services/AppointmentSeriesService.php');
$appointmentsBootstrapA = readOrFail($system . '/modules/bootstrap/register_appointments_documents_notifications.php');
$appointmentsBootstrapB = readOrFail($system . '/modules/bootstrap/register_appointments_online_contracts.php');
$paymentRepo = readOrFail($system . '/modules/sales/repositories/PaymentMethodRepository.php');
$paymentSvc = readOrFail($system . '/modules/sales/services/PaymentMethodService.php');
$paymentCtrl = readOrFail($system . '/modules/settings/controllers/PaymentMethodsController.php');
$vatRepo = readOrFail($system . '/modules/sales/repositories/VatRateRepository.php');
$vatSvc = readOrFail($system . '/modules/sales/services/VatRateService.php');
$vatCtrl = readOrFail($system . '/modules/settings/controllers/VatRatesController.php');
$membershipRepo = readOrFail($system . '/modules/memberships/Repositories/MembershipDefinitionRepository.php');
$membershipSvc = readOrFail($system . '/modules/memberships/Services/MembershipService.php');
$serviceCategoryRepo = readOrFail($system . '/modules/services-resources/repositories/ServiceCategoryRepository.php');
$equipmentRepo = readOrFail($system . '/modules/services-resources/repositories/EquipmentRepository.php');
$roomRepo = readOrFail($system . '/modules/services-resources/repositories/RoomRepository.php');
$staffGroupRepo = readOrFail($system . '/modules/staff/repositories/StaffGroupRepository.php');
$staffBreakRepo = readOrFail($system . '/modules/staff/repositories/StaffBreakRepository.php');
$staffScheduleRepo = readOrFail($system . '/modules/staff/repositories/StaffScheduleRepository.php');
$notificationRepo = readOrFail($system . '/modules/notifications/repositories/NotificationRepository.php');
$packageUsageRepo = readOrFail($system . '/modules/packages/repositories/PackageUsageRepository.php');
$bootstrapServicesResources = readOrFail($system . '/modules/bootstrap/register_services_resources.php');
$bootstrapStaff = readOrFail($system . '/modules/bootstrap/register_staff.php');
$bootstrapPackages = readOrFail($system . '/modules/bootstrap/register_packages.php');
$productBrandRepo = readOrFail($system . '/modules/inventory/repositories/ProductBrandRepository.php');
$productBrandSvc = readOrFail($system . '/modules/inventory/services/ProductBrandService.php');
$productCategoryRepo = readOrFail($system . '/modules/inventory/repositories/ProductCategoryRepository.php');
$productCategorySvc = readOrFail($system . '/modules/inventory/services/ProductCategoryService.php');
$doc = readOrFail($docs . '/PLT-TNT-01-root-01-id-only-closure-wave.md');

$checks = [];

// Appointment cluster checks
$checks['AppointmentRepository exposes scoped findForUpdate'] =
    str_contains($appointmentRepo, 'function findForUpdate(')
    && str_contains($appointmentRepo, "branchColumnOwnedByResolvedOrganizationExistsClause('a')")
    && str_contains($appointmentRepo, "WHERE a.id = ? AND a.deleted_at IS NULL");

$checks['AppointmentService removed raw id-only appointment FOR UPDATE SQL'] =
    !str_contains($appointmentSvc, 'SELECT * FROM appointments WHERE id = ? AND deleted_at IS NULL FOR UPDATE');

// BIG-04 residual: AppointmentRepository::findForUpdate() exists and is tenant-scoped, but AppointmentService
// mutation paths still call repo->find($id) (non-locking read) rather than repo->findForUpdate($id).
// The raw FOR UPDATE SQL is gone (above check passes); this check tracks the remaining migration gap.
// Until resolved, AppointmentService mutations do not use the org-scoped row lock.
$checks['AppointmentService mutation paths migrated to repo->findForUpdate($id) [BIG-04 residual]'] =
    substr_count($appointmentSvc, 'repo->findForUpdate($id)') >= 5;

$checks['AppointmentService scopes staff and service lock/read SQL'] =
    str_contains($appointmentSvc, "branchColumnOwnedByResolvedOrganizationExistsClause('st')")
    && str_contains($appointmentSvc, "branchColumnOwnedByResolvedOrganizationExistsClause('svc')")
    && !str_contains($appointmentSvc, 'SELECT id FROM staff WHERE id = ? AND deleted_at IS NULL AND is_active = 1 FOR UPDATE')
    && !str_contains($appointmentSvc, 'SELECT id, branch_id, is_active, deleted_at FROM services WHERE id = ? FOR UPDATE');

$checks['AppointmentSeriesRepository scopes id read lock mutate family'] =
    str_contains($seriesRepo, 'OrganizationRepositoryScope')
    && str_contains($seriesRepo, "branchColumnOwnedByResolvedOrganizationExistsClause('asr')")
    && str_contains($seriesRepo, 'WHERE asr.id = ?')
    && str_contains($seriesRepo, 'UPDATE appointment_series asr SET');

$checks['AppointmentSeriesService removed raw id-only appointment and entity lookups'] =
    str_contains($seriesSvc, "clientProfileOrgMembershipExistsClause('c')")
    && str_contains($seriesSvc, "branchColumnOwnedByResolvedOrganizationExistsClause('s')")
    && str_contains($seriesSvc, "branchColumnOwnedByResolvedOrganizationExistsClause('st')")
    && !str_contains($seriesSvc, 'SELECT id, series_id, branch_id, status, start_at FROM appointments WHERE id = ? AND deleted_at IS NULL')
    && !str_contains($seriesSvc, 'SELECT id FROM clients WHERE id = ? AND deleted_at IS NULL');

$checks['WaitlistRepository id read and mutate paths are tenant-scoped'] =
    str_contains($waitlistRepo, "taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('w')")
    && str_contains($waitlistRepo, 'WHERE w.id = ? AND (')
    && str_contains($waitlistRepo, 'UPDATE appointment_waitlist w SET');

$checks['BlockedSlotRepository id read and soft delete are tenant-scoped'] =
    str_contains($blockedRepo, "taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('bs')")
    && str_contains($blockedRepo, 'WHERE bs.id = ? AND bs.deleted_at IS NULL AND (')
    && str_contains($blockedRepo, 'UPDATE appointment_blocked_slots bs SET deleted_at = NOW()');

$checks['BlockedSlotRepository all FROM clauses carry bs alias (no unaliased table exposing appendBlockedSlotBranchTenantClause to column-not-found)'] =
    (substr_count($blockedRepo, 'FROM appointment_blocked_slots') === substr_count($blockedRepo, 'FROM appointment_blocked_slots bs'))
    && substr_count($blockedRepo, 'FROM appointment_blocked_slots bs') >= 3
    && !preg_match('/FROM\s+appointment_blocked_slots\s+WHERE/', $blockedRepo);

$checks['Appointments bootstrap wires new scope dependencies'] =
    str_contains($appointmentsBootstrapA, 'new \Modules\Appointments\Repositories\AppointmentSeriesRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class))')
    && str_contains($appointmentsBootstrapA, 'new \Modules\Appointments\Repositories\WaitlistRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class))')
    && str_contains($appointmentsBootstrapB, '$c->get(\Core\Organization\OrganizationRepositoryScope::class))');

// PaymentMethod / VatRate checks
$checks['PaymentMethodRepository only exposes explicit global catalog by-id methods'] =
    str_contains($paymentRepo, 'function findGlobalCatalogMethodInResolvedTenantById(')
    && str_contains($paymentRepo, 'function updateGlobalCatalogMethodInResolvedTenantById(')
    && str_contains($paymentRepo, 'function archiveGlobalCatalogMethodInResolvedTenantById(')
    && !str_contains($paymentRepo, 'function getById(')
    && !preg_match('/function\s+update\s*\(/', $paymentRepo)
    && !preg_match('/function\s+archive\s*\(/', $paymentRepo);

$checks['Payment methods settings path uses explicit global catalog service methods'] =
    str_contains($paymentSvc, 'function getGlobalCatalogMethodForSettingsAdmin(')
    && str_contains($paymentSvc, 'function updateGlobalCatalogMethodForSettingsAdmin(')
    && str_contains($paymentSvc, 'function archiveGlobalCatalogMethodForSettingsAdmin(')
    && str_contains($paymentCtrl, 'getGlobalCatalogMethodForSettingsAdmin(')
    && str_contains($paymentCtrl, 'updateGlobalCatalogMethodForSettingsAdmin(')
    && str_contains($paymentCtrl, 'archiveGlobalCatalogMethodForSettingsAdmin(');

$checks['VatRateRepository splits tenant runtime read from explicit global catalog control-plane methods'] =
    str_contains($vatRepo, 'function findTenantVisibleRateById(')
    && str_contains($vatRepo, 'function findGlobalCatalogRateInResolvedTenantById(')
    && str_contains($vatRepo, 'function updateGlobalCatalogRateInResolvedTenantById(')
    && str_contains($vatRepo, 'function archiveGlobalCatalogRateInResolvedTenantById(')
    && !preg_match('/function\s+find\s*\(/', $vatRepo)
    && !preg_match('/function\s+update\s*\(/', $vatRepo)
    && !preg_match('/function\s+archive\s*\(/', $vatRepo);

$checks['VAT settings path uses explicit global catalog service methods without touching invoice math path'] =
    str_contains($vatSvc, 'function getGlobalCatalogRateForSettingsAdmin(')
    && str_contains($vatSvc, 'function updateGlobalCatalogRateForSettingsAdmin(')
    && str_contains($vatSvc, 'function archiveGlobalCatalogRateForSettingsAdmin(')
    && str_contains($vatSvc, 'findTenantVisibleRateById(')
    && str_contains($vatCtrl, 'getGlobalCatalogRateForSettingsAdmin(')
    && str_contains($vatCtrl, 'updateGlobalCatalogRateForSettingsAdmin(')
    && str_contains($vatCtrl, 'archiveGlobalCatalogRateForSettingsAdmin(');

// MembershipDefinition checks
$checks['MembershipDefinitionRepository tenant update is explicit and generic id-only update is removed'] =
    str_contains($membershipRepo, 'function updateInTenantScope(')
    && str_contains($membershipRepo, 'function updateForControlPlaneById(')
    && str_contains($membershipRepo, 'function softDeleteForControlPlaneById(')
    && !preg_match('/function\s+update\s*\(/', $membershipRepo)
    && !preg_match('/function\s+softDelete\s*\(/', $membershipRepo);

$checks['MembershipService uses explicit tenant-scoped definition update'] =
    str_contains($membershipSvc, 'definitions->updateInTenantScope(')
    && !str_contains($membershipSvc, 'definitions->update(');

// Services-resources catalog checks
$checks['ServiceCategoryRepository find is tenant-scoped with taxonomyCatalogUnion clause'] =
    str_contains($serviceCategoryRepo, 'OrganizationRepositoryScope')
    && str_contains($serviceCategoryRepo, "taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('sc')")
    && str_contains($serviceCategoryRepo, "WHERE sc.id = ? AND sc.deleted_at IS NULL AND (")
    && !str_contains($serviceCategoryRepo, "'SELECT * FROM service_categories WHERE id = ?'")
    && str_contains($serviceCategoryRepo, "UPDATE service_categories sc SET")
    && str_contains($serviceCategoryRepo, "WHERE sc.id = ? AND (");

$checks['EquipmentRepository find/update/softDelete are tenant-scoped'] =
    str_contains($equipmentRepo, 'OrganizationRepositoryScope')
    && str_contains($equipmentRepo, "taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('eq')")
    && str_contains($equipmentRepo, "WHERE eq.id = ? AND eq.deleted_at IS NULL AND (")
    && !str_contains($equipmentRepo, "'SELECT * FROM equipment WHERE id = ?'")
    && str_contains($equipmentRepo, "UPDATE equipment eq SET");

$checks['RoomRepository find/update/softDelete are tenant-scoped'] =
    str_contains($roomRepo, 'OrganizationRepositoryScope')
    && str_contains($roomRepo, "taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('r')")
    && str_contains($roomRepo, "WHERE r.id = ? AND r.deleted_at IS NULL AND (")
    && !str_contains($roomRepo, "'SELECT * FROM rooms WHERE id = ?'")
    && str_contains($roomRepo, "UPDATE rooms r SET");

$checks['Bootstrap wires OrganizationRepositoryScope into ServiceCategoryRepository RoomRepository EquipmentRepository'] =
    str_contains($bootstrapServicesResources, 'ServiceCategoryRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class))')
    && str_contains($bootstrapServicesResources, 'RoomRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class))')
    && str_contains($bootstrapServicesResources, 'EquipmentRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class))');

// StaffGroup checks
$checks['StaffGroupRepository find is tenant-scoped with taxonomyCatalogUnion clause'] =
    str_contains($staffGroupRepo, "taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('sg')")
    && str_contains($staffGroupRepo, "WHERE sg.id = ? AND (")
    && str_contains($staffGroupRepo, "UPDATE staff_groups sg SET")
    && str_contains($staffGroupRepo, "WHERE sg.id = ? AND (")
    && !str_contains($staffGroupRepo, "'SELECT * FROM staff_groups WHERE id = ?'");

// Staff schedule/break checks
$checks['StaffBreakRepository find/update/delete are tenant-scoped via staff JOIN'] =
    str_contains($staffBreakRepo, 'OrganizationRepositoryScope')
    && str_contains($staffBreakRepo, "branchColumnOwnedByResolvedOrganizationExistsClause('s')")
    && str_contains($staffBreakRepo, 'INNER JOIN staff s ON s.id = sb.staff_id AND s.deleted_at IS NULL')
    && str_contains($staffBreakRepo, 'WHERE sb.id = ?')
    && !str_contains($staffBreakRepo, "'SELECT * FROM staff_breaks WHERE id = ?'");

$checks['StaffScheduleRepository find/update/delete are tenant-scoped via staff JOIN'] =
    str_contains($staffScheduleRepo, 'OrganizationRepositoryScope')
    && str_contains($staffScheduleRepo, "branchColumnOwnedByResolvedOrganizationExistsClause('s')")
    && str_contains($staffScheduleRepo, 'INNER JOIN staff s ON s.id = ss.staff_id AND s.deleted_at IS NULL')
    && str_contains($staffScheduleRepo, 'WHERE ss.id = ?')
    && !str_contains($staffScheduleRepo, "'SELECT * FROM staff_schedules WHERE id = ?'");

$checks['Bootstrap wires OrganizationRepositoryScope into StaffBreakRepository and StaffScheduleRepository'] =
    str_contains($bootstrapStaff, 'StaffBreakRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class))')
    && str_contains($bootstrapStaff, 'StaffScheduleRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class))');

// Notification checks
$checks['NotificationRepository find is tenant-scoped with notificationTenantWideBranchOrGlobalNullClause'] =
    str_contains($notificationRepo, 'notificationTenantWideBranchOrGlobalNullClause')
    && str_contains($notificationRepo, "WHERE n.id = ? AND (")
    && !str_contains($notificationRepo, "'SELECT * FROM notifications WHERE id = ?'");

// PackageUsage checks
$checks['PackageUsageRepository find is tenant-scoped via client_packages JOIN'] =
    str_contains($packageUsageRepo, 'OrganizationRepositoryScope')
    && str_contains($packageUsageRepo, "branchColumnOwnedByResolvedOrganizationExistsClause('cp')")
    && str_contains($packageUsageRepo, 'INNER JOIN client_packages cp ON cp.id = pu.client_package_id')
    && str_contains($packageUsageRepo, 'WHERE pu.id = ?')
    && !str_contains($packageUsageRepo, "'SELECT * FROM package_usages WHERE id = ?'");

$checks['Bootstrap wires OrganizationRepositoryScope into PackageUsageRepository'] =
    str_contains($bootstrapPackages, 'PackageUsageRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class))');

// ProductBrand / ProductCategory scoped mutation checks
$checks['ProductBrandRepository exposes updateInResolvedTenantCatalogScope and bare update is deprecated'] =
    str_contains($productBrandRepo, 'function updateInResolvedTenantCatalogScope(')
    && str_contains($productBrandRepo, "taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pb')")
    && str_contains($productBrandRepo, 'UPDATE product_brands pb SET')
    && str_contains($productBrandRepo, '@deprecated Id-only WHERE');

$checks['ProductBrandService update calls updateInResolvedTenantCatalogScope not bare update'] =
    str_contains($productBrandSvc, '->updateInResolvedTenantCatalogScope(')
    && !preg_match('/->update\s*\(\s*\$id\s*,\s*\$data\s*\)/', $productBrandSvc);

$checks['ProductCategoryRepository exposes updateInResolvedTenantCatalogScope and bare update is deprecated'] =
    str_contains($productCategoryRepo, 'function updateInResolvedTenantCatalogScope(')
    && str_contains($productCategoryRepo, "taxonomyCatalogUnionBranchInOrgOrNullGlobalOrgHasLiveBranchClause('pc')")
    && str_contains($productCategoryRepo, 'UPDATE product_categories pc SET')
    && str_contains($productCategoryRepo, '@deprecated Id-only WHERE');

$checks['ProductCategoryService update calls updateInResolvedTenantCatalogScope not bare update'] =
    str_contains($productCategorySvc, '->updateInResolvedTenantCatalogScope(')
    && !preg_match('/->update\s*\(\s*\$id\s*,\s*\$data\s*\)/', $productCategorySvc);

// Doc checks
$checks['Repo truth doc records canonical vs explicit paths and residuals'] =
    str_contains($doc, 'Canonical tenant-scoped closures:')
    && str_contains($doc, 'Explicit control-plane/global catalog paths:')
    && str_contains($doc, 'Explicit exceptions (control-plane/repair/worker')
    && str_contains($doc, 'ServiceCategoryRepository')
    && str_contains($doc, 'StaffBreakRepository')
    && str_contains($doc, 'NotificationRepository')
    && str_contains($doc, 'PackageUsageRepository')
    && str_contains($doc, 'ProductBrandRepository::updateInResolvedTenantCatalogScope()')
    && str_contains($doc, 'ProductCategoryRepository::updateInResolvedTenantCatalogScope()');

$failed = [];
foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}

echo PHP_EOL . "verify_root_01_id_only_closure_wave_plt_tnt_01: OK\n";
exit(0);
