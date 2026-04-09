<?php

declare(strict_types=1);

/**
 * Dev smoke: create three clients with identical name + mobile + email, then assert strong-duplicate detection.
 *
 * Requires normalized client search columns (migration 119). Run from repo root:
 *   php system/scripts/dev-only/smoke_clients_strong_duplicate_identity_01.php
 *
 * Optional: pass --cleanup to soft-delete the inserted rows (same session org/branch only).
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Clients\Services\ClientService;
use Modules\Clients\Support\ClientNormalizedSearchSchemaReadiness;

$cleanup = in_array('--cleanup', $argv, true);

$db = app(\Core\App\Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$sessionAuth = app(SessionAuth::class);
$clientRepo = app(ClientRepository::class);
$clientService = app(ClientService::class);
$norm = app(ClientNormalizedSearchSchemaReadiness::class);

$branchRow = $db->fetchOne(
    'SELECT b.id AS branch_id, b.organization_id
     FROM branches b
     INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
     WHERE b.deleted_at IS NULL
     ORDER BY b.id ASC
     LIMIT 1'
);
if ($branchRow === null) {
    fwrite(STDERR, "FAIL: no active branch\n");
    exit(2);
}
$branchId = (int) $branchRow['branch_id'];
$orgId = (int) $branchRow['organization_id'];

$userRow = $db->fetchOne('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
if ($userRow === null) {
    fwrite(STDERR, "FAIL: no user\n");
    exit(2);
}
$userId = (int) $userRow['id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
$sessionAuth->login($userId);

if (!$norm->isReady()) {
    echo "SKIP: normalized search schema not ready (email_lc / phone_mobile_digits missing)\n";
    exit(0);
}

$tag = 'smoke_strong_dup_' . bin2hex(random_bytes(4));
$email = $tag . '@smoke-duplicate.test';
$phone = '+1000555' . str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
$first = 'SmokeDup';
$last = 'SamePerson';

$createdIds = [];
try {
    for ($i = 0; $i < 3; $i++) {
        $createdIds[] = $clientService->create([
            'branch_id' => $branchId,
            'first_name' => $first,
            'last_name' => $last,
            'phone_mobile' => $phone,
            'email' => $email,
            'notes' => 'smoke_clients_strong_duplicate_identity_01 ' . $tag,
            'custom_fields' => [],
        ]);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'FAIL: create — ' . $e->getMessage() . "\n");
    exit(2);
}

$anchor = $clientRepo->find($createdIds[0]);
$nameNorm = strtolower(trim($first . ' ' . $last));
$count = 0;
if ($anchor !== null) {
    $count = $clientRepo->countClientsMatchingStrongDuplicateIdentity(
        (string) ($anchor['email_lc'] ?? ''),
        (string) ($anchor['phone_mobile_digits'] ?? ''),
        $nameNorm,
        []
    );
}

$pair = $clientRepo->findFirstStrongDuplicatePair([]);
$pairTouchesOurs = $pair !== null && (
    in_array($pair['id_a'], $createdIds, true) || in_array($pair['id_b'], $createdIds, true)
);
$emailOk = $anchor !== null && strtolower((string) ($anchor['email'] ?? '')) === strtolower($email);
$countOk = $count >= 3;

echo 'created_client_ids=' . implode(',', $createdIds) . "\n";
echo 'email=' . $email . ' phone=' . $phone . "\n";
echo 'pair=' . ($pair === null ? 'null' : $pair['id_a'] . ',' . $pair['id_b']) . "\n";
echo 'pair_includes_created=' . ($pairTouchesOurs ? '1' : '0') . "\n";
echo 'cluster_count=' . $count . "\n";

if ($cleanup) {
    foreach ($createdIds as $cid) {
        try {
            $clientService->delete($cid);
        } catch (\Throwable) {
            /* best-effort */
        }
    }
    echo "cleanup=soft-deleted test clients\n";
}

if ($countOk && $emailOk) {
    echo "RESULT=PASS cluster count >= 3 for same name + mobile + email (pair_includes_created=" . ($pairTouchesOurs ? '1' : '0') . ")\n";
    exit(0);
}

echo "RESULT=FAIL count_ok=" . ($countOk ? '1' : '0') . " email_ok=" . ($emailOk ? '1' : '0') . "\n";
exit(1);
