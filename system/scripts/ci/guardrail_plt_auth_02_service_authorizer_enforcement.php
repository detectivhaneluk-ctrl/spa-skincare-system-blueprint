<?php

declare(strict_types=1);

/**
 * PLT-AUTH-02 Guardrail: Service Layer Authorization Enforcement
 *
 * Fails if any explicitly-migrated service file does NOT contain a call to
 * ->requireAuthorized(...) or ->authorize(...) from AuthorizerInterface.
 *
 * Architecture rule (PLT-AUTH-02):
 *   Services migrated to the authorization kernel MUST invoke
 *   AuthorizerInterface::requireAuthorized() at each write mutation entry point.
 *   This prevents silent bypass: if the AuthorizerInterface injection is removed
 *   or the call is dropped, this guardrail fires.
 *
 * What this checks:
 *   Each protected service file MUST contain at least one call to:
 *     ->requireAuthorized(
 *   This is the canonical call form: $this->authorizer->requireAuthorized(...)
 *
 * Scope — PLT-AUTH-02 migrated vertical slice (2026-03-31):
 *   Client domain (4 services): ClientService, ClientIssueFlagService,
 *     ClientMergeJobService, ClientRegistrationService
 *   Sales domain (3 services): InvoiceService, PaymentService, RegisterSessionService
 *
 * Run from repo root:
 *   php system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php
 */

$repoRoot = dirname(__DIR__, 3);

// ---------------------------------------------------------------------------
// Services that MUST contain at least one ->requireAuthorized( call.
// Grows as PLT-AUTH-02 migrated slice expands to more services.
// ---------------------------------------------------------------------------
$enforcedServices = [
    // Client domain (PLT-AUTH-02, 2026-03-31)
    'system/modules/clients/services/ClientService.php',
    'system/modules/clients/services/ClientIssueFlagService.php',
    'system/modules/clients/services/ClientMergeJobService.php',
    'system/modules/clients/services/ClientRegistrationService.php',
    // Sales domain (PLT-AUTH-02, 2026-03-31)
    'system/modules/sales/services/InvoiceService.php',
    'system/modules/sales/services/PaymentService.php',
    'system/modules/sales/services/RegisterSessionService.php',
    // Appointments domain (PLT-AUTH-02 / PRIVILEGED-PLANE-CLOSURE-AND-STEP-UP-AUTH-01, 2026-04-01)
    'system/modules/appointments/services/AppointmentService.php',
    // Staff domain (PLT-AUTH-02 / PRIVILEGED-PLANE-CLOSURE-AND-STEP-UP-AUTH-01, 2026-04-01)
    'system/modules/staff/services/StaffGroupService.php',
    'system/modules/staff/services/StaffGroupPermissionService.php',
    // Services-resources domain (PLT-AUTH-02 / PRIVILEGED-PLANE-CLOSURE-AND-STEP-UP-AUTH-01, 2026-04-01)
    'system/modules/services-resources/services/ServiceService.php',
    // Settings domain (PLT-AUTH-02 / PRIVILEGED-PLANE-CLOSURE-AND-STEP-UP-AUTH-01, 2026-04-01)
    'system/modules/settings/services/BranchOperatingHoursService.php',
    'system/modules/settings/services/PriceModificationReasonService.php',
    'system/modules/settings/services/BranchClosureDateService.php',
    'system/modules/settings/services/AppointmentCancellationReasonService.php',
];

// Pattern that must appear at least once in each protected file.
$requiredPattern = '/->requireAuthorized\s*\(/';

// ---------------------------------------------------------------------------
// Scan
// ---------------------------------------------------------------------------
$violations = [];
$checked    = 0;

foreach ($enforcedServices as $rel) {
    $path = $repoRoot . '/' . $rel;
    if (!is_file($path)) {
        $violations[] = "PROTECTED FILE MISSING: {$rel}\n"
            . "  → If this file was moved or renamed, update the guardrail enforced list.";
        continue;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        $violations[] = "UNREADABLE: {$rel}";
        continue;
    }

    if (!preg_match($requiredPattern, $content)) {
        $violations[] = "MISSING AUTHORIZER CALL in {$rel}\n"
            . "  → This PLT-AUTH-02 migrated service must call \$this->authorizer->requireAuthorized() "
            . "at each write mutation entry point.\n"
            . "  → Add: \$this->authorizer->requireAuthorized(\$ctx, ResourceAction::XYZ, ResourceRef::...);";
    }

    $checked++;
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
if ($violations === []) {
    echo '[PASS] PLT-AUTH-02 authorization enforcement guardrail: '
        . count($enforcedServices) . '/' . count($enforcedServices)
        . " protected services contain required requireAuthorized() calls.\n";
    exit(0);
}

echo '[FAIL] PLT-AUTH-02 authorization enforcement guardrail: '
    . count($violations) . ' violation(s) found.' . "\n\n";
foreach ($violations as $v) {
    echo '  ✗ ' . $v . "\n\n";
}
exit(1);
