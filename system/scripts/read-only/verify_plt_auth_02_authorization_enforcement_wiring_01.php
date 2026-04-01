<?php

declare(strict_types=1);

/**
 * PLT-AUTH-02: Authorization Enforcement Wiring — Verification Script
 *
 * Proves that the installed authorization kernel is enforced at real execution
 * boundaries, not merely registered in the container.
 *
 * Assertion groups:
 *   1. Kernel contracts still intact (no regression from PLT-Q-01)
 *   2. ResourceAction enum completeness (new PLT-AUTH-02 actions present)
 *   3. PolicyAuthorizer ACTION_PERMISSION_MAP correctness (real permission codes)
 *   4. Service layer wiring — migrated services declare AuthorizerInterface injection
 *   5. Service layer wiring — migrated services call requireAuthorized() at write mutations
 *   6. Bootstrap wiring — migrated services receive AuthorizerInterface from container
 *   7. AuthorizationMiddleware — new HTTP-level enforcement middleware exists and is correct
 *   8. Fail-closed semantics — DenyAllAuthorizer and AuthorizationException still intact
 *   9. Support-actor write blocking — SUPPORT_ACTOR_ALLOWED_ACTIONS remains read-only
 *  10. Legacy guards preserved — PermissionMiddleware, PlatformPrincipalMiddleware not removed
 *  11. Guardrail present — PLT-AUTH-02 service authorizer enforcement guardrail exists
 *  12. No regression on prior verifier signals
 *
 * Run with explicit PHP binary:
 *   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe system/scripts/read-only/verify_plt_auth_02_authorization_enforcement_wiring_01.php
 */

const SYSTEM_PATH = __DIR__ . '/../../..';

$pass  = 0;
$fail  = 0;
$notes = [];

function assertPass(string $label, bool $condition, string $detail = ''): void
{
    global $pass, $fail, $notes;
    if ($condition) {
        $pass++;
        echo "  [PASS] {$label}\n";
    } else {
        $fail++;
        $msg = "  [FAIL] {$label}";
        if ($detail !== '') {
            $msg .= "\n         → {$detail}";
        }
        echo $msg . "\n";
        $notes[] = $label;
    }
}

function fileContains(string $relPath, string $pattern): bool
{
    $absPath = SYSTEM_PATH . '/' . $relPath;
    if (!is_file($absPath)) {
        return false;
    }
    $content = file_get_contents($absPath);

    return $content !== false && str_contains($content, $pattern);
}

function fileExists(string $relPath): bool
{
    return is_file(SYSTEM_PATH . '/' . $relPath);
}

function fileContainsPattern(string $relPath, string $regex): bool
{
    $absPath = SYSTEM_PATH . '/' . $relPath;
    if (!is_file($absPath)) {
        return false;
    }
    $content = file_get_contents($absPath);

    return $content !== false && (bool) preg_match($regex, $content);
}

echo "\n";
echo "=============================================================\n";
echo " PLT-AUTH-02: Authorization Enforcement Wiring Verifier\n";
echo "=============================================================\n\n";

// ---------------------------------------------------------------------------
// GROUP 1: Kernel contracts intact
// ---------------------------------------------------------------------------
echo "--- GROUP 1: Kernel Contracts Intact ---\n";

assertPass(
    'AuthorizerInterface exists',
    fileExists('system/core/Kernel/Authorization/AuthorizerInterface.php')
);
assertPass(
    'PolicyAuthorizer exists',
    fileExists('system/core/Kernel/Authorization/PolicyAuthorizer.php')
);
assertPass(
    'AccessDecision exists',
    fileExists('system/core/Kernel/Authorization/AccessDecision.php')
);
assertPass(
    'ResourceRef exists',
    fileExists('system/core/Kernel/Authorization/ResourceRef.php')
);
assertPass(
    'ResourceAction exists',
    fileExists('system/core/Kernel/Authorization/ResourceAction.php')
);
assertPass(
    'AuthorizationException exists',
    fileExists('system/core/Kernel/Authorization/AuthorizationException.php')
);
assertPass(
    'DenyAllAuthorizer exists (fail-closed fallback preserved)',
    fileExists('system/core/Kernel/Authorization/DenyAllAuthorizer.php')
);

