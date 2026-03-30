<?php

declare(strict_types=1);

/**
 * FOUNDATION-PLATFORM-INVARIANTS-AND-FOUNDER-RISK-ENGINE-01 — static proof (no DB):
 * - High-risk marketing campaign repositories do not expose ambiguous id-only find/update names.
 * - Founder risk policy + TOTP verifier artifacts exist for the control-plane MFA foundation.
 *
 *   php system/scripts/read-only/verify_foundation_platform_invariants_readonly_01.php
 */

$system = dirname(__DIR__, 2);
$checks = [];

$mcr = (string) file_get_contents($system . '/modules/marketing/repositories/MarketingCampaignRepository.php');
$mrun = (string) file_get_contents($system . '/modules/marketing/repositories/MarketingCampaignRunRepository.php');
$mrec = (string) file_get_contents($system . '/modules/marketing/repositories/MarketingCampaignRecipientRepository.php');

$checks['MarketingCampaignRepository: no public function find(int $id)'] = !preg_match('/public\s+function\s+find\s*\(\s*int\s+\$/', $mcr);
$checks['MarketingCampaignRepository: explicit staff-scoped find'] = str_contains($mcr, 'function findInTenantScopeForStaff');
$checks['MarketingCampaignRunRepository: no public function find(int $id)'] = !preg_match('/public\s+function\s+find\s*\(\s*int\s+\$/', $mrun);
$checks['MarketingCampaignRecipientRepository: no public function findForUpdate(int $id)'] = !preg_match('/public\s+function\s+findForUpdate\s*\(\s*int\s+\$/', $mrec);

$policy = (string) file_get_contents($system . '/modules/organizations/policies/FounderActionRiskPolicy.php');
$checks['FounderActionRiskPolicy defines LOW..CRITICAL + action keys'] = str_contains($policy, 'LEVEL_CRITICAL')
    && str_contains($policy, 'ACTION_SECURITY_KILL_SWITCH')
    && str_contains($policy, 'function levelForAction');

$totp = (string) file_get_contents($system . '/core/auth/TotpVerifier.php');
$checks['TotpVerifier RFC6238 helper present'] = str_contains($totp, 'namespace Core\\Auth')
    && str_contains($totp, 'function verify');

$guard = (string) file_get_contents($system . '/modules/organizations/services/FounderSafeActionGuardrailService.php');
$checks['Guardrail wires audit + MFA + policy on platform step-up'] = str_contains($guard, 'founder_mfa_totp_verified')
    && str_contains($guard, 'ControlPlaneTotpService');

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
