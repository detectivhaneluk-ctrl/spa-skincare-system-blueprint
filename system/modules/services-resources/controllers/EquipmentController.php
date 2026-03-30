<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Controllers;

use Core\App\Application;
use Modules\ServicesResources\Repositories\EquipmentRepository;
use Modules\ServicesResources\Services\EquipmentService;

final class EquipmentController
{
    public function __construct(
        private EquipmentRepository $repo,
        private EquipmentService $service
    ) {
    }

    public function index(): void
    {
        $listBranchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        $equipment = $this->repo->list($listBranchId);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/services-resources/views/equipment/index.php');
    }

    public function create(): void
    {
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $item = [];
        require base_path('modules/services-resources/views/equipment/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $item = $data;
            require base_path('modules/services-resources/views/equipment/create.php');
            return;
        }
        $id = $this->service->create($data);
        flash('success', 'Equipment created.');
        header('Location: /services-resources/equipment/' . $id);
        exit;
    }

    public function show(int $id): void
    {
        $item = $this->repo->find($id);
        if (!$item) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($item)) {
            return;
        }
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/services-resources/views/equipment/show.php');
    }

    public function edit(int $id): void
    {
        $item = $this->repo->find($id);
        if (!$item) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($item)) {
            return;
        }
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        require base_path('modules/services-resources/views/equipment/edit.php');
    }

    public function update(int $id): void
    {
        $item = $this->repo->find($id);
        if (!$item) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($item)) {
            return;
        }
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $item = array_merge($item, $data);
            require base_path('modules/services-resources/views/equipment/edit.php');
            return;
        }
        $this->service->update($id, $data);
        flash('success', 'Equipment updated.');
        header('Location: /services-resources/equipment/' . $id);
        exit;
    }

    public function destroy(int $id): void
    {
        $item = $this->repo->find($id);
        if (!$item) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($item)) {
            return;
        }
        $this->service->delete($id);
        flash('success', 'Equipment deleted.');
        header('Location: /services-resources/equipment');
        exit;
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

    private function parseInput(): array
    {
        return [
            'name' => trim($_POST['name'] ?? ''),
            'code' => trim($_POST['code'] ?? '') ?: null,
            'serial_number' => trim($_POST['serial_number'] ?? '') ?: null,
            'is_active' => !empty($_POST['is_active']),
            'maintenance_mode' => !empty($_POST['maintenance_mode']),
            'branch_id' => trim($_POST['branch_id'] ?? '') ? (int) $_POST['branch_id'] : null,
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'Name is required.';
        }
        return $errors;
    }
}
