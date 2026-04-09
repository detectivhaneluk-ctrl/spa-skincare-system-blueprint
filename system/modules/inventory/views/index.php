<?php
$title = 'Stock';
ob_start();
$stockWorkspaceActiveTab = '';
$stockWorkspaceShellTitle = 'Stock';
require base_path('modules/inventory/views/partials/stock-workspace-shell.php');
?>
<p class="inventory-hub__lead" style="margin:1rem 0 0;font-size:0.87rem;color:#4b5563;">Select a section above to manage your inventory.</p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
