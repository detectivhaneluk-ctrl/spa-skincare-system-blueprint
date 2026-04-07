<?php

declare(strict_types=1);

/**
 * Services Trash — full HTTP stack proof for permanent delete (subprocess per case).
 *
 *   php system/scripts/dev-only/smoke_services_trash_http_permanent_delete_proof_01.php
 */

$systemRoot = dirname(__DIR__, 2);
require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';

use Core\App\Database;
use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Kernel\AssuranceLevel;
use Core\Kernel\ExecutionSurface;
use Core\Kernel\RequestContextHolder;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationContext;
use Modules\ServicesResources\Services\ServiceService;

$worker = __DIR__ . DIRECTORY_SEPARATOR . '_services_trash_http_proof_case.php';
if (!is_file($worker)) {
    fwrite(STDERR, "Missing worker: {$worker}\n");
    exit(1);
}

$cases = [
    'permanent_success',
    'active_blocked',
    'restore_after_permanent',
    'dependency_blocked',
    'bulk_partial',
];

$fail = 0;
$pass = static function (string $m): void {
    fwrite(STDOUT, "PASS  {$m}\n");
};
$failf = static function (string $m) use (&$fail): void {
    $fail++;
    fwrite(STDERR, "FAIL  {$m}\n");
};

$php = PHP_BINARY;
if ($php === '') {
    fwrite(STDERR, "PHP_BINARY empty\n");
    exit(1);
}

$db = app(Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$contextHolder = app(RequestContextHolder::class);
$service = app(ServiceService::class);

$applyCleanup = static function (?array $cleanup) use ($db, $branchContext, $orgContext, $contextHolder, $service): void {
    if ($cleanup === null || $cleanup === []) {
        return;
    }
    $br = $db->fetchOne(
        'SELECT b.id AS branch_id, b.organization_id FROM branches b
         INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
         WHERE b.deleted_at IS NULL ORDER BY b.id LIMIT 1'
    );
    if ($br === null) {
        return;
    }
    $branchId = (int) $br['branch_id'];
    $orgId = (int) $br['organization_id'];
    $admin = $db->fetchOne('SELECT id FROM users ORDER BY id LIMIT 1');
    if ($admin === null) {
        return;
    }
    $actorId = (int) $admin['id'];

    foreach ($cleanup['appointment_series_service_ids'] ?? [] as $sid) {
        $db->query('DELETE FROM appointment_series WHERE service_id = ?', [(int) $sid]);
    }
    foreach ($cleanup['delete_live_service_ids'] ?? [] as $sid) {
        $db->query('DELETE FROM services WHERE id = ? AND deleted_at IS NULL', [(int) $sid]);
    }
    foreach ($cleanup['purge_trashed_service_ids'] ?? [] as $sid) {
        $branchContext->setCurrentBranchId($branchId);
        $orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
        if (session_status() === PHP_SESSION_NONE) {
            app(SessionAuth::class)->startSession();
        }
        $_SESSION['user_id'] = $actorId;
        $contextHolder->set(TenantContext::resolvedTenant(
            actorId: $actorId,
            organizationId: $orgId,
            branchId: $branchId,
            isSupportEntry: false,
            supportActorId: null,
            assuranceLevel: AssuranceLevel::SESSION,
            executionSurface: ExecutionSurface::CLI,
            organizationResolutionMode: OrganizationContext::MODE_BRANCH_DERIVED,
        ));
        try {
            $service->permanentlyDelete((int) $sid);
        } catch (\Throwable) {
            $db->query('DELETE FROM services WHERE id = ?', [(int) $sid]);
        }
    }
};

foreach ($cases as $case) {
    $tmp = tempnam(sys_get_temp_dir(), 'svchttp');
    if ($tmp === false) {
        $failf('tempnam failed');
        exit(1);
    }
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($case) . ' ' . escapeshellarg($tmp);
    passthru($cmd, $exitCode);
    $raw = @file_get_contents($tmp);
    @unlink($tmp);
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        $failf("case {$case}: no/invalid JSON result");
        continue;
    }
    $applyCleanup(is_array($j['cleanup'] ?? null) ? $j['cleanup'] : null);

    if ($exitCode === 3) {
        $pass("HTTP services {$case} (SKIP — missing seed data)");
        continue;
    }
    if ($exitCode !== 0) {
        $failf("case {$case}: worker exit {$exitCode}");
        continue;
    }

    $code = (int) ($j['http_code'] ?? 0);
    $loc = (string) ($j['location'] ?? '');
    $flash = $j['flash'] ?? null;

    switch ($case) {
        case 'permanent_success':
            if (!in_array($code, [301, 302, 303], true)) {
                $failf('permanent_success: expected redirect HTTP status, got ' . $code);
            } elseif (!is_array($flash) || !isset($flash['success']) || !str_contains(strtolower((string) $flash['success']), 'permanently deleted')) {
                $failf('permanent_success: expected success flash');
            } elseif ($loc !== '' && !str_contains($loc, 'status=trash')) {
                $failf('permanent_success: Location present but not trash: ' . $loc);
            } else {
                $pass('HTTP services permanent_success (full middleware stack)');
            }
            break;
        case 'active_blocked':
            if (!in_array($code, [301, 302, 303], true)) {
                $failf('active_blocked: expected redirect HTTP status, got ' . $code);
            } elseif (!is_array($flash) || !isset($flash['error']) || !str_contains((string) $flash['error'], 'Only trashed')) {
                $failf('active_blocked: expected Only trashed error flash');
            } elseif ($loc !== '' && str_contains($loc, 'status=trash')) {
                $failf('active_blocked: Location should not be trash: ' . $loc);
            } else {
                $pass('HTTP services active_blocked');
            }
            break;
        case 'restore_after_permanent':
            if (!in_array($code, [301, 302, 303], true)) {
                $failf('restore_after_permanent: expected redirect HTTP status, got ' . $code);
            } elseif (!is_array($flash) || !isset($flash['error']) || !str_contains((string) $flash['error'], 'not found')) {
                $failf('restore_after_permanent: expected not-found error flash');
            } else {
                $pass('HTTP services restore_after_permanent');
            }
            break;
        case 'dependency_blocked':
            if (!in_array($code, [301, 302, 303], true)) {
                $failf('dependency_blocked: expected redirect HTTP status, got ' . $code);
            } elseif (!is_array($flash) || !isset($flash['error'])) {
                $failf('dependency_blocked: expected error flash');
            } else {
                $msg = strtolower((string) $flash['error']);
                if (!str_contains($msg, 'appointment series') && !str_contains($msg, 'related records')) {
                    $failf('dependency_blocked: unexpected message: ' . $flash['error']);
                } else {
                    $pass('HTTP services dependency_blocked');
                }
            }
            break;
        case 'bulk_partial':
            if (!in_array($code, [301, 302, 303], true)) {
                $failf('bulk_partial: expected redirect HTTP status, got ' . $code);
            } elseif (!is_array($flash) || !isset($flash['warning'])) {
                $failf('bulk_partial: expected warning flash');
            } else {
                $w = (string) $flash['warning'];
                if (!str_contains(strtolower($w), 'permanently deleted') || !str_contains($w, 'Not deleted')) {
                    $failf('bulk_partial: expected partial summary');
                } else {
                    $pass('HTTP services bulk_partial');
                }
            }
            break;
    }
}

exit($fail > 0 ? 1 : 0);
