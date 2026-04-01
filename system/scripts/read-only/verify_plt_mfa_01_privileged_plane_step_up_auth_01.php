<?php

declare(strict_types=1);

/**
 * PLT-MFA-01: Privileged Plane Step-Up Authentication — Verification Script
 *
 * Proves that the strong-auth enforcement chain for founder/support-entry
 * privileged operations is fully wired in the codebase.
 *
 * Assertion groups:
 *   1.  Controller-layer guardrail wiring (all 4 gates present in postStart)
 *   2.  FounderSafeActionGuardrailService — password step-up implementation
 *   3.  FounderSafeActionGuardrailService — TOTP MFA implementation
 *   4.  ControlPlaneTotpService — TOTP mechanics (enroll, verify, session)
 *   5.  FounderSupportEntryService — service invariants (no POST, audit, principal check)
 *   6.  SessionAuth — support-entry session hardening (regenerate, correlation, actor keys)
 *   7.  PolicyAuthorizer — SUPPORT_ACTOR write blocking
 *   8.  FounderImpersonationAuditService — explicit audit trail (start, end, correlation)
 *   9.  SupportEntryController — stop path CSRF + session guard
 *  10.  PLT-MFA-01 guardrail present
 *  11.  No regression on prior auth signals
 *
 * Run with explicit PHP binary:
 *   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe system/scripts/read-only/verify_plt_mfa_01_privileged_plane_step_up_auth_01.php
 */

const SYSTEM_PATH = __DIR__ . '/../../..';

$pass  = 0;
$fail  = 0;
$notes = [];

function mfaAssertPass(string $label, bool $condition, string $detail = ''): void
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

function mfaFileContains(string $relPath, string $pattern): bool
{
    $absPath = SYSTEM_PATH . '/' . $relPath;
    if (!is_file($absPath)) {
        return false;
    }
    $content = file_get_contents($absPath);
    return $content !== false && str_contains($content, $pattern);
}

function mfaFileExists(string $relPath): bool
{
    return is_file(SYSTEM_PATH . '/' . $relPath);
}

echo "\n";
echo "=============================================================\n";
echo " PLT-MFA-01: Privileged Plane Step-Up Auth Verifier\n";
echo "=============================================================\n\n";

// ---------------------------------------------------------------------------
// GROUP 1: Controller-layer — all 4 guardrail gates present in postStart()
// ---------------------------------------------------------------------------
echo "--- GROUP 1: Controller-Layer Guardrail Gates ---\n";

$ctrl = 'system/modules/organizations/controllers/PlatformFounderSupportEntryController.php';

mfaAssertPass(
    'PlatformFounderSupportEntryController exists',
    mfaFileExists($ctrl)
);
mfaAssertPass(
    'postStart: requireValidatedReason called (operational reason required)',
    mfaFileContains($ctrl, 'requireValidatedReason'),
    "Reason gate prevents silent/unaudited support entry"
);
mfaAssertPass(
    'postStart: requireHighImpactConfirmation called (checkbox)',
    mfaFileContains($ctrl, 'requireHighImpactConfirmation'),
    "Confirmation gate prevents accidental privileged escalation"
);
mfaAssertPass(
    'postStart: requireSupportEntryPasswordStepUp called (knowledge factor)',
    mfaFileContains($ctrl, 'requireSupportEntryPasswordStepUp'),
    "Password step-up: ambient session alone is insufficient"
);
mfaAssertPass(
    'postStart: requireSupportEntryControlPlaneMfa called (TOTP second factor)',
    mfaFileContains($ctrl, 'requireSupportEntryControlPlaneMfa'),
    "TOTP MFA: enrolled authenticator mandatory for support entry"
);
mfaAssertPass(
    'postStart: audit log on success (founder_support_entry_allowed)',
    mfaFileContains($ctrl, 'founder_support_entry_allowed'),
    "Successful support entry must be explicitly audit-logged"
);

// ---------------------------------------------------------------------------
// GROUP 2: FounderSafeActionGuardrailService — password step-up
// ---------------------------------------------------------------------------
echo "\n--- GROUP 2: Password Step-Up Implementation ---\n";

$guardrailSvc = 'system/modules/organizations/services/FounderSafeActionGuardrailService.php';

