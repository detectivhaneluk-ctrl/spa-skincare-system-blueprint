<?php

declare(strict_types=1);

namespace Modules\Staff\Controllers;

use Core\App\Application;
use Modules\Staff\Repositories\StaffBreakRepository;
use Modules\Staff\Repositories\StaffRepository;
use Modules\Staff\Repositories\StaffScheduleRepository;
use Modules\Staff\Services\StaffBreakService;
use Modules\Staff\Services\StaffScheduleService;
use Modules\Staff\Services\StaffService;

final class StaffController
{
    public function __construct(
        private StaffRepository $repo,
        private StaffService $service,
        private StaffScheduleRepository $scheduleRepo,
        private StaffBreakRepository $breakRepo,
        private StaffScheduleService $scheduleService,
        private StaffBreakService $breakService
    ) {
    }

    public function index(): void
    {
        $activeOnly = isset($_GET['active']) ? (bool) $_GET['active'] : true;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $filters = $activeOnly ? ['active' => true] : [];
        $branchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        // If BranchContext is unset (e.g. ordering/session edge) but the user row is branch-scoped, still filter the list.
        if ($branchId === null) {
            $user = Application::container()->get(\Core\Auth\SessionAuth::class)->user();
            if ($user !== null && array_key_exists('branch_id', $user)) {
                $ub = $user['branch_id'];
                if ($ub !== null && $ub !== '') {
                    $branchId = (int) $ub;
                }
            }
        }
        if ($branchId !== null) {
            $filters['branch_id'] = $branchId;
        }
        $staff = $this->repo->list($filters, $perPage, ($page - 1) * $perPage);
        $total = $this->repo->count($filters);
        foreach ($staff as &$s) {
            $s['display_name'] = $this->service->getDisplayName($s);
        }
        unset($s);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/staff/views/index.php');
    }

    public function create(): void
    {
        $users = Application::container()->get(\Core\App\Database::class)->fetchAll('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name');
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $staff = [];
        require base_path('modules/staff/views/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $users = Application::container()->get(\Core\App\Database::class)->fetchAll('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name');
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $staff = $data;
            require base_path('modules/staff/views/create.php');
            return;
        }
        $id = $this->service->create($data);
        flash('success', 'Staff member created.');
        header('Location: /staff/' . $id);
        exit;
    }

    public function show(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $staff['display_name'] = $this->service->getDisplayName($staff);
        $schedules = $this->scheduleRepo->listByStaff($id);
        $breaks = $this->breakRepo->listByStaff($id);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/staff/views/show.php');
    }

    public function edit(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $users = Application::container()->get(\Core\App\Database::class)->fetchAll('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name');
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        require base_path('modules/staff/views/edit.php');
    }

    public function update(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $users = Application::container()->get(\Core\App\Database::class)->fetchAll('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name');
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $staff = array_merge($staff, $data);
            require base_path('modules/staff/views/edit.php');
            return;
        }
        $this->service->update($id, $data);
        flash('success', 'Staff member updated.');
        header('Location: /staff/' . $id);
        exit;
    }

    public function destroy(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $this->service->delete($id);
        flash('success', 'Staff member deleted.');
        header('Location: /staff');
        exit;
    }

    public function scheduleStore(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $data = [
            'day_of_week' => (int) ($_POST['day_of_week'] ?? 0),
            'start_time' => trim((string) ($_POST['start_time'] ?? '')),
            'end_time' => trim((string) ($_POST['end_time'] ?? '')),
        ];
        try {
            $this->scheduleService->create($id, $data);
            flash('success', 'Schedule entry added.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /staff/' . $id);
        exit;
    }

    public function scheduleDelete(int $id, int $scheduleId): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        try {
            $this->scheduleService->delete($scheduleId);
            flash('success', 'Schedule entry removed.');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /staff/' . $id);
        exit;
    }

    public function breakStore(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $data = [
            'day_of_week' => (int) ($_POST['day_of_week'] ?? 0),
            'start_time' => trim((string) ($_POST['start_time'] ?? '')),
            'end_time' => trim((string) ($_POST['end_time'] ?? '')),
            'title' => trim((string) ($_POST['title'] ?? '')),
        ];
        try {
            $this->breakService->create($id, $data);
            flash('success', 'Break entry added.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /staff/' . $id);
        exit;
    }

    public function breakDelete(int $id, int $breakId): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        try {
            $this->breakService->delete($breakId);
            flash('success', 'Break entry removed.');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /staff/' . $id);
        exit;
    }

    private function parseInput(): array
    {
        $uid = trim($_POST['user_id'] ?? '');
        return [
            'user_id' => $uid !== '' ? (int) $uid : null,
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'phone' => trim($_POST['phone'] ?? '') ?: null,
            'email' => trim($_POST['email'] ?? '') ?: null,
            'job_title' => trim($_POST['job_title'] ?? '') ?: null,
            'is_active' => !empty($_POST['is_active']),
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if ($data['first_name'] === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($data['last_name'] === '') {
            $errors['last_name'] = 'Last name is required.';
        }
        return $errors;
    }

    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null ? (int) $entity['branch_id'] : null;
            Application::container()->get(\Core\Branch\BranchContext::class)->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }
}
