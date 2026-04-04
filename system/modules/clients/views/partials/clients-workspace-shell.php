<?php
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$activeTab = (string) ($workspace['active_tab'] ?? '');
$tabs = isset($workspace['tabs']) && is_array($workspace['tabs']) ? $workspace['tabs'] : [];
?>
<div class="workspace-shell clients-workspace-shell">
    <header class="workspace-module-head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title">Clients</h1>
            <p class="workspace-module-head__sub">Search the directory, open client records, and use registrations, merge, and custom fields from one workspace.</p>
        </div>
    </header>
    <nav class="ds-segmented ds-segmented--ios ds-segmented--pill-track ds-segmented--thumb" aria-label="Clients workspace" data-ds-segmented-thumb>
        <span class="ds-segmented__thumb" aria-hidden="true"></span>
        <?php foreach ($tabs as $tab): ?>
        <?php
        $tabId = (string) ($tab['id'] ?? '');
        $isActive = $tabId !== '' && $tabId === $activeTab;
        ?>
        <a href="<?= htmlspecialchars((string) ($tab['url'] ?? '/clients')) ?>"
           class="ds-segmented__link<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
            <?= htmlspecialchars((string) ($tab['label'] ?? 'Tab')) ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
