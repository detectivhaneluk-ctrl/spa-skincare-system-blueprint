<?php
use Core\App\Application;
use Core\Auth\AuthService;
use Core\Permissions\PermissionService;

$salesWorkspaceShellModifier = isset($salesWorkspaceShellModifier) ? trim((string) $salesWorkspaceShellModifier) : '';
$salesWorkspaceActiveTab = isset($salesWorkspaceActiveTab) ? (string) $salesWorkspaceActiveTab : '';
$salesWorkspaceShellTitle = isset($salesWorkspaceShellTitle) ? trim((string) $salesWorkspaceShellTitle) : 'Sales';
$salesWorkspaceShellSubIn = isset($salesWorkspaceShellSub) ? trim((string) $salesWorkspaceShellSub) : '';
$salesWorkspaceShellUsesDefaultSub = ($salesWorkspaceShellSubIn === '');
$salesWorkspaceShellSub = $salesWorkspaceShellUsesDefaultSub
    ? 'One financial workspace: charge, collect, refund, and reconcile. Invoices, checkout, payments, gift cards (stored value), and register (cash drawer sessions — not the same as checkout).'
    : $salesWorkspaceShellSubIn;
$user = Application::container()->get(AuthService::class)->user();
$canViewReports = $user !== null
    && Application::container()->get(PermissionService::class)->has((int) ($user['id'] ?? 0), 'reports.view');
$tabs = [
    ['id' => 'manage_sales', 'label' => 'Manage Sales', 'url' => '/sales/invoices'],
    ['id' => 'staff_checkout', 'label' => 'New sale', 'url' => '/sales'],
    ['id' => 'gift_cards', 'label' => 'Gift cards', 'url' => '/gift-cards'],
    ['id' => 'register', 'label' => 'Register', 'url' => '/sales/register'],
];
if ($canViewReports) {
    $tabs[] = ['id' => 'reports', 'label' => 'Reports', 'url' => '/reports'];
}
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
            <?php if (!empty($salesWorkspaceShellUsesDefaultSub)): ?>
            <p class="workspace-module-head__sub workspace-module-head__sub--minor">Gift card liability is <strong>measured</strong> via reporting — <a href="/reports/gift-card-liability">Gift card liability</a><?= $canViewReports ? ' (also under the <strong>Reports</strong> tab)' : '' ?> — not on this workspace.</p>
            <?php endif; ?>
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
