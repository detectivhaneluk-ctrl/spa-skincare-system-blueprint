<?php

declare(strict_types=1);

/**
 * PLT-MFA-01 Guardrail: Privileged Plane Step-Up Authentication Enforcement
 *
 * Fails CI if the mandatory strong-auth chain for privileged founder/support-entry
 * operations has been silently removed or bypassed.
 *
 * What this checks:
 *   1. PlatformFounderSupportEntryController::postStart() must call both:
 *      - requireSupportEntryPasswordStepUp (knowledge-factor re-auth)
 *      - requireSupportEntryControlPlaneMfa (TOTP control-plane MFA)
 *   2. FounderSafeActionGuardrailService must implement requireSupportEntryPasswordStepUp()
 *      and requireSupportEntryControlPlaneMfa() — both call verifyPasswordForUserStepUp
 *      and requireControlPlaneTotpVerifiedForActor respectively.
 *   3. ControlPlaneTotpService must implement isEnrolled(), verifyCode(), markSessionFresh().
 *   4. FounderSupportEntryService::startForFounderActor() must NOT read POST — guard comment preserved.
 *   5. SessionAuth::beginSupportEntry() must call session_regenerate_id().
 *   6. PolicyAuthorizer must enforce SUPPORT_ACTOR write blocking.
 *   7. FounderImpersonationAuditService must log session start and end.
 *
 * Run from repo root:
 *   php system/scripts/ci/guardrail_plt_mfa_01_privileged_plane_step_up.php
 */

$repoRoot = dirname(__DIR__, 3);

$violations = [];
$checked    = 0;

function fileContainsMfa(string $absPath, string $pattern): bool
{
    if (!is_file($absPath)) {
        return false;
    }
    $content = file_get_contents($absPath);
    return $content !== false && str_contains($content, $pattern);
}

// ---------------------------------------------------------------------------
// 1. PlatformFounderSupportEntryController must invoke both step-up methods
// ---------------------------------------------------------------------------
$controllerPath = $repoRoot . '/system/modules/organizations/controllers/PlatformFounderSupportEntryController.php';
$checked++;
if (!is_file($controllerPath)) {
    $violations[] = "MISSING: PlatformFounderSupportEntryController.php — privileged plane entry controller not found.";
} else {
    if (!fileContainsMfa($controllerPath, 'requireSupportEntryPasswordStepUp')) {
        $violations[] = "MISSING STEP-UP in PlatformFounderSupportEntryController::postStart()\n"
            . "  → Must call: \$this->guardrail->requireSupportEntryPasswordStepUp(\$founderId);";
    }
    if (!fileContainsMfa($controllerPath, 'requireSupportEntryControlPlaneMfa')) {
        $violations[] = "MISSING MFA GATE in PlatformFounderSupportEntryController::postStart()\n"
            . "  → Must call: \$this->guardrail->requireSupportEntryControlPlaneMfa(\$founderId);";
    }
    if (!fileContainsMfa($controllerPath, 'requireValidatedReason')) {
        $violations[] = "MISSING REASON GATE in PlatformFounderSupportEntryController::postStart()\n"
            . "  → Must call: \$this->guardrail->requireValidatedReason(...);";
    }
    if (!fileContainsMfa($controllerPath, 'requireHighImpactConfirmation')) {
        $violations[] = "MISSING CONFIRMATION GATE in PlatformFounderSupportEntryController::postStart()\n"
            . "  → Must call: \$this->guardrail->requireHighImpactConfirmation();";
    }
}

// ---------------------------------------------------------------------------
// 2. FounderSafeActionGuardrailService must implement both step-up methods
// ---------------------------------------------------------------------------
$guardrailSvcPath = $repoRoot . '/system/modules/organizations/services/FounderSafeActionGuardrailService.php';
$checked++;
if (!is_file($guardrailSvcPath)) {
    $violations[] = "MISSING: FounderSafeActionGuardrailService.php";
} else {
    if (!fileContainsMfa($guardrailSvcPath, 'function requireSupportEntryPasswordStepUp')) {
        $violations[] = "MISSING METHOD: FounderSafeActionGuardrailService::requireSupportEntryPasswordStepUp()";
    }
    if (!fileContainsMfa($guardrailSvcPath, 'function requireSupportEntryControlPlaneMfa')) {
        $violations[] = "MISSING METHOD: FounderSafeActionGuardrailService::requireSupportEntryControlPlaneMfa()";
    }
    if (!fileContainsMfa($guardrailSvcPath, 'verifyPasswordForUserStepUp')) {
        $violations[] = "MISSING: FounderSafeActionGuardrailService must call verifyPasswordForUserStepUp for password step-up.";
    }
    if (!fileContainsMfa($guardrailSvcPath, 'requireControlPlaneTotpVerifiedForActor')) {
        $violations[] = "MISSING: FounderSafeActionGuardrailService must call requireControlPlaneTotpVerifiedForActor for MFA gate.";
    }
}

// ---------------------------------------------------------------------------
// 3. ControlPlaneTotpService must be fully functional
// ---------------------------------------------------------------------------
$totpPath = $repoRoot . '/system/modules/organizations/services/ControlPlaneTotpService.php';
$checked++;
if (!is_file($totpPath)) {
    $violations[] = "MISSING: ControlPlaneTotpService.php — TOTP service not found.";
} else {
    if (!fileContainsMfa($totpPath, 'function isEnrolled')) {
        $violations[] = "MISSING: ControlPlaneTotpService::isEnrolled()";
    }
    if (!fileContainsMfa($totpPath, 'function verifyCode')) {
        $violations[] = "MISSING: ControlPlaneTotpService::verifyCode()";
    }
    if (!fileContainsMfa($totpPath, 'function markSessionFresh')) {
        $violations[] = "MISSING: ControlPlaneTotpService::markSessionFresh()";
    }
    if (!fileContainsMfa($totpPath, 'function isSessionFresh')) {
        $violations[] = "MISSING: ControlPlaneTotpService::isSessionFresh()";
    }
}

