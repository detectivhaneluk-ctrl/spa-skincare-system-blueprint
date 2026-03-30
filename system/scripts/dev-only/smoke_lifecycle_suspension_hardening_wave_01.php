<?php

declare(strict_types=1);

/**
 * Dev-only runtime smoke: suspended org + inactive staff at branch (Dispatcher + AuthMiddleware stack).
 *
 * Run: php system/scripts/dev-only/smoke_lifecycle_suspension_hardening_wave_01.php
 */

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Core\App\Database;
use Core\Auth\PrincipalPlaneResolver;

$db = app(Database::class);
$principal = app(PrincipalPlaneResolver::class);

$candidates = $db->fetchAll(
    'SELECT u.id AS user_id, u.branch_id AS branch_id, b.organization_id AS organization_id
     FROM users u
     INNER JOIN branches b ON b.id = u.branch_id AND b.deleted_at IS NULL
     INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
     WHERE u.deleted_at IS NULL AND u.branch_id IS NOT NULL
       AND o.suspended_at IS NULL
     ORDER BY u.id ASC'
);

$results = [];

$baseRow = null;
foreach ($candidates as $cand) {
    $uid = (int) ($cand['user_id'] ?? 0);
    if ($uid > 0 && !$principal->isControlPlane($uid)) {
        $baseRow = $cand;
        break;
    }
}

if ($baseRow === null) {
    $results[] = ['check' => 'setup', 'status' => 'FAIL', 'detail' => 'no non-control-plane user with pinned branch + non-suspended org'];
    foreach ($results as $r) {
        echo $r['status'] . ' ' . $r['check'] . ' — ' . $r['detail'] . "\n";
    }
    exit(1);
}

$userId = (int) $baseRow['user_id'];
$branchId = (int) $baseRow['branch_id'];
$orgId = (int) $baseRow['organization_id'];

$staffRow = $db->fetchOne(
    'SELECT 1 AS ok FROM staff WHERE user_id = ? AND branch_id = ? AND deleted_at IS NULL AND is_active = 1 LIMIT 1',
    [$userId, $branchId]
);
$hasStaffBinding = $staffRow !== null;

$stateFile = tempnam(sys_get_temp_dir(), 'lsh01_');
    $worker = __DIR__ . '/lifecycle_suspension_hardening_wave_01_dispatch_worker.php';

    $orgBefore = $db->fetchOne('SELECT suspended_at FROM organizations WHERE id = ?', [$orgId]);
    $prevSuspended = $orgBefore['suspended_at'] ?? null;

    file_put_contents($stateFile, json_encode(['user_id' => $userId, 'branch_id' => $branchId], JSON_THROW_ON_ERROR));

    $db->query('UPDATE organizations SET suspended_at = NOW() WHERE id = ?', [$orgId]);

    $resFile1 = tempnam(sys_get_temp_dir(), 'lsh01r_');
    $cmd = [PHP_BINARY, $worker, $stateFile, $resFile1, 'GET', '/dashboard'];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);

    $raw1 = file_get_contents($resFile1);
    @unlink($resFile1);
    $j1 = $raw1 !== false && $raw1 !== '' ? json_decode($raw1, true) : null;
    $payload1 = is_array($j1) && isset($j1['body']) ? json_decode((string) $j1['body'], true) : null;
    $code1 = is_array($payload1) && isset($payload1['error']['code']) ? (string) $payload1['error']['code'] : '';
    $http1 = is_array($j1) ? (int) ($j1['http_status'] ?? 0) : 0;

    if ($prevSuspended === null || $prevSuspended === '') {
        $db->query('UPDATE organizations SET suspended_at = NULL WHERE id = ?', [$orgId]);
    } else {
        $db->query('UPDATE organizations SET suspended_at = ? WHERE id = ?', [$prevSuspended, $orgId]);
    }

    $ok1 = $http1 === 403 && $code1 === 'TENANT_ORGANIZATION_SUSPENDED';
    $results[] = [
        'check' => '1_suspended_org_json',
        'status' => $ok1 ? 'PASS' : 'FAIL',
        'detail' => $ok1 ? 'HTTP 403 + TENANT_ORGANIZATION_SUSPENDED' : 'http=' . (string) $http1 . ' code=' . $code1 . ' body=' . substr((string) ($j1['body'] ?? ''), 0, 200),
    ];

    if (!$hasStaffBinding) {
        $results[] = [
            'check' => '2_inactive_staff_json',
            'status' => 'SKIP',
            'detail' => 'no active staff row for this user+branch (cannot flip is_active)',
        ];
    } else {
        $db->query('UPDATE staff SET is_active = 0 WHERE user_id = ? AND branch_id = ?', [$userId, $branchId]);

        $resFile2 = tempnam(sys_get_temp_dir(), 'lsh01r2_');
        $cmd2 = [PHP_BINARY, $worker, $stateFile, $resFile2, 'GET', '/dashboard'];
        $proc2 = proc_open($cmd2, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2, null, null, ['bypass_shell' => true]);
        fclose($pipes2[0]);
        stream_get_contents($pipes2[1]);
        fclose($pipes2[1]);
        stream_get_contents($pipes2[2]);
        fclose($pipes2[2]);
        proc_close($proc2);

        $raw2 = file_get_contents($resFile2);
        @unlink($resFile2);
        $j2 = $raw2 !== false && $raw2 !== '' ? json_decode($raw2, true) : null;
        $payload2 = is_array($j2) && isset($j2['body']) ? json_decode((string) $j2['body'], true) : null;
        $code2 = is_array($payload2) && isset($payload2['error']['code']) ? (string) $payload2['error']['code'] : '';
        $http2 = is_array($j2) ? (int) ($j2['http_status'] ?? 0) : 0;

        $db->query('UPDATE staff SET is_active = 1 WHERE user_id = ? AND branch_id = ?', [$userId, $branchId]);

        $ok2 = $http2 === 403 && $code2 === 'TENANT_ACTOR_INACTIVE';
        $results[] = [
            'check' => '2_inactive_staff_json',
            'status' => $ok2 ? 'PASS' : 'FAIL',
            'detail' => $ok2 ? 'HTTP 403 + TENANT_ACTOR_INACTIVE' : 'http=' . (string) $http2 . ' code=' . $code2 . ' body=' . substr((string) ($j2['body'] ?? ''), 0, 200),
        ];
    }
@unlink($stateFile);

$failed = false;
foreach ($results as $r) {
    echo $r['status'] . ' ' . $r['check'] . ' — ' . $r['detail'] . "\n";
    if ($r['status'] === 'FAIL') {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