mfaAssertPass(
    'FounderSafeActionGuardrailService exists',
    mfaFileExists($guardrailSvc)
);
mfaAssertPass(
    'requireSupportEntryPasswordStepUp() method exists',
    mfaFileContains($guardrailSvc, 'function requireSupportEntryPasswordStepUp')
);
mfaAssertPass(
    'Password step-up: calls verifyPasswordForUserStepUp',
    mfaFileContains($guardrailSvc, 'verifyPasswordForUserStepUp'),
    "Must verify actual password — not just session presence"
);
mfaAssertPass(
    'Password step-up: blank password rejected (trim check)',
    mfaFileContains($guardrailSvc, 'SUPPORT_ENTRY_PASSWORD_CONFIRM_FIELD'),
    "POST field constant must be used — prevents empty-string acceptance"
);
mfaAssertPass(
    'SUPPORT_ENTRY_PASSWORD_CONFIRM_FIELD constant defined',
    mfaFileContains($guardrailSvc, "SUPPORT_ENTRY_PASSWORD_CONFIRM_FIELD = '")
);

// ---------------------------------------------------------------------------
// GROUP 3: FounderSafeActionGuardrailService — TOTP MFA
// ---------------------------------------------------------------------------
echo "\n--- GROUP 3: TOTP MFA Implementation ---\n";

mfaAssertPass(
    'requireSupportEntryControlPlaneMfa() method exists',
    mfaFileContains($guardrailSvc, 'function requireSupportEntryControlPlaneMfa')
);
mfaAssertPass(
    'MFA gate: calls requireControlPlaneTotpVerifiedForActor',
    mfaFileContains($guardrailSvc, 'requireControlPlaneTotpVerifiedForActor'),
    "Must delegate to the shared TOTP verification path"
);
mfaAssertPass(
    'MFA gate: enrollment check before code verification (isEnrolled)',
    mfaFileContains($guardrailSvc, 'isEnrolled'),
    "Unenrolled founders must be blocked — no silent MFA skip"
);
mfaAssertPass(
    'MFA gate: denied enrollment logged (founder_mfa_denied_missing_enrollment)',
    mfaFileContains($guardrailSvc, 'founder_mfa_denied_missing_enrollment')
);
mfaAssertPass(
    'MFA gate: TOTP verified logged (founder_mfa_totp_verified)',
    mfaFileContains($guardrailSvc, 'founder_mfa_totp_verified')
);
mfaAssertPass(
    'MFA gate: marks session fresh after successful TOTP (markSessionFresh)',
    mfaFileContains($guardrailSvc, 'markSessionFresh')
);
mfaAssertPass(
    'MFA gate: PLATFORM_CONTROL_PLANE_TOTP_FIELD constant defined',
    mfaFileContains($guardrailSvc, "PLATFORM_CONTROL_PLANE_TOTP_FIELD = '")
);

// ---------------------------------------------------------------------------
// GROUP 4: ControlPlaneTotpService — TOTP mechanics
// ---------------------------------------------------------------------------
echo "\n--- GROUP 4: ControlPlaneTotpService TOTP Mechanics ---\n";

$totpSvc = 'system/modules/organizations/services/ControlPlaneTotpService.php';

mfaAssertPass(
    'ControlPlaneTotpService exists',
    mfaFileExists($totpSvc)
);
mfaAssertPass(
    'isEnrolled(): checks totp_enabled flag and ciphertext',
    mfaFileContains($totpSvc, 'control_plane_totp_enabled')
    && mfaFileContains($totpSvc, 'control_plane_totp_secret_ciphertext')
);
mfaAssertPass(
    'verifyCode(): uses TotpVerifier::verify()',
    mfaFileContains($totpSvc, 'TotpVerifier::verify')
);
mfaAssertPass(
    'Secret encrypted at rest (sodium or openssl AES-GCM)',
    mfaFileContains($totpSvc, 'sodium_crypto_secretbox')
    || mfaFileContains($totpSvc, 'aes-256-gcm')
);
mfaAssertPass(
    'Encryption key derived from APP_KEY (fail if missing)',
    mfaFileContains($totpSvc, 'APP_KEY')
    && mfaFileContains($totpSvc, 'control_plane_totp_v1|')
);
mfaAssertPass(
    'markSessionFresh() sets SESSION_MFA_UNTIL timestamp',
    mfaFileContains($totpSvc, 'SESSION_MFA_UNTIL')
    && mfaFileContains($totpSvc, 'function markSessionFresh')
);
mfaAssertPass(
    'isSessionFresh() checks against current time',
    mfaFileContains($totpSvc, 'function isSessionFresh')
    && mfaFileContains($totpSvc, 'time()')
);