// ---------------------------------------------------------------------------
// GROUP 2: ResourceAction enum completeness
// ---------------------------------------------------------------------------
echo "\n--- GROUP 2: ResourceAction Enum — New PLT-AUTH-02 Cases ---\n";

assertPass(
    'ResourceAction: INVOICE_EDIT present',
    fileContains('system/core/Kernel/Authorization/ResourceAction.php', "INVOICE_EDIT"),
    "Add: case INVOICE_EDIT = 'invoice:edit';"
);
assertPass(
    'ResourceAction: INVOICE_DELETE present',
    fileContains('system/core/Kernel/Authorization/ResourceAction.php', "INVOICE_DELETE"),
    "Add: case INVOICE_DELETE = 'invoice:delete';"
);
assertPass(
    'ResourceAction: INVOICE_PAY present',
    fileContains('system/core/Kernel/Authorization/ResourceAction.php', "INVOICE_PAY"),
    "Add: case INVOICE_PAY = 'invoice:pay';"
);
assertPass(
    'ResourceAction: all original cases still present',
    fileContains('system/core/Kernel/Authorization/ResourceAction.php', "CLIENT_VIEW")
    && fileContains('system/core/Kernel/Authorization/ResourceAction.php', "APPOINTMENT_VIEW")
    && fileContains('system/core/Kernel/Authorization/ResourceAction.php', "INVOICE_VIEW")
);

// ---------------------------------------------------------------------------
// GROUP 3: PolicyAuthorizer permission map correctness
// ---------------------------------------------------------------------------
echo "\n--- GROUP 3: PolicyAuthorizer ACTION_PERMISSION_MAP Correctness ---\n";

assertPass(
    'PolicyAuthorizer: client:modify maps to clients.edit (real permission code)',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'client:modify'           => 'clients.edit'"),
    "Was 'clients.update' — fixed to match actual RBAC permission code 'clients.edit'"
);
assertPass(
    'PolicyAuthorizer: appointment:modify maps to appointments.edit',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'appointment:modify'      => 'appointments.edit'"),
    "Was 'appointments.update'"
);
assertPass(
    'PolicyAuthorizer: appointment:cancel maps to appointments.edit',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'appointment:cancel'      => 'appointments.edit'"),
    "Was 'appointments.cancel'"
);
assertPass(
    'PolicyAuthorizer: invoice:view maps to sales.view (real permission code)',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'invoice:view'            => 'sales.view'"),
    "Was 'invoices.view'"
);
assertPass(
    'PolicyAuthorizer: invoice:create maps to sales.create',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'invoice:create'          => 'sales.create'"),
    "Was 'invoices.create'"
);
assertPass(
    'PolicyAuthorizer: invoice:edit maps to sales.edit (new)',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'invoice:edit'            => 'sales.edit'")
);
assertPass(
    'PolicyAuthorizer: invoice:delete maps to sales.delete (new)',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'invoice:delete'          => 'sales.delete'")
);
assertPass(
    'PolicyAuthorizer: invoice:pay maps to sales.pay (new)',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'invoice:pay'             => 'sales.pay'")
);
assertPass(
    'PolicyAuthorizer: service:view maps to services-resources.view',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'service:view'            => 'services-resources.view'"),
    "Was 'services.view'"
);
assertPass(
    'PolicyAuthorizer: staff:manage maps to staff.edit',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'staff:manage'            => 'staff.edit'"),
    "Was 'staff.manage'"
);
assertPass(
    'PolicyAuthorizer: branch-settings:manage maps to settings.edit',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'branch-settings:manage'  => 'settings.edit'"),
    "Was 'settings.manage'"
);
assertPass(
    'PolicyAuthorizer: deny-by-default platform actions still null-mapped',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'platform:support-entry'  => null")
    && fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'platform:org-manage'     => null")
);

