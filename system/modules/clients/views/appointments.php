<?php
$title = 'Client · Appointments · ' . ($client['display_name'] ?? '');
$mainClass = 'client-resume-page client-ref-surface client-ref--appointments-page';
$clientId = (int) $client['id'];
$clientRefActiveTab = 'rdv';
$clientRefDedicatedAppointments = true;
ob_start();
?>
<div class="client-ref client-ref-surface client-ref--appointments-page">
<?php require base_path('modules/clients/views/partials/client-ref-header-tabs.php'); ?>

    <div class="client-ref-body">
<?php require base_path('modules/clients/views/partials/client-ref-sidebar.php'); ?>

        <div class="client-ref-main client-ref-main--appointments" role="main">
<?php require base_path('modules/clients/views/partials/client-ref-rdv-workspace.php'); ?>
        </div>
    </div>
</div>

<?php require base_path('modules/clients/views/partials/client-ref-shell-styles.php'); ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