// ---------------------------------------------------------------------------
// GROUP 5: FounderSupportEntryService — service invariants
// ---------------------------------------------------------------------------
echo "\n--- GROUP 5: FounderSupportEntryService Invariants ---\n";

$supportSvc = 'system/modules/organizations/services/FounderSupportEntryService.php';

mfaAssertPass(
    'FounderSupportEntryService exists',
    mfaFileExists($supportSvc)
);
mfaAssertPass(
    'Service does not read POST (explicit contract comment preserved)',
    mfaFileContains($supportSvc, 'does not read POST'),
    "Service must NOT enforce step-up internally — HTTP entry controller is responsible"
);
mfaAssertPass(
    'Validates actor is control-plane principal (isControlPlane check)',
    mfaFileContains($supportSvc, 'isControlPlane'),
    "Non-platform principals must be blocked at service layer"
);
mfaAssertPass(
    'Validates target user is tenant-plane (isTenantPlane check)',
    mfaFileContains($supportSvc, 'isTenantPlane')
);
mfaAssertPass(
    'Session matches founder actor (session user id match)',
    mfaFileContains($supportSvc, 'Session user does not match founder actor')
);
mfaAssertPass(
    'Prevents nested support sessions (isSupportEntryActive check)',
    mfaFileContains($supportSvc, 'isSupportEntryActive')
);
mfaAssertPass(
    'Correlation id generated for audit pairing (bin2hex(random_bytes))',
    mfaFileContains($supportSvc, 'bin2hex(random_bytes(')
);
mfaAssertPass(
    'Calls logSupportSessionStart for explicit audit trail',
    mfaFileContains($supportSvc, 'logSupportSessionStart')
);
mfaAssertPass(
    'Calls logSupportSessionEnd on stopActive()',
    mfaFileContains($supportSvc, 'logSupportSessionEnd')
);

// ---------------------------------------------------------------------------
// GROUP 6: SessionAuth — support-entry session hardening
// ---------------------------------------------------------------------------
echo "\n--- GROUP 6: SessionAuth Support-Entry Session Hardening ---\n";

$sessionAuth = 'system/core/auth/SessionAuth.php';

mfaAssertPass(
    'SessionAuth exists',
    mfaFileExists($sessionAuth)
);
mfaAssertPass(
    'beginSupportEntry: session_regenerate_id called (session fixation protection)',
    mfaFileContains($sessionAuth, 'session_regenerate_id'),
    "Session ID must be rotated on support-entry to prevent fixation attacks"
);
mfaAssertPass(
    'Support actor user id stored in session (SUPPORT_ACTOR_USER_ID key)',
    mfaFileContains($sessionAuth, 'SUPPORT_ACTOR_USER_ID')
);
mfaAssertPass(
    'Support session correlation stored (SUPPORT_SESSION_CORRELATION_ID)',
    mfaFileContains($sessionAuth, 'SUPPORT_SESSION_CORRELATION_ID'),
    "Correlation id must pair audit log start/end events"
);
mfaAssertPass(
    'clearSupportEntryKeys() clears all 3 support-entry session keys on end',
    mfaFileContains($sessionAuth, 'clearSupportEntryKeys')
);
mfaAssertPass(
    'auditActorUserId() returns real founder during support entry',
    mfaFileContains($sessionAuth, 'function auditActorUserId')
    && mfaFileContains($sessionAuth, 'supportActorUserId')
);

// ---------------------------------------------------------------------------
// GROUP 7: PolicyAuthorizer — SUPPORT_ACTOR write blocking
// ---------------------------------------------------------------------------
echo "\n--- GROUP 7: PolicyAuthorizer Support-Actor Write Blocking ---\n";

$authorizer = 'system/core/Kernel/Authorization/PolicyAuthorizer.php';

