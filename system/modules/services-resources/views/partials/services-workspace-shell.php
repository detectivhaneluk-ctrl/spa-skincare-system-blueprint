<?php
$svcWorkspaceActiveTab  = isset($svcWorkspaceActiveTab)  ? (string) $svcWorkspaceActiveTab  : '';
$svcWorkspaceShellTitle = isset($svcWorkspaceShellTitle) ? trim((string) $svcWorkspaceShellTitle) : 'Services';
$svcWorkspaceShellSubIn = isset($svcWorkspaceShellSub)   ? trim((string) $svcWorkspaceShellSub)   : '';
$svcWorkspaceShellSub   = $svcWorkspaceShellSubIn !== ''
    ? $svcWorkspaceShellSubIn
    : 'Services, categories, treatment spaces, and equipment resources.';

$tabs = [
    ['id' => 'services',   'label' => 'Services',    'url' => '/services-resources/services'],
    ['id' => 'categories', 'label' => 'Categories',  'url' => '/services-resources/categories'],
    ['id' => 'spaces',     'label' => 'Spaces',      'url' => '/services-resources/rooms'],
    ['id' => 'equipment',  'label' => 'Equipment',   'url' => '/services-resources/equipment'],
];
?>
<div class="workspace-shell workspace-shell--services">
    <header class="workspace-module-head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($svcWorkspaceShellTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="workspace-module-head__sub"><?= htmlspecialchars($svcWorkspaceShellSub, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </header>
    <nav class="ds-segmented ds-segmented--ios ds-segmented--pill-track ds-segmented--thumb" aria-label="Services workspace" data-ds-segmented-thumb>
        <span class="ds-segmented__thumb" aria-hidden="true"></span>
        <?php foreach ($tabs as $tab): ?>
        <?php
        $tabId   = (string) ($tab['id'] ?? '');
        $isActive = $svcWorkspaceActiveTab !== '' && $tabId === $svcWorkspaceActiveTab;
        ?>
        <a href="<?= htmlspecialchars((string) ($tab['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"
           class="ds-segmented__link<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
            <?= htmlspecialchars((string) ($tab['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
