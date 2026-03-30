<?php

declare(strict_types=1);

namespace Modules\Packages\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Core\Contracts\ClientListProvider;
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
        private BranchContext $branchContext
    ) {
    }

    public function index(): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $filters = [
            'search' => $search ?: null,
            'status' => $status ?: null,
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $rows = $this->clientPackages->listInTenantScope($filters, $tenantBranchId, $perPage, ($page - 1) * $perPage);
        foreach ($rows as &$r) {
            $r['client_display'] = trim(($r['client_first_name'] ?? '') . ' ' . ($r['client_last_name'] ?? '')) ?: '—';
            $r['remaining_now'] = $this->service->getRemainingSessions((int) $r['id']);
        }
        unset($r);
        $total = $this->clientPackages->countInTenantScope($filters, $tenantBranchId);
        $branches = $this->getBranches();
        $flash = flash();
        require base_path('modules/packages/views/client-packages/index.php');
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
}
