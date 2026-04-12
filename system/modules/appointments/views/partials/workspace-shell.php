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
$headerSubtitle = $shellModifier === 'workspace-shell--calendar'
    ? ''
    : "Manage your salon's daily schedule.";
/* Find the visually widest label so every tab's phantom reserves the same space.
   This keeps the track width identical across all three pages. */
$maxTabLabel = '';
foreach ($tabs as $_t) {
    $_lbl = (string) ($_t['label'] ?? '');
    $_len = mb_strlen($_lbl, 'UTF-8');
    $curLen = mb_strlen($maxTabLabel, 'UTF-8');
    if ($_len > $curLen) {
        $maxTabLabel = $_lbl;
    } elseif ($_len === $curLen && $_lbl !== '') {
        /* Same glyph count (e.g. Calendar vs Waitlist): pick stable tie-break so phantom reserves enough width */
        if ($maxTabLabel === '' || strcmp($_lbl, $maxTabLabel) > 0) {
            $maxTabLabel = $_lbl;
        }
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
                <?php if ($headerSubtitle !== ''): ?>
                <p class="appts-workspace-header__subtitle ds-page-subheader__subtitle"><?= htmlspecialchars($headerSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <?php if ($hasApptsModeNav || $canCreate): ?>
            <div class="appts-workspace-header__controls ds-page-subheader__controls">
                <?php if ($hasApptsModeNav): ?>
                <nav class="appts-seg" id="appts-seg" aria-label="Appointments sections">
                    <span class="appts-seg__thumb" id="appts-seg-thumb" aria-hidden="true"></span>
                    <div class="appts-seg__track" role="tablist">
                        <?php foreach ($tabs as $tab): ?>
                        <?php
                        $tabId = (string) ($tab['id'] ?? '');
                        $isActive = $tabId !== '' && $tabId === $activeTab;
                        $tabUrl = (string) ($tab['url'] ?? '/appointments');
                        ?>
                        <a href="<?= htmlspecialchars($tabUrl) ?>"
                           class="appts-seg__tab" role="tab"
                           aria-selected="<?= $isActive ? 'true' : 'false' ?>">
                            <?= htmlspecialchars((string) ($tab['label'] ?? 'Tab')) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </nav>
                <?php endif; ?>
                <?php if ($canCreate): ?>
                <div class="appts-workspace-header__action">
                    <?php if ($useCalendarNewAppointmentBtn): ?>
                    <button type="button" class="appts-cta" id="calendar-new-appointment-btn" data-calendar-new-appt>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>New appointment</span>
                    </button>
                    <?php else: ?>
                    <a class="appts-cta" href="<?= htmlspecialchars($newAppointmentUrl) ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>New appointment</span>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </header>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';
    var root = document.getElementById('appts-seg');
    var thumb = document.getElementById('appts-seg-thumb');
    if (!root || !thumb) return;

    var tabs = [].slice.call(document.querySelectorAll('.appts-seg__tab'));
    var reduce = false;
    try {
        reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {}

    function syncThumb() {
        var active = tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; })[0];
        if (!active) return;
        var rr = root.getBoundingClientRect();
        var er = active.getBoundingClientRect();
        var x = er.left - rr.left;
        var w = er.width;
        root.style.setProperty('--thumb-x', x + 'px');
        root.style.setProperty('--thumb-w', w + 'px');
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
            if (reduce) {
                thumb.style.transition = 'none';
            }
            syncThumb();
            requestAnimationFrame(function () {
                thumb.style.transition = '';
            });
        });
    });

    window.addEventListener('resize', syncThumb);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncThumb);
    } else {
        syncThumb();
    }
})();
</script>
