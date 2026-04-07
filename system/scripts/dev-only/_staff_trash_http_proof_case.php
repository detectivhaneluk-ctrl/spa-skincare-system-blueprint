<?php

declare(strict_types=1);

/**
 * Internal: one HTTP stack exercise for staff trash permanent delete proof.
 * Invoked by smoke_staff_trash_http_permanent_delete_proof_01.php (subprocess; controller calls exit).
 *
 * Usage: php _staff_trash_http_proof_case.php <case> <result_json_path>
 */

$systemRoot = dirname(__DIR__, 2);
require $systemRoot . '/bootstrap.php';
require $systemRoot . '/modules/bootstrap.php';

use Core\App\Application;
use Core\App\Database;
use Core\Auth\PrincipalPlaneResolver;
use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Branch\TenantBranchAccessService;
use Core\Kernel\AssuranceLevel;
use Core\Kernel\ExecutionSurface;
use Core\Kernel\RequestContextHolder;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationContext;
use Core\Permissions\PermissionService;
use Modules\Staff\Repositories\StaffRepository;
use Modules\Staff\Services\StaffService;

$case = $argv[1] ?? '';
$resultFile = $argv[2] ?? '';
if ($case === '' || $resultFile === '') {
    fwrite(STDERR, "Usage: php _staff_trash_http_proof_case.php <case> <result_json_path>\n");
    exit(2);
}

