<?php
$memWorkspaceActiveTab  = isset($memWorkspaceActiveTab)  ? (string) $memWorkspaceActiveTab  : '';
$memWorkspaceShellTitle = isset($memWorkspaceShellTitle) ? trim((string) $memWorkspaceShellTitle) : 'Memberships';
$memWorkspaceShellSubIn = isset($memWorkspaceShellSub)   ? trim((string) $memWorkspaceShellSub)   : '';
$memWorkspaceShellSub   = $memWorkspaceShellSubIn !== ''
    ? $memWorkspaceShellSubIn
    : 'Membership plan definitions — duration, price, and availability.';

$tabs = [
    ['id' => 'plans',         'label' => 'Plans',         'url' => '/memberships'],
    ['id' => 'refund-review', 'label' => 'Refund Review', 'url' => '/memberships/refund-review'],
];
?>
<div class="workspace-shell workspace-shell--memberships">
    <header class="workspace-module-head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($memWorkspaceShellTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="workspace-module-head__sub"><?= htmlspecialchars($memWorkspaceShellSub, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </header>
    <nav class="ds-segmented ds-segmented--ios ds-segmented--pill-track ds-segmented--thumb" aria-label="Memberships workspace" data-ds-segmented-thumb>
        <span class="ds-segmented__thumb" aria-hidden="true"></span>
        <?php foreach ($tabs as $tab): ?>
        <?php
        $tabId   = (string) ($tab['id'] ?? '');
        $isActive = $memWorkspaceActiveTab !== '' && $tabId === $memWorkspaceActiveTab;
        ?>
        <a href="<?= htmlspecialchars((string) ($tab['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"
           class="ds-segmented__link<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
            <?= htmlspecialchars((string) ($tab['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