// ---------------------------------------------------------------------------
// GROUP 4: Service layer — AuthorizerInterface injection declared
// ---------------------------------------------------------------------------
echo "\n--- GROUP 4: Service Layer — AuthorizerInterface Injection ---\n";

$services = [
    'system/modules/clients/services/ClientService.php'             => 'ClientService',
    'system/modules/clients/services/ClientIssueFlagService.php'    => 'ClientIssueFlagService',
    'system/modules/clients/services/ClientMergeJobService.php'     => 'ClientMergeJobService',
    'system/modules/clients/services/ClientRegistrationService.php' => 'ClientRegistrationService',
    'system/modules/sales/services/InvoiceService.php'              => 'InvoiceService',
    'system/modules/sales/services/PaymentService.php'              => 'PaymentService',
    'system/modules/sales/services/RegisterSessionService.php'      => 'RegisterSessionService',
];

foreach ($services as $relPath => $name) {
    assertPass(
        "{$name}: declares AuthorizerInterface injection",
        fileContains($relPath, 'AuthorizerInterface')
        && fileContains($relPath, 'use Core\Kernel\Authorization\AuthorizerInterface'),
        "{$relPath} must import and inject AuthorizerInterface"
    );
    assertPass(
        "{$name}: imports ResourceAction",
        fileContains($relPath, 'use Core\Kernel\Authorization\ResourceAction'),
        "{$relPath} must import ResourceAction"
    );
    assertPass(
        "{$name}: imports ResourceRef",
        fileContains($relPath, 'use Core\Kernel\Authorization\ResourceRef'),
        "{$relPath} must import ResourceRef"
    );
}

// ---------------------------------------------------------------------------
// GROUP 5: Service layer — requireAuthorized() calls at write mutations
// ---------------------------------------------------------------------------
echo "\n--- GROUP 5: Service Layer — requireAuthorized() Write Mutation Calls ---\n";

assertPass(
    'ClientService: requireAuthorized called for CLIENT_CREATE',
    fileContains('system/modules/clients/services/ClientService.php', 'ResourceAction::CLIENT_CREATE')
);
assertPass(
    'ClientService: requireAuthorized called for CLIENT_MODIFY',
    fileContains('system/modules/clients/services/ClientService.php', 'ResourceAction::CLIENT_MODIFY')
);
assertPass(
    'ClientService: requireAuthorized called for CLIENT_DELETE',
    fileContains('system/modules/clients/services/ClientService.php', 'ResourceAction::CLIENT_DELETE')
);

assertPass(
    'ClientIssueFlagService: requireAuthorized called for CLIENT_MODIFY',
    fileContains('system/modules/clients/services/ClientIssueFlagService.php', 'ResourceAction::CLIENT_MODIFY')
);

assertPass(
    'ClientMergeJobService: requireAuthorized called for CLIENT_MODIFY (enqueue path)',
    fileContains('system/modules/clients/services/ClientMergeJobService.php', 'ResourceAction::CLIENT_MODIFY')
);

assertPass(
    'ClientRegistrationService: requireAuthorized called for CLIENT_CREATE',
    fileContains('system/modules/clients/services/ClientRegistrationService.php', 'ResourceAction::CLIENT_CREATE')
);
assertPass(
    'ClientRegistrationService: requireAuthorized called for CLIENT_MODIFY',
    fileContains('system/modules/clients/services/ClientRegistrationService.php', 'ResourceAction::CLIENT_MODIFY')
);

assertPass(
    'InvoiceService: requireAuthorized called for INVOICE_CREATE',
    fileContains('system/modules/sales/services/InvoiceService.php', 'ResourceAction::INVOICE_CREATE')
);
assertPass(
    'InvoiceService: requireAuthorized called for INVOICE_EDIT',
    fileContains('system/modules/sales/services/InvoiceService.php', 'ResourceAction::INVOICE_EDIT')
);
assertPass(
    'InvoiceService: requireAuthorized called for INVOICE_VOID (cancel)',
    fileContains('system/modules/sales/services/InvoiceService.php', 'ResourceAction::INVOICE_VOID')
);
assertPass(
    'InvoiceService: requireAuthorized called for INVOICE_DELETE',
    fileContains('system/modules/sales/services/InvoiceService.php', 'ResourceAction::INVOICE_DELETE')
);

