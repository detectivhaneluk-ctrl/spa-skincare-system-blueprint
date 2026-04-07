<?php

declare(strict_types=1);

/**
 * Internal: one HTTP stack exercise for services trash permanent delete proof.
 * Usage: php _services_trash_http_proof_case.php <case> <result_json_path>
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
use Modules\ServicesResources\Services\ServiceService;

$case = $argv[1] ?? '';
$resultFile = $argv[2] ?? '';
if ($case === '' || $resultFile === '') {
    fwrite(STDERR, "Usage: php _services_trash_http_proof_case.php <case> <result_json_path>\n");
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
    if (isset($GLOBALS['_SERVICES_HTTP_PROOF_CLEANUP']) && is_array($GLOBALS['_SERVICES_HTTP_PROOF_CLEANUP'])) {
        $payload['cleanup'] = $GLOBALS['_SERVICES_HTTP_PROOF_CLEANUP'];
    }
    @file_put_contents($resultFile, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
});

$db = app(Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$contextHolder = app(RequestContextHolder::class);
$service = app(ServiceService::class);
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
    if (!$perms->has($uid, 'services-resources.delete') || !$perms->has($uid, 'services-resources.edit')) {
        continue;
    }
    $actorId = $uid;
    break;
}

if ($actorId <= 0) {
    fwrite(STDERR, "ABORT: no tenant user with services-resources.delete+edit for branch {$branchId}\n");
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
};

$insertProofService = static function () use ($db, $branchId, $suffix): int {
    $sku = 'HTTP-SVC-' . $suffix . '-' . random_int(1000, 999999);

    return $db->insert('services', [
        'name' => 'HttpProof ' . $suffix,
        'branch_id' => $branchId,
        'sku' => $sku,
        'is_active' => 1,
    ]);
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
        $proofId = $insertProofService();
        $service->delete($proofId);
        $GLOBALS['_SERVICES_HTTP_PROOF_CLEANUP'] = [];
        $postUri = '/services-resources/services/' . $proofId . '/permanent-delete';
        break;

    case 'active_blocked':
        $bindCliTenant();
        $activeId = $insertProofService();
        $GLOBALS['_SERVICES_HTTP_PROOF_CLEANUP'] = ['delete_live_service_ids' => [$activeId]];
        $postUri = '/services-resources/services/' . $activeId . '/permanent-delete';
        break;

    case 'restore_after_permanent':
        $bindCliTenant();
        $goneId = $insertProofService();
        $service->delete($goneId);
        $service->permanentlyDelete($goneId);
        $GLOBALS['_SERVICES_HTTP_PROOF_CLEANUP'] = [];
        $postUri = '/services-resources/services/' . $goneId . '/restore';
        break;

    case 'dependency_blocked':
        $bindCliTenant();
        $blockedId = $insertProofService();
        $service->delete($blockedId);
        $client = $db->fetchOne(
            'SELECT id FROM clients WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$branchId]
        );
        $staffRow = $db->fetchOne(
            'SELECT id FROM staff WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$branchId]
        );
        if ($client === null || $staffRow === null) {
            fwrite(STDERR, "SKIP_CASE: need client+staff in branch for appointment_series\n");
            exit(3);
        }
        $db->query(
            'INSERT INTO appointment_series (
                branch_id, client_id, service_id, staff_id,
                recurrence_type, interval_weeks, weekday, start_date, start_time, end_time, status
            ) VALUES (?, ?, ?, ?, \'weekly\', 1, 1, CURDATE(), \'09:00:00\', \'10:00:00\', \'active\')',
            [$branchId, (int) $client['id'], $blockedId, (int) $staffRow['id']]
        );
        $GLOBALS['_SERVICES_HTTP_PROOF_CLEANUP'] = [
            'appointment_series_service_ids' => [$blockedId],
            'purge_trashed_service_ids' => [$blockedId],
        ];
        $postUri = '/services-resources/services/' . $blockedId . '/permanent-delete';
        break;

    case 'bulk_partial':
        $bindCliTenant();
        $idOk = $insertProofService();
        $idBad = $insertProofService();
        $service->delete($idOk);
        $service->delete($idBad);
        $client = $db->fetchOne(
            'SELECT id FROM clients WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$branchId]
        );
        $staffRow = $db->fetchOne(
            'SELECT id FROM staff WHERE branch_id = ? AND deleted_at IS NULL LIMIT 1',
            [$branchId]
        );
        if ($client === null || $staffRow === null) {
            fwrite(STDERR, "SKIP_CASE: need client+staff in branch for appointment_series\n");
            exit(3);
        }
        $db->query(
            'INSERT INTO appointment_series (
                branch_id, client_id, service_id, staff_id,
                recurrence_type, interval_weeks, weekday, start_date, start_time, end_time, status
            ) VALUES (?, ?, ?, ?, \'weekly\', 1, 1, CURDATE(), \'09:00:00\', \'10:00:00\', \'active\')',
            [$branchId, (int) $client['id'], $idBad, (int) $staffRow['id']]
        );
        $GLOBALS['_SERVICES_HTTP_PROOF_CLEANUP'] = [
            'appointment_series_service_ids' => [$idBad],
            'purge_trashed_service_ids' => [$idBad],
        ];
        $postUri = '/services-resources/services/bulk-permanent-delete';
        $postBody = [
            'service_ids' => [$idOk, $idBad],
            'list_status' => 'trash',
            'list_category' => '',
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