// ---------------------------------------------------------------------------
// 4. FounderSupportEntryService must NOT read POST (HTTP entry enforces guardrail)
// ---------------------------------------------------------------------------
$supportEntrySvcPath = $repoRoot . '/system/modules/organizations/services/FounderSupportEntryService.php';
$checked++;
if (!is_file($supportEntrySvcPath)) {
    $violations[] = "MISSING: FounderSupportEntryService.php";
} else {
    if (!fileContainsMfa($supportEntrySvcPath, 'does not read POST')) {
        $violations[] = "REGRESSION: FounderSupportEntryService must preserve the comment 'this service does not read POST'\n"
            . "  → The service must NOT validate step-up internally; the HTTP entry controller is responsible.";
    }
    if (!fileContainsMfa($supportEntrySvcPath, 'isControlPlane')) {
        $violations[] = "MISSING: FounderSupportEntryService must verify actor is a control-plane principal.";
    }
    if (!fileContainsMfa($supportEntrySvcPath, 'logSupportSessionStart')) {
        $violations[] = "MISSING: FounderSupportEntryService must call logSupportSessionStart for audit trail.";
    }
}

// ---------------------------------------------------------------------------
// 5. SessionAuth::beginSupportEntry must call session_regenerate_id
// ---------------------------------------------------------------------------
$sessionAuthPath = $repoRoot . '/system/core/auth/SessionAuth.php';
$checked++;
if (!is_file($sessionAuthPath)) {
    $violations[] = "MISSING: SessionAuth.php";
} else {
    if (!fileContainsMfa($sessionAuthPath, 'session_regenerate_id')) {
        $violations[] = "REGRESSION: SessionAuth must call session_regenerate_id() in beginSupportEntry() for session fixation protection.";
    }
    if (!fileContainsMfa($sessionAuthPath, 'SUPPORT_ACTOR_USER_ID')) {
        $violations[] = "REGRESSION: SessionAuth must maintain SUPPORT_ACTOR_USER_ID session key for support-entry state.";
    }
    if (!fileContainsMfa($sessionAuthPath, 'SUPPORT_SESSION_CORRELATION_ID')) {
        $violations[] = "REGRESSION: SessionAuth must maintain SUPPORT_SESSION_CORRELATION_ID for audit pairing.";
    }
}

// ---------------------------------------------------------------------------
// 6. PolicyAuthorizer must enforce SUPPORT_ACTOR write blocking
// ---------------------------------------------------------------------------
$authorizerPath = $repoRoot . '/system/core/Kernel/Authorization/PolicyAuthorizer.php';
$checked++;
if (!is_file($authorizerPath)) {
    $violations[] = "MISSING: PolicyAuthorizer.php";
} else {
    if (!fileContainsMfa($authorizerPath, 'SUPPORT_ACTOR_ALLOWED_ACTIONS')) {
        $violations[] = "REGRESSION: PolicyAuthorizer must define SUPPORT_ACTOR_ALLOWED_ACTIONS (read-only list).";
    }
    if (!fileContainsMfa($authorizerPath, 'support_actor_write_blocked')) {
        $violations[] = "REGRESSION: PolicyAuthorizer must deny support-actor writes with 'support_actor_write_blocked' reason.";
    }
    if (!fileContainsMfa($authorizerPath, 'decideForSupportActor')) {
        $violations[] = "REGRESSION: PolicyAuthorizer must have decideForSupportActor() method.";
    }
}

// ---------------------------------------------------------------------------
// 7. FounderImpersonationAuditService must implement start+end logging
// ---------------------------------------------------------------------------
$auditSvcPath = $repoRoot . '/system/modules/organizations/services/FounderImpersonationAuditService.php';
$checked++;
if (!is_file($auditSvcPath)) {
    $violations[] = "MISSING: FounderImpersonationAuditService.php";
} else {
    if (!fileContainsMfa($auditSvcPath, 'function logSupportSessionStart')) {
        $violations[] = "MISSING: FounderImpersonationAuditService::logSupportSessionStart()";
    }
    if (!fileContainsMfa($auditSvcPath, 'function logSupportSessionEnd')) {
        $violations[] = "MISSING: FounderImpersonationAuditService::logSupportSessionEnd()";
    }
    if (!fileContainsMfa($auditSvcPath, 'founder_support_session_start')) {
        $violations[] = "MISSING: FounderImpersonationAuditService must log 'founder_support_session_start' event.";
    }
    if (!fileContainsMfa($auditSvcPath, 'founder_support_session_end')) {
        $violations[] = "MISSING: FounderImpersonationAuditService must log 'founder_support_session_end' event.";
    }
    if (!fileContainsMfa($auditSvcPath, 'support_session_correlation_id')) {
        $violations[] = "MISSING: FounderImpersonationAuditService must include correlation_id in audit for session pairing.";
    }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
if ($violations === []) {
    echo '[PASS] PLT-MFA-01 privileged plane step-up guardrail: '
        . $checked . '/' . $checked
        . " critical enforcement surfaces verified — no regressions found.\n";
    exit(0);
}

echo '[FAIL] PLT-MFA-01 privileged plane step-up guardrail: '
    . count($violations) . ' violation(s) found.' . "\n\n";
foreach ($violations as $v) {
    echo '  ✗ ' . $v . "\n\n";
}
exit(1);
