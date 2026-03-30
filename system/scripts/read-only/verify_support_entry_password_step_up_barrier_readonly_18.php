<?php

declare(strict_types=1);

/**
 * CLOSURE-18: privileged support-entry start must require password step-up (not ambient session alone).
 * Static string contract — no DB.
 */
$system = dirname(__DIR__, 2);
$ctrl = (string) file_get_contents($system . '/modules/organizations/controllers/PlatformFounderSupportEntryController.php');
$guard = (string) file_get_contents($system . '/modules/organizations/services/FounderSafeActionGuardrailService.php');
$auth = (string) file_get_contents($system . '/core/auth/AuthService.php');
$safePreviewView = (string) file_get_contents($system . '/modules/organizations/views/platform_control_plane/safe_action_preview.php');
$previewSvc = (string) file_get_contents($system . '/modules/organizations/services/FounderSafeActionPreviewService.php');

$checks = [
    'PlatformFounderSupportEntryController: password step-up then MFA then startForFounderActor' => str_contains($ctrl, 'requireSupportEntryPasswordStepUp')
        && str_contains($ctrl, 'requireSupportEntryControlPlaneMfa')
        && str_contains($ctrl, 'startForFounderActor')
        && strpos($ctrl, 'requireSupportEntryPasswordStepUp') < strpos($ctrl, 'requireSupportEntryControlPlaneMfa')
        && strpos($ctrl, 'requireSupportEntryControlPlaneMfa') < strpos($ctrl, 'startForFounderActor'),
    'FounderSafeActionGuardrailService defines SUPPORT_ENTRY_PASSWORD_CONFIRM_FIELD' => str_contains($guard, 'SUPPORT_ENTRY_PASSWORD_CONFIRM_FIELD')
        && str_contains($guard, 'support_entry_password_confirm'),
    'FounderSafeActionGuardrailService requireSupportEntryPasswordStepUp uses verifyPasswordForUserStepUp' => str_contains($guard, 'requireSupportEntryPasswordStepUp')
        && str_contains($guard, 'verifyPasswordForUserStepUp'),
    'AuthService exposes verifyPasswordForUserStepUp and support-entry throttle id' => str_contains($auth, 'function verifyPasswordForUserStepUp')
        && str_contains($auth, 'support_entry_stepup:')
        && str_contains($auth, 'assertSupportEntryPasswordStepUpAllowed'),
    'safe_action_preview renders password field when require_support_entry_password_step_up' => str_contains($safePreviewView, 'require_support_entry_password_step_up')
        && str_contains($safePreviewView, 'SUPPORT_ENTRY_PASSWORD_CONFIRM_FIELD')
        && str_contains($safePreviewView, 'type="password"'),
    'FounderSafeActionPreviewService sets require_support_entry_password_step_up for support entry' => str_contains($previewSvc, 'require_support_entry_password_step_up')
        && str_contains($previewSvc, '/platform-admin/support-entry/start'),
    'FounderSafeActionPreviewService sets require_support_entry_control_plane_mfa for support entry' => str_contains($previewSvc, 'require_support_entry_control_plane_mfa')
        && str_contains($previewSvc, 'buildSupportEntryPreview'),
    'safe_action_preview renders control-plane TOTP for support entry when flagged' => str_contains($safePreviewView, 'require_support_entry_control_plane_mfa')
        && str_contains($safePreviewView, 'PLATFORM_CONTROL_PLANE_TOTP_FIELD'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
