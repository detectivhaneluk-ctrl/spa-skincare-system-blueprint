<?php
$title = 'Staff Checkout';
$mainClass = 'sales-workspace-page cashier-workspace-page';
ob_start();
$salesWorkspaceShellModifier = 'workspace-shell--list';
$salesWorkspaceActiveTab = 'staff_checkout';
require __DIR__ . '/../partials/sales-workspace-shell.php';
require __DIR__ . '/_cashier_workspace.php';
$content = ob_get_clean();
require shared_path('layout/base.php');
