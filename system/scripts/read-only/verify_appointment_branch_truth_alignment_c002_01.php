<?php

declare(strict_types=1);

/**
 * C-002-APPOINTMENT-BRANCH-TRUTH-ALIGNMENT-01: static proof that appointment read paths use the same
 * validated branch resolution contract as internal create/slot writes (no BranchContext-only drift helper).
 *
 * No database required.
 *
 * Usage:
 *   php system/scripts/read-only/verify_appointment_branch_truth_alignment_c002_01.php
 */

$base = dirname(__DIR__, 2);
$controller = $base . '/modules/appointments/controllers/AppointmentController.php';
$service = $base . '/modules/appointments/services/AppointmentService.php';
$bootstrap = $base . '/modules/bootstrap/register_appointments_online_contracts.php';

foreach ([$controller, $service, $bootstrap] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: missing file: {$path}\n");
        exit(1);
    }
}

$c = (string) file_get_contents($controller);
$s = (string) file_get_contents($service);
$b = (string) file_get_contents($bootstrap);

$appointmentControllerBootstrapLine = null;
foreach (explode("\n", $b) as $line) {
    if (str_contains($line, 'AppointmentController::class') && str_contains($line, 'new \\Modules\\Appointments\\Controllers\\AppointmentController')) {
        $appointmentControllerBootstrapLine = $line;
        break;
    }
}

$checks = [
    'AppointmentController: resolveAppointmentBranchFromGetOrFail' => str_contains($c, 'resolveAppointmentBranchFromGetOrFail'),
    'AppointmentController: resolveAppointmentBranchForPrincipalFromOptionalRequestId' => str_contains($c, 'resolveAppointmentBranchForPrincipalFromOptionalRequestId'),
    'AppointmentController: TenantBranchAccessService + org assert usage' => str_contains($c, '$this->tenantBranchAccess->allowedBranchIdsForUser')
        && str_contains($c, '$this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization'),
    'AppointmentController: removed queryBranchId helper' => !str_contains($c, 'queryBranchId'),
    'AppointmentService: createFromSlot applies applyTenantCreateBranchResolution' => preg_match(
        '/function\s+createFromSlot\s*\([^)]*\)\s*:\s*int\s*\{[^}]*applyTenantCreateBranchResolution/s',
        $s
    ) === 1,
    'AppointmentService: internal slot branch principal assert' => str_contains($s, 'assertInternalSlotBookingBranchAllowedForPrincipal'),
    'AppointmentService: applyTenantCreateBranchResolution' => str_contains($s, 'function applyTenantCreateBranchResolution'),
    'bootstrap: AppointmentController gets TenantBranchAccess + OrganizationScopedBranchAssert + BranchContext' => is_string($appointmentControllerBootstrapLine)
        && str_contains($appointmentControllerBootstrapLine, '\\Core\\Branch\\TenantBranchAccessService::class')
        && str_contains($appointmentControllerBootstrapLine, '\\Core\\Organization\\OrganizationScopedBranchAssert::class')
        && str_contains($appointmentControllerBootstrapLine, '\\Core\\Branch\\BranchContext::class'),
    'bootstrap: AppointmentService gets TenantBranchAccess + OrganizationScopedBranchAssert' => str_contains($b, 'new \\Modules\\Appointments\\Services\\AppointmentService(')
        && str_contains($b, '\\Core\\Branch\\TenantBranchAccessService::class')
        && str_contains($b, '\\Core\\Organization\\OrganizationScopedBranchAssert::class'),
];

$failed = false;
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'MISSING') . PHP_EOL;
    if (!$ok) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
