<?php

declare(strict_types=1);

/**
 * CLIENTS-WAVE-PROOF-CLOSE-02: deterministic dev fixture + real route/dispatcher smoke (subprocess workers).
 *
 * Usage (from repo root, path as in Laragon layout):
 *   php system/scripts/dev-only/clients_wave_proof_close_02.php [fixture|routes|cleanup|all]
 *
 * Default: all (cleanup → fixture → routes → cleanup).
 */

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Core\App\Application;
use Core\App\Database;
use Core\Branch\TenantBranchAccessService;
use Core\Permissions\PermissionService;
use Modules\Clients\Support\ClientNormalizedSearchSchemaReadiness;

const CWPFC02_MARKER = '[CWPF02] fixture marker';
const CWPFC02_TOKEN = 'CWPF02';

$db = app(Database::class);

/**
 * @return list<int>
 */
function cwpfc02FixtureClientIds(Database $db): array
{
    $rows = $db->fetchAll(
        "SELECT id FROM clients WHERE deleted_at IS NULL AND (notes LIKE ? OR first_name LIKE 'CWPF02-%')",
        ['%' . CWPFC02_MARKER . '%']
    );
    $ids = [];
    foreach ($rows as $r) {
        $ids[] = (int) $r['id'];
    }

    return array_values(array_unique($ids));
}

function cwpfc02TableExists(Database $db, string $table): bool
{
    $row = $db->fetchOne(
        'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
        [$table]
    );

    return $row !== null;
}

function cwpfc02Cleanup(Database $db): void
{
    $clientIds = cwpfc02FixtureClientIds($db);
    $idList = $clientIds === [] ? '0' : implode(',', $clientIds);

    if (cwpfc02TableExists($db, 'marketing_campaign_recipients') && cwpfc02TableExists($db, 'marketing_campaigns')) {
        $db->query(
            "DELETE r FROM marketing_campaign_recipients r
             INNER JOIN marketing_campaigns c ON c.id = r.campaign_id
             WHERE c.name = ?",
            ['CWPF02 Campaign']
        );
    }
    if (cwpfc02TableExists($db, 'marketing_campaign_runs') && cwpfc02TableExists($db, 'marketing_campaigns')) {
        $db->query(
            "DELETE r FROM marketing_campaign_runs r
             INNER JOIN marketing_campaigns c ON c.id = r.campaign_id
             WHERE c.name = ?",
            ['CWPF02 Campaign']
        );
    }
    if (cwpfc02TableExists($db, 'marketing_campaigns')) {
        $db->query('DELETE FROM marketing_campaigns WHERE name = ?', ['CWPF02 Campaign']);
    }

    if ($clientIds !== [] && cwpfc02TableExists($db, 'appointment_series')) {
        $db->query("DELETE FROM appointment_series WHERE client_id IN ({$idList})");
    }
    if ($clientIds !== [] && cwpfc02TableExists($db, 'invoices')) {
        $db->query("DELETE FROM invoices WHERE client_id IN ({$idList}) OR invoice_number LIKE 'CWPF02-%'");
    }
    if ($clientIds !== [] && cwpfc02TableExists($db, 'client_profile_images')) {
        $db->query("DELETE FROM client_profile_images WHERE client_id IN ({$idList})");
    }

    $db->query(
        "DELETE FROM client_registration_requests WHERE full_name = ? OR notes LIKE ?",
        ['[CWPF02] Branchless Registration', '%' . CWPFC02_TOKEN . '%']
    );

    if ($clientIds !== []) {
        $db->query("DELETE FROM clients WHERE id IN ({$idList})");
    }
}

/**
 * @return array{user_id:int,branch_id:int,organization_id:int}|null
 */
