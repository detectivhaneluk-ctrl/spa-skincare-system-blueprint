<?php
$title = 'Services';
ob_start();
$svcWorkspaceActiveTab = '';
require base_path('modules/services-resources/views/partials/services-workspace-shell.php');
?>
<?php if ($flash && is_array($flash)): $t = array_key_first($flash); ?>
<div class="flash flash-<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($flash[$t] ?? '') ?></div>
<?php endif; ?>
<p style="margin:1rem 0 0;font-size:0.87rem;color:#4b5563;">Select a section above to manage your services and resources.</p>
<?php $content = ob_get_clean(); require shared_path('layout/base.php'); ?>
