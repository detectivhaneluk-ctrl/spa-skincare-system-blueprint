<?php
/** @var array $snap @see \Modules\Appointments\Services\DayCalendarPrintService::buildDaySnapshot */
$staff = $snap['staff'] ?? [];
$byStaff = $snap['appointments_by_staff'] ?? [];
?>
<link rel="stylesheet" href="/assets/css/day-print.css">
<div class="day-print" id="day-print-root">
    <header class="day-print__actions no-print">
        <button type="button" class="day-print__btn" onclick="window.print()">Print</button>
        <a class="day-print__link" href="/appointments/calendar/day?<?= htmlspecialchars(http_build_query(['date' => (string) ($snap['date'] ?? ''), 'branch_id' => (int) ($snap['branch_id'] ?? 0)]), ENT_QUOTES, 'UTF-8') ?>">Back to calendar</a>
    </header>
    <h1 class="day-print__h1">Day calendar</h1>
    <p class="day-print__meta"><?= htmlspecialchars((string) ($snap['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($snap['branch_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
    <table class="day-print__table">
        <thead>
        <tr>
            <th scope="col">Staff</th>
            <th scope="col">Appointments</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($staff as $s): ?>
            <?php
            $sid = (string) ((int) ($s['id'] ?? 0));
            $label = trim((string) ($s['first_name'] ?? '') . ' ' . (string) ($s['last_name'] ?? ''));
            $list = $byStaff[$sid] ?? [];
            usort($list, static fn ($a, $b) => strcmp((string) ($a['start_at'] ?? ''), (string) ($b['start_at'] ?? '')));
            ?>
            <tr>
                <th scope="row"><?= htmlspecialchars($label !== '' ? $label : 'Staff #' . $sid, ENT_QUOTES, 'UTF-8') ?></th>
                <td>
                    <?php if ($list === []): ?>
                        <span class="appt-print__muted">—</span>
                    <?php else: ?>
                        <ul class="day-print__list">
                            <?php foreach ($list as $a): ?>
                                <li><?= htmlspecialchars($this->dayCalendarPrint->formatAppointmentLine($a), ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
