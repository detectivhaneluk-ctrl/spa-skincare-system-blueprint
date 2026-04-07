<?php
$title = 'New Service — Step 1 of 4';
ob_start();
$isCreate    = true;
$formAction  = '/services-resources/services';
$currentStep = 1;
require __DIR__ . '/_wizard_nav.php';
?>

<?php require __DIR__ . '/_step1_form.php'; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
