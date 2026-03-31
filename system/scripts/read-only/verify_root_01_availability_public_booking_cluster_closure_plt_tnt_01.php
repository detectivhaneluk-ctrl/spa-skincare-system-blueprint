<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 / ROOT-01 — appointments + public-booking availability cluster closure proof.
 *
 * Usage:
 *   php system/scripts/read-only/verify_root_01_availability_public_booking_cluster_closure_plt_tnt_01.php
 */

$root = dirname(__DIR__, 3);
$system = $root . '/system';
$docs = $root . '/docs';

/**
 * @return string
 */
function src(string $path): string
{
    $text = @file_get_contents($path);
    if (!is_string($text) || $text === '') {
        fwrite(STDERR, "FAIL: unreadable file {$path}\n");
        exit(1);
    }

    return $text;
}

$availability = src($system . '/modules/appointments/services/AvailabilityService.php');
$publicBooking = src($system . '/modules/online-booking/services/PublicBookingService.php');
$appointmentController = src($system . '/modules/appointments/controllers/AppointmentController.php');
$blockedSlotService = src($system . '/modules/appointments/services/BlockedSlotService.php');
$appointmentService = src($system . '/modules/appointments/services/AppointmentService.php');
$appointmentSeriesService = src($system . '/modules/appointments/services/AppointmentSeriesService.php');
$waitlistService = src($system . '/modules/appointments/services/WaitlistService.php');
$bootstrap = src($system . '/modules/bootstrap/register_appointments_online_contracts.php');
$doc = src($docs . '/PLT-TNT-01-root-01-id-only-closure-wave.md');

$checks = [];

$checks['AvailabilityService active service lookup is SQL-scoped before return'] =
    str_contains($availability, 'productCatalogUnionBranchRowOrNullGlobalFromOperationBranchClause')
    && str_contains($availability, 'resolvedTenantOrganizationHasLiveBranchExistsClause')
    && !str_contains($availability, 'SELECT id, duration_minutes, branch_id FROM services WHERE id = ? AND deleted_at IS NULL AND is_active = 1');

$checks['AvailabilityService active staff lookup is SQL-scoped before return'] =
    str_contains($availability, 'staffSelectableAtOperationBranchTenantClause')
    && str_contains($availability, "WHERE st.id = ? AND st.deleted_at IS NULL AND st.is_active = 1 AND st.branch_id IS NULL")
    && !str_contains($availability, 'SELECT id, branch_id FROM staff WHERE id = ? AND deleted_at IS NULL AND is_active = 1');

$checks['AvailabilityService scoped public helpers no longer post-filter a bare loaded row'] =
    str_contains($availability, 'public function getActiveServiceForScope(int $serviceId, ?int $branchId = null): ?array')
    && str_contains($availability, '$service = $this->getActiveService($serviceId, $branchId);')
    && str_contains($availability, 'public function getActiveStaffForScope(int $staffId, ?int $branchId = null, ?int $serviceId = null): ?array')
    && str_contains($availability, '$rows = $this->getEligibleStaff($serviceId ?? 0, $branchId, $staffId, $serviceId !== null && $serviceId > 0);')
    && !str_contains($availability, 'serviceIsBranchOwnedOrOrgGlobalForOperationBranch');

$checks['AvailabilityService timing helpers require branch-aware scoped service lookup'] =
    str_contains($availability, 'public function getServiceDurationMinutes(int $serviceId, ?int $branchId = null): int')
    && str_contains($availability, 'public function getServiceTiming(int $serviceId, ?int $branchId = null): ?array')
    && str_contains($availability, '$timing = $this->getServiceTiming($serviceId, $branchId);');

$checks['AvailabilityService no longer loads staff availability shape from raw id-only staff read'] =
    str_contains($availability, '$staff = $this->getActiveStaffForScope($staffId, $branchId);')
    && !str_contains($availability, '$staff = $this->getActiveStaff($staffId);');

$checks['PublicBookingService uses scoped availability helpers only for service and staff resolution'] =
    substr_count($publicBooking, 'getActiveServiceForScope(') >= 2
    && substr_count($publicBooking, 'getActiveStaffForScope(') >= 2
    && !preg_match('/SELECT .* FROM services .*WHERE id = \?/i', $publicBooking)
    && !preg_match('/SELECT .* FROM staff .*WHERE id = \?/i', $publicBooking);

$checks['PublicBookingService validateBranch resolves active organization in SQL'] =
    str_contains($publicBooking, 'INNER JOIN organizations o ON o.id = b.organization_id')
    && str_contains($publicBooking, 'AND o.suspended_at IS NULL')
    && !str_contains($publicBooking, 'if ($this->lifecycleGate->isBranchLinkedToSuspendedOrganization($branchId))');

$checks['AppointmentService passes branch context into availability timing helpers'] =
    str_contains($appointmentService, 'getServiceDurationMinutes($serviceId, $branchId)')
    && str_contains($appointmentService, 'getServiceTiming($serviceId, $branchId)')
    && str_contains($appointmentService, 'getServiceDurationMinutes($serviceId, $durationScope)');

$checks['AppointmentSeriesService and WaitlistService pass branch context into timing helpers'] =
    str_contains($appointmentSeriesService, 'getServiceDurationMinutes($serviceId, $branchId > 0 ? $branchId : null)')
    && str_contains($waitlistService, 'getServiceTiming($serviceId, $branchId)');

$checks['BlockedSlotService no longer validates staff with raw id-only SQL'] =
    str_contains($blockedSlotService, 'getActiveStaffForScope($staffId, $branchId)')
    && !preg_match('/SELECT .* FROM staff .*WHERE id = \?/i', $blockedSlotService);

$checks['AppointmentController current-assignee preservation no longer uses raw unscoped staff query'] =
    str_contains($appointmentController, 'Application::container()->get(\Modules\Staff\Repositories\StaffRepository::class)->find($cur);')
    && !preg_match('/SELECT .* FROM staff .*WHERE id = \?/i', $appointmentController);

$checks['Bootstrap wires BlockedSlotService through scoped availability dependency'] =
    str_contains($bootstrap, 'new \Modules\Appointments\Services\BlockedSlotService(')
    && str_contains($bootstrap, '$c->get(\Modules\Appointments\Services\AvailabilityService::class))');

$checks['Repo truth doc records availability/public-booking continuation closure'] =
    str_contains($doc, 'Continuation status for the appointments/public-booking cluster:')
    && str_contains($doc, 'Cluster residuals after continuation:')
    && str_contains($doc, 'No unresolved `ROOT-01` issue is expected to remain inside the touched `AvailabilityService` + public-booking cluster.');

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

echo PHP_EOL . "verify_root_01_availability_public_booking_cluster_closure_plt_tnt_01: OK\n";
exit(0);
