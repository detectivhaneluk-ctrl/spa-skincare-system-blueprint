<?php

declare(strict_types=1);

/**
 * PHP upload gateway (foundation wave). Worker/variant tuning is documented in
 * {@see system/docs/IMAGE-PIPELINE-FOUNDATION-01-OPS.md} for a later wave.
 */

$maxMb = (float) env('IMAGE_MAX_UPLOAD_MB', 12);
$maxMb = max(0.5, min(256.0, $maxMb));

return [
    'max_upload_bytes' => (int) round($maxMb * 1048576),
    'max_megapixels' => max(0.5, min(200.0, (float) env('IMAGE_MAX_MEGAPIXELS', 40))),
];
