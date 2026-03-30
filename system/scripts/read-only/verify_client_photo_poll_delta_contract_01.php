<?php

declare(strict_types=1);

/**
 * CLIENT-PHOTO-STATUS-DELTA-PAYLOAD-AND-POLLING-HARDENING-01 — read-only structural proof (no DB).
 *
 * From repo root:
 *   php system/scripts/read-only/verify_client_photo_poll_delta_contract_01.php
 */

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(2);
}

$system = dirname(__DIR__, 2);
$repo = $system . '/modules/clients/repositories/ClientProfileImageRepository.php';
$service = $system . '/modules/clients/services/ClientProfileImageService.php';
$controller = $system . '/modules/clients/controllers/ClientController.php';
$photosView = $system . '/modules/clients/views/photos.php';

foreach ([$repo, $service, $controller, $photosView] as $p) {
    if (!is_file($p)) {
        fail("missing: {$p}");
    }
}

$repoSrc = (string) file_get_contents($repo);
$serviceSrc = (string) file_get_contents($service);
$controllerSrc = (string) file_get_contents($controller);
$viewSrc = (string) file_get_contents($photosView);

if (!str_contains($repoSrc, 'function listActiveEnrichedForClientInBranchByIds(')) {
    fail('ClientProfileImageRepository missing listActiveEnrichedForClientInBranchByIds');
}

if (!str_contains($serviceSrc, 'function buildClientPhotoPollStatusPayload(')) {
    fail('ClientProfileImageService missing buildClientPhotoPollStatusPayload');
}
if (!str_contains($serviceSrc, 'listActiveEnrichedForClientInBranchByIds')) {
    fail('poll path must use listActiveEnrichedForClientInBranchByIds');
}

$deltaStart = strpos($serviceSrc, 'function buildClientPhotoPollDeltaPayload(');
if ($deltaStart === false) {
    fail('ClientProfileImageService missing buildClientPhotoPollDeltaPayload');
}
$deltaEnd = strpos($serviceSrc, 'public function buildImageLibraryStatusPayload(', $deltaStart);
$deltaChunk = $deltaEnd !== false
    ? substr($serviceSrc, $deltaStart, $deltaEnd - $deltaStart)
    : substr($serviceSrc, $deltaStart);
if (str_contains($deltaChunk, 'listActiveForClientInBranch(')) {
    fail('buildClientPhotoPollDeltaPayload must not call listActiveForClientInBranch (use by-ids only)');
}

if (!str_contains($controllerSrc, 'parseClientPhotoPollIdsQuery')) {
    fail('ClientController must parse poll ids');
}
if (!str_contains($controllerSrc, 'buildClientPhotoPollStatusPayload')) {
    fail('ClientController must call buildClientPhotoPollStatusPayload');
}
if (!str_contains($controllerSrc, "'poll_mode' => 'full'") || !str_contains($controllerSrc, "'removed_image_ids' => []")) {
    fail('library-not-ready JSON must expose poll_mode and removed_image_ids for contract stability');
}

foreach (['removed_image_ids', 'pendingPollImageIds', 'fetchUrl', 'encodeURIComponent'] as $needle) {
    if (!str_contains($viewSrc, $needle)) {
        fail("photos.php polling must reference {$needle}");
    }
}

echo "PASS: client_photo_poll_delta_contract_01\n";
exit(0);