function cwpfc02ResolveActor(Database $db, int $branchId): ?array
{
    $tba = app(TenantBranchAccessService::class);
    $perms = app(PermissionService::class);
    $rows = $db->fetchAll('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC');
    foreach ($rows as $row) {
        $uid = (int) $row['id'];
        if (!in_array($branchId, $tba->allowedBranchIdsForUser($uid), true)) {
            continue;
        }
        foreach (['clients.view', 'clients.edit', 'clients.create'] as $code) {
            if (!$perms->has($uid, $code)) {
                continue 2;
            }
        }
        $orgRow = $db->fetchOne(
            'SELECT organization_id FROM branches WHERE id = ? AND deleted_at IS NULL',
            [$branchId]
        );
        if ($orgRow === null) {
            return null;
        }

        return [
            'user_id' => $uid,
            'branch_id' => $branchId,
            'organization_id' => (int) $orgRow['organization_id'],
        ];
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function cwpfc02EnsureFixture(Database $db): ?array
{
    cwpfc02Cleanup($db);

    $branchRow = $db->fetchOne(
        'SELECT b.id AS branch_id, b.organization_id
         FROM branches b
         INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
         WHERE b.deleted_at IS NULL
         ORDER BY b.id ASC
         LIMIT 1'
    );
    if ($branchRow === null) {
        fwrite(STDERR, "CWPFC02: no active branch\n");

        return null;
    }
    $branchId = (int) $branchRow['branch_id'];
    $actor = cwpfc02ResolveActor($db, $branchId);
    if ($actor === null) {
        fwrite(STDERR, "CWPFC02: no user with branch access + clients.view/edit/create\n");

        return null;
    }
    $userId = $actor['user_id'];

    $notes = CWPFC02_MARKER;

    $branchClientId = $db->insert('clients', [
        'first_name' => 'CWPF02-Primary',
        'last_name' => 'Branch',
        'phone' => null,
        'email' => null,
        'notes' => $notes,
        'branch_id' => $branchId,
        'created_by' => $userId,
    ]);

    $branchlessClientId = $db->insert('clients', [
        'first_name' => 'CWPF02-Branchless',
        'last_name' => 'Anchor',
        'phone' => null,
        'email' => null,
        'notes' => $notes,
        'branch_id' => null,
        'created_by' => $userId,
    ]);

    $mergeSecondaryId = $db->insert('clients', [
        'first_name' => 'CWPF02-MergeSecondary',
        'last_name' => 'Rows',
        'phone' => null,
        'email' => null,
        'notes' => $notes,
        'branch_id' => $branchId,
        'created_by' => $userId,
    ]);

    $anchor = 'none';
    $serviceId = 0;
    $staffId = 0;
    if (cwpfc02TableExists($db, 'services')) {
        $sr = $db->fetchOne(
            'SELECT id FROM services WHERE deleted_at IS NULL AND (branch_id = ? OR branch_id IS NULL) ORDER BY id ASC LIMIT 1',
            [$branchId]
        );
        if ($sr !== null) {
            $serviceId = (int) $sr['id'];
        }
    }
    if (cwpfc02TableExists($db, 'staff')) {
        $st = $db->fetchOne(
            'SELECT id FROM staff WHERE deleted_at IS NULL AND branch_id = ? ORDER BY id ASC LIMIT 1',
            [$branchId]
        );
        if ($st !== null) {
            $staffId = (int) $st['id'];
        }
    }

    if (cwpfc02TableExists($db, 'appointment_series') && $serviceId > 0 && $staffId > 0) {
        $db->insert('appointment_series', [
            'branch_id' => $branchId,
            'client_id' => $branchlessClientId,
            'service_id' => $serviceId,
            'staff_id' => $staffId,
            'recurrence_type' => 'weekly',
            'interval_weeks' => 1,
            'weekday' => 1,
            'start_date' => date('Y-m-d'),
            'end_date' => null,
            'occurrences_count' => null,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'active',
        ]);
        $anchor = 'appointment_series';
    } elseif (cwpfc02TableExists($db, 'invoices')) {
        $db->insert('invoices', [
            'invoice_number' => 'CWPF02-INV-' . bin2hex(random_bytes(4)),
            'client_id' => $branchlessClientId,
            'appointment_id' => null,
            'branch_id' => $branchId,
            'status' => 'draft',
            'subtotal_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'created_by' => $userId,
        ]);
        $anchor = 'invoices';
    }

    if ($anchor === 'none') {
        cwpfc02Cleanup($db);
        fwrite(STDERR, "CWPFC02: branchless anchor requires appointment_series (+ service+staff in branch) or invoices table.\n");

        return null;
    }

    $optionalCoverage = [
        'appointment_series' => false,
        'marketing_campaign_recipients' => false,
        'client_profile_images' => false,
    ];

    if (cwpfc02TableExists($db, 'appointment_series') && $serviceId > 0 && $staffId > 0) {
        $db->insert('appointment_series', [
            'branch_id' => $branchId,
            'client_id' => $mergeSecondaryId,
            'service_id' => $serviceId,
            'staff_id' => $staffId,
            'recurrence_type' => 'weekly',
            'interval_weeks' => 1,
            'weekday' => 2,
            'start_date' => date('Y-m-d'),
            'end_date' => null,
            'occurrences_count' => null,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'status' => 'active',
        ]);
        $optionalCoverage['appointment_series'] = true;
    }

    if (cwpfc02TableExists($db, 'marketing_campaigns')
        && cwpfc02TableExists($db, 'marketing_campaign_runs')
        && cwpfc02TableExists($db, 'marketing_campaign_recipients')) {
        $campaignId = $db->insert('marketing_campaigns', [
            'branch_id' => $branchId,
            'name' => 'CWPF02 Campaign',
            'channel' => 'email',
            'segment_key' => 'all',
            'segment_config_json' => null,
            'subject' => 'CWPF02',
            'body_text' => 'CWPF02',
            'status' => 'draft',
            'created_by' => $userId,
        ]);
        $runId = $db->insert('marketing_campaign_runs', [
            'campaign_id' => $campaignId,
            'branch_id' => $branchId,
            'status' => 'frozen',
            'recipient_count' => 1,
            'created_by' => $userId,
        ]);
        $db->insert('marketing_campaign_recipients', [
            'campaign_run_id' => $runId,
            'campaign_id' => $campaignId,
            'client_id' => $mergeSecondaryId,
            'channel' => 'email',
            'email_snapshot' => 'cwpf02@example.invalid',
            'first_name_snapshot' => 'CWPF02',
            'last_name_snapshot' => 'Recipient',
            'delivery_status' => 'pending',
        ]);
        $optionalCoverage['marketing_campaign_recipients'] = true;
    }

    if (cwpfc02TableExists($db, 'client_profile_images')) {
        $db->insert('client_profile_images', [
            'branch_id' => $branchId,
            'client_id' => $mergeSecondaryId,
            'media_asset_id' => null,
            'title' => 'CWPF02',
            'storage_path' => 'cwpf02/smoke.bin',
            'filename' => 'cwpf02.bin',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 1,
            'is_active' => 1,
            'created_by' => $userId,
        ]);
        $optionalCoverage['client_profile_images'] = true;
    }

    $registrationId = $db->insert('client_registration_requests', [
        'branch_id' => null,
        'full_name' => '[CWPF02] Branchless Registration',
        'phone' => null,
        'email' => null,
        'notes' => CWPFC02_MARKER,
        'source' => 'manual',
        'status' => 'new',
        'linked_client_id' => $branchlessClientId,
        'created_by' => $userId,
    ]);

    $normReady = app(ClientNormalizedSearchSchemaReadiness::class)->isReady();

    return [
        'token' => CWPFC02_TOKEN,
        'user_id' => $userId,
        'branch_id' => $branchId,
        'organization_id' => $actor['organization_id'],
        'branch_client_id' => $branchClientId,
        'branchless_client_id' => $branchlessClientId,
        'merge_secondary_id' => $mergeSecondaryId,
        'registration_id' => $registrationId,
        'normalized_search_ready' => $normReady,
        'branchless_anchor' => $anchor,
        'optional_merge_coverage' => $optionalCoverage,
    ];
}

/**
 * @param array<string, mixed> $r
 */
function cwpfc02HeaderLine(array $r, string $prefix): ?string
{
    foreach ($r['headers'] ?? [] as $h) {
        if (is_string($h) && stripos($h, $prefix) === 0) {
            return $h;
        }
    }

    return null;
}

/**
 * @return array{status:string,reason:string,http:?int,redirect:?string,assertion:string}
 */
function cwpfc02RunDispatch(string $stateFile, string $method, string $uri, array $post = []): array
{
    $resultFile = tempnam(sys_get_temp_dir(), 'cwpf02res_');
    if ($resultFile === false) {
        return [
            'status' => 'FAIL',
            'reason' => 'tempnam failed',
            'http' => null,
            'redirect' => null,
            'assertion' => 'dispatch setup',
        ];
    }
    $postFile = '-';
    if ($post !== []) {
        $pf = tempnam(sys_get_temp_dir(), 'cwpf02post_');
        if ($pf === false) {
            return [
                'status' => 'FAIL',
                'reason' => 'post tempnam failed',
                'http' => null,
                'redirect' => null,
                'assertion' => 'dispatch setup',
            ];
        }
        file_put_contents($pf, json_encode($post, JSON_THROW_ON_ERROR));
        $postFile = $pf;
    }

    $worker = __DIR__ . '/clients_wave_proof_close_02_dispatch_worker.php';
    $cmd = [PHP_BINARY, $worker, $stateFile, $resultFile, $method, $uri, $postFile];
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        return [
            'status' => 'FAIL',
            'reason' => 'proc_open failed',
            'http' => null,
            'redirect' => null,
            'assertion' => 'proc_open',
        ];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($postFile !== '-') {
        @unlink($postFile);
    }

    if ($code !== 0) {
        return [
            'status' => 'FAIL',
            'reason' => 'worker exit ' . $code . ($stderr !== '' ? ('; ' . trim($stderr)) : ''),
            'http' => null,
            'redirect' => null,
            'assertion' => 'worker exit code',
        ];
    }

    $raw = file_get_contents($resultFile);
    @unlink($resultFile);
    if ($raw === false || $raw === '') {
        return [
            'status' => 'FAIL',
            'reason' => 'empty worker result',
            'http' => null,
            'redirect' => null,
            'assertion' => 'result file',
        ];
    }
    /** @var array<string, mixed> $r */
    $r = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    if (!empty($r['fatal'])) {
        return [
            'status' => 'FAIL',
            'reason' => (string) $r['fatal'] . ($stderr !== '' ? (' | ' . trim($stderr)) : ''),
            'http' => isset($r['http_status']) ? (int) $r['http_status'] : null,
            'redirect' => null,
            'assertion' => 'no worker fatal',
        ];
    }

    $rawHs = $r['http_status'] ?? null;
    $http = 200;
    if ($rawHs !== false && $rawHs !== null && $rawHs !== '') {
        $http = (int) $rawHs;
    }
    if ($http === 0) {
        $http = 200;
    }
    $loc = cwpfc02HeaderLine($r, 'Location:');
    $redirect = null;
    if ($loc !== null && preg_match('/^Location:\s*(.+)$/i', $loc, $m)) {
        $redirect = trim($m[1]);
    }
    $body = (string) ($r['body'] ?? '');

    return [
        'status' => 'OK',
        'reason' => '',
        'http' => $http,
        'redirect' => $redirect,
        'assertion' => '',
        '_body' => $body,
        '_flash_error' => $r['flash_error'] ?? null,
        '_raw' => $r,
    ];
}

$mode = $argv[1] ?? 'all';

$stateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spa_cwpfc02_state.json';

if ($mode === 'routes' && !is_readable($stateFile)) {
    fwrite(STDERR, "CWPFC02: missing state file. Run: php system/scripts/dev-only/clients_wave_proof_close_02.php fixture\n");
    exit(2);
}

if ($mode === 'cleanup') {
    cwpfc02Cleanup($db);
    echo "CWPFC02 cleanup done.\n";
    exit(0);
}

if ($mode === 'fixture') {
    $fx = cwpfc02EnsureFixture($db);
    if ($fx === null) {
        exit(2);
    }
    file_put_contents($stateFile, json_encode($fx, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo $stateFile . "\n";
    echo json_encode($fx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$doFixture = ($mode === 'all' || $mode === 'routes');
$doRoutes = ($mode === 'all' || $mode === 'routes');
$doFinalCleanup = $mode === 'all';

$report = [];

if ($doFixture) {
    $fx = cwpfc02EnsureFixture($db);
    if ($fx === null) {
        exit(2);
    }
    file_put_contents($stateFile, json_encode($fx, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

if (!$doRoutes) {
    exit(0);
}

/** @var array<string, mixed> $fx */
$fx = json_decode((string) file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
$branchClientId = (int) $fx['branch_client_id'];
$branchlessId = (int) $fx['branchless_client_id'];
$mergeSecondaryId = (int) $fx['merge_secondary_id'];
$registrationId = (int) $fx['registration_id'];
$normReady = (bool) $fx['normalized_search_ready'];
/** @var array<string, bool> $optCov */
$optCov = $fx['optional_merge_coverage'] ?? [];

// --- 1) GET /clients/{branch client}
$u1 = '/clients/' . $branchClientId;
$res1 = cwpfc02RunDispatch($stateFile, 'GET', $u1);
if ($res1['status'] !== 'OK') {
    $report[] = ['check' => '1 GET ' . $u1, 'status' => 'FAIL', 'reason' => $res1['reason'], 'http' => $res1['http'], 'redirect' => $res1['redirect'], 'assertion' => $res1['assertion']];
} else {
    $b = $res1['_body'] ?? '';
    $ok = str_contains($b, 'client-ref-contact-heading') && str_contains($b, 'CWPF02-Primary');
    if (!$normReady) {
        $ok = $ok && str_contains($b, ClientNormalizedSearchSchemaReadiness::PUBLIC_UNAVAILABLE_MESSAGE);
    }
    $report[] = [
        'check' => '1 GET ' . $u1,
        'status' => $ok ? 'PASS' : 'FAIL',
        'reason' => $ok ? 'profile shell + primary marker' . (!$normReady ? '; duplicate-search blocked message present' : '') : 'missing expected HTML markers',
        'http' => $res1['http'],
        'redirect' => $res1['redirect'],
        'assertion' => 'body contains client-ref-contact-heading, CWPF02-Primary' . (!$normReady ? ', PUBLIC_UNAVAILABLE_MESSAGE' : ''),
    ];
}

// --- 2) GET /clients/duplicates?...
$u2 = '/clients/duplicates?name=' . rawurlencode('CWPF02') . '&partial=1';
$res2 = cwpfc02RunDispatch($stateFile, 'GET', $u2);
if ($res2['status'] !== 'OK') {
    $report[] = ['check' => '2 GET ' . $u2, 'status' => 'FAIL', 'reason' => $res2['reason'], 'http' => $res2['http'], 'redirect' => $res2['redirect'], 'assertion' => $res2['assertion']];
} else {
    $b = $res2['_body'] ?? '';
    if ($normReady) {
        $report[] = [
            'check' => '2 GET duplicates (schema ready)',
            'status' => 'SKIP',
            'reason' => 'normalized columns ready; blocked-state duplicate surface not asserted on this DB',
            'http' => $res2['http'],
            'redirect' => $res2['redirect'],
            'assertion' => 'SKIP when migration 119 applied',
        ];
    } else {
        $ok = str_contains($b, ClientNormalizedSearchSchemaReadiness::PUBLIC_UNAVAILABLE_MESSAGE)
            && !str_contains($b, 'No clients matched these criteria');
        $fakeRow = str_contains($b, '/clients/' . $branchClientId) && str_contains($b, 'clients-ws-table') && str_contains($b, 'CWPF02-Primary');
        $ok = $ok && !$fakeRow;
        $report[] = [
            'check' => '2 GET ' . $u2,
            'status' => $ok ? 'PASS' : 'FAIL',
            'reason' => $ok ? 'honest unavailable message; no duplicate results table for fixture client' : 'blocked message or duplicate rows wrong',
            'http' => $res2['http'],
            'redirect' => $res2['redirect'],
            'assertion' => 'PUBLIC_UNAVAILABLE_MESSAGE; no results row linking to fixture primary',
        ];
    }
}

// --- 3) GET /clients/merge?...
$u3 = '/clients/merge?primary_id=' . $branchClientId . '&secondary_id=' . $mergeSecondaryId;
$res3 = cwpfc02RunDispatch($stateFile, 'GET', $u3);
if ($res3['status'] !== 'OK') {
    $report[] = ['check' => '3 GET merge preview', 'status' => 'FAIL', 'reason' => $res3['reason'], 'http' => $res3['http'], 'redirect' => $res3['redirect'], 'assertion' => $res3['assertion']];
} else {
    $b = $res3['_body'] ?? '';
    $ok = str_contains($b, 'Linked Table') && str_contains($b, 'Preview');
    $skipParts = [];
    $failParts = [];
    foreach (['appointment_series', 'marketing_campaign_recipients', 'client_profile_images'] as $tbl) {
        if (!empty($optCov[$tbl])) {
            if (!str_contains($b, $tbl)) {
                $failParts[] = "missing table label {$tbl}";
            }
        } else {
            $skipParts[] = $tbl;
        }
    }
    if ($failParts !== []) {
        $ok = false;
    }
    $reason = $ok
        ? 'merge preview HTML; optional keys present: ' . implode(',', array_keys(array_filter($optCov)))
            . ($skipParts !== [] ? ('; SKIP optional absent: ' . implode(',', $skipParts)) : '')
        : implode('; ', $failParts);
    $report[] = [
        'check' => '3 GET ' . $u3,
        'status' => $ok ? 'PASS' : 'FAIL',
        'reason' => $reason,
        'http' => $res3['http'],
        'redirect' => $res3['redirect'],
        'assertion' => 'index-table lists each existing optional merge table key',
    ];
}

// --- 4) POST convert
$u4 = '/clients/registrations/' . $registrationId . '/convert';
$res4 = cwpfc02RunDispatch($stateFile, 'POST', $u4, ['existing_client_id' => $branchClientId]);
if ($res4['status'] !== 'OK') {
    $report[] = ['check' => '4 POST ' . $u4, 'status' => 'FAIL', 'reason' => $res4['reason'], 'http' => $res4['http'], 'redirect' => $res4['redirect'], 'assertion' => $res4['assertion']];
} else {
    $red = (string) ($res4['redirect'] ?? '');
    $flashErr = $res4['_flash_error'] ?? '';
    $flashOk = stripos((string) $flashErr, 'branchless') !== false
        && stripos((string) $flashErr, 'branch-specific') !== false;
    $redirectOk = $red === '' || str_contains($red, '/clients/registrations/' . $registrationId);
    $httpOk = $res4['http'] === 302 || $res4['http'] === 303 || $res4['http'] === 200;
    $ok = $flashOk && $redirectOk && $httpOk;
    $report[] = [
        'check' => '4 POST ' . $u4,
        'status' => $ok ? 'PASS' : 'FAIL',
        'reason' => $ok
            ? 'SafeDomainException surfaced as flash; no silent attach (HTTP ' . (string) $res4['http'] . ')'
            : 'expected public ambiguity message; http=' . (string) $res4['http'] . ' redirect=' . $red . ' flash=' . (string) $flashErr,
        'http' => $res4['http'],
        'redirect' => $res4['redirect'] === '' ? 'n/a (CLI may omit Location header list)' : $res4['redirect'],
        'assertion' => 'flash error mentions branchless + branch-specific; HTTP redirect-class or 200; Location if present targets registration',
    ];
}

// --- 5) GET branchless profile
$u5 = '/clients/' . $branchlessId;
$res5 = cwpfc02RunDispatch($stateFile, 'GET', $u5);
if ($res5['status'] !== 'OK') {
    $report[] = ['check' => '5 GET ' . $u5, 'status' => 'FAIL', 'reason' => $res5['reason'], 'http' => $res5['http'], 'redirect' => $res5['redirect'], 'assertion' => $res5['assertion']];
} else {
    $b = $res5['_body'] ?? '';
    $ok = ($res5['http'] >= 200 && $res5['http'] < 400)
        && str_contains($b, 'CWPF02-Branchless');
    $report[] = [
        'check' => '5 GET ' . $u5,
        'status' => $ok ? 'PASS' : 'FAIL',
        'reason' => $ok ? 'branchless client visible in profile HTML' : 'missing profile or wrong status',
        'http' => $res5['http'],
        'redirect' => $res5['redirect'],
        'assertion' => 'HTTP 200, body contains CWPF02-Branchless',
    ];
}

foreach ($report as $line) {
    echo sprintf(
        "%s — %s — HTTP:%s — redirect:%s — %s\n",
        $line['check'],
        $line['status'],
        $line['http'] === null ? 'n/a' : (string) $line['http'],
        $line['redirect'] === null ? 'n/a' : $line['redirect'],
        $line['reason']
    );
    echo "  assertion: {$line['assertion']}\n";
}

if ($doFinalCleanup) {
    cwpfc02Cleanup($db);
    echo "CWPFC02 final cleanup done.\n";
}

$failed = false;
foreach ($report as $line) {
    if ($line['status'] === 'FAIL') {
        $failed = true;
    }
}
exit($failed ? 1 : 0);