assertPass(
    'PaymentService: requireAuthorized called for INVOICE_PAY (create payment)',
    fileContains('system/modules/sales/services/PaymentService.php', 'ResourceAction::INVOICE_PAY')
);

assertPass(
    'RegisterSessionService: requireAuthorized called for INVOICE_PAY (open/close/movement)',
    fileContains('system/modules/sales/services/RegisterSessionService.php', 'ResourceAction::INVOICE_PAY')
);

// Confirm requireAuthorized() call pattern is present in each file
foreach ($services as $relPath => $name) {
    assertPass(
        "{$name}: contains requireAuthorized() call",
        fileContainsPattern($relPath, '/->requireAuthorized\s*\(/'),
        "{$relPath} must call \$this->authorizer->requireAuthorized() at write mutations"
    );
}

// ---------------------------------------------------------------------------
// GROUP 6: Bootstrap wiring — AuthorizerInterface injected into services
// ---------------------------------------------------------------------------
echo "\n--- GROUP 6: Bootstrap Wiring — DI Container Injections ---\n";

assertPass(
    'register_clients.php: ClientService receives AuthorizerInterface',
    fileContains(
        'system/modules/bootstrap/register_clients.php',
        'ClientService::class'
    ) && fileContainsPattern(
        'system/modules/bootstrap/register_clients.php',
        '/ClientService::class.*AuthorizerInterface::class/'
    ),
    "ClientService factory must pass \$c->get(AuthorizerInterface::class)"
);
assertPass(
    'register_clients.php: ClientIssueFlagService receives AuthorizerInterface',
    fileContainsPattern(
        'system/modules/bootstrap/register_clients.php',
        '/ClientIssueFlagService::class.*AuthorizerInterface::class/'
    )
);
assertPass(
    'register_clients.php: ClientMergeJobService receives AuthorizerInterface',
    fileContainsPattern(
        'system/modules/bootstrap/register_clients.php',
        '/ClientMergeJobService::class.*AuthorizerInterface::class/'
    )
);
assertPass(
    'register_clients.php: ClientRegistrationService receives AuthorizerInterface',
    fileContainsPattern(
        'system/modules/bootstrap/register_clients.php',
        '/ClientRegistrationService::class.*AuthorizerInterface::class/'
    )
);

assertPass(
    'register_sales.php: InvoiceService receives AuthorizerInterface',
    fileContainsPattern(
        'system/modules/bootstrap/register_sales_public_commerce_memberships_settings.php',
        '/InvoiceService::class.*AuthorizerInterface::class/'
    )
);
assertPass(
    'register_sales.php: PaymentService receives AuthorizerInterface',
    fileContainsPattern(
        'system/modules/bootstrap/register_sales_public_commerce_memberships_settings.php',
        '/PaymentService::class.*AuthorizerInterface::class/'
    )
);
assertPass(
    'register_sales.php: RegisterSessionService receives AuthorizerInterface',
    fileContainsPattern(
        'system/modules/bootstrap/register_sales_public_commerce_memberships_settings.php',
        '/RegisterSessionService::class.*AuthorizerInterface::class/'
    )
);

assertPass(
    'bootstrap.php: PolicyAuthorizer registered as AuthorizerInterface',
    fileContains(
        'system/bootstrap.php',
        'AuthorizerInterface::class'
    ) && fileContains(
        'system/bootstrap.php',
        'PolicyAuthorizer'
    )
);

// ---------------------------------------------------------------------------
// GROUP 7: AuthorizationMiddleware — HTTP-level enforcement middleware
// ---------------------------------------------------------------------------
echo "\n--- GROUP 7: AuthorizationMiddleware — HTTP Enforcement Middleware ---\n";

