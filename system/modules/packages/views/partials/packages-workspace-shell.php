<?php
$pkgWorkspaceActiveTab  = isset($pkgWorkspaceActiveTab)  ? (string) $pkgWorkspaceActiveTab  : '';
$pkgWorkspaceShellTitle = isset($pkgWorkspaceShellTitle) ? trim((string) $pkgWorkspaceShellTitle) : 'Packages';
$pkgWorkspaceShellSubIn = isset($pkgWorkspaceShellSub)   ? trim((string) $pkgWorkspaceShellSub)   : '';
$pkgWorkspaceShellSub   = $pkgWorkspaceShellSubIn !== ''
    ? $pkgWorkspaceShellSubIn
    : 'Package plan definitions — sessions, validity, and pricing.';

$tabs = [
    ['id' => 'plans', 'label' => 'Plans', 'url' => '/packages'],
    ['id' => 'held', 'label' => 'Client-held', 'url' => '/packages/client-packages'],
];
?>
<div class="workspace-shell workspace-shell--packages clients-workspace-shell">
    <header class="workspace-module-head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($pkgWorkspaceShellTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="workspace-module-head__sub"><?= htmlspecialchars($pkgWorkspaceShellSub, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </header>
    <nav class="ds-segmented ds-segmented--ios ds-segmented--pill-track ds-segmented--thumb" aria-label="Packages workspace" data-ds-segmented-thumb>
        <span class="ds-segmented__thumb" aria-hidden="true"></span>
        <?php foreach ($tabs as $tab): ?>
        <?php
        $tabId   = (string) ($tab['id'] ?? '');
        $isActive = $pkgWorkspaceActiveTab !== '' && $tabId === $pkgWorkspaceActiveTab;
        ?>
        <a href="<?= htmlspecialchars((string) ($tab['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"
           class="ds-segmented__link<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
            <?= htmlspecialchars((string) ($tab['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
