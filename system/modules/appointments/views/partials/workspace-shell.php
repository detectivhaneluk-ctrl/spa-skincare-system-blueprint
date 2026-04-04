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
    <header class="appts-workspace-header ds-page-subheader">
        <div class="appts-workspace-header__row ds-page-subheader__row">
            <div class="appts-workspace-header__intro ds-page-subheader__intro">
                <h1 class="appts-workspace-header__title ds-page-subheader__title">Appointments</h1>
                <p class="appts-workspace-header__subtitle ds-page-subheader__subtitle">Manage your salon's daily schedule.</p>
            </div>
            <div class="appts-workspace-header__controls ds-page-subheader__controls">
                <nav class="appts-workspace-header__modes appts-workspace-header__segmented-track ds-segmented ds-segmented--ios ds-segmented--pill-track ds-segmented--thumb" aria-label="Appointments sections" data-ds-segmented-thumb>
                    <span class="ds-segmented__thumb" aria-hidden="true"></span>
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
                    <button type="button" class="ds-btn ds-btn--toolbar appts-workspace-header__new" id="calendar-new-appointment-btn" data-calendar-new-appt><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg><span class="appts-workspace-header__new-label">New appointment</span></button>
                    <?php else: ?>
                    <a class="ds-btn ds-btn--toolbar appts-workspace-header__new" href="<?= htmlspecialchars($newAppointmentUrl) ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg><span class="appts-workspace-header__new-label">New appointment</span></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </header>
</div>
