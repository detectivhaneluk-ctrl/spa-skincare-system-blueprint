<?php

declare(strict_types=1);

namespace Modules\Payroll\Controllers;

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Core\Permissions\PermissionService;
use Modules\Payroll\Repositories\PayrollCommissionLineRepository;
use Modules\Payroll\Repositories\PayrollRunRepository;
use Modules\Payroll\Services\PayrollService;
use Modules\Staff\Repositories\StaffRepository;

final class PayrollRunController
{
    public function __construct(
        private PayrollRunRepository $runs,
        private PayrollCommissionLineRepository $lines,
        private PayrollService $payroll,
        private BranchContext $branchContext,
        private AuthService $auth,
        private PermissionService $perms,
        private StaffRepository $staff,
        private BranchDirectory $branchDirectory,
    ) {
    }

    public function index(): void
    {
        $this->requireView();
        $branchId = $this->branchContext->getCurrentBranchId();
        $items = $this->runs->listRecent($branchId, 50, 0);
        $flash = flash();
        $title = 'Payroll runs';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $canManage = $this->canManage();
        require base_path('modules/payroll/views/runs/index.php');
    }

    public function create(): void
    {
        $this->requireManage();
        $run = [
            'branch_id' => $this->branchContext->getCurrentBranchId(),
            'period_start' => date('Y-m-01'),
            'period_end' => date('Y-m-t'),
            'notes' => '',
        ];
        $errors = [];
        $branches = $this->getBranches();
        $title = 'Create payroll run';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/payroll/views/runs/create.php');
    }

    public function store(): void
    {
        $this->requireManage();
        $branchId = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' && $_POST['branch_id'] !== null
            ? (int) $_POST['branch_id']
            : null;
        if ($this->branchContext->getCurrentBranchId() !== null) {
            $branchId = $this->branchContext->getCurrentBranchId();
        }
        if ($branchId === null || $branchId < 1) {
            $errors = ['_general' => 'Branch is required.'];
            $run = [
                'branch_id' => $branchId,
                'period_start' => (string) ($_POST['period_start'] ?? ''),
                'period_end' => (string) ($_POST['period_end'] ?? ''),
                'notes' => trim((string) ($_POST['notes'] ?? '')),
            ];
            $branches = $this->getBranches();
            $title = 'Create payroll run';
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/payroll/views/runs/create.php');
            return;
        }
        $periodStart = trim((string) ($_POST['period_start'] ?? ''));
        $periodEnd = trim((string) ($_POST['period_end'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $user = $this->auth->user();
        $uid = $user ? (int) $user['id'] : null;
        try {
            $this->branchContext->assertBranchMatchStrict($branchId);
            $id = $this->payroll->createRun($branchId, $periodStart, $periodEnd, $notes !== '' ? $notes : null, $uid);
        } catch (\Throwable $e) {
            $errors = ['_general' => $e->getMessage()];
            $run = [
                'branch_id' => $branchId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'notes' => $notes,
            ];
            $branches = $this->getBranches();
            $title = 'Create payroll run';
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/payroll/views/runs/create.php');
            return;
        }
        flash('success', 'Payroll run created.');
        header('Location: /payroll/runs/' . $id);
        exit;
    }

    public function show(int $id): void
    {
        $this->requireView();
        $run = $this->runs->find($id);
        if (!$run) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($run)) {
            return;
        }
        $canManage = $this->canManage();
        $lineRows = $this->lines->listByRunId($id);
        if (!$canManage) {
            $selfStaff = $this->selfStaffId();
            if ($selfStaff === null) {
                Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
                return;
            }
            $lineRows = array_values(array_filter(
                $lineRows,
                static fn (array $r): bool => (int) ($r['staff_id'] ?? 0) === $selfStaff
            ));
        }
        $flash = flash();
        $title = 'Payroll run #' . $id;
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/payroll/views/runs/show.php');
    }

    public function calculate(int $id): void
    {
        $this->requireManage();
        $run = $this->runs->find($id);
        if (!$run) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($run)) {
            return;
        }
        $user = $this->auth->user();
        $uid = $user ? (int) $user['id'] : null;
        try {
            $this->payroll->calculateRun($id, $uid);
            flash('success', 'Run calculated.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /payroll/runs/' . $id);
        exit;
    }

    public function reopen(int $id): void
    {
        $this->requireManage();
        $run = $this->runs->find($id);
        if (!$run) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($run)) {
            return;
        }
        $user = $this->auth->user();
        $uid = $user ? (int) $user['id'] : null;
        try {
            $this->payroll->reopenRunToDraft($id, $uid);
            flash('success', 'Run reopened to draft.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /payroll/runs/' . $id);
        exit;
    }

    public function lock(int $id): void
    {
        $this->requireManage();
        $run = $this->runs->find($id);
        if (!$run) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($run)) {
            return;
        }
        $user = $this->auth->user();
        $uid = $user ? (int) $user['id'] : null;
        try {
            $this->payroll->lockRun($id, $uid);
            flash('success', 'Run locked.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /payroll/runs/' . $id);
        exit;
    }

    public function settle(int $id): void
    {
        $this->requireManage();
        $run = $this->runs->find($id);
        if (!$run) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($run)) {
            return;
        }
        $user = $this->auth->user();
        $uid = $user ? (int) $user['id'] : null;
        try {
            $this->payroll->settleRun($id, $uid);
            flash('success', 'Run marked settled (external payout recorded).');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /payroll/runs/' . $id);
        exit;
    }

    public function destroy(int $id): void
    {
        $this->requireManage();
        $run = $this->runs->find($id);
        if (!$run) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($run)) {
            return;
        }
        try {
            $this->payroll->deleteDraftRun($id);
            flash('success', 'Draft run deleted.');
            header('Location: /payroll/runs');
            exit;
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            header('Location: /payroll/runs/' . $id);
            exit;
        }
    }

    private function requireView(): void
    {
        $user = $this->auth->user();
        if (!$user || !$this->perms->has((int) $user['id'], 'payroll.view')) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            exit;
        }
    }

    private function requireManage(): void
    {
        $user = $this->auth->user();
        if (!$user || !$this->perms->has((int) $user['id'], 'payroll.manage')) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            exit;
        }
    }

    private function canManage(): bool
    {
        $user = $this->auth->user();
        if (!$user) {
            return false;
        }

        return $this->perms->has((int) $user['id'], 'payroll.manage');
    }

    private function selfStaffId(): ?int
    {
        $user = $this->auth->user();
        if (!$user) {
            return null;
        }
        $row = $this->staff->findByUserId((int) $user['id']);

        return $row ? (int) $row['id'] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null
                ? (int) $entity['branch_id']
                : null;
            $this->branchContext->assertBranchMatchStrict($branchId);

            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }
}
