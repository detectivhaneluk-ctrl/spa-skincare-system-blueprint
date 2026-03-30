<?php
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$activeTab = (string) ($workspace['active_tab'] ?? '');
$tabs = isset($workspace['tabs']) && is_array($workspace['tabs']) ? $workspace['tabs'] : [];
$shellModifier = (string) ($workspace['shell_modifier'] ?? '');
$shellClass = 'workspace-shell' . ($shellModifier !== '' ? ' ' . htmlspecialchars($shellModifier, ENT_QUOTES, 'UTF-8') : '');
?>
<div class="<?= $shellClass ?>">
    <header class="workspace-module-head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title">Appointments</h1>
            <p class="workspace-module-head__sub">Day calendar, list view, waitlist, and new bookings.</p>
        </div>
    </header>
    <nav class="workspace-subnav" aria-label="Appointments workspace">
        <?php foreach ($tabs as $tab): ?>
        <?php
        $tabId = (string) ($tab['id'] ?? '');
        $isActive = $tabId !== '' && $tabId === $activeTab;
        ?>
        <a href="<?= htmlspecialchars((string) ($tab['url'] ?? '/appointments')) ?>"
           class="workspace-subnav__link workspace-tab<?= $isActive ? ' workspace-subnav__link--active workspace-tab--active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
            <?= htmlspecialchars((string) ($tab['label'] ?? 'Tab')) ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
