<?php
use Core\App\Application;
use Core\Auth\AuthService;
use Core\Permissions\PermissionService;

$teamWorkspaceActiveTab = isset($teamWorkspaceActiveTab) ? (string) $teamWorkspaceActiveTab : '';
$teamWorkspaceShellTitle = isset($teamWorkspaceShellTitle) ? trim((string) $teamWorkspaceShellTitle) : 'Team';
$teamWorkspaceShellSubIn = isset($teamWorkspaceShellSub) ? trim((string) $teamWorkspaceShellSub) : '';
$teamWorkspaceShellSub = $teamWorkspaceShellSubIn !== ''
    ? $teamWorkspaceShellSubIn
    : 'Your team in one place: staff directory, schedules, compensation rules, and payroll runs.';

$user = Application::container()->get(AuthService::class)->user();
$perm = Application::container()->get(PermissionService::class);
$uid = (int) ($user['id'] ?? 0);

$canViewPayroll = $user !== null && $perm->has($uid, 'payroll.view');
$canViewStaff   = $user !== null && $perm->has($uid, 'staff.view');

$tabs = [
    ['id' => 'directory', 'label' => 'Staff Directory', 'url' => '/staff'],
];
if ($canViewPayroll) {
    $tabs[] = ['id' => 'payroll', 'label' => 'Payroll', 'url' => '/payroll/runs'];
}
if ($canViewStaff) {
    $tabs[] = ['id' => 'groups', 'label' => 'Groups', 'url' => '/staff/groups/admin'];
}
?>
<div class="workspace-shell workspace-shell--team">
    <header class="workspace-module-head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($teamWorkspaceShellTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="workspace-module-head__sub"><?= htmlspecialchars($teamWorkspaceShellSub, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </header>
    <nav class="ds-segmented ds-segmented--ios ds-segmented--pill-track ds-segmented--thumb" aria-label="Team workspace" data-ds-segmented-thumb>
        <span class="ds-segmented__thumb" aria-hidden="true"></span>
        <?php foreach ($tabs as $tab): ?>
        <?php
        $tabId = (string) ($tab['id'] ?? '');
        $isActive = $teamWorkspaceActiveTab !== '' && $tabId === $teamWorkspaceActiveTab;
        ?>
        <a href="<?= htmlspecialchars((string) ($tab['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"
           class="ds-segmented__link<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
            <?= htmlspecialchars((string) ($tab['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endforeach; ?>
    </nav>
</div>
