<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Controllers;

use Core\App\Application;
use Modules\ServicesResources\Repositories\ServiceCategoryRepository;
use Modules\ServicesResources\Services\ServiceCategoryService;

final class ServiceCategoryController
{
    public function __construct(
        private ServiceCategoryRepository $repo,
        private ServiceCategoryService $service
    ) {
    }

    public function index(): void
    {
        $listBranchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        $categories = $this->repo->list($listBranchId);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/services-resources/views/categories/index.php');
    }

    public function create(): void
    {
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $category = [];
        require base_path('modules/services-resources/views/categories/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $category = $data;
            require base_path('modules/services-resources/views/categories/create.php');
            return;
        }
        $id = $this->service->create($data);
        flash('success', 'Service category created.');
        header('Location: /services-resources/categories/' . $id);
        exit;
    }

    public function show(int $id): void
    {
        $category = $this->repo->find($id);
        if (!$category) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($category)) {
            return;
        }
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/services-resources/views/categories/show.php');
    }

    public function edit(int $id): void
    {
        $category = $this->repo->find($id);
        if (!$category) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($category)) {
            return;
        }
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        require base_path('modules/services-resources/views/categories/edit.php');
    }

    public function update(int $id): void
    {
        $category = $this->repo->find($id);
        if (!$category) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($category)) {
            return;
        }
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $category = array_merge($category, $data);
            require base_path('modules/services-resources/views/categories/edit.php');
            return;
        }
        $this->service->update($id, $data);
        flash('success', 'Service category updated.');
        header('Location: /services-resources/categories/' . $id);
        exit;
    }

    public function destroy(int $id): void
    {
        $category = $this->repo->find($id);
        if (!$category) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($category)) {
            return;
        }
        $this->service->delete($id);
        flash('success', 'Service category deleted.');
        header('Location: /services-resources/categories');
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
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'branch_id' => trim($_POST['branch_id'] ?? '') ? (int) $_POST['branch_id'] : null,
        ];
        if (array_key_exists('parent_id', $_POST)) {
            $raw = trim((string) ($_POST['parent_id'] ?? ''));
            $data['parent_id'] = $raw === '' ? null : (int) $raw;
        }

        return $data;
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