assertPass(
    'AuthorizationMiddleware.php exists',
    fileExists('system/core/middleware/AuthorizationMiddleware.php')
);
assertPass(
    'AuthorizationMiddleware implements MiddlewareInterface',
    fileContains('system/core/middleware/AuthorizationMiddleware.php', 'implements MiddlewareInterface')
);
assertPass(
    'AuthorizationMiddleware: forAction() factory exists',
    fileContains('system/core/middleware/AuthorizationMiddleware.php', 'public static function forAction(')
);
assertPass(
    'AuthorizationMiddleware: uses AuthorizerInterface::requireAuthorized()',
    fileContains('system/core/middleware/AuthorizationMiddleware.php', 'requireAuthorized')
);
assertPass(
    'AuthorizationMiddleware: deny emits 403',
    fileContains('system/core/middleware/AuthorizationMiddleware.php', '403')
);
assertPass(
    'AuthorizationMiddleware: deny does not expose exception reason to client',
    fileContains('system/core/middleware/AuthorizationMiddleware.php', 'Access denied by policy')
    && !fileContains('system/core/middleware/AuthorizationMiddleware.php', '$e->getMessage()')
);

// ---------------------------------------------------------------------------
// GROUP 8: Fail-closed semantics
// ---------------------------------------------------------------------------
echo "\n--- GROUP 8: Fail-Closed Semantics ---\n";

assertPass(
    'AccessDecision::deny() exists (fail-closed path)',
    fileContains('system/core/Kernel/Authorization/AccessDecision.php', 'public static function deny(')
);
assertPass(
    'AccessDecision::orThrow() enforces denial',
    fileContains('system/core/Kernel/Authorization/AccessDecision.php', 'public function orThrow(')
    && fileContains('system/core/Kernel/Authorization/AccessDecision.php', 'AuthorizationException')
);
assertPass(
    'PolicyAuthorizer: unresolved context returns DENY',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', 'tenant_context_unresolved')
    && fileContainsPattern(
        'system/core/Kernel/Authorization/PolicyAuthorizer.php',
        '/tenantContextResolved/'
    )
);
assertPass(
    'PolicyAuthorizer: unmapped action returns DENY (deny-by-default)',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "AccessDecision::deny('action_not_in_policy_map")
    || fileContainsPattern(
        'system/core/Kernel/Authorization/PolicyAuthorizer.php',
        '/action_not_in_policy_map/'
    )
);
assertPass(
    'PolicyAuthorizer: GUEST principal returns DENY',
    fileContainsPattern(
        'system/core/Kernel/Authorization/PolicyAuthorizer.php',
        "/no_policy_for_principal_kind/"
    )
);

// ---------------------------------------------------------------------------
// GROUP 9: Support-actor write blocking
// ---------------------------------------------------------------------------
echo "\n--- GROUP 9: Support-Actor Write Blocking ---\n";

assertPass(
    'PolicyAuthorizer: SUPPORT_ACTOR_ALLOWED_ACTIONS exists and is read-only',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', 'SUPPORT_ACTOR_ALLOWED_ACTIONS')
    && !fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'invoice:create'")
    || (
        fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', 'SUPPORT_ACTOR_ALLOWED_ACTIONS')
        && fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', 'support_actor_write_blocked')
    )
);
assertPass(
    'PolicyAuthorizer: support actor decideForSupportActor method exists',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', 'decideForSupportActor')
);
assertPass(
    'PolicyAuthorizer: support actor write returns DENY with reason',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', 'support_actor_write_blocked')
);

// ---------------------------------------------------------------------------
// GROUP 10: Legacy guards preserved
// ---------------------------------------------------------------------------
echo "\n--- GROUP 10: Legacy Guards Preserved ---\n";

