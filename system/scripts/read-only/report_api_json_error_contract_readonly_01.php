<?php

declare(strict_types=1);

/**
 * API-ERROR-CONTRACT-AND-STATUS-CODE-HARDENING-01 — read-only inventory of likely non-canonical JSON error patterns.
 *
 * Canonical error body (public/API JSON): {@see \Core\App\Response::jsonPublicApiError}
 * `{ "success": false, "error": { "code": "…", "message": "…", "details"?: … } }`
 *
 * Heuristic: considers only files that appear to emit JSON over HTTP (`json_encode`, `respondJson`, `$this->json(`,
 * `application/json`, etc.) to avoid false positives from `flash('success')` plus internal `'error' =>` audit keys.
 * Flags `success` => false with a string `error` value, or flat `error` => literal patterns — excludes normalized
 * controller paths and files using `jsonPublicApiError` (approximate).
 *
 * From repo root:
 *   php system/scripts/read-only/report_api_json_error_contract_readonly_01.php
 */

$root = dirname(__DIR__, 2);
$modules = $root . '/modules';

$report = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS));
/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $rel = str_replace(['\\', '/'], '/', substr($path, strlen($root) + 1));
    $src = (string) file_get_contents($path);
    if (!str_contains($src, "'success'") && !str_contains($src, '"success"')) {
        continue;
    }
    $emitsJsonHttp = str_contains($src, 'json_encode')
        || str_contains($src, 'jsonPublicApiError')
        || str_contains($src, 'respondJson')
        || str_contains($src, 'jsonResponse')
        || str_contains($src, '$this->json(')
        || str_contains($src, 'application/json');
    if (!$emitsJsonHttp) {
        continue;
    }
    if (str_contains($src, 'jsonPublicApiError')) {
        // May still have legacy patterns; continue scanning
    }
    // Flat: 'error' => 'text' or "error" => $var (string) — crude
    if (preg_match("/['\"]error['\"]\\s*=>\\s*['\"]/", $src) === 1) {
        $report[] = $rel . ' (likely string `error` => literal)';
    } elseif (preg_match("/\\['success'\\s*=>\\s*false[^\\]]*'error'\\s*=>\\s*\\\$/", $src) === 1) {
        $report[] = $rel . ' (success false + dynamic string error)';
    }
}

// De-dupe and subtract known normalized controllers (still may match false positives)
$normalized = [
    'modules/online-booking/controllers/PublicBookingController.php',
    'modules/public-commerce/controllers/PublicCommerceController.php',
    'modules/auth/controllers/BranchContextController.php',
    'modules/media/controllers/MediaAssetController.php',
    'modules/marketing/controllers/MarketingContactListsController.php',
    'modules/appointments/controllers/AppointmentController.php',
    'modules/documents/controllers/DocumentController.php',
    'modules/marketing/controllers/MarketingGiftCardTemplatesController.php',
    'modules/staff/controllers/StaffGroupController.php',
    'modules/public-commerce/services/PublicCommerceService.php',
];
$report = array_values(array_unique($report));
$report = array_filter($report, static fn (string $r): bool => !in_array($r, $normalized, true));

sort($report);

echo 'API JSON error contract audit (heuristic)' . PHP_EOL;
echo 'Canonical helper: Core\\App\\Response::jsonPublicApiError' . PHP_EOL;
echo 'Normalized in this task (excluded from list below): ' . implode(', ', $normalized) . PHP_EOL . PHP_EOL;

if ($report === []) {
    echo 'No additional heuristic matches (or only excluded files).' . PHP_EOL;
    exit(0);
}

echo 'Remaining files to review (' . count($report) . '):' . PHP_EOL;
foreach ($report as $line) {
    echo '  - ' . $line . PHP_EOL;
}

echo PHP_EOL . 'Also review: staff JSON controllers, payroll/reports `json()` helpers, and any client expecting legacy flat `error` strings.' . PHP_EOL;
exit(0);
