<?php
$stockWorkspaceActiveTab = isset($stockWorkspaceActiveTab) ? (string) $stockWorkspaceActiveTab : '';
$stockWorkspaceShellTitle = isset($stockWorkspaceShellTitle) ? trim((string) $stockWorkspaceShellTitle) : 'Stock';
$stockWorkspaceShellSubIn = isset($stockWorkspaceShellSub) ? trim((string) $stockWorkspaceShellSub) : '';
$stockWorkspaceShellSub = $stockWorkspaceShellSubIn !== ''
    ? $stockWorkspaceShellSubIn
    : 'Products, stock control, purchase orders, suppliers, brands, and categories.';

$tabs = [
    ['id' => 'products',    'label' => 'Products',    'url' => '/inventory/products'],
    ['id' => 'categories',  'label' => 'Categories',  'url' => '/inventory/product-categories'],
    ['id' => 'brands',      'label' => 'Brands',      'url' => '/inventory/product-brands'],
    ['id' => 'suppliers',   'label' => 'Suppliers',   'url' => '/inventory/suppliers'],
    ['id' => 'movements',   'label' => 'Movements',   'url' => '/inventory/movements'],
    ['id' => 'counts',      'label' => 'Counts',      'url' => '/inventory/counts'],
];
?>
<div class="workspace-shell workspace-shell--stock">
    <header class="workspace-module-head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($stockWorkspaceShellTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="workspace-module-head__sub"><?= htmlspecialchars($stockWorkspaceShellSub, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </header>
    <nav class="ds-segmented ds-segmented--ios ds-segmented--pill-track ds-segmented--thumb" aria-label="Stock workspace" data-ds-segmented-thumb>
        <span class="ds-segmented__thumb" aria-hidden="true"></span>
        <?php foreach ($tabs as $tab): ?>
        <?php
        $tabId = (string) ($tab['id'] ?? '');
        $isActive = $stockWorkspaceActiveTab !== '' && $tabId === $stockWorkspaceActiveTab;
        ?>
        <a href="<?= htmlspecialchars((string) ($tab['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"
           class="ds-segmented__link<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
            <?= htmlspecialchars((string) ($tab['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
