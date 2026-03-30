<?php

declare(strict_types=1);

/** @var bool $storageReady */
$storageReady = !empty($storageReady ?? false);
if ($storageReady) {
    return;
}
?>
<p class="hint" role="status" style="margin:0 0 12px;padding:10px 12px;border:1px solid #d1d5db;background:#fffbeb;">
    Gift card template storage is not initialized. Apply migration 102 to enable template and image management.
</p>
