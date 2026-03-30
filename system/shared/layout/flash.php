<?php $msg = flash(); if ($msg && is_array($msg)): $type = array_key_first($msg) ?: 'info'; $text = $msg[$type] ?? ''; ?>
<div class="flash flash-<?= htmlspecialchars($type) ?>">
    <?= htmlspecialchars($text) ?>
</div>
<?php endif; ?>
