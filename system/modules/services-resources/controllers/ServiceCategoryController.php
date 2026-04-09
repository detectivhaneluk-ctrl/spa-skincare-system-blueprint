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
        $rawCategories = $this->repo->listWithCounts($listBranchId);
        $treeRows = $this->repo->buildTreeFlat($rawCategories);
        // Merge counts back into treeRows (buildTreeFlat returns enriched rows preserving extra keys)
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        $totalCount = count($treeRows);
        // For the create/edit panel we need the tree without counts to avoid confusion
        $panelTreeRows = $treeRows; // same rows, used for parent picker
        $editCategory = null; // no category being edited initially
        $editErrors = [];
        $panelMode = 'create'; // 'create' or 'edit'
        // Check if we should open the edit panel for a specific category (from query param)
        $editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
        if ($editId) {
            $editCategory = $this->repo->find($editId);
            if ($editCategory && $this->checkBranchAccess($editCategory)) {
                $byId = array_column($rawCategories, null, 'id');
                $excludeIds = array_merge([(int) $editId], $this->repo->descendantIds((int) $editId, $byId));
                $panelTreeRows = array_values(array_filter(
                    $treeRows,
                    fn ($r) => !in_array((int) $r['id'], $excludeIds, true)
                ));
                $panelMode = 'edit';
            }
        }
        // Pre-seed parent_id for "add child" flow
        $preParentId = isset($_GET['parent_id']) && (int) $_GET['parent_id'] > 0 ? (int) $_GET['parent_id'] : null;
        if ($this->isDrawerRequest()) {
            require base_path('modules/services-resources/views/categories/drawer/category-panel.php');
            return;
        }
        require base_path('modules/services-resources/views/categories/index.php');
    }

    public function create(): void
    {
        $listBranchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        $allCategories = $this->repo->list($listBranchId);
        $treeRows = $this->repo->buildTreeFlat($allCategories);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $category = [];
        // Allow pre-seeding parent_id from query string (e.g. "Add child" button)
        $preParentId = isset($_GET['parent_id']) && (int) $_GET['parent_id'] > 0 ? (int) $_GET['parent_id'] : null;
        require base_path('modules/services-resources/views/categories/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (empty($errors)) {
            try {
                $this->service->create($data);
                if ($this->isDrawerRequest()) {
                    $this->sendDrawerJsonSuccess('Category created.', ['reload_host' => true]);
                }
                flash('success', 'Category created.');
                header('Location: /services-resources/categories');
                exit;
            } catch (\InvalidArgumentException|\DomainException $e) {
                $errors['parent_id'] = $e->getMessage();
            }
        }
        // Re-render index with panel errors
        $listBranchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        $rawCategories = $this->repo->listWithCounts($listBranchId);
        $treeRows = $this->repo->buildTreeFlat($rawCategories);
        $panelTreeRows = $treeRows;
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = null;
        $totalCount = count($treeRows);
        $editCategory = null;
        $editErrors = $errors;
        $panelMode = 'create';
        $preParentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        // Preserve submitted values
        $editCategory = null;
        $panelFormData = $data;
        if ($this->isDrawerRequest()) {
            $this->sendDrawerValidationHtml($this->captureCategoryDrawerPanelHtml());
        }
        require base_path('modules/services-resources/views/categories/index.php');
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
        $listBranchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        $allCategories = $this->repo->list($listBranchId);
        $byId = array_column($allCategories, null, 'id');
        $ancestorChain = $this->repo->ancestorChain((int) $category['id'], $byId);
        $directChildren = array_values(array_filter($allCategories, fn ($c) => (int) ($c['parent_id'] ?? 0) === (int) $category['id']));
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
        $listBranchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        $allCategories = $this->repo->list($listBranchId);
        $byId = array_column($allCategories, null, 'id');
        $excludeIds = array_merge([(int) $id], $this->repo->descendantIds((int) $id, $byId));
        $treeRows = array_values(array_filter(
            $this->repo->buildTreeFlat($allCategories),
            fn ($r) => !in_array((int) $r['id'], $excludeIds, true)
        ));
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
        if (empty($errors)) {
            try {
                $this->service->update($id, $data);
                if ($this->isDrawerRequest()) {
                    $this->sendDrawerJsonSuccess('Category updated.', ['reload_host' => true]);
                }
                flash('success', 'Category updated.');
                header('Location: /services-resources/categories');
                exit;
            } catch (\InvalidArgumentException|\DomainException $e) {
                $errors['parent_id'] = $e->getMessage();
            }
        }
        // Re-render index with edit panel + errors
        $listBranchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        $rawCategories = $this->repo->listWithCounts($listBranchId);
        $byId = array_column($rawCategories, null, 'id');
        $excludeIds = array_merge([(int) $id], $this->repo->descendantIds((int) $id, $byId));
        $treeRows = $this->repo->buildTreeFlat($rawCategories);
        $panelTreeRows = array_values(array_filter(
            $treeRows,
            fn ($r) => !in_array((int) $r['id'], $excludeIds, true)
        ));
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = null;
        $totalCount = count($treeRows);
        $editCategory = array_merge($category, $data);
        $editErrors = $errors;
        $panelMode = 'edit';
        $preParentId = null;
        $panelFormData = null;
        if ($this->isDrawerRequest()) {
            $this->sendDrawerValidationHtml($this->captureCategoryDrawerPanelHtml());
        }
        require base_path('modules/services-resources/views/categories/index.php');
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

        // Fail-closed: block delete when children or assigned services exist
        $blockReason = $this->repo->getDeleteBlockReason($id);
        if ($blockReason !== null) {
            flash('error', $blockReason);
            header('Location: /services-resources/categories');
            exit;
        }

        $this->service->delete($id);
        flash('success', 'Category deleted.');
        header('Location: /services-resources/categories');
        exit;
    }

    /**
     * AJAX: update sort_order for a single category (called from sibling reorder UI).
     * Expects POST fields: id, sort_order — responds JSON.
     */
    public function reorder(): void
    {
        header('Content-Type: application/json');
        $id = (int) ($_POST['id'] ?? 0);
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid id']);
            exit;
        }
        $category = $this->repo->find($id);
        if (!$category || !$this->checkBranchAccess($category)) {
            echo json_encode(['ok' => false, 'error' => 'Not found or access denied']);
            exit;
        }
        $this->repo->updateSortOrder($id, $sortOrder);
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * JSON: reparent one category (map drag-reconnect). Body JSON: { "id": int, "parent_id": int|null }.
     * CSRF: header X-CSRF-TOKEN or form field (same as other POST routes).
     */
    public function reparent(): void
    {
        @ini_set('display_errors', '0');
        header('Content-Type: application/json; charset=UTF-8');

        $raw = file_get_contents('php://input');
        $body = json_decode($raw !== false && $raw !== '' ? $raw : '{}', true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
            exit;
        }

        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid category id']);
            exit;
        }

        $category = $this->repo->find($id);
        if (!$category || !$this->checkBranchAccess($category)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Not found or access denied']);
            exit;
        }

        $parentRaw = $body['parent_id'] ?? null;
        $parentId = null;
        if ($parentRaw !== null && $parentRaw !== '') {
            $parentId = (int) $parentRaw;
            if ($parentId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Invalid parent_id']);
                exit;
            }
        }

        if ($parentId === $id) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Category cannot be its own parent']);
            exit;
        }

        $data = [
            'name' => trim((string) ($category['name'] ?? '')),
            'sort_order' => (int) ($category['sort_order'] ?? 0),
            'branch_id' => isset($category['branch_id']) && $category['branch_id'] !== '' && $category['branch_id'] !== null
                ? (int) $category['branch_id']
                : null,
            'parent_id' => $parentId,
        ];
        if ($data['name'] === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Category name missing']);
            exit;
        }

        try {
            $this->service->update($id, $data);
        } catch (\InvalidArgumentException|\DomainException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        } catch (\Throwable) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Update failed']);
            exit;
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    private function ensureBranchAccess(array $entity): bool
    {
        if (!$this->checkBranchAccess($entity)) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
        return true;
    }

    private function checkBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null ? (int) $entity['branch_id'] : null;
            Application::container()->get(\Core\Branch\BranchContext::class)->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
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

    private function isDrawerRequest(): bool
    {
        return (string) ($_GET['drawer'] ?? '') === '1'
            || (string) ($_SERVER['HTTP_X_APP_DRAWER'] ?? '') === '1';
    }

    /**
     * @param array<string, mixed> $extra merged into payload data (e.g. reload_host)
     */
    private function sendDrawerJsonSuccess(string $message, array $extra = []): void
    {
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'data' => array_merge(['message' => $message], $extra),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function sendDrawerValidationHtml(string $html): void
    {
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => ['message' => 'Please correct the errors below.'],
            'data' => ['html' => $html],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function captureCategoryDrawerPanelHtml(): string
    {
        ob_start();
        require base_path('modules/services-resources/views/categories/drawer/category-panel.php');

        return ob_get_clean();
    }
}
