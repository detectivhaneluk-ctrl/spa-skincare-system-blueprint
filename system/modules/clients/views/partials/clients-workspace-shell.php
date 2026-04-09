<?php
$workspace = isset($workspace) && is_array($workspace) ? $workspace : [];
$activeTab = (string) ($workspace['active_tab'] ?? '');
$tabs = isset($workspace['tabs']) && is_array($workspace['tabs']) ? $workspace['tabs'] : [];
$tabsMore = isset($workspace['tabs_more']) && is_array($workspace['tabs_more']) ? $workspace['tabs_more'] : [];
$moreTabIds = [];
foreach ($tabsMore as $__tm) {
    $__id = (string) ($__tm['id'] ?? '');
    if ($__id !== '') {
        $moreTabIds[] = $__id;
    }
}
$moreMenuActive = $activeTab !== '' && in_array($activeTab, $moreTabIds, true);
?>
<div class="workspace-shell clients-workspace-shell">
    <header class="workspace-module-head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title">Clients</h1>
            <p class="workspace-module-head__sub">Search the directory, open client records, and use registrations, merge, and custom fields from one workspace.</p>
        </div>
    </header>
    <div class="clients-ws-nav-row">
        <nav class="ds-segmented ds-segmented--ios ds-segmented--pill-track clients-ws-segmented<?= $tabsMore !== [] ? ' clients-ws-segmented--has-more' : '' ?>" aria-label="Clients workspace">
            <?php foreach ($tabs as $tab): ?>
            <?php
            $tabId = (string) ($tab['id'] ?? '');
            $isActive = $tabId !== '' && $tabId === $activeTab;
            ?>
            <?php $tabDrawer = trim((string) ($tab['drawer_url'] ?? '')); ?>
            <a href="<?= htmlspecialchars((string) ($tab['url'] ?? '/clients')) ?>"
               class="ds-segmented__link<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?><?= $tabDrawer !== '' ? ' data-drawer-url="' . htmlspecialchars($tabDrawer, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                <?= htmlspecialchars((string) ($tab['label'] ?? 'Tab')) ?>
            </a>
            <?php endforeach; ?>
            <?php if ($tabsMore !== []): ?>
            <details class="clients-ws-more">
                <summary class="clients-ws-more__summary<?= $moreMenuActive ? ' is-active' : '' ?>">
                    <span>More</span>
                    <svg class="clients-ws-more__chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </summary>
                <div class="clients-ws-more__panel">
                    <?php foreach ($tabsMore as $mt): ?>
                    <?php
                    $mid = (string) ($mt['id'] ?? '');
                    $mActive = $mid !== '' && $mid === $activeTab;
                    $mUrl = (string) ($mt['url'] ?? '#');
                    $mLabel = (string) ($mt['label'] ?? 'Link');
                    ?>
                    <a href="<?= htmlspecialchars($mUrl) ?>"
                       class="clients-ws-more__link<?= $mActive ? ' is-active' : '' ?>"<?= $mActive ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($mLabel) ?></a>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </nav>
    </div>
</div>
