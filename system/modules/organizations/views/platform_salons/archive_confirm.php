<?php
/** @var string $csrf */
/** @var string $title */
/** @var array<string, mixed> $preview */
/** @var int $salonId */
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
if (!empty($preview['error'])): ?>
<div class="workspace-shell platform-control-plane">
    <p class="platform-control-plane__recent-lead" role="alert"><?= htmlspecialchars((string) $preview['error']) ?></p>
    <p class="platform-control-plane__recent-lead"><a class="tenant-dash-table__link" href="/platform-admin/salons/<?= (int) $salonId ?>">Back to salon</a></p>
</div>
<?php else:

$rev = (string) ($preview['reversibility'] ?? '');
$revLabel = match ($rev) {
    'reversible' => 'Reversible',
    'not_easily_reversible' => 'Not easily reversible',
    'requires_follow_up' => 'Requires follow-up',
    default => $rev,
};
$postUrl = (string) ($preview['post_url'] ?? '#');
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

        <form method="post" action="<?= htmlspecialchars($postUrl) ?>" class="tenant-dash-form-row platform-guided-wizard__form">
            <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <label>Operational reason <textarea name="action_reason" required minlength="<?= (int) \Modules\Organizations\Services\FounderSafeActionGuardrailService::MIN_REASON_LENGTH ?>" rows="3" cols="60" placeholder="Ticket, incident id, or ops context (required for audit)."></textarea></label>
            <label class="platform-guided-wizard__confirm"><input type="checkbox" name="confirm_high_impact" value="1" required> <?= htmlspecialchars((string) ($preview['confirm_checkbox_label'] ?? 'I have read the summary above and this is the intended action.')) ?></label>
            <label class="platform-guided-wizard__confirm"><input type="checkbox" name="confirm_archive_salon" value="1" required> I understand this salon will be soft-archived and normal founder operations will no longer apply to it from this control plane.</label>
            <?php require base_path('modules/organizations/views/platform_control_plane/partials/platform_manage_password_step_up_field.php'); ?>
            <p><button type="submit"><?= htmlspecialchars((string) ($preview['submit_label'] ?? 'Archive salon')) ?></button></p>
        </form>
    </section>
</div>
<?php endif; ?>
