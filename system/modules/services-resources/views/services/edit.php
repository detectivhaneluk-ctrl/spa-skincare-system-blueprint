<?php
$title       = 'Edit: ' . htmlspecialchars($service['name'] ?? 'Service') . ' — Step 1 of 4';
ob_start();
$isCreate    = false;
$formAction  = '/services-resources/services/' . (int) $service['id'];
$currentStep = 1;
require __DIR__ . '/_wizard_nav.php';
?>

<?php require __DIR__ . '/_step1_form.php'; ?>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
