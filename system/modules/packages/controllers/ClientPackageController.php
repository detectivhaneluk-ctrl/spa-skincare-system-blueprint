<?php

declare(strict_types=1);

namespace Modules\Packages\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Core\Contracts\ClientListProvider;
use Core\Permissions\PermissionService;
use Modules\Packages\Repositories\ClientPackageRepository;
use Modules\Packages\Repositories\PackageRepository;
use Modules\Packages\Repositories\PackageUsageRepository;
use Modules\Packages\Services\PackageService;

final class ClientPackageController
{
    public function __construct(
        private ClientPackageRepository $clientPackages,
        private PackageRepository $packages,
        private PackageUsageRepository $usages,
        private PackageService $service,
        private ClientListProvider $clients,
        private BranchDirectory $branchDirectory,
        private BranchContext $branchContext,
        private PermissionService $perm
    ) {
    }

    public function index(): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $search = trim((string) ($_GET['search'] ?? ''));
        $status = trim((string) ($_GET['status'] ?? ''));
        $branchRaw = trim((string) ($_GET['branch_id'] ?? ''));
        $filterClientId = max(0, (int) ($_GET['client_id'] ?? 0));
        $filters = [
            'search' => $search !== '' ? $search : null,
            'status' => $status !== '' ? $status : null,
        ];
        if ($filterClientId > 0) {
            $filters['client_id'] = $filterClientId;
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $total = $this->clientPackages->countInTenantScope($filters, $tenantBranchId);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($total === 0) {
            $page = 1;
        } elseif ($page > $totalPages) {
            $page = $totalPages;
        }
        $rows = $this->clientPackages->listInTenantScope($filters, $tenantBranchId, $perPage, ($page - 1) * $perPage);
        foreach ($rows as &$r) {
            $r['client_display'] = trim(($r['client_first_name'] ?? '') . ' ' . ($r['client_last_name'] ?? '')) ?: '—';
            $r['remaining_now'] = $this->service->getRemainingSessions((int) $r['id']);
        }
        unset($r);
        $branches = $this->getBranches();
        $flash = flash();
        $sessionAuth = Application::container()->get(\Core\Auth\SessionAuth::class);
        $uid = $sessionAuth->id();
        $csrf = $sessionAuth->csrfToken();
        $canBulkCancel = $uid !== null && $this->perm->has($uid, 'packages.cancel');
        require base_path('modules/packages/views/client-packages/index.php');
    }

