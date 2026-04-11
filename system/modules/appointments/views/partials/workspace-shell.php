<?php
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$activeTab = (string) ($workspace['active_tab'] ?? '');
$tabs = isset($workspace['tabs']) && is_array($workspace['tabs']) ? $workspace['tabs'] : [];
$shellModifier = (string) ($workspace['shell_modifier'] ?? '');
$shellClass = 'workspace-shell' . ($shellModifier !== '' ? ' ' . htmlspecialchars($shellModifier, ENT_QUOTES, 'UTF-8') : '');
$newAppointmentUrl = (string) ($workspace['new_appointment_url'] ?? '/appointments/create');
$useCalendarNewAppointmentBtn = ($shellModifier === 'workspace-shell--calendar');
$canCreate = (bool) ($workspace['can_create'] ?? false);
$hasApptsModeNav = $tabs !== [];
$useClientsStyleModuleHead = ($shellModifier === 'workspace-shell--create');
/* Find the visually widest label so every tab's phantom reserves the same space.
   This keeps the track width identical across all three pages. */
$maxTabLabel = '';
foreach ($tabs as $_t) {
    $_lbl = (string) ($_t['label'] ?? '');
    if (mb_strlen($_lbl, 'UTF-8') > mb_strlen($maxTabLabel, 'UTF-8')) {
        $maxTabLabel = $_lbl;
    }
}
$maxTabLabelAttr = htmlspecialchars($maxTabLabel, ENT_QUOTES, 'UTF-8');
?>
<div class="ds-workspace <?= $shellClass ?><?= $useClientsStyleModuleHead ? ' appointments-wizard-workspace-shell' : '' ?>">
    <?php if ($useClientsStyleModuleHead): ?>
    <header class="workspace-module-head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title">Appointments</h1>
            <p class="workspace-module-head__sub">Manage your salon's daily schedule.</p>
        </div>
    </header>
    <?php else: ?>
    <header class="appts-workspace-header ds-page-subheader">
        <div class="appts-workspace-header__row ds-page-subheader__row">
            <div class="appts-workspace-header__intro ds-page-subheader__intro">
                <h1 class="appts-workspace-header__title ds-page-subheader__title">Appointments</h1>
                <p class="appts-workspace-header__subtitle ds-page-subheader__subtitle">Manage your salon's daily schedule.</p>
            </div>
            <?php if ($hasApptsModeNav || $canCreate): ?>
            <div class="appts-workspace-header__controls ds-page-subheader__controls">
                <?php if ($hasApptsModeNav): ?>
                <nav class="appts-workspace-header__modes appts-view-switch" aria-label="Appointments sections" data-appts-view-switch>
                    <span class="appts-view-switch__thumb" aria-hidden="true"></span>
                    <?php foreach ($tabs as $tab): ?>
                    <?php
                    $tabId = (string) ($tab['id'] ?? '');
                    $isActive = $tabId !== '' && $tabId === $activeTab;
                    $tabUrl = (string) ($tab['url'] ?? '/appointments');
                    ?>
                    <a href="<?= htmlspecialchars($tabUrl) ?>"
                       class="appts-view-switch__segment<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>
                       data-text="<?= htmlspecialchars((string) ($tab['label'] ?? 'Tab')) ?>"
                       data-max-tab-text="<?= $maxTabLabelAttr ?>">
                        <?= htmlspecialchars((string) ($tab['label'] ?? 'Tab')) ?>
                    </a>
                    <?php endforeach; ?>
                </nav>
                <?php endif; ?>
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
            <?php endif; ?>
        </div>
    </header>
    <?php endif; ?>
</div>
