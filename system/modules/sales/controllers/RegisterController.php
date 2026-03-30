<?php

declare(strict_types=1);

namespace Modules\Sales\Controllers;

use Core\App\Application;
use Core\Branch\BranchDirectory;
use Modules\Sales\Repositories\CashMovementRepository;
use Modules\Sales\Repositories\RegisterSessionRepository;
use Modules\Sales\Services\RegisterSessionService;
use Modules\Sales\Services\SalesTenantScope;

final class RegisterController
{
    public function __construct(
        private RegisterSessionRepository $sessions,
        private CashMovementRepository $movements,
        private RegisterSessionService $service,
        private BranchDirectory $branchDirectory,
        private SalesTenantScope $tenantScope
    ) {
    }

    public function index(): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== '' ? (int) $_GET['branch_id'] : null;
        $contextBranch = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        if ($contextBranch !== null) {
            $branchId = $contextBranch;
        }
        $branches = $this->getBranches();
        if ($branchId === null && $branches !== []) {
            $branchId = (int) $branches[0]['id'];
        }

        $openSession = $branchId !== null ? $this->sessions->findOpenByBranch($branchId) : null;
        $movements = $openSession ? $this->movements->listBySession((int) $openSession['id'], 100) : [];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $history = $this->sessions->listRecent($branchId, $perPage, ($page - 1) * $perPage);
        $total = $this->sessions->count($branchId);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/sales/views/register/index.php');
    }

    public function open(): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $opening = (float) ($_POST['opening_cash_amount'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        try {
            $this->service->openSession($branchId, $opening, $notes);
            flash('success', 'Register session opened.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /sales/register?branch_id=' . $branchId);
        exit;
    }

    public function close(int $id): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $closing = (float) ($_POST['closing_cash_amount'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        try {
            $result = $this->service->closeSession($id, $closing, $notes);
            if (!empty($result['cash_sales_mixed_currency'])) {
                flash('success', 'Register session closed. Expected cash and variance were not computed (multiple cash payment currencies in this session).');
            } else {
                flash('success', 'Register session closed. Variance: ' . number_format((float) ($result['variance_amount'] ?? 0), 2));
            }
            $branchId = (int) ($result['branch_id'] ?? $branchId);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /sales/register?branch_id=' . $branchId);
        exit;
    }

    public function move(int $id): void
    {
        if (!$this->ensureProtectedTenantScope()) {
            return;
        }
        $type = trim((string) ($_POST['type'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        try {
            $this->service->addCashMovement($id, $type, $amount, $reason, $notes);
            flash('success', 'Cash movement recorded.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /sales/register?branch_id=' . $branchId);
        exit;
    }

    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    private function ensureProtectedTenantScope(): bool
    {
        if ($this->tenantScope->requiresProtectedTenantContext()) {
            return true;
        }
        Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);

        return false;
    }
}
