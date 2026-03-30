<?php
$salesWorkspaceShellModifier = isset($salesWorkspaceShellModifier) ? trim((string) $salesWorkspaceShellModifier) : '';
$salesWorkspaceActiveTab = isset($salesWorkspaceActiveTab) ? (string) $salesWorkspaceActiveTab : '';
$tabs = [
    ['id' => 'manage_sales', 'label' => 'Manage Sales', 'url' => '/sales/invoices'],
    ['id' => 'staff_checkout', 'label' => 'New sale', 'url' => '/sales'],
    ['id' => 'gift_cards', 'label' => 'Gift cards', 'url' => '/gift-cards'],
    ['id' => 'packages', 'label' => 'Packages', 'url' => '/packages'],
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
            <h1 class="workspace-module-head__title">Sales</h1>
            <p class="workspace-module-head__sub">Staff checkout, orders, gift cards, packages, and register.</p>
        </div>
    </header>
    <nav class="workspace-subnav" aria-label="Sales workspace">
        <?php foreach ($tabs as $tab): ?>
        <?php
        $tabId = (string) ($tab['id'] ?? '');
        $isActive = $salesWorkspaceActiveTab !== '' && $tabId === $salesWorkspaceActiveTab;
        ?>
        <a href="<?= htmlspecialchars((string) ($tab['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"
           class="workspace-subnav__link workspace-tab<?= $isActive ? ' workspace-subnav__link--active workspace-tab--active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
            <?= htmlspecialchars((string) ($tab['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
