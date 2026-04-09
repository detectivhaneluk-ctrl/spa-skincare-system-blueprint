<?php
/**
 * Context sidebar for client details edit (résumé + booking snapshot).
 *
 * @var array<string, mixed> $client
 * @var array<string, mixed> $appointmentSummary
 * @var list<array<string, mixed>> $recentAppointments
 * @var array<string, mixed> $salesSummary
 * @var string|null $clientRefPrimaryPhotoUrl
 */
$appointmentSummary = is_array($appointmentSummary ?? null) ? $appointmentSummary : [];
$recentAppointments = is_array($recentAppointments ?? null) ? $recentAppointments : [];
$salesSummary = is_array($salesSummary ?? null) ? $salesSummary : [];

$displayName = trim((string) ($client['display_name'] ?? ''));
if ($displayName === '') {
    $displayName = trim(trim((string) ($client['first_name'] ?? '')) . ' ' . trim((string) ($client['last_name'] ?? '')));
}
if ($displayName === '') {
    $displayName = 'Customer';
}

$photoUrl = isset($clientRefPrimaryPhotoUrl) && is_string($clientRefPrimaryPhotoUrl) && $clientRefPrimaryPhotoUrl !== ''
    ? $clientRefPrimaryPhotoUrl
    : null;

$totalVisits = (int) ($appointmentSummary['total'] ?? 0);
$totalPaid = $salesSummary['total_paid'] ?? null;
$ltvLabel = ($totalPaid === null || $totalPaid === '')
    ? '—'
    : number_format((float) $totalPaid, 2);

$now = time();
$todayStart = strtotime('today');

$lastApptLine = '—';
foreach ($recentAppointments as $row) {
    if (!is_array($row)) {
        continue;
    }
    $startRaw = (string) ($row['start_at'] ?? '');
    $t = strtotime($startRaw);
    if ($t === false || $t > $now) {
        continue;
    }
    $dt = date_create($startRaw);
    $lastApptLine = $dt ? $dt->format('M j, Y · g:i A') : $startRaw;
    if (($row['service_name'] ?? null) !== null && (string) $row['service_name'] !== '') {
        $lastApptLine .= ' · ' . htmlspecialchars((string) $row['service_name'], ENT_QUOTES, 'UTF-8');
    }
    break;
}

$upcomingStatuses = ['scheduled', 'confirmed', 'in_progress'];
$upcomingBookings = [];
foreach ($recentAppointments as $row) {
    if (!is_array($row)) {
        continue;
    }
    if (count($upcomingBookings) >= 4) {
        break;
    }
    $startRaw = (string) ($row['start_at'] ?? '');
    $t = strtotime($startRaw);
    $st = (string) ($row['status'] ?? '');
    if ($t === false || $t < $todayStart || !in_array($st, $upcomingStatuses, true)) {
        continue;
    }
    $upcomingBookings[] = $row;
}

usort($upcomingBookings, static function (array $a, array $b): int {
    $ta = strtotime((string) ($a['start_at'] ?? '')) ?: 0;
    $tb = strtotime((string) ($b['start_at'] ?? '')) ?: 0;

    return $ta <=> $tb;
});

?>
<aside class="client-ref-details-context-aside" aria-label="Customer résumé">
    <div class="client-ref-details-context-aside__inner">
        <header class="client-ref-details-context-aside__header">
            <div class="client-ref-details-context-aside__avatar<?= $photoUrl !== null ? ' client-ref-details-context-aside__avatar--photo' : '' ?>" aria-hidden="true">
                <?php if ($photoUrl !== null): ?>
                <img src="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" width="72" height="72" loading="lazy" decoding="async">
                <?php else: ?>
                <span class="client-ref-details-context-aside__avatar-initials"><?php
                $ch = function_exists('mb_substr') ? mb_substr($displayName, 0, 1, 'UTF-8') : substr($displayName, 0, 1);
                $chU = function_exists('mb_strtoupper') ? mb_strtoupper((string) $ch, 'UTF-8') : strtoupper((string) $ch);
                echo htmlspecialchars($chU !== '' ? $chU : '?', ENT_QUOTES, 'UTF-8');
                ?></span>
                <?php endif; ?>
            </div>
            <div class="client-ref-details-context-aside__identity">
                <p class="client-ref-details-context-aside__eyebrow">Customer résumé</p>
                <h2 class="client-ref-details-context-aside__name"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
        </header>

        <section class="client-ref-details-context-aside__section client-ref-details-context-aside__section--first" aria-labelledby="cr-context-metrics-heading">
            <h3 id="cr-context-metrics-heading" class="client-ref-details-context-aside__section-title">At a glance</h3>
            <dl class="client-ref-details-context-aside__metrics">
                <div class="client-ref-details-context-aside__metric">
                    <dt>Total visits</dt>
                    <dd><?= $totalVisits ?></dd>
                </div>
                <div class="client-ref-details-context-aside__metric">
                    <dt>Lifetime value</dt>
                    <dd><?= $ltvLabel === '—' ? '—' : htmlspecialchars($ltvLabel, ENT_QUOTES, 'UTF-8') ?></dd>
                </div>
                <div class="client-ref-details-context-aside__metric client-ref-details-context-aside__metric--wide">
                    <dt>Last appointment</dt>
                    <dd><?= $lastApptLine === '—' ? '—' : $lastApptLine ?></dd>
                </div>
            </dl>
        </section>

        <section class="client-ref-details-context-aside__section" aria-labelledby="cr-context-upcoming-heading">
            <h3 id="cr-context-upcoming-heading" class="client-ref-details-context-aside__section-title">Upcoming bookings</h3>
            <?php if ($upcomingBookings === []): ?>
            <p class="client-ref-details-context-aside__empty">No scheduled visits in the next window. New facials and follow-ups will appear here.</p>
            <?php else: ?>
            <ul class="client-ref-details-context-aside__bookings">
                <?php foreach ($upcomingBookings as $bk): ?>
                <?php
                $startRaw = (string) ($bk['start_at'] ?? '');
                $dt = date_create($startRaw);
                $when = $dt ? $dt->format('D, M j · g:i A') : htmlspecialchars($startRaw, ENT_QUOTES, 'UTF-8');
                $svc = trim((string) ($bk['service_name'] ?? ''));
                if ($svc === '') {
                    $svc = 'Service';
                }
                $staff = isset($bk['staff_name']) && (string) $bk['staff_name'] !== '' ? (string) $bk['staff_name'] : null;
                ?>
                <li class="client-ref-details-context-aside__booking">
                    <span class="client-ref-details-context-aside__booking-service"><?= htmlspecialchars($svc, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="client-ref-details-context-aside__booking-meta"><?= $when ?><?php if ($staff !== null): ?> · <?= htmlspecialchars($staff, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </div>
</aside>
