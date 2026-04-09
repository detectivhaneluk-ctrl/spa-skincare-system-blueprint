<?php

$isDrawerCategoryPanel = true;
require __DIR__ . '/../partials/category-panel-state.php';

$drawerSub = '';
if ($isEditMode && $editCatPath !== '') {
    $drawerSub = $editCatPath;
} elseif ($isChildMode && $parentHintPath !== '') {
    $drawerSub = 'Under ' . $parentHintPath;
} else {
    $drawerSub = 'Service categories';
}
?>
<div
    class="drawer-workspace drawer-workspace--svc-category"
    data-drawer-content-root
    data-drawer-title="<?= htmlspecialchars($panelTitle, ENT_QUOTES, 'UTF-8') ?>"
    data-drawer-subtitle="<?= htmlspecialchars($drawerSub, ENT_QUOTES, 'UTF-8') ?>"
    data-drawer-width="medium"
>
    <aside class="taxmgr-panel taxmgr-panel--drawer" id="taxmgr-panel">
        <?php require __DIR__ . '/../partials/category-panel-inner.php'; ?>
    </aside>
</div>
