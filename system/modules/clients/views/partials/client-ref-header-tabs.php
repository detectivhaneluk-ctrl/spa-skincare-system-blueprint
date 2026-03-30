<?php
/** @var array<string, mixed> $client */
/** @var int $clientId */
/** @var list<array<string, mixed>> $recentAppointments */
/** @var array<string, mixed> $appointmentSummary */
/** @var string $clientRefActiveTab 'resume'|'details'|'commentaires'|'rdv'|'sales'|'billing'|'photos'|'mail_marketing'|'documents' */
/** @var bool $clientRefDedicatedAppointments When true, dedicated /clients/{id}/appointments surface (layout hooks only). */
/** @var bool $clientRefDedicatedDetails When true, dedicated Client Details (/edit) surface (layout hooks only). */
/** @var bool $clientRefTitleRowSecondaryTab Optional title-row style for dedicated client tab pages (sales, billing, etc.). */

$fmtApptDate = static function ($raw): string {
    if ($raw === null || $raw === '') {
        return '—';
    }
    $t = strtotime((string) $raw);

    return $t ? date('Y-m-d H:i', $t) : (string) $raw;
};

$apptListed = is_array($recentAppointments) ? $recentAppointments : [];
$apptListedCount = count($apptListed);
$lastApptLabel = '—';
$firstApptLabel = '—';
$apptDatesFootnote = '';
$lastFromSummary = $appointmentSummary['last_start_at'] ?? null;
$firstFromSummary = $appointmentSummary['first_start_at'] ?? null;
if (is_string($lastFromSummary) && $lastFromSummary !== '') {
    $lastApptLabel = $fmtApptDate($lastFromSummary);
}
if (is_string($firstFromSummary) && $firstFromSummary !== '') {
    $firstApptLabel = $fmtApptDate($firstFromSummary);
}
if (($lastApptLabel === '—' || $firstApptLabel === '—') && $apptListedCount > 0) {
    $lastApptLabel = $fmtApptDate($apptListed[0]['start_at'] ?? '');
    $firstApptLabel = $fmtApptDate($apptListed[$apptListedCount - 1]['start_at'] ?? '');
}
$apptTotalAll = (int) ($appointmentSummary['total'] ?? 0);
if (empty($clientRefHideAppointmentListFootnote) && $apptTotalAll > $apptListedCount && $apptListedCount > 0) {
    $apptDatesFootnote = 'Preview based on the ' . $apptListedCount . ' most recent appointments loaded for the header; recorded total: ' . $apptTotalAll . '.';
}

/** Client summary (#client-ref-rdv / #client-ref-ventes on resume); appointments also at /clients/{id}/appointments. */
$cid = (int) $clientId;
$resumeUrl = '/clients/' . $cid;
$detailsUrl = '/clients/' . $cid . '/edit';
$commentairesUrl = '/clients/' . $cid . '/commentaires';
$appointmentsUrl = '/clients/' . $cid . '/appointments';
$salesTabUrl = '/clients/' . $cid . '/sales';
$billingTabUrl = '/clients/' . $cid . '/billing';
$photosTabUrl = '/clients/' . $cid . '/photos';
$mailMarketingTabUrl = '/clients/' . $cid . '/mail-marketing';
$documentsTabUrl = '/clients/' . $cid . '/documents';
?>
    <div class="client-ref-title-row<?= !empty($clientRefDedicatedAppointments) ? ' client-ref-title-row--appointments-page' : '' ?><?= !empty($clientRefDedicatedDetails) ? ' client-ref-title-row--details-page' : '' ?><?= !empty($clientRefTitleRowSecondaryTab) ? ' client-ref-title-row--secondary-tab' : '' ?>">
        <h1 class="client-ref-title"><?= htmlspecialchars((string) ($client['display_name'] ?? '')) ?></h1>
        <div class="client-ref-title-meta">
            <div><span class="client-ref-meta-label">Last visit</span> <?= htmlspecialchars($lastApptLabel) ?></div>
            <div><span class="client-ref-meta-label">First appointment</span> <?= htmlspecialchars($firstApptLabel) ?></div>
            <?php if ($apptDatesFootnote !== ''): ?>
            <p class="hint client-ref-meta-footnote"><?= htmlspecialchars($apptDatesFootnote) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <nav class="client-ref-tabs" aria-label="Client workspace">
        <?php if ($clientRefActiveTab === 'resume'): ?>
        <span class="client-ref-tab client-ref-tab--active" aria-current="page">Summary</span>
        <?php else: ?>
        <a class="client-ref-tab" href="<?= htmlspecialchars($resumeUrl) ?>">Summary</a>
        <?php endif; ?>
        <?php if ($clientRefActiveTab === 'details'): ?>
        <span class="client-ref-tab client-ref-tab--active" aria-current="page">Details</span>
        <?php else: ?>
        <a class="client-ref-tab" href="<?= htmlspecialchars($detailsUrl) ?>">Details</a>
        <?php endif; ?>
        <?php if ($clientRefActiveTab === 'commentaires'): ?>
        <span class="client-ref-tab client-ref-tab--active" aria-current="page">Comments</span>
        <?php else: ?>
        <a class="client-ref-tab" href="<?= htmlspecialchars($commentairesUrl) ?>">Comments</a>
        <?php endif; ?>
        <?php if ($clientRefActiveTab === 'rdv'): ?>
        <span class="client-ref-tab client-ref-tab--active" aria-current="page">Appointments</span>
        <?php else: ?>
        <a class="client-ref-tab" href="<?= htmlspecialchars($appointmentsUrl) ?>">Appointments</a>
        <?php endif; ?>
        <?php if ($clientRefActiveTab === 'sales'): ?>
        <span class="client-ref-tab client-ref-tab--active" aria-current="page">Sales</span>
        <?php else: ?>
        <a class="client-ref-tab" href="<?= htmlspecialchars($salesTabUrl) ?>">Sales</a>
        <?php endif; ?>
        <?php if ($clientRefActiveTab === 'billing'): ?>
        <span class="client-ref-tab client-ref-tab--active" aria-current="page">Billing</span>
        <?php else: ?>
        <a class="client-ref-tab" href="<?= htmlspecialchars($billingTabUrl) ?>">Billing</a>
        <?php endif; ?>
        <?php if ($clientRefActiveTab === 'photos'): ?>
        <span class="client-ref-tab client-ref-tab--active" aria-current="page">Photos</span>
        <?php else: ?>
        <a class="client-ref-tab" href="<?= htmlspecialchars($photosTabUrl) ?>">Photos</a>
        <?php endif; ?>
        <?php if ($clientRefActiveTab === 'mail_marketing'): ?>
        <span class="client-ref-tab client-ref-tab--active" aria-current="page">Mail marketing</span>
        <?php else: ?>
        <a class="client-ref-tab" href="<?= htmlspecialchars($mailMarketingTabUrl) ?>">Mail marketing</a>
        <?php endif; ?>
        <?php if ($clientRefActiveTab === 'documents'): ?>
        <span class="client-ref-tab client-ref-tab--active" aria-current="page">Document storage</span>
        <?php else: ?>
        <a class="client-ref-tab" href="<?= htmlspecialchars($documentsTabUrl) ?>">Document storage</a>
        <?php endif; ?>
    </nav>
