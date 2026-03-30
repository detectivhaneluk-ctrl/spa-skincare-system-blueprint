<?php
$title = 'Client · Sales · ' . ($client['display_name'] ?? '');
$mainClass = 'client-resume-page client-ref-surface client-ref--client-tab client-ref--tab-sales';
$clientRefTitleRowSecondaryTab = true;
ob_start();
?>
<div class="client-ref client-ref-surface client-ref--client-tab client-ref--tab-sales">
<?php require base_path('modules/clients/views/partials/client-ref-header-tabs.php'); ?>

    <div class="client-ref-body">
<?php require base_path('modules/clients/views/partials/client-ref-sidebar.php'); ?>

        <div class="client-ref-main client-ref-main--client-tab" role="main">
<?php require base_path('modules/clients/views/partials/client-ref-sales-workspace.php'); ?>
        </div>
    </div>
</div>

<?php require base_path('modules/clients/views/partials/client-ref-shell-styles.php'); ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