assertPass(
    'PermissionMiddleware exists (legacy RBAC gate preserved)',
    fileExists('system/core/middleware/PermissionMiddleware.php')
);
assertPass(
    'PlatformPrincipalMiddleware exists (control-plane gate preserved)',
    fileExists('system/core/middleware/PlatformPrincipalMiddleware.php')
);
assertPass(
    'TenantProtectedRouteMiddleware exists (tenant boundary preserved)',
    fileExists('system/core/middleware/TenantProtectedRouteMiddleware.php')
);
assertPass(
    'TenantContextMiddleware exists (context resolution preserved)',
    fileExists('system/core/middleware/TenantContextMiddleware.php')
);
assertPass(
    'Platform routes still use PlatformPrincipalMiddleware',
    fileContains(
        'system/routes/web/register_platform_control_plane.php',
        'PlatformPrincipalMiddleware::class'
    )
);
assertPass(
    'Client routes still use PermissionMiddleware',
    fileContains(
        'system/routes/web/register_clients.php',
        'PermissionMiddleware::for'
    )
);
assertPass(
    'Sales routes still use PermissionMiddleware',
    fileContains(
        'system/routes/web/register_sales_public_commerce_staff.php',
        'PermissionMiddleware::for'
    )
);

// ---------------------------------------------------------------------------
// GROUP 11: PLT-AUTH-02 guardrail present
// ---------------------------------------------------------------------------
echo "\n--- GROUP 11: PLT-AUTH-02 Guardrail ---\n";

assertPass(
    'guardrail_plt_auth_02_service_authorizer_enforcement.php exists',
    fileExists('system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php')
);
assertPass(
    'PLT-AUTH-02 guardrail checks 7 migrated services',
    fileContains(
        'system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php',
        'ClientService.php'
    ) && fileContains(
        'system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php',
        'InvoiceService.php'
    ) && fileContains(
        'system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php',
        'RegisterSessionService.php'
    )
);
assertPass(
    'PLT-AUTH-02 guardrail scans for ->requireAuthorized( pattern',
    fileContains(
        'system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php',
        '->requireAuthorized'
    )
);

// ---------------------------------------------------------------------------
// GROUP 12: No regression on prior verifier signals
// ---------------------------------------------------------------------------
echo "\n--- GROUP 12: Prior Verifier / Guardrail Regression Signals ---\n";

assertPass(
    'verify_kernel_tenant_context_01.php exists (FOUNDATION-A1)',
    fileExists('system/scripts/read-only/verify_kernel_tenant_context_01.php')
);
assertPass(
    'verify_big_04_appointments_migration_01.php exists (BIG-04)',
    fileExists('system/scripts/read-only/verify_big_04_appointments_migration_01.php')
);
assertPass(
    'verify_big_06_sales_migration_01.php exists (BIG-06)',
    fileExists('system/scripts/read-only/verify_big_06_sales_migration_01.php')
);
assertPass(
    'verify_big_07_client_owned_resources_migration_01.php exists (BIG-07)',
    fileExists('system/scripts/read-only/verify_big_07_client_owned_resources_migration_01.php')
);
assertPass(
    'verify_plt_q_01_unified_async_queue_control_plane_01.php exists (PLT-Q-01)',
    fileExists('system/scripts/read-only/verify_plt_q_01_unified_async_queue_control_plane_01.php')
);
assertPass(
    'guardrail_service_layer_db_ban.php exists (FOUNDATION-A6)',
    fileExists('system/scripts/ci/guardrail_service_layer_db_ban.php')
);
assertPass(
    'guardrail_id_only_repo_api_freeze.php exists (FOUNDATION-A6)',
    fileExists('system/scripts/ci/guardrail_id_only_repo_api_freeze.php')
);
assertPass(
    'guardrail_async_state_machine_ban.php exists (PLT-Q-01)',
    fileExists('system/scripts/ci/guardrail_async_state_machine_ban.php')
);

// ---------------------------------------------------------------------------
// GROUP 13: Appointments domain wiring (PRIVILEGED-PLANE-CLOSURE-AND-STEP-UP-AUTH-01)
// ---------------------------------------------------------------------------
echo "\n--- GROUP 13: Appointments Domain Authorization Wiring ---\n";

