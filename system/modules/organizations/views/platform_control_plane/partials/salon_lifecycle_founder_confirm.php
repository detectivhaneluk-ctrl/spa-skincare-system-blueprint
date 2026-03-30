<?php
/** @var array<string, mixed> $preview */
/** @var string $csrf */
$csrfField = (string) config('app.csrf_token_name', 'csrf_token');
$postUrl = (string) ($preview['post_url'] ?? '#');
$extra = $preview['extra_hidden'] ?? [];
if (!is_array($extra)) {
    $extra = [];
}
$cancelUrl = (string) ($preview['cancel_url'] ?? '/platform-admin/salons');
$minReason = (int) \Modules\Organizations\Services\FounderSafeActionGuardrailService::MIN_REASON_LENGTH;
$salonName = trim((string) ($preview['founder_salon_name'] ?? ''));
$lede = trim((string) ($preview['founder_lede'] ?? ''));
$transition = trim((string) ($preview['founder_transition'] ?? ''));
$changes = $preview['founder_changes'] ?? [];
$changes = is_array($changes) ? $changes : [];
$stays = $preview['founder_stays'] ?? [];
$stays = is_array($stays) ? $stays : [];
$auditNote = trim((string) ($preview['founder_audit_note'] ?? ''));
?>
<div class="workspace-shell platform-control-plane founder-confirm">
    <header class="founder-confirm__hero">
        <a class="founder-confirm__back" href="<?= htmlspecialchars($cancelUrl) ?>">← Salon</a>
        <h1 class="founder-confirm__title"><?= htmlspecialchars((string) ($preview['title'] ?? '')) ?></h1>
        <?php if ($salonName !== ''): ?>
            <p class="founder-confirm__salon"><?= htmlspecialchars($salonName) ?></p>
        <?php endif; ?>
        <?php if ($transition !== ''): ?>
            <p class="founder-confirm__transition"><?= htmlspecialchars($transition) ?></p>
        <?php endif; ?>
        <?php if ($lede !== ''): ?>
            <p class="founder-confirm__lede"><?= htmlspecialchars($lede) ?></p>
        <?php endif; ?>
    </header>

    <section class="founder-confirm__card" aria-label="Summary">
        <h2 class="founder-confirm__h">What changes</h2>
        <ul class="founder-confirm__list">
            <?php foreach ($changes as $line): ?>
                <li><?= htmlspecialchars((string) $line) ?></li>
            <?php endforeach; ?>
        </ul>
        <h2 class="founder-confirm__h founder-confirm__h--second">What stays the same</h2>
        <ul class="founder-confirm__list">
            <?php foreach ($stays as $line): ?>
                <li><?= htmlspecialchars((string) $line) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php if ($auditNote !== ''): ?>
            <p class="founder-confirm__audit"><?= htmlspecialchars($auditNote) ?></p>
        <?php endif; ?>
    </section>

    <section class="founder-confirm__confirm" aria-label="Confirm">
        <form method="post" action="<?= htmlspecialchars($postUrl) ?>" class="founder-confirm__form">
            <input type="hidden" name="<?= htmlspecialchars($csrfField) ?>" value="<?= htmlspecialchars($csrf) ?>">
            <?php foreach ($extra as $k => $v): ?>
                <input type="hidden" name="<?= htmlspecialchars((string) $k) ?>" value="<?= htmlspecialchars((string) $v) ?>">
            <?php endforeach; ?>
            <label class="founder-confirm__label" for="action_reason">Reason <span class="founder-confirm__req">(required, min <?= $minReason ?> characters)</span></label>
            <textarea id="action_reason" class="founder-confirm__textarea" name="action_reason" required minlength="<?= $minReason ?>" rows="3" cols="60" placeholder="Short note for your records"></textarea>
            <label class="founder-confirm__check">
                <input type="checkbox" name="confirm_high_impact" value="1" required>
                <?= htmlspecialchars((string) ($preview['confirm_checkbox_label'] ?? 'I confirm this action.')) ?>
            </label>
            <div class="founder-confirm__actions">
                <button type="submit" class="founder-ctl-btn founder-ctl-btn--primary"><?= htmlspecialchars((string) ($preview['submit_label'] ?? 'Continue')) ?></button>
                <a class="founder-confirm__cancel" href="<?= htmlspecialchars($cancelUrl) ?>">Cancel</a>
            </div>
        </form>
    </section>
</div>
