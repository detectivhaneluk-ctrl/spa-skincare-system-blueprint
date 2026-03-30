<?php
/** @var array<string, mixed>|null $founderGuardrailResult */
if (!empty($founderGuardrailResult) && is_array($founderGuardrailResult)):
$wc = (string) ($founderGuardrailResult['what_changed'] ?? '');
$wn = (string) ($founderGuardrailResult['what_unchanged'] ?? '');
$nrUrl = (string) ($founderGuardrailResult['next_review_url'] ?? '');
$nrLabel = (string) ($founderGuardrailResult['next_review_label'] ?? '');
$rb = isset($founderGuardrailResult['rollback_hint']) ? (string) $founderGuardrailResult['rollback_hint'] : '';
?>
<section class="platform-impact-panel" aria-label="Action result summary">
    <h2 class="dashboard-quicklinks__heading">Result summary</h2>
    <?php if ($wc !== ''): ?>
        <p class="platform-control-plane__recent-lead"><strong>What changed</strong> — <?= htmlspecialchars($wc) ?></p>
    <?php endif; ?>
    <?php if ($wn !== ''): ?>
        <p class="platform-control-plane__recent-lead"><strong>What did not change</strong> — <?= htmlspecialchars($wn) ?></p>
    <?php endif; ?>
    <?php if ($nrUrl !== '' && $nrLabel !== ''): ?>
        <p class="platform-control-plane__recent-lead"><strong>Recommended next review</strong> — <a class="tenant-dash-table__link" href="<?= htmlspecialchars($nrUrl) ?>"><?= htmlspecialchars($nrLabel) ?></a></p>
    <?php endif; ?>
    <?php if ($rb !== ''): ?>
        <p class="platform-control-plane__recent-lead" role="note"><strong>Rollback / follow-up</strong> — <?= htmlspecialchars($rb) ?></p>
    <?php endif; ?>
</section>
<?php endif; ?>
