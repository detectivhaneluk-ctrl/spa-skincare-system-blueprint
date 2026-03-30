<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\Auth\AuthService;
use Core\Audit\AuditService;
use InvalidArgumentException;
use Modules\Organizations\Policies\FounderActionRiskPolicy;

/**
 * Validates operator reason text and builds standard audit metadata for founder guardrails.
 * FOUNDER-OPS-SAFE-ACTION-GUARDRAILS-01 + FOUNDATION-PLATFORM-INVARIANTS-AND-FOUNDER-RISK-ENGINE-01.
 */
final class FounderSafeActionGuardrailService
{
    public const MIN_REASON_LENGTH = 10;
    public const MAX_REASON_LENGTH = 2000;

    /** POST field for password step-up before privileged support-entry start (not MFA; knowledge-factor re-auth). */
    public const SUPPORT_ENTRY_PASSWORD_CONFIRM_FIELD = 'support_entry_password_confirm';

    /** POST field: operator password before high-impact platform.manage mutations (not MFA). */
    public const PLATFORM_MANAGE_PASSWORD_CONFIRM_FIELD = 'platform_manage_password_confirm';

    /** POST field: 6-digit TOTP when enrolled and action risk is HIGH/CRITICAL (founder control-plane MFA). */
    public const PLATFORM_CONTROL_PLANE_TOTP_FIELD = 'platform_control_plane_totp_code';

