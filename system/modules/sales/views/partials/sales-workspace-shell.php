<?php
$salesWorkspaceShellModifier = isset($salesWorkspaceShellModifier) ? trim((string) $salesWorkspaceShellModifier) : '';
$salesWorkspaceActiveTab = isset($salesWorkspaceActiveTab) ? (string) $salesWorkspaceActiveTab : '';
$salesWorkspaceShellTitle = isset($salesWorkspaceShellTitle) ? trim((string) $salesWorkspaceShellTitle) : 'Sales';
$salesWorkspaceShellSub = isset($salesWorkspaceShellSub) ? trim((string) $salesWorkspaceShellSub) : 'Invoices, checkout, payments, gift cards, and register — money movement and stored value live here.';
$tabs = [
    ['id' => 'manage_sales', 'label' => 'Manage Sales', 'url' => '/sales/invoices'],
    ['id' => 'staff_checkout', 'label' => 'New sale', 'url' => '/sales'],
    ['id' => 'gift_cards', 'label' => 'Gift cards', 'url' => '/gift-cards'],
    ['id' => 'register', 'label' => 'Register', 'url' => '/sales/register'],
];
$shellClass = 'workspace-shell workspace-shell--sales';
if ($salesWorkspaceShellModifier !== '') {
    $shellClass .= ' ' . htmlspecialchars($salesWorkspaceShellModifier, ENT_QUOTES, 'UTF-8');
}
?>
<div class="<?= $shellClass ?>">
    <header class="workspace-module-head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($salesWorkspaceShellTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="workspace-module-head__sub"><?= htmlspecialchars($salesWorkspaceShellSub, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </header>
    <nav class="ds-segmented ds-segmented--ios ds-segmented--pill-track ds-segmented--thumb" aria-label="Sales workspace" data-ds-segmented-thumb>
        <span class="ds-segmented__thumb" aria-hidden="true"></span>
        <?php foreach ($tabs as $tab): ?>
        <?php
        $tabId = (string) ($tab['id'] ?? '');
        $isActive = $salesWorkspaceActiveTab !== '' && $tabId === $salesWorkspaceActiveTab;
        ?>
        <a href="<?= htmlspecialchars((string) ($tab['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"
           class="ds-segmented__link<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
            <?= htmlspecialchars((string) ($tab['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
