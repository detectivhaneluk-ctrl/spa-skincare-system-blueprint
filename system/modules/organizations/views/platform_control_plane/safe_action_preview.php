<?php
/** @var string $csrf */
/** @var string $title */
/** @var array<string, mixed> $preview */
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
if (!empty($preview['error'])): ?>
<div class="workspace-shell platform-control-plane">
    <p class="platform-control-plane__recent-lead" role="alert"><?= htmlspecialchars((string) $preview['error']) ?></p>
    <p class="platform-control-plane__recent-lead"><a class="tenant-dash-table__link" href="/platform-admin">Dashboard</a></p>
</div>
<?php elseif (!empty($preview['salon_founder_confirm'])):
    require base_path('modules/organizations/views/platform_control_plane/partials/salon_lifecycle_founder_confirm.php');
else:

$postUrl = (string) ($preview['post_url'] ?? '#');
$extra = $preview['extra_hidden'] ?? [];
if (!is_array($extra)) {
    $extra = [];
}
$rev = (string) ($preview['reversibility'] ?? '');
$revLabel = match ($rev) {
    'reversible' => 'Reversible',
    'not_easily_reversible' => 'Not easily reversible',
    'requires_follow_up' => 'Requires follow-up action',
    default => $rev,
};
?>
<div class="workspace-shell platform-control-plane">
    <header class="workspace-module-head platform-control-plane__head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars((string) ($preview['title'] ?? '')) ?></h1>
            <p class="workspace-module-head__sub"><?= htmlspecialchars((string) ($preview['headline'] ?? '')) ?></p>
        </div>
    </header>

    <section class="platform-impact-panel" aria-label="Preview">
        <h2 class="dashboard-quicklinks__heading">What will be affected</h2>
        <ul class="tenant-dash-attention__list">
            <?php foreach ($preview['preview_bullets'] ?? [] as $line): ?>
                <li><?= htmlspecialchars((string) $line) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="platform-impact-panel" aria-label="Impact detail">
        <h2 class="dashboard-quicklinks__heading">Change summary</h2>
        <dl class="platform-control-plane__meta">
            <div class="platform-control-plane__meta-row"><dt>What will change</dt><dd><?= htmlspecialchars((string) ($preview['what_will_change'] ?? '')) ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>What will stay the same</dt><dd><?= htmlspecialchars((string) ($preview['what_stays'] ?? '')) ?></dd></div>
            <div class="platform-control-plane__meta-row"><dt>Reversibility</dt><dd><?= htmlspecialchars($revLabel) ?> — <?= htmlspecialchars((string) ($preview['reversibility_detail'] ?? '')) ?></dd></div>
            <?php if (!empty($preview['rollback_hint'])): ?>
                <div class="platform-control-plane__meta-row"><dt>Rollback / follow-up</dt><dd><?= htmlspecialchars((string) $preview['rollback_hint']) ?></dd></div>
            <?php endif; ?>
        </dl>
    </section>

    <section class="platform-impact-panel" aria-label="Confirm">
        <h2 class="dashboard-quicklinks__heading">Operator confirmation</h2>
        <p class="platform-control-plane__recent-lead">Type an operational reason (audit trail). Minimum <?= (int) \Modules\Organizations\Services\FounderSafeActionGuardrailService::MIN_REASON_LENGTH ?> characters.</p>

        <?php if (($preview['post_url'] ?? '') === '/platform-admin/security/public-surface'): ?>
            <?php
            $kd = $preview['kill_desired'] ?? ['kill_online_booking' => false, 'kill_anonymous_public_apis' => false, 'kill_public_commerce' => false];
            ?>
            <form method="post" action="<?= htmlspecialchars($postUrl) ?>" class="tenant-dash-form-row platform-guided-wizard__form">
                <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <label><input type="checkbox" name="kill_online_booking" value="1"<?= !empty($kd['kill_online_booking']) ? ' checked' : '' ?>> Block online booking (anonymous/public)</label>
                <label><input type="checkbox" name="kill_anonymous_public_apis" value="1"<?= !empty($kd['kill_anonymous_public_apis']) ? ' checked' : '' ?>> Block anonymous public APIs</label>
                <label><input type="checkbox" name="kill_public_commerce" value="1"<?= !empty($kd['kill_public_commerce']) ? ' checked' : '' ?>> Block public commerce</label>
                <label>Operational reason <textarea name="action_reason" required minlength="<?= (int) \Modules\Organizations\Services\FounderSafeActionGuardrailService::MIN_REASON_LENGTH ?>" rows="3" cols="60" placeholder="Why are you changing deployment-wide public stops? Ticket or incident reference."></textarea></label>
                <label class="platform-guided-wizard__confirm"><input type="checkbox" name="confirm_high_impact" value="1" required> <?= htmlspecialchars((string) ($preview['confirm_checkbox_label'] ?? 'I understand this changes anonymous/public traffic deployment-wide and is recorded in audit.')) ?></label>
                <?php if (!empty($preview['require_platform_manage_password_step_up'])) {
                    require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php');
                } ?>
                <p><button type="submit">Apply kill switch settings</button></p>
            </form>
        <?php else: ?>
            <form method="post" action="<?= htmlspecialchars($postUrl) ?>" class="tenant-dash-form-row platform-guided-wizard__form">
                <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
                <?php foreach ($extra as $k => $v): ?>
                    <input type="hidden" name="<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
                <?php endforeach; ?>
                <label>Operational reason <textarea name="action_reason" required minlength="<?= (int) \Modules\Organizations\Services\FounderSafeActionGuardrailService::MIN_REASON_LENGTH ?>" rows="3" cols="60" placeholder="Ticket, incident id, or ops context (required for audit)."></textarea></label>
                <?php if (!empty($preview['require_support_entry_password_step_up'])): ?>
                    <label>Confirm your password (step-up re-auth, not MFA) <input type="password" name="<?= htmlspecialchars(\Modules\Organizations\Services\FounderSafeActionGuardrailService::SUPPORT_ENTRY_PASSWORD_CONFIRM_FIELD) ?>" required autocomplete="current-password" maxlength="500"></label>
                <?php endif; ?>
                <?php if (!empty($preview['require_support_entry_control_plane_mfa'])): ?>
                    <label>Authenticator code (6 digits) — required unless you already passed control-plane MFA recently in this session
                        <input type="text" name="<?= htmlspecialchars(\Modules\Organizations\Services\FounderSafeActionGuardrailService::PLATFORM_CONTROL_PLANE_TOTP_FIELD) ?>" autocomplete="one-time-code" inputmode="numeric" maxlength="6" placeholder="000000">
                    </label>
                <?php endif; ?>
                <label class="platform-guided-wizard__confirm"><input type="checkbox" name="confirm_high_impact" value="1" required> <?= htmlspecialchars((string) ($preview['confirm_checkbox_label'] ?? 'I have read the summary above and this is the intended action.')) ?></label>
                <?php if (!empty($preview['require_platform_manage_password_step_up'])) {
                    require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php');
                } ?>
                <p><button type="submit"><?= htmlspecialchars((string) ($preview['submit_label'] ?? 'Apply action')) ?></button></p>
            </form>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
