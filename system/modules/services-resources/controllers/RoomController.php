<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Controllers;

use Core\App\Application;
use Modules\ServicesResources\Repositories\RoomRepository;
use Modules\ServicesResources\Services\RoomService;

final class RoomController
{
    public function __construct(
        private RoomRepository $repo,
        private RoomService $service
    ) {
    }

    public function index(): void
    {
        $listBranchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        $rooms = $this->repo->list($listBranchId);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/services-resources/views/rooms/index.php');
    }

    public function create(): void
    {
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $room = [];
        require base_path('modules/services-resources/views/rooms/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $room = $data;
            require base_path('modules/services-resources/views/rooms/create.php');
            return;
        }
        $id = $this->service->create($data);
        flash('success', 'Room created.');
        header('Location: /services-resources/rooms/' . $id);
        exit;
    }

    public function show(int $id): void
    {
        $room = $this->repo->find($id);
        if (!$room) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($room)) {
            return;
        }
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/services-resources/views/rooms/show.php');
    }

    public function edit(int $id): void
    {
        $room = $this->repo->find($id);
        if (!$room) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($room)) {
            return;
        }
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        require base_path('modules/services-resources/views/rooms/edit.php');
    }

    public function update(int $id): void
    {
        $room = $this->repo->find($id);
        if (!$room) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($room)) {
            return;
        }
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $room = array_merge($room, $data);
            require base_path('modules/services-resources/views/rooms/edit.php');
            return;
        }
        $this->service->update($id, $data);
        flash('success', 'Room updated.');
        header('Location: /services-resources/rooms/' . $id);
        exit;
    }

    public function destroy(int $id): void
    {
        $room = $this->repo->find($id);
        if (!$room) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($room)) {
            return;
        }
        $this->service->delete($id);
        flash('success', 'Room deleted.');
        header('Location: /services-resources/rooms');
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
