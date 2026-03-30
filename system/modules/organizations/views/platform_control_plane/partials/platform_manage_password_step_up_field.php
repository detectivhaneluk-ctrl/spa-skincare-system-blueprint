<?php
declare(strict_types=1);
?>
<p class="platform-control-plane__recent-lead platform-manage-stepup-hint">
    <label>
        Your password (step-up re-auth, not MFA)
        <input type="password" name="<?= htmlspecialchars(\Modules\Organizations\Services\FounderSafeActionGuardrailService::PLATFORM_MANAGE_PASSWORD_CONFIRM_FIELD) ?>" required autocomplete="current-password" maxlength="500">
    </label>
</p>
<p class="platform-control-plane__recent-lead platform-manage-stepup-hint">
    <label>
        Authenticator code (6 digits) — required for HIGH/CRITICAL platform actions when you have enrolled control-plane MFA (mandatory enrollment for those actions)
        <input type="text" name="<?= htmlspecialchars(\Modules\Organizations\Services\FounderSafeActionGuardrailService::PLATFORM_CONTROL_PLANE_TOTP_FIELD) ?>" autocomplete="one-time-code" inputmode="numeric" maxlength="6" placeholder="6-digit code">
    </label>
</p>
