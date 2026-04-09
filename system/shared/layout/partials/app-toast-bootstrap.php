<?php

declare(strict_types=1);

/**
 * Session / controller flash → top-right toast queue (see app-toast.js).
 * Supports scalar messages for keys: success, error, info, warning.
 */
$toastKeys = ['success', 'error', 'info', 'warning'];
$__flashBag = [];
if (isset($flash) && is_array($flash)) {
    $__flashBag = $flash;
} elseif (isset($flashMsg) && is_array($flashMsg)) {
    $__flashBag = $flashMsg;
}
if ($__flashBag === []) {
    $p = flash();
    if (is_array($p)) {
        $__flashBag = $p;
    }
}

$__toastItems = [];
foreach ($toastKeys as $tk) {
    if (!isset($__flashBag[$tk])) {
        continue;
    }
    $val = $__flashBag[$tk];
    if (!is_string($val) || $val === '') {
        continue;
    }
    $__toastItems[] = ['type' => $tk, 'message' => $val];
}

try {
    $__toastJson = json_encode(
        $__toastItems,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_THROW_ON_ERROR
    );
} catch (JsonException) {
    $__toastJson = '[]';
}

if ($__toastItems !== []) {
    echo '<style id="app-toast-suppress-inline-flash">main .flash,.main .flash{display:none!important}</style>';
}
?>
<div id="app-toast-host" class="app-toast-host" aria-label="Activity notifications"></div>
<script type="application/json" id="app-toast-initial"><?= $__toastJson ?></script>
