<?php
$branchWorkspaceShellTitle = isset($branchWorkspaceShellTitle) ? trim((string) $branchWorkspaceShellTitle) : 'Branches';
$branchWorkspaceShellSubIn = isset($branchWorkspaceShellSub)   ? trim((string) $branchWorkspaceShellSub)   : '';
$branchWorkspaceShellSub   = $branchWorkspaceShellSubIn !== ''
    ? $branchWorkspaceShellSubIn
    : 'Business locations and branches for this organisation.';
?>
<div class="workspace-shell workspace-shell--branches">
    <header class="workspace-module-head">
        <div class="workspace-module-head__text">
            <h1 class="workspace-module-head__title"><?= htmlspecialchars($branchWorkspaceShellTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="workspace-module-head__sub"><?= htmlspecialchars($branchWorkspaceShellSub, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </header>
</div>