register_shutdown_function(static function () use ($resultFile): void {
    $location = null;
    foreach (headers_list() as $h) {
        if (preg_match('/^Location:\s*(.+)$/i', $h, $m)) {
            $location = trim($m[1]);
            break;
        }
    }
    $flash = $_SESSION['_flash'] ?? null;
    $payload = [
        'http_code' => http_response_code() ?: 200,
        'location' => $location,
        'flash' => is_array($flash) ? $flash : null,
    ];
    if (isset($GLOBALS['_STAFF_HTTP_PROOF_CLEANUP']) && is_array($GLOBALS['_STAFF_HTTP_PROOF_CLEANUP'])) {
        $payload['cleanup'] = $GLOBALS['_STAFF_HTTP_PROOF_CLEANUP'];
    }
    @file_put_contents($resultFile, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
});

$db = app(Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$contextHolder = app(RequestContextHolder::class);
$repo = app(StaffRepository::class);
$service = app(StaffService::class);
$perms = app(PermissionService::class);
$plane = app(PrincipalPlaneResolver::class);
$tenantBranchAccess = app(TenantBranchAccessService::class);

$br = $db->fetchOne(
    'SELECT b.id AS branch_id, b.organization_id FROM branches b
     INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
     WHERE b.deleted_at IS NULL ORDER BY b.id LIMIT 1'
);
if ($br === null) {
    fwrite(STDERR, "ABORT: no live branch\n");
    exit(1);
}
$branchId = (int) $br['branch_id'];
$orgId = (int) $br['organization_id'];

$actorId = 0;
$users = $db->fetchAll('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 100');
foreach ($users as $ur) {
    $uid = (int) $ur['id'];
    if ($plane->resolveForUserId($uid) !== PrincipalPlaneResolver::TENANT_PLANE) {
        continue;
    }
    $allowed = $tenantBranchAccess->allowedBranchIdsForUser($uid);
    if (!in_array($branchId, $allowed, true)) {
        continue;
    }
    $branchContext->setCurrentBranchId($branchId);
    if (!$perms->has($uid, 'staff.delete')) {
        continue;
    }
    $actorId = $uid;
    break;
}

if ($actorId <= 0) {
    fwrite(STDERR, "ABORT: no tenant user with staff.delete for branch {$branchId}\n");
    exit(1);
}

$suffix = bin2hex(random_bytes(4));
$csrfName = (string) config('app.csrf_token_name', 'csrf_token');

$bindCliTenant = static function () use ($actorId, $branchId, $orgId, $branchContext, $orgContext, $contextHolder): void {
    $branchContext->setCurrentBranchId($branchId);
    $orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
    if (session_status() === PHP_SESSION_NONE) {
        app(SessionAuth::class)->startSession();
    }
    $_SESSION['user_id'] = $actorId;
    $tenantCtx = TenantContext::resolvedTenant(
        actorId: $actorId,
        organizationId: $orgId,
        branchId: $branchId,
        isSupportEntry: false,
        supportActorId: null,
        assuranceLevel: AssuranceLevel::SESSION,
        executionSurface: ExecutionSurface::CLI,
        organizationResolutionMode: OrganizationContext::MODE_BRANCH_DERIVED,
    );
    $contextHolder->set($tenantCtx);
};

$sessionAuth = app(SessionAuth::class);
$sessionAuth->startSession();
$sessionAuth->login($actorId);
$_SESSION['branch_id'] = $branchId;

$postUri = '';
$postBody = [];

switch ($case) {
    case 'permanent_success':
        $bindCliTenant();
        $proofId = $db->insert('staff', [
            'first_name' => 'HttpProof',
            'last_name' => 'Ok' . $suffix,
            'branch_id' => $branchId,
            'is_active' => 1,
        ]);
        $service->delete($proofId);
        $GLOBALS['_STAFF_HTTP_PROOF_CLEANUP'] = [];
        $postUri = '/staff/' . $proofId . '/permanent-delete';
        $postBody = [];
        break;

    case 'active_blocked':
        $bindCliTenant();
        $activeId = $db->insert('staff', [
            'first_name' => 'HttpProof',
            'last_name' => 'Active' . $suffix,
            'branch_id' => $branchId,
            'is_active' => 1,
        ]);
        $GLOBALS['_STAFF_HTTP_PROOF_CLEANUP'] = ['delete_live_staff_ids' => [$activeId]];
        $postUri = '/staff/' . $activeId . '/permanent-delete';
        $postBody = [];
        break;

    case 'restore_after_permanent':
        $bindCliTenant();
        $goneId = $db->insert('staff', [
            'first_name' => 'HttpProof',
            'last_name' => 'Gone' . $suffix,
            'branch_id' => $branchId,
            'is_active' => 1,
        ]);
        $service->delete($goneId);
        $service->permanentlyDelete($goneId);
        $GLOBALS['_STAFF_HTTP_PROOF_CLEANUP'] = [];
        $postUri = '/staff/' . $goneId . '/restore';
        $postBody = [];
        break;

    case 'dependency_blocked':
        $bindCliTenant();
        $blockedId = $db->insert('staff', [
            'first_name' => 'HttpProof',
            'last_name' => 'Dep' . $suffix,
            'branch_id' => $branchId,
            'is_active' => 1,
        ]);
        $service->delete($blockedId);
        $client = $db->fetchOne(
            'SELECT id FROM clients WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$branchId]
        );
        $svcRow = $db->fetchOne(
            'SELECT id FROM services WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$branchId]
        );
        if ($client === null || $svcRow === null) {
            fwrite(STDERR, "SKIP_CASE: no client/service in branch for appointment_series\n");
            exit(3);
        }
        $db->query(
            'INSERT INTO appointment_series (
                branch_id, client_id, service_id, staff_id,
                recurrence_type, interval_weeks, weekday, start_date, start_time, end_time, status
            ) VALUES (?, ?, ?, ?, \'weekly\', 1, 1, CURDATE(), \'09:00:00\', \'10:00:00\', \'active\')',
            [$branchId, (int) $client['id'], (int) $svcRow['id'], $blockedId]
        );
        $GLOBALS['_STAFF_HTTP_PROOF_CLEANUP'] = [
            'appointment_series_staff_ids' => [$blockedId],
            'purge_trashed_staff_ids' => [$blockedId],
        ];
        $postUri = '/staff/' . $blockedId . '/permanent-delete';
        $postBody = [];
        break;

    case 'bulk_partial':
        $bindCliTenant();
        $idOk = $db->insert('staff', [
            'first_name' => 'HttpProof',
            'last_name' => 'BulkOk' . $suffix,
            'branch_id' => $branchId,
            'is_active' => 1,
        ]);
        $idBad = $db->insert('staff', [
            'first_name' => 'HttpProof',
            'last_name' => 'BulkBad' . $suffix,
            'branch_id' => $branchId,
            'is_active' => 1,
        ]);
        $service->delete($idOk);
        $service->delete($idBad);
        $client = $db->fetchOne(
            'SELECT id FROM clients WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$branchId]
        );
        $svcRow = $db->fetchOne(
            'SELECT id FROM services WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$branchId]
        );
        if ($client === null || $svcRow === null) {
            fwrite(STDERR, "SKIP_CASE: no client/service in branch for appointment_series\n");
            exit(3);
        }
        $db->query(
            'INSERT INTO appointment_series (
                branch_id, client_id, service_id, staff_id,
                recurrence_type, interval_weeks, weekday, start_date, start_time, end_time, status
            ) VALUES (?, ?, ?, ?, \'weekly\', 1, 1, CURDATE(), \'09:00:00\', \'10:00:00\', \'active\')',
            [$branchId, (int) $client['id'], (int) $svcRow['id'], $idBad]
        );
        $GLOBALS['_STAFF_HTTP_PROOF_CLEANUP'] = [
            'appointment_series_staff_ids' => [$idBad],
            'purge_trashed_staff_ids' => [$idBad],
        ];
        $postUri = '/staff/bulk-permanent-delete';
        $postBody = [
            'staff_ids' => [$idOk, $idBad],
            'list_status' => 'trash',
            'list_page' => '1',
            'list_active' => '1',
        ];
        break;

    default:
        fwrite(STDERR, "Unknown case: {$case}\n");
        exit(2);
}

$token = $sessionAuth->csrfToken();
$postBody[$csrfName] = $token;

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $systemRoot . '/public/index.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = $postUri;
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTPS'] = 'off';
$_GET = [];
$_POST = $postBody;

$app = new Application($systemRoot);
$app->run();