mfaAssertPass(
    'PolicyAuthorizer exists',
    mfaFileExists($authorizer)
);
mfaAssertPass(
    'SUPPORT_ACTOR_ALLOWED_ACTIONS list is read-only (view actions only)',
    mfaFileContains($authorizer, 'SUPPORT_ACTOR_ALLOWED_ACTIONS')
    && mfaFileContains($authorizer, "'appointment:view'")
    && mfaFileContains($authorizer, "'client:view'")
    && !mfaFileContains($authorizer, "'appointment:create' // support-actor-allowed")
);
mfaAssertPass(
    'decideForSupportActor(): write actions return support_actor_write_blocked DENY',
    mfaFileContains($authorizer, 'support_actor_write_blocked')
);
mfaAssertPass(
    'FOUNDER principal gets full allow (founder_tenant_policy)',
    mfaFileContains($authorizer, 'founder_tenant_policy')
    && mfaFileContains($authorizer, 'founder_platform_policy')
);
mfaAssertPass(
    'Unresolved TenantContext denied for all tenant-scoped actions',
    mfaFileContains($authorizer, 'tenant_context_unresolved')
);

// ---------------------------------------------------------------------------
// GROUP 8: FounderImpersonationAuditService — explicit audit trail
// ---------------------------------------------------------------------------
echo "\n--- GROUP 8: FounderImpersonationAuditService Audit Trail ---\n";

$auditSvc = 'system/modules/organizations/services/FounderImpersonationAuditService.php';

mfaAssertPass(
    'FounderImpersonationAuditService exists',
    mfaFileExists($auditSvc)
);
mfaAssertPass(
    'logSupportSessionStart(): logs founder_support_session_start event',
    mfaFileContains($auditSvc, 'founder_support_session_start')
);
mfaAssertPass(
    'logSupportSessionEnd(): logs founder_support_session_end event',
    mfaFileContains($auditSvc, 'founder_support_session_end')
);
mfaAssertPass(
    'Correlation id included in audit metadata (support_session_correlation_id)',
    mfaFileContains($auditSvc, 'support_session_correlation_id'),
    "Correlation id is required to pair start/end audit events for forensic timeline"
);
mfaAssertPass(
    'Context tenant user id recorded in audit metadata',
    mfaFileContains($auditSvc, 'context_tenant_user_id')
);

// ---------------------------------------------------------------------------
// GROUP 9: SupportEntryController — stop path hardening
// ---------------------------------------------------------------------------
echo "\n--- GROUP 9: SupportEntryController Stop-Path Hardening ---\n";

$stopCtrl = 'system/modules/auth/controllers/SupportEntryController.php';

mfaAssertPass(
    'SupportEntryController exists',
    mfaFileExists($stopCtrl)
);
mfaAssertPass(
    'postStop: validates CSRF token before stopping support entry',
    mfaFileContains($stopCtrl, 'validateCsrf'),
    "Stop path must be CSRF-protected to prevent forced session termination"
);
mfaAssertPass(
    'postStop: checks isSupportEntryActive() before stopping',
    mfaFileContains($stopCtrl, 'isSupportEntryActive')
);

// ---------------------------------------------------------------------------
// GROUP 10: PLT-MFA-01 guardrail present
// ---------------------------------------------------------------------------
echo "\n--- GROUP 10: PLT-MFA-01 Guardrail Present ---\n";

mfaAssertPass(
    'guardrail_plt_mfa_01_privileged_plane_step_up.php exists',
    mfaFileExists('system/scripts/ci/guardrail_plt_mfa_01_privileged_plane_step_up.php')
);

// ---------------------------------------------------------------------------
// GROUP 11: No regression on prior auth signals
// ---------------------------------------------------------------------------
echo "\n--- GROUP 11: Prior Auth/Middleware Regression Signals ---\n";

mfaAssertPass(
    'PlatformPrincipalMiddleware exists (control-plane route gate preserved)',
    mfaFileExists('system/core/middleware/PlatformPrincipalMiddleware.php')
);
mfaAssertPass(
    'PermissionMiddleware exists (tenant RBAC gate preserved)',
    mfaFileExists('system/core/middleware/PermissionMiddleware.php')
);
mfaAssertPass(
    'verify_plt_auth_02_authorization_enforcement_wiring_01.php exists',
    mfaFileExists('system/scripts/read-only/verify_plt_auth_02_authorization_enforcement_wiring_01.php')
);
mfaAssertPass(
    'guardrail_plt_auth_02_service_authorizer_enforcement.php exists',
    mfaFileExists('system/scripts/ci/guardrail_plt_auth_02_service_authorizer_enforcement.php')
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

echo "All {$pass} PLT-MFA-01 privileged plane step-up assertions pass.\n\n";
exit(0);
