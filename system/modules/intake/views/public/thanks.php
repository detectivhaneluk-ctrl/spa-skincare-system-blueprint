<?php
ob_start();
?>
<h1>Thank you</h1>
<p>Your responses have been submitted.</p>
<?php
$content = ob_get_clean();
require shared_path('layout/base.php');
