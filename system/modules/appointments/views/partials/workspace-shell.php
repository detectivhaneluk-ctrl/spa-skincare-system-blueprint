<?php
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$activeTab = (string) ($workspace['active_tab'] ?? '');
$tabs = isset($workspace['tabs']) && is_array($workspace['tabs']) ? $workspace['tabs'] : [];
$shellModifier = (string) ($workspace['shell_modifier'] ?? '');
$shellClass = 'workspace-shell' . ($shellModifier !== '' ? ' ' . htmlspecialchars($shellModifier, ENT_QUOTES, 'UTF-8') : '');
$newAppointmentUrl = (string) ($workspace['new_appointment_url'] ?? '/appointments/create');
$useCalendarNewAppointmentBtn = ($activeTab === 'calendar');
$canCreate = (bool) ($workspace['can_create'] ?? false);
?>
<div class="ds-workspace <?= $shellClass ?>">
    <header class="appts-workspace-header">
        <div class="appts-workspace-header__row">
            <h1 class="appts-workspace-header__title">Appointments</h1>
            <nav class="appts-workspace-header__modes ds-segmented" aria-label="Appointments sections">
                <?php foreach ($tabs as $tab): ?>
                <?php
                $tabId = (string) ($tab['id'] ?? '');
                $isActive = $tabId !== '' && $tabId === $activeTab;
                $tabUrl = (string) ($tab['url'] ?? '/appointments');
                ?>
                <a href="<?= htmlspecialchars($tabUrl) ?>"
                   class="ds-segmented__link appts-workspace-header__mode-link<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                    <?= htmlspecialchars((string) ($tab['label'] ?? 'Tab')) ?>
                </a>
                <?php endforeach; ?>
            </nav>
            <?php if ($canCreate): ?>
            <div class="appts-workspace-header__action">
                <?php if ($useCalendarNewAppointmentBtn): ?>
                <button type="button" class="ds-btn ds-btn--primary appts-workspace-header__new" id="calendar-new-appointment-btn" data-calendar-new-appt>New appointment</button>
                <?php else: ?>
                <a class="ds-btn ds-btn--primary appts-workspace-header__new" href="<?= htmlspecialchars($newAppointmentUrl) ?>">New appointment</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </header>
</div>
