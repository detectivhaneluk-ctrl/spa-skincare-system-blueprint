<?php
ob_start();
?>
<h1>Intake form</h1>
<p><?= htmlspecialchars($error ?? 'Unable to load this form.') ?></p>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