    public function bulkCancel(): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $action = trim((string) ($_POST['bulk_action'] ?? ''));
        if ($action !== 'cancel') {
            flash('error', 'Choose a bulk action.');
            $this->redirectToClientPackagesIndexPostContext();
            return;
        }
        $raw = $_POST['client_package_ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $idSet = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $idSet[$id] = true;
            }
        }
        $ids = array_keys($idSet);
        if ($ids === []) {
            flash('error', 'No packages selected.');
            $this->redirectToClientPackagesIndexPostContext();
            return;
        }
        $notesRaw = trim((string) ($_POST['bulk_notes'] ?? ''));
        $notes = $notesRaw !== '' ? $notesRaw : null;
        $out = $this->service->bulkCancelClientPackages($ids, $notes, $tenantBranchId);
        $cancelled = $out['cancelled'];
        $skipped = $out['skipped'];
        if ($cancelled === 0) {
            flash('error', $skipped > 0 ? 'No packages could be cancelled (not found, expired, or branch mismatch).' : 'No packages could be cancelled.');
        } elseif ($skipped === 0) {
            flash('success', $cancelled === 1 ? '1 package cancelled.' : "{$cancelled} packages cancelled.");
        } else {
            flash('warning', "{$cancelled} cancelled, {$skipped} skipped.");
        }
        $this->redirectToClientPackagesIndexPostContext();
    }

    public function assign(): void
    {
        $branchId = $this->tenantBranchOrRedirect();
        $packageDefs = $this->filterAssignablePackages($branchId);
        $clientOptions = $this->clients->list($branchId);
        $branches = $this->getBranches();
        $assignment = ['assigned_sessions' => 1, 'assigned_at' => date('Y-m-d\TH:i')];
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/packages/views/client-packages/assign.php');
    }

    public function storeAssign(): void
    {
        $this->tenantBranchOrRedirect();
        $data = $this->parseAssignInput();
        $errors = $this->validateAssign($data);
        if (!empty($errors)) {
            $packageDefs = $this->filterAssignablePackages($data['branch_id']);
            $clientOptions = $this->clients->list($data['branch_id']);
            $branches = $this->getBranches();
            $assignment = $data;
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/packages/views/client-packages/assign.php');
            return;
        }
        try {
            $id = $this->service->assignPackageToClient($data);
            flash('success', 'Package assigned.');
            header('Location: /packages/client-packages/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $packageDefs = $this->filterAssignablePackages($data['branch_id']);
            $clientOptions = $this->clients->list($data['branch_id']);
            $branches = $this->getBranches();
            $assignment = $data;
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/packages/views/client-packages/assign.php');
        }
    }

    public function show(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $clientPackage = $this->clientPackages->findInTenantScope($id, $tenantBranchId);
        if (!$clientPackage) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        try {
            $this->service->expireClientPackageIfNeeded(
                $id,
                $tenantBranchId
            );
            $clientPackage = $this->clientPackages->findInTenantScope($id, $tenantBranchId) ?? $clientPackage;
        } catch (\Throwable) {
            // keep page render resilient
        }
        $clientPackage['client_display'] = trim(($clientPackage['client_first_name'] ?? '') . ' ' . ($clientPackage['client_last_name'] ?? '')) ?: '—';
        $currentRemaining = $this->service->getRemainingSessions($id);
        $usageHistory = $this->usages->listByClientPackage($id);
        $flash = flash();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/packages/views/client-packages/show.php');
    }

    public function useForm(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $clientPackage = $this->clientPackages->findInTenantScope($id, $tenantBranchId);
        if (!$clientPackage) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $currentRemaining = $this->service->getRemainingSessions($id);
        $errors = [];
        $usage = ['quantity' => 1, 'notes' => ''];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/packages/views/client-packages/use.php');
    }

    public function useStore(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $clientPackage = $this->clientPackages->findInTenantScope($id, $tenantBranchId);
        if (!$clientPackage) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $usage = ['quantity' => (int) ($_POST['quantity'] ?? 0), 'notes' => trim($_POST['notes'] ?? '') ?: null];
        $errors = [];
        if ($usage['quantity'] <= 0) {
            $errors['quantity'] = 'Use quantity must be greater than zero.';
        }
        if (!empty($errors)) {
            $currentRemaining = $this->service->getRemainingSessions($id);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/packages/views/client-packages/use.php');
            return;
        }
        try {
            $this->service->usePackageSession($id, $usage['quantity'], [
                'notes' => $usage['notes'],
                'branch_id' => $tenantBranchId,
            ]);
            flash('success', 'Session usage recorded.');
            header('Location: /packages/client-packages/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $currentRemaining = $this->service->getRemainingSessions($id);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/packages/views/client-packages/use.php');
        }
    }

    public function adjustForm(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $clientPackage = $this->clientPackages->findInTenantScope($id, $tenantBranchId);
        if (!$clientPackage) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $currentRemaining = $this->service->getRemainingSessions($id);
        $errors = [];
        $adjustment = ['quantity' => 1, 'notes' => ''];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/packages/views/client-packages/adjust.php');
    }

    public function adjustStore(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $clientPackage = $this->clientPackages->findInTenantScope($id, $tenantBranchId);
        if (!$clientPackage) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $adjustment = ['quantity' => (int) ($_POST['quantity'] ?? 0), 'notes' => trim($_POST['notes'] ?? '') ?: null];
        $errors = [];
        if ($adjustment['quantity'] === 0) {
            $errors['quantity'] = 'Adjustment quantity cannot be zero.';
        }
        if (!empty($errors)) {
            $currentRemaining = $this->service->getRemainingSessions($id);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/packages/views/client-packages/adjust.php');
            return;
        }
        try {
            $this->service->adjustPackageSessions($id, $adjustment['quantity'], [
                'notes' => $adjustment['notes'],
                'branch_id' => $tenantBranchId,
            ]);
            flash('success', 'Package sessions adjusted.');
            header('Location: /packages/client-packages/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $currentRemaining = $this->service->getRemainingSessions($id);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/packages/views/client-packages/adjust.php');
        }
    }

    public function reverseStore(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $clientPackage = $this->clientPackages->findInTenantScope($id, $tenantBranchId);
        if (!$clientPackage) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $usageId = (int) ($_POST['usage_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '') ?: null;
        if ($usageId <= 0) {
            flash('error', 'usage_id is required for reverse.');
            header('Location: /packages/client-packages/' . $id);
            exit;
        }
        try {
            $this->service->reversePackageUsage($id, $usageId, [
                'notes' => $notes,
                'branch_id' => $tenantBranchId,
            ]);
            flash('success', 'Package usage reversed.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /packages/client-packages/' . $id);
        exit;
    }

    public function cancelStore(int $id): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $clientPackage = $this->clientPackages->findInTenantScope($id, $tenantBranchId);
        if (!$clientPackage) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $notes = trim($_POST['notes'] ?? '') ?: null;
        try {
            $this->service->cancelClientPackage($id, $notes, $tenantBranchId);
            flash('success', 'Client package cancelled.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /packages/client-packages/' . $id);
        exit;
    }

    private function parseAssignInput(): array
    {
        $branchRaw = trim($_POST['branch_id'] ?? '');
        $assignedAtRaw = trim($_POST['assigned_at'] ?? '');
        $startsAtRaw = trim($_POST['starts_at'] ?? '');
        $expiresAtRaw = trim($_POST['expires_at'] ?? '');
        return [
            'package_id' => (int) ($_POST['package_id'] ?? 0),
            'client_id' => (int) ($_POST['client_id'] ?? 0),
            'branch_id' => $branchRaw === '' ? null : (int) $branchRaw,
            'assigned_sessions' => (int) ($_POST['assigned_sessions'] ?? 0),
            'assigned_at' => $assignedAtRaw !== '' ? date('Y-m-d H:i:s', strtotime($assignedAtRaw)) : date('Y-m-d H:i:s'),
            'starts_at' => $startsAtRaw !== '' ? date('Y-m-d H:i:s', strtotime($startsAtRaw)) : null,
            'expires_at' => $expiresAtRaw !== '' ? date('Y-m-d H:i:s', strtotime($expiresAtRaw)) : null,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
        ];
    }

    private function validateAssign(array $data): array
    {
        $errors = [];
        if ((int) $data['package_id'] <= 0) {
            $errors['package_id'] = 'Package is required.';
        }
        if ((int) $data['client_id'] <= 0) {
            $errors['client_id'] = 'Client is required.';
        }
        if ((int) $data['assigned_sessions'] <= 0) {
            $errors['assigned_sessions'] = 'assigned_sessions must be greater than zero.';
        }
        if (!empty($data['expires_at']) && strtotime((string) $data['expires_at']) <= strtotime((string) $data['assigned_at'])) {
            $errors['expires_at'] = 'expires_at must be after assigned_at.';
        }
        return $errors;
    }

    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    private function filterAssignablePackages(int $branchId): array
    {
        return $this->packages->listActiveAssignableInTenantScope($branchId);
    }

    private function tenantBranchOrRedirect(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            flash('error', 'Tenant branch context is required for client package routes.');
            header('Location: /packages/client-packages');
            exit;
        }

        return $branchId;
    }

    private function redirectToClientPackagesIndexPostContext(): void
    {
        $q = [];
        $s = trim((string) ($_POST['list_search'] ?? ''));
        if ($s !== '') {
            $q['search'] = $s;
        }
        $st = trim((string) ($_POST['list_status'] ?? ''));
        if ($st !== '') {
            $q['status'] = $st;
        }
        $br = trim((string) ($_POST['list_branch_id'] ?? ''));
        if ($br !== '') {
            $q['branch_id'] = $br;
        }
        $cid = (int) ($_POST['list_client_id'] ?? 0);
        if ($cid > 0) {
            $q['client_id'] = (string) $cid;
        }
        $pg = (int) ($_POST['list_page'] ?? 1);
        if ($pg > 1) {
            $q['page'] = (string) $pg;
        }
        $url = '/packages/client-packages' . ($q !== [] ? '?' . http_build_query($q) : '');
        header('Location: ' . $url);
        exit;
    }
}