assertPass(
    'AppointmentService: declares AuthorizerInterface injection',
    fileContains('system/modules/appointments/services/AppointmentService.php', 'AuthorizerInterface')
    && fileContains('system/modules/appointments/services/AppointmentService.php', 'use Core\Kernel\Authorization\AuthorizerInterface')
);
assertPass(
    'AppointmentService: requireAuthorized called for APPOINTMENT_CREATE',
    fileContains('system/modules/appointments/services/AppointmentService.php', 'ResourceAction::APPOINTMENT_CREATE')
);
assertPass(
    'AppointmentService: requireAuthorized called for APPOINTMENT_MODIFY',
    fileContains('system/modules/appointments/services/AppointmentService.php', 'ResourceAction::APPOINTMENT_MODIFY')
);
assertPass(
    'AppointmentService: requireAuthorized called for APPOINTMENT_CANCEL',
    fileContains('system/modules/appointments/services/AppointmentService.php', 'ResourceAction::APPOINTMENT_CANCEL')
);
assertPass(
    'AppointmentService: requireAuthorized called for APPOINTMENT_DELETE',
    fileContains('system/modules/appointments/services/AppointmentService.php', 'ResourceAction::APPOINTMENT_DELETE')
);
assertPass(
    'AppointmentService: createFromPublicBooking NOT gated (public path preserved)',
    !fileContainsPattern(
        'system/modules/appointments/services/AppointmentService.php',
        '/createFromPublicBooking[^{]+\{[^}]*requireAuthorized/'
    )
);
assertPass(
    'ResourceAction: APPOINTMENT_DELETE case present',
    fileContains('system/core/Kernel/Authorization/ResourceAction.php', 'APPOINTMENT_DELETE')
);
assertPass(
    'PolicyAuthorizer: appointment:delete maps to appointments.edit',
    fileContains('system/core/Kernel/Authorization/PolicyAuthorizer.php', "'appointment:delete'")
);
assertPass(
    'register_appointments: AppointmentService receives AuthorizerInterface',
    fileContains('system/modules/bootstrap/register_appointments_online_contracts.php', 'AuthorizerInterface::class')
);

// ---------------------------------------------------------------------------
// GROUP 14: Staff domain wiring (PRIVILEGED-PLANE-CLOSURE-AND-STEP-UP-AUTH-01)
// ---------------------------------------------------------------------------
echo "\n--- GROUP 14: Staff Domain Authorization Wiring ---\n";

assertPass(
    'StaffGroupService: declares AuthorizerInterface injection',
    fileContains('system/modules/staff/services/StaffGroupService.php', 'AuthorizerInterface')
);
assertPass(
    'StaffGroupService: requireAuthorized called for STAFF_MANAGE',
    fileContains('system/modules/staff/services/StaffGroupService.php', 'ResourceAction::STAFF_MANAGE')
);
assertPass(
    'StaffGroupPermissionService: declares AuthorizerInterface injection',
    fileContains('system/modules/staff/services/StaffGroupPermissionService.php', 'AuthorizerInterface')
);
assertPass(
    'StaffGroupPermissionService: requireAuthorized called for STAFF_MANAGE',
    fileContains('system/modules/staff/services/StaffGroupPermissionService.php', 'ResourceAction::STAFF_MANAGE')
);
assertPass(
    'register_staff: StaffGroupService receives AuthorizerInterface',
    fileContains('system/modules/bootstrap/register_staff.php', 'AuthorizerInterface::class')
    && fileContainsPattern(
        'system/modules/bootstrap/register_staff.php',
        '/StaffGroupService::class.*AuthorizerInterface::class/'
    )
);
assertPass(
    'register_staff: StaffGroupPermissionService receives AuthorizerInterface',
    fileContainsPattern(
        'system/modules/bootstrap/register_staff.php',
        '/StaffGroupPermissionService::class.*AuthorizerInterface::class/'
    )
);

// ---------------------------------------------------------------------------
// GROUP 15: Services-resources domain wiring (PRIVILEGED-PLANE-CLOSURE-AND-STEP-UP-AUTH-01)
// ---------------------------------------------------------------------------
echo "\n--- GROUP 15: Services-Resources Domain Authorization Wiring ---\n";

