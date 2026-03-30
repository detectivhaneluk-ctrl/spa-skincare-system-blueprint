<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Appointments\Services\AppointmentService;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Clients\Services\ClientService;
use Modules\ServicesResources\Repositories\ServiceRepository;
use Modules\ServicesResources\Services\ServiceService;
use Modules\Staff\Repositories\StaffRepository;
use Modules\Staff\Services\StaffService;

$db = app(\Core\App\Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$clientRepo = app(ClientRepository::class);
$staffRepo = app(StaffRepository::class);
$serviceRepo = app(ServiceRepository::class);
$appointmentRepo = app(AppointmentRepository::class);
$clientService = app(ClientService::class);
$staffService = app(StaffService::class);
$serviceService = app(ServiceService::class);
$appointmentService = app(AppointmentService::class);

$passed = 0;
$failed = 0;
function tdpPass(string $name): void { global $passed; $passed++; echo "PASS  {$name}\n"; }
function tdpFail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }
function tdpExpectThrows(callable $fn): bool { try { $fn(); return false; } catch (\Throwable) { return true; } }

/**
 * @return array{branch_id:int, organization_id:int}
 */
$resolveScope = static function (string $branchCode) use ($db): array {
    $row = $db->fetchOne(
        'SELECT b.id AS branch_id, b.organization_id AS organization_id
         FROM branches b
         INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
         WHERE b.code = ? AND b.deleted_at IS NULL
         LIMIT 1',
        [$branchCode]
    );
    if ($row === null) {
        throw new RuntimeException('Missing branch: ' . $branchCode);
    }

    return ['branch_id' => (int) $row['branch_id'], 'organization_id' => (int) $row['organization_id']];
};

$setScope = static function (int $branchId, int $orgId) use ($branchContext, $orgContext): void {
    $branchContext->setCurrentBranchId($branchId);
    $orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
};

$scopeA = $resolveScope('SMOKE_A');
$scopeC = $resolveScope('SMOKE_C');
$futureA = date('Y-m-d H:i:s', strtotime('+2 days 11:17:00'));
$futureC = date('Y-m-d H:i:s', strtotime('+3 days 15:17:00'));

// In-tenant (A) fixtures.
$setScope($scopeA['branch_id'], $scopeA['organization_id']);
$clientAId = $clientService->create(['first_name' => 'TDP', 'last_name' => 'Client A']);
$staffAId = $staffService->create(['first_name' => 'TDP', 'last_name' => 'Staff A', 'is_active' => true]);
$serviceAId = $serviceService->create(['name' => 'TDP Service A', 'duration_minutes' => 60, 'price' => 99.0, 'is_active' => true]);
$appointmentAId = $appointmentRepo->create([
    'client_id' => $clientAId,
    'service_id' => $serviceAId,
    'staff_id' => $staffAId,
    'branch_id' => $scopeA['branch_id'],
    'start_at' => $futureA,
    'end_at' => date('Y-m-d H:i:s', strtotime($futureA . ' +60 minutes')),
    'status' => 'scheduled',
]);

// Foreign-tenant (C) fixtures.
$setScope($scopeC['branch_id'], $scopeC['organization_id']);
$clientCId = $clientService->create(['first_name' => 'TDP', 'last_name' => 'Client C']);
$staffCId = $staffService->create(['first_name' => 'TDP', 'last_name' => 'Staff C', 'is_active' => true]);
$serviceCId = $serviceService->create(['name' => 'TDP Service C', 'duration_minutes' => 60, 'price' => 120.0, 'is_active' => true]);
$appointmentCId = $appointmentRepo->create([
    'client_id' => $clientCId,
    'service_id' => $serviceCId,
    'staff_id' => $staffCId,
    'branch_id' => $scopeC['branch_id'],
    'start_at' => $futureC,
    'end_at' => date('Y-m-d H:i:s', strtotime($futureC . ' +60 minutes')),
    'status' => 'scheduled',
]);

// Back to tenant A checks.
$setScope($scopeA['branch_id'], $scopeA['organization_id']);

// Reads: own data available.
($clientRepo->find($clientAId) !== null) ? tdpPass('read_own_client_allowed') : tdpFail('read_own_client_allowed', 'expected non-null');
($staffRepo->find($staffAId) !== null) ? tdpPass('read_own_staff_allowed') : tdpFail('read_own_staff_allowed', 'expected non-null');
($serviceRepo->find($serviceAId) !== null) ? tdpPass('read_own_service_allowed') : tdpFail('read_own_service_allowed', 'expected non-null');
($appointmentRepo->find($appointmentAId) !== null) ? tdpPass('read_own_appointment_allowed') : tdpFail('read_own_appointment_allowed', 'expected non-null');

// Reads: foreign ids denied.
($clientRepo->find($clientCId) === null) ? tdpPass('read_foreign_client_denied') : tdpFail('read_foreign_client_denied', 'expected null');
($staffRepo->find($staffCId) === null) ? tdpPass('read_foreign_staff_denied') : tdpFail('read_foreign_staff_denied', 'expected null');
($serviceRepo->find($serviceCId) === null) ? tdpPass('read_foreign_service_denied') : tdpFail('read_foreign_service_denied', 'expected null');
($appointmentRepo->find($appointmentCId) === null) ? tdpPass('read_foreign_appointment_denied') : tdpFail('read_foreign_appointment_denied', 'expected null');

// Write by foreign id denied.
tdpExpectThrows(static fn () => $clientService->update($clientCId, ['phone' => '0001']))
    ? tdpPass('update_foreign_client_denied')
    : tdpFail('update_foreign_client_denied', 'expected throw');
tdpExpectThrows(static fn () => $staffService->update($staffCId, ['phone' => '0002']))
    ? tdpPass('update_foreign_staff_denied')
    : tdpFail('update_foreign_staff_denied', 'expected throw');
tdpExpectThrows(static fn () => $serviceService->update($serviceCId, ['price' => 77.0]))
    ? tdpPass('update_foreign_service_denied')
    : tdpFail('update_foreign_service_denied', 'expected throw');

// Cross-tenant linked ids denied.
tdpExpectThrows(static fn () => $appointmentService->createFromSlot([
    'client_id' => $clientAId,
    'service_id' => $serviceCId,
    'staff_id' => $staffAId,
    'start_time' => $futureA,
]))
    ? tdpPass('appointment_cross_tenant_service_denied')
    : tdpFail('appointment_cross_tenant_service_denied', 'expected throw');

// Valid in-tenant writes still work.
try {
    $clientService->update($clientAId, ['phone' => '5550001']);
    $staffService->update($staffAId, ['phone' => '5550002']);
    $serviceService->update($serviceAId, ['price' => 88.0]);
    $appointmentService->updateStatus($appointmentAId, 'confirmed', 'tdp status');
    tdpPass('valid_in_tenant_create_update_paths_work');
} catch (\Throwable $e) {
    tdpFail('valid_in_tenant_create_update_paths_work', $e->getMessage());
}

// Unresolved context fails closed.
$branchContext->setCurrentBranchId(null);
$orgContext->setFromResolution(null, OrganizationContext::MODE_UNRESOLVED_AMBIGUOUS_ORGS);
tdpExpectThrows(static fn () => $clientService->create(['first_name' => 'Fail', 'last_name' => 'Closed']))
    ? tdpPass('unresolved_context_fails_closed')
    : tdpFail('unresolved_context_fails_closed', 'expected throw');

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