    public function __construct(
        private AuthService $auth,
        private AuditService $audit,
        private FounderActionRiskPolicy $riskPolicy,
        private ControlPlaneTotpService $controlPlaneTotp,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function requireValidatedReason(string $raw): string
    {
        $r = trim($raw);
        if (strlen($r) < self::MIN_REASON_LENGTH) {
            throw new InvalidArgumentException(
                'Enter an operational reason (at least ' . self::MIN_REASON_LENGTH . ' characters) so the audit trail is usable.'
            );
        }
        if (strlen($r) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException('Reason is too long (max ' . self::MAX_REASON_LENGTH . ' characters).');
        }

        return $r;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function auditMetadata(string $reason, string $effectSummary, string $reversibilityLabel, array $extra = []): array
    {
        return array_merge([
            'operator_reason' => $reason,
            'effect_summary' => $effectSummary,
            'reversibility' => $reversibilityLabel,
        ], $extra);
    }

    public function requireHighImpactConfirmation(): void
    {
        $v = $_POST['confirm_high_impact'] ?? '';
        if ((string) $v !== '1') {
            throw new InvalidArgumentException(
                'Check the confirmation box to acknowledge this high-impact action before it can be applied.'
            );
        }
    }

    /**
     * Mandatory password step-up for founder support-entry start. Ambient session alone is insufficient.
     */
    public function requireSupportEntryPasswordStepUp(int $founderUserId): void
    {
        $this->auth->assertSupportEntryPasswordStepUpAllowed($founderUserId);
        $raw = (string) ($_POST[self::SUPPORT_ENTRY_PASSWORD_CONFIRM_FIELD] ?? '');
        if (trim($raw) === '') {
            throw new InvalidArgumentException(
                'Enter your account password to confirm support entry (step-up re-authentication).'
            );
        }
        if (!$this->auth->verifyPasswordForUserStepUp($founderUserId, $raw)) {
            throw new InvalidArgumentException(
                'Password confirmation failed. Re-enter your password to start support entry.'
            );
        }
    }

    /**
     * Password step-up (always) + control-plane TOTP for HIGH/CRITICAL: enrolled MFA is mandatory (no silent skip).
     *
     * @param non-empty-string $actionKey one of {@see FounderActionRiskPolicy} ACTION_* constants
     */
    public function requirePlatformManagePasswordStepUp(int $operatorUserId, string $actionKey = FounderActionRiskPolicy::ACTION_PLATFORM_MANAGE_FALLBACK): void
    {
        $level = $this->riskPolicy->levelForAction($actionKey);

        $this->auth->assertPlatformManagePasswordStepUpAllowed($operatorUserId);
        $raw = (string) ($_POST[self::PLATFORM_MANAGE_PASSWORD_CONFIRM_FIELD] ?? '');
        if (trim($raw) === '') {
            throw new InvalidArgumentException(
                'Enter your account password to confirm this platform action (step-up re-authentication, not MFA).'
            );
        }
        if (!$this->auth->verifyPasswordForPlatformManageStepUp($operatorUserId, $raw)) {
            throw new InvalidArgumentException(
                'Password confirmation failed. Re-enter your password to apply this action.'
            );
        }

        $this->audit->log('founder_step_up_password_verified', 'platform_control_plane', null, $operatorUserId, null, [
            'action_key' => $actionKey,
            'risk_level' => $level,
        ], 'success', 'platform_control');

        \slog('info', 'critical_path.auth', 'platform_manage_step_up_password_ok', [
            'user_id' => $operatorUserId,
            'action_key' => $actionKey,
        ]);

        if (!$this->riskPolicy->requiresTotpWhenEnrolled($level)) {
            return;
        }

        $this->audit->log('founder_mfa_required', 'platform_control_plane', $operatorUserId, $operatorUserId, null, [
            'action_key' => $actionKey,
            'risk_level' => $level,
        ], null, 'platform_control');

        $this->requireControlPlaneTotpVerifiedForActor($operatorUserId, $actionKey, $level);

        \slog('info', 'critical_path.auth', 'platform_manage_totp_ok', [
            'user_id' => $operatorUserId,
            'action_key' => $actionKey,
        ]);
    }

    /**
     * After password step-up: require enrolled control-plane TOTP (or fresh MFA session) before support-entry bootstrap.
     *
     * @throws InvalidArgumentException when not enrolled, code missing/invalid, or session not fresh without code
     */
    public function requireSupportEntryControlPlaneMfa(int $founderUserId): void
    {
        $actionKey = FounderActionRiskPolicy::ACTION_SUPPORT_ENTRY_START;
        $level = $this->riskPolicy->levelForAction($actionKey);

        $this->audit->log('founder_mfa_required', 'platform_control_plane', $founderUserId, $founderUserId, null, [
            'action_key' => $actionKey,
            'risk_level' => $level,
            'gate' => 'support_entry_start',
        ], null, 'platform_control');

        $this->requireControlPlaneTotpVerifiedForActor($founderUserId, $actionKey, $level);

        \slog('info', 'critical_path.auth', 'support_entry_mfa_ok', [
            'user_id' => $founderUserId,
            'action_key' => $actionKey,
        ]);
    }

    /**
     * @param string $level {@see FounderActionRiskPolicy} LEVEL_* (HIGH/CRITICAL paths only)
     */
    private function requireControlPlaneTotpVerifiedForActor(int $operatorUserId, string $actionKey, string $level): void
    {
        if (!$this->controlPlaneTotp->isEnrolled($operatorUserId)) {
            $this->audit->log('founder_mfa_denied_missing_enrollment', 'platform_control_plane', $operatorUserId, $operatorUserId, null, [
                'action_key' => $actionKey,
                'risk_level' => $level,
            ], 'denied', 'platform_control');

            throw new InvalidArgumentException(
                'Control-plane authenticator (TOTP) must be enrolled for this action. Enroll in platform security settings, then retry.'
            );
        }

        if ($this->controlPlaneTotp->isSessionFresh()) {
            $this->audit->log('founder_mfa_session_reuse', 'platform_control_plane', $operatorUserId, $operatorUserId, null, [
                'action_key' => $actionKey,
                'risk_level' => $level,
            ], 'success', 'platform_control');

            return;
        }

        $totp = trim((string) ($_POST[self::PLATFORM_CONTROL_PLANE_TOTP_FIELD] ?? ''));
        if ($totp === '' || !$this->controlPlaneTotp->verifyCode($operatorUserId, $totp)) {
            $this->audit->log('founder_mfa_denied_invalid_totp', 'platform_control_plane', $operatorUserId, $operatorUserId, null, [
                'action_key' => $actionKey,
                'risk_level' => $level,
            ], 'denied', 'platform_control');

            throw new InvalidArgumentException(
                'Enter a valid 6-digit authenticator code (control-plane MFA) to complete this action.'
            );
        }

        $this->controlPlaneTotp->markSessionFresh();
        $this->audit->log('founder_mfa_totp_verified', 'platform_control_plane', $operatorUserId, $operatorUserId, null, [
            'action_key' => $actionKey,
            'risk_level' => $level,
        ], 'success', 'platform_control');
    }
}