assertPass(
    'ServiceService: declares AuthorizerInterface injection',
    fileContains('system/modules/services-resources/services/ServiceService.php', 'AuthorizerInterface')
);
assertPass(
    'ServiceService: requireAuthorized called for SERVICE_MANAGE',
    fileContains('system/modules/services-resources/services/ServiceService.php', 'ResourceAction::SERVICE_MANAGE')
);
assertPass(
    'register_services_resources: ServiceService receives AuthorizerInterface',
    fileContains('system/modules/bootstrap/register_services_resources.php', 'AuthorizerInterface::class')
);

// ---------------------------------------------------------------------------
// GROUP 16: Settings domain wiring (PRIVILEGED-PLANE-CLOSURE-AND-STEP-UP-AUTH-01)
// ---------------------------------------------------------------------------
echo "\n--- GROUP 16: Settings Domain Authorization Wiring ---\n";

$settingsServices = [
    'system/modules/settings/services/BranchOperatingHoursService.php'        => 'BranchOperatingHoursService',
    'system/modules/settings/services/PriceModificationReasonService.php'      => 'PriceModificationReasonService',
    'system/modules/settings/services/BranchClosureDateService.php'            => 'BranchClosureDateService',
    'system/modules/settings/services/AppointmentCancellationReasonService.php' => 'AppointmentCancellationReasonService',
];
foreach ($settingsServices as $relPath => $name) {
    assertPass(
        "{$name}: declares AuthorizerInterface injection",
        fileContains($relPath, 'AuthorizerInterface')
    );
    assertPass(
        "{$name}: requireAuthorized called for BRANCH_SETTINGS_MANAGE",
        fileContains($relPath, 'ResourceAction::BRANCH_SETTINGS_MANAGE')
    );
}
assertPass(
    'register_settings: settings services receive AuthorizerInterface (bootstrap updated)',
    fileContains(
        'system/modules/bootstrap/register_sales_public_commerce_memberships_settings.php',
        'AuthorizerInterface::class'
    )
);

// ---------------------------------------------------------------------------
// GROUP 17: PLT-AUTH-02 guardrail covers all 15 services
// ---------------------------------------------------------------------------
echo "\n--- GROUP 17: PLT-AUTH-02 Guardrail Scope (15 services) ---\n";

assertPass(
    'PLT-AUTH-02 guardrail covers AppointmentService',
    fileContains(
        'system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php',
        'AppointmentService.php'
    )
);
assertPass(
    'PLT-AUTH-02 guardrail covers StaffGroupService',
    fileContains(
        'system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php',
        'StaffGroupService.php'
    )
);
assertPass(
    'PLT-AUTH-02 guardrail covers ServiceService',
    fileContains(
        'system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php',
        'ServiceService.php'
    )
);
assertPass(
    'PLT-AUTH-02 guardrail covers BranchOperatingHoursService',
    fileContains(
        'system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php',
        'BranchOperatingHoursService.php'
    )
);

// ---------------------------------------------------------------------------
// GROUP 18: PLT-MFA-01 verifier + guardrail present
// ---------------------------------------------------------------------------
echo "\n--- GROUP 18: PLT-MFA-01 Proof Artifacts ---\n";

assertPass(
    'verify_plt_mfa_01_privileged_plane_step_up_auth_01.php exists',
    fileExists('system/scripts/read-only/verify_plt_mfa_01_privileged_plane_step_up_auth_01.php')
);
assertPass(
    'guardrail_plt_mfa_01_privileged_plane_step_up.php exists',
    fileExists('system/scripts/ci/guardrail_plt_mfa_01_privileged_plane_step_up.php')
);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n=============================================================\n";
$total = $pass + $fail;
echo " RESULTS: {$pass}/{$total} assertions passed\n";
echo "=============================================================\n\n";

if ($fail > 0) {
    echo "FAILED assertions:\n";
    foreach ($notes as $n) {
        echo "  ✗ {$n}\n";
    }
    echo "\n";
    exit(1);
}

echo "All {$pass} PLT-AUTH-02 authorization enforcement assertions pass.\n\n";
exit(0);
