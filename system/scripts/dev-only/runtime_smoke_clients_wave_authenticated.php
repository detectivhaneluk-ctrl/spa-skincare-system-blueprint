<?php

declare(strict_types=1);

/**
 * Authenticated in-process runtime smoke for the clients gap wave (same bootstrap/DB/DI as HTTP).
 *
 * Run from repo root:
 *   php system/scripts/dev-only/runtime_smoke_clients_wave_authenticated.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Errors\SafeDomainException;
use Core\Organization\OrganizationContext;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Clients\Services\ClientProfileReadService;
use Modules\Clients\Services\ClientRegistrationService;
use Modules\Clients\Services\ClientService;
use Modules\Clients\Support\ClientNormalizedSearchSchemaReadiness;

$db = app(\Core\App\Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$sessionAuth = app(SessionAuth::class);

$results = [];

$fail = static function (string $name, string $detail) use (&$results): void {
    $results[] = ['check' => $name, 'status' => 'FAIL', 'detail' => $detail];
};

$pass = static function (string $name, string $detail = '') use (&$results): void {
    $results[] = ['check' => $name, 'status' => 'PASS', 'detail' => $detail];
};

$skip = static function (string $name, string $detail) use (&$results): void {
    $results[] = ['check' => $name, 'status' => 'SKIP', 'detail' => $detail];
};

$branchRow = $db->fetchOne(
    'SELECT b.id AS branch_id, b.organization_id
     FROM branches b
     INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
     WHERE b.deleted_at IS NULL
     ORDER BY b.id ASC
     LIMIT 1'
);
if ($branchRow === null) {
    fwrite(STDERR, "FAIL: no active branch in database\n");
    exit(2);
}
$branchId = (int) $branchRow['branch_id'];
$orgId = (int) $branchRow['organization_id'];

$userRow = $db->fetchOne('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
if ($userRow === null) {
    fwrite(STDERR, "FAIL: no user in database\n");
    exit(2);
}
$userId = (int) $userRow['id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
$sessionAuth->login($userId);

$normReady = app(ClientNormalizedSearchSchemaReadiness::class)->isReady();

$resumeFilters = [
    'status' => null,
    'date_mode' => 'appointment',
    'date_from' => null,
    'date_to' => null,
    'page' => 1,
    'per_page' => 15,
];

$clientRepo = app(ClientRepository::class);
$profileRead = app(ClientProfileReadService::class);
$clientService = app(ClientService::class);

$clientRow = $clientRepo->findLiveReadableForProfile(
    (int) ($db->fetchOne(
        'SELECT c.id FROM clients c
         WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL AND c.branch_id = ?
         ORDER BY c.id ASC LIMIT 1',
        [$branchId]
    )['id'] ?? 0),
    $branchId
);
if ($clientRow === null) {
    $newId = $clientService->create([
        'branch_id' => $branchId,
        'first_name' => 'Runtime',
        'last_name' => 'Smoke',
        'phone' => null,
        'email' => null,
        'custom_fields' => [],
    ]);
    $clientRow = $clientRepo->findLiveReadableForProfile($newId, $branchId);
}
if ($clientRow === null) {
    $fail('setup', 'Could not resolve a profile client row.');
    foreach ($results as $r) {
        echo "{$r['status']}  {$r['check']}" . ($r['detail'] !== '' ? " — {$r['detail']}" : '') . PHP_EOL;
    }
    exit(2);
}
$clientId = (int) $clientRow['id'];

// --- 1) Profile fail-soft ---
try {
    $read = $profileRead->buildMainProfileReadModel($clientId, $clientRow, $resumeFilters);
    $ds = $read['duplicate_search'] ?? null;
    if ($normReady) {
        $skip(
            '1_profile_fail_soft',
            'Normalized columns are ready on this DB; fail-soft path not exercised (no migration-119 gap).'
        );
    } else {
        if (($ds['ready'] ?? true) !== false) {
            $fail('1_profile_fail_soft', 'duplicate_search.ready expected false when schema not ready');
        } elseif (($ds['blocked_reason'] ?? '') === '') {
            $fail('1_profile_fail_soft', 'blocked_reason expected non-empty');
        } elseif (!isset($read['client']['display_name']) || $read['client']['display_name'] === '') {
            $fail('1_profile_fail_soft', 'client display data missing');
        } else {
            $pass('1_profile_fail_soft', 'model built; duplicate_search not ready + reason; profile fields present');
        }
    }
} catch (\Throwable $e) {
    $fail('1_profile_fail_soft', $e->getMessage());
}

// --- 2) Duplicates fail-soft ---
try {
    $pack = $clientService->searchDuplicateCandidatesPaginated(
        ['full_name' => 'Smoke', 'phone' => null, 'email' => null],
        null,
        true,
        true,
        1,
        25
    );
    if ($normReady) {
        $skip('2_duplicates_fail_soft', 'Schema ready; paginated gate not in blocked mode on this DB.');
    } else {
        if (($pack['normalized_search_schema_ready'] ?? true) !== false) {
            $fail('2_duplicates_fail_soft', 'normalized_search_schema_ready expected false');
        } elseif ((int) ($pack['total'] ?? -1) !== 0 || ($pack['rows'] ?? []) !== []) {
            $fail('2_duplicates_fail_soft', 'expected zero rows/total when schema not ready');
        } else {
            $pass('2_duplicates_fail_soft', 'blocked flag; empty results');
        }
    }
} catch (\Throwable $e) {
    $fail('2_duplicates_fail_soft', $e->getMessage());
}

// --- 3) Merge preview coverage ---
try {
    $secondaryRow = $db->fetchOne(
        'SELECT c.id FROM clients c
         WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL AND c.branch_id = ? AND c.id != ?
         ORDER BY c.id ASC LIMIT 1',
        [$branchId, $clientId]
    );
    if ($secondaryRow === null) {
        $secId = $clientService->create([
            'branch_id' => $branchId,
            'first_name' => 'Merge',
            'last_name' => 'Secondary',
            'phone' => null,
            'email' => null,
            'custom_fields' => [],
        ]);
    } else {
        $secId = (int) $secondaryRow['id'];
    }
    $preview = $clientService->getMergePreview($clientId, $secId);
    $counts = $preview['secondary_linked_counts'] ?? [];
    $need = ['appointment_series', 'marketing_campaign_recipients', 'client_profile_images'];
    $missing = [];
    foreach ($need as $k) {
        $tblExists = $db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$k]
        ) !== null;
        if ($tblExists && !array_key_exists($k, $counts)) {
            $missing[] = $k;
        }
    }
    if ($missing !== []) {
        $fail('3_merge_preview_coverage', 'missing keys for existing tables: ' . implode(', ', $missing));
    } else {
        $present = array_values(array_filter($need, static function (string $k) use ($counts): bool {
            return array_key_exists($k, $counts);
        }));
        $pass('3_merge_preview_coverage', 'covered keys in preview: ' . ($present === [] ? '(none — optional tables absent)' : implode(', ', $present)));
    }
} catch (\Throwable $e) {
    $fail('3_merge_preview_coverage', $e->getMessage());
}

// --- 4) Registration convert ambiguity ---
try {
    $branchlessAnchor = $db->fetchOne(
        'SELECT c.id
         FROM clients c
         WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL AND c.branch_id IS NULL
           AND EXISTS (
               SELECT 1 FROM appointment_series s
               INNER JOIN branches b ON b.id = s.branch_id AND b.deleted_at IS NULL
               WHERE s.client_id = c.id AND b.organization_id = ?
           )
         LIMIT 1',
        [$orgId]
    );
    if ($branchlessAnchor === null) {
        $skip(
            '4_registration_convert_ambiguity',
            'No branchless client with appointment_series anchor in org (cannot build tenant-visible branchless registration).'
        );
    } else {
        $blClientId = (int) $branchlessAnchor['id'];
        $regId = $db->insert('client_registration_requests', [
            'branch_id' => null,
            'full_name' => 'Ambiguity smoke',
            'phone' => null,
            'email' => null,
            'notes' => null,
            'source' => 'manual',
            'status' => 'new',
            'linked_client_id' => $blClientId,
            'created_by' => $userId,
        ]);
        $outcome = 'none';
        try {
            try {
                app(ClientRegistrationService::class)->convert($regId, $clientId);
            } catch (SafeDomainException $e) {
                if ($e->publicCode === 'BRANCH_ATTACHMENT_AMBIGUOUS') {
                    $outcome = 'ok';
                } else {
                    $outcome = 'wrong:' . $e->publicCode;
                }
            }
        } catch (\Throwable $e) {
            $outcome = 'throw:' . $e->getMessage();
        } finally {
            $db->query('DELETE FROM client_registration_requests WHERE id = ?', [$regId]);
        }
        if ($outcome === 'ok') {
            $pass('4_registration_convert_ambiguity', 'BRANCH_ATTACHMENT_AMBIGUOUS');
        } elseif (str_starts_with($outcome, 'wrong:')) {
            $fail('4_registration_convert_ambiguity', substr($outcome, 6));
        } elseif (str_starts_with($outcome, 'throw:')) {
            $fail('4_registration_convert_ambiguity', substr($outcome, 6));
        } else {
            $fail('4_registration_convert_ambiguity', 'expected SafeDomainException BRANCH_ATTACHMENT_AMBIGUOUS not thrown');
        }
    }
} catch (\Throwable $e) {
    $fail('4_registration_convert_ambiguity', $e->getMessage());
}

// --- 5) Branchless client visibility (org proof) ---
try {
    $bl = $db->fetchOne(
        'SELECT c.id
         FROM clients c
         WHERE c.deleted_at IS NULL AND c.merged_into_client_id IS NULL AND c.branch_id IS NULL
           AND (
             EXISTS (
               SELECT 1 FROM appointment_series s
               INNER JOIN branches b ON b.id = s.branch_id AND b.deleted_at IS NULL
               WHERE s.client_id = c.id AND b.organization_id = ?
             )
             OR EXISTS (
               SELECT 1 FROM invoices i
               INNER JOIN branches b ON b.id = i.branch_id AND b.deleted_at IS NULL
               WHERE i.client_id = c.id AND i.deleted_at IS NULL AND i.branch_id IS NOT NULL AND b.organization_id = ?
             )
           )
         LIMIT 1',
        [$orgId, $orgId]
    );
    if ($bl === null) {
        $skip('5_branchless_client_visibility', 'No branchless client with series/invoice org anchor in DB.');
    } else {
        $bid = (int) $bl['id'];
        $row = $clientRepo->find($bid);
        if ($row === null) {
            $fail('5_branchless_client_visibility', 'find() returned null for anchored branchless client');
        } else {
            $pass('5_branchless_client_visibility', 'client id ' . $bid . ' visible via tenant org clause');
        }
    }
} catch (\Throwable $e) {
    $fail('5_branchless_client_visibility', $e->getMessage());
}

$exit = 0;
foreach ($results as $r) {
    echo "{$r['status']}  {$r['check']}" . ($r['detail'] !== '' ? " — {$r['detail']}" : '') . PHP_EOL;
    if ($r['status'] === 'FAIL') {
        $exit = 1;
    }
}
exit($exit);
