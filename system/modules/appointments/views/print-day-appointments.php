<?php
/** @var array $snap */
/** @var list<array<string,mixed>> $flat */
?>
<link rel="stylesheet" href="/assets/css/day-print.css">
<div class="day-print" id="day-print-root">
    <header class="day-print__actions no-print">
        <button type="button" class="day-print__btn" onclick="window.print()">Print</button>
        <a class="day-print__link" href="/appointments/calendar/day?<?= htmlspecialchars(http_build_query(['date' => (string) ($snap['date'] ?? ''), 'branch_id' => (int) ($snap['branch_id'] ?? 0)]), ENT_QUOTES, 'UTF-8') ?>">Back to calendar</a>
    </header>
    <h1 class="day-print__h1">Appointments</h1>
    <p class="day-print__meta"><?= htmlspecialchars((string) ($snap['date'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($snap['branch_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($flat === []): ?>
        <p class="appt-print__muted">No appointments for this day.</p>
    <?php else: ?>
        <ol class="day-print__list">
            <?php foreach ($flat as $a): ?>
                <li><?= htmlspecialchars($this->dayCalendarPrint->formatAppointmentLine($a), ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>
