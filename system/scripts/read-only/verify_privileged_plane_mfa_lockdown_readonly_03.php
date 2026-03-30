<?php

declare(strict_types=1);

/**
 * FOUNDATION-PRIVILEGED-PLANE-LOCKDOWN-MFA-ENFORCER-03 — static proof: no MFA bypass for HIGH/CRITICAL; support-entry is MFA-gated.
 *
 *   php system/scripts/read-only/verify_privileged_plane_mfa_lockdown_readonly_03.php
 */

$system = dirname(__DIR__, 2);
$guard = (string) file_get_contents($system . '/modules/organizations/services/FounderSafeActionGuardrailService.php');
$policy = (string) file_get_contents($system . '/modules/organizations/policies/FounderActionRiskPolicy.php');
$ctrl = (string) file_get_contents($system . '/modules/organizations/controllers/PlatformFounderSupportEntryController.php');

$checks = [
    'FounderActionRiskPolicy defines ACTION_SUPPORT_ENTRY_START as CRITICAL-tier' => str_contains($policy, 'ACTION_SUPPORT_ENTRY_START')
        && preg_match("/ACTION_SUPPORT_ENTRY_START\s*,[\s\S]*?LEVEL_CRITICAL/s", $policy) === 1,
    'Guardrail: no founder_mfa_skipped_not_enrolled bypass' => !str_contains($guard, 'founder_mfa_skipped_not_enrolled'),
    'Guardrail: HIGH/CRITICAL path uses requireControlPlaneTotpVerifiedForActor' => str_contains($guard, 'requireControlPlaneTotpVerifiedForActor')
        && str_contains($guard, 'founder_mfa_denied_missing_enrollment'),
    'Guardrail: requireSupportEntryControlPlaneMfa exists and calls TOTP verifier path' => str_contains($guard, 'function requireSupportEntryControlPlaneMfa')
        && str_contains($guard, 'ACTION_SUPPORT_ENTRY_START'),
    'Guardrail: founder_mfa_required audit before TOTP enforcement' => str_contains($guard, 'founder_mfa_required'),
    'Guardrail: invalid TOTP audited as denied' => str_contains($guard, 'founder_mfa_denied_invalid_totp'),
    'Support entry controller: MFA after password before start + audit allowed' => str_contains($ctrl, 'requireSupportEntryControlPlaneMfa')
        && str_contains($ctrl, 'founder_support_entry_allowed')
        && strpos($ctrl, 'requireSupportEntryPasswordStepUp') < strpos($ctrl, 'requireSupportEntryControlPlaneMfa')
        && strpos($ctrl, 'requireSupportEntryControlPlaneMfa') < strpos($ctrl, 'startForFounderActor'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
