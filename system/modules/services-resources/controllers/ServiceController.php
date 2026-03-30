<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Contracts\StaffListProvider;
use Modules\Sales\Services\VatRateService;
use Modules\ServicesResources\Repositories\ServiceCategoryRepository;
use Modules\ServicesResources\Repositories\ServiceRepository;
use Modules\ServicesResources\Repositories\RoomRepository;
use Modules\ServicesResources\Repositories\EquipmentRepository;
use Modules\ServicesResources\Services\ServiceService;
use Modules\Staff\Repositories\StaffGroupRepository;

final class ServiceController
{
    public function __construct(
        private ServiceRepository $repo,
        private ServiceService $service,
        private ServiceCategoryRepository $categoryRepo,
        private StaffListProvider $staffList,
        private RoomRepository $roomRepo,
        private EquipmentRepository $equipmentRepo,
        private VatRateService $vatRateService,
        private StaffGroupRepository $staffGroupRepo,
        private BranchContext $branchContext
    ) {
    }

    public function index(): void
    {
        $categoryId = isset($_GET['category']) ? (int) $_GET['category'] : null;
        $listBranchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        $services = $this->repo->list($categoryId, $listBranchId);
        $categories = $this->categoryRepo->list($listBranchId);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/services-resources/views/services/index.php');
    }

    public function create(): void
    {
        $scope = $this->listScopeBranchId();
        $categories = $this->categoryRepo->list($scope);
        $staff = $this->staffList->list($scope);
        $rooms = $this->roomRepo->list($scope);
        $equipment = $this->equipmentRepo->list($scope);
        $vatRates = $this->vatRateService->listActive($scope);
        $staffGroups = $this->staffGroupRepo->listAssignableForServiceBranch($this->effectiveBranchForStaffGroupPicker($scope, null));
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $service = ['staff_ids' => [], 'room_ids' => [], 'equipment_ids' => [], 'staff_group_ids' => [], 'description' => null];
        require base_path('modules/services-resources/views/services/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput(false);
        $errors = $this->validate($data, null);
        if (!empty($errors)) {
            $scope = $this->listScopeBranchId();
            $categories = $this->categoryRepo->list($scope);
            $staff = $this->staffList->list($scope);
            $rooms = $this->roomRepo->list($scope);
            $equipment = $this->equipmentRepo->list($scope);
            $staffGroups = $this->staffGroupRepo->listAssignableForServiceBranch($this->effectiveBranchForStaffGroupPicker($scope, $data));
            $vatRates = $this->vatRateService->listActive($scope ?? ($data['branch_id'] ?? null));
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $service = $data;
            require base_path('modules/services-resources/views/services/create.php');
            return;
        }
        try {
            $id = $this->service->create($data);
        } catch (\DomainException $e) {
            $scope = $this->listScopeBranchId();
            $categories = $this->categoryRepo->list($scope);
            $staff = $this->staffList->list($scope);
            $rooms = $this->roomRepo->list($scope);
            $equipment = $this->equipmentRepo->list($scope);
            $staffGroups = $this->staffGroupRepo->listAssignableForServiceBranch($this->effectiveBranchForStaffGroupPicker($scope, $data));
            $vatRates = $this->vatRateService->listActive($scope ?? ($data['branch_id'] ?? null));
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $service = $data;
            $errors = $this->mapServiceDomainExceptionToErrors($e);
            require base_path('modules/services-resources/views/services/create.php');
            return;
        }
        flash('success', 'Service created.');
        header('Location: /services-resources/services/' . $id);
        exit;
    }

    public function show(int $id): void
    {
        $service = $this->repo->find($id);
        if (!$service) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($service)) {
            return;
        }
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/services-resources/views/services/show.php');
    }

    public function edit(int $id): void
    {
        $service = $this->repo->find($id);
        if (!$service) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($service)) {
            return;
        }
        $service['staff_ids'] = $service['staff_ids'] ?? [];
        $service['room_ids'] = $service['room_ids'] ?? [];
        $service['equipment_ids'] = $service['equipment_ids'] ?? [];
        $service['staff_group_ids'] = $service['staff_group_ids'] ?? [];
        $scope = $this->listScopeBranchId();
        $categories = $this->categoryRepo->list($scope);
        $staff = $this->staffList->list($scope);
        $rooms = $this->roomRepo->list($scope);
        $equipment = $this->equipmentRepo->list($scope);
        $staffGroups = $this->staffGroupRepo->listAssignableForServiceBranch($this->entityBranchId($service));
        $vatRates = $this->vatRateService->listActive($scope ?? $this->entityBranchId($service));
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        require base_path('modules/services-resources/views/services/edit.php');
    }

    public function update(int $id): void
    {
        $service = $this->repo->find($id);
        if (!$service) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($service)) {
            return;
        }
        $data = $this->parseInput(true);
        $errors = $this->validate($data, $service);
        if (!empty($errors)) {
            $service = array_merge($service, $data);
            $service['staff_ids'] = $service['staff_ids'] ?? [];
            $service['room_ids'] = $service['room_ids'] ?? [];
            $service['equipment_ids'] = $service['equipment_ids'] ?? [];
            $service['staff_group_ids'] = $service['staff_group_ids'] ?? [];
            $scope = $this->listScopeBranchId();
            $categories = $this->categoryRepo->list($scope);
            $staff = $this->staffList->list($scope);
            $rooms = $this->roomRepo->list($scope);
            $equipment = $this->equipmentRepo->list($scope);
            $staffGroups = $this->staffGroupRepo->listAssignableForServiceBranch($this->entityBranchId($service));
            $vatRates = $this->vatRateService->listActive($scope ?? $this->entityBranchId($service));
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/services-resources/views/services/edit.php');
            return;
        }
        try {
            $this->service->update($id, $data);
        } catch (\DomainException $e) {
            $service = $this->repo->find($id);
            if (!$service) {
                Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
                return;
            }
            $service = array_merge($service, $data);
            $service['staff_ids'] = $service['staff_ids'] ?? [];
            $service['room_ids'] = $service['room_ids'] ?? [];
            $service['equipment_ids'] = $service['equipment_ids'] ?? [];
            $service['staff_group_ids'] = $service['staff_group_ids'] ?? [];
            $scope = $this->listScopeBranchId();
            $categories = $this->categoryRepo->list($scope);
            $staff = $this->staffList->list($scope);
            $rooms = $this->roomRepo->list($scope);
            $equipment = $this->equipmentRepo->list($scope);
            $staffGroups = $this->staffGroupRepo->listAssignableForServiceBranch($this->entityBranchId($service));
            $vatRates = $this->vatRateService->listActive($scope ?? $this->entityBranchId($service));
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $errors = $this->mapServiceDomainExceptionToErrors($e);
            require base_path('modules/services-resources/views/services/edit.php');
            return;
        }
        flash('success', 'Service updated.');
        header('Location: /services-resources/services/' . $id);
        exit;
    }

    public function destroy(int $id): void
    {
        $service = $this->repo->find($id);
        if (!$service) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($service)) {
            return;
        }
        $this->service->delete($id);
        flash('success', 'Service deleted.');
        header('Location: /services-resources/services');
        exit;
    }

    /**
     * Matches services index: null = global (all branches); non-null = branch-scoped option lists.
     */
    private function listScopeBranchId(): ?int
    {
        return $this->branchContext->getCurrentBranchId();
    }

    /**
     * Branch used to list assignable staff groups on create (picker matches post-enforcement branch when possible).
     *
     * @param array<string, mixed>|null $parsedData
     */
    private function effectiveBranchForStaffGroupPicker(?int $scope, ?array $parsedData): ?int
    {
        if ($scope !== null) {
            return $scope;
        }
        if ($parsedData !== null && !empty($parsedData['branch_id'])) {
            return (int) $parsedData['branch_id'];
        }

        return null;
    }

    private function entityBranchId(array $entity): ?int
    {
        return isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null
            ? (int) $entity['branch_id']
            : null;
    }

    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = $this->entityBranchId($entity);
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }

    /**
     * Trim; empty or whitespace-only string becomes null for nullable TEXT persistence.
     */
    private function normalizeDescriptionInput(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $t = trim($raw);

        return $t === '' ? null : $t;
    }

    private function parseInput(bool $forUpdate): array
    {
        $staffIds = $_POST['staff_ids'] ?? [];
        $roomIds = $_POST['room_ids'] ?? [];
        $equipmentIds = $_POST['equipment_ids'] ?? [];
        if (!is_array($staffIds)) $staffIds = array_filter(explode(',', (string) $staffIds));
        if (!is_array($roomIds)) $roomIds = array_filter(explode(',', (string) $roomIds));
        if (!is_array($equipmentIds)) $equipmentIds = array_filter(explode(',', (string) $equipmentIds));
        $data = [
            'category_id' => trim($_POST['category_id'] ?? '') ? (int) $_POST['category_id'] : null,
            'name' => trim($_POST['name'] ?? ''),
            'description' => $this->normalizeDescriptionInput($_POST['description'] ?? null),
            'duration_minutes' => (int) ($_POST['duration_minutes'] ?? 60),
            'buffer_before_minutes' => (int) ($_POST['buffer_before_minutes'] ?? 0),
            'buffer_after_minutes' => (int) ($_POST['buffer_after_minutes'] ?? 0),
            'price' => (float) ($_POST['price'] ?? 0),
            'vat_rate_id' => trim($_POST['vat_rate_id'] ?? '') ? (int) $_POST['vat_rate_id'] : null,
            'is_active' => !empty($_POST['is_active']),
            'branch_id' => trim($_POST['branch_id'] ?? '') ? (int) $_POST['branch_id'] : null,
            'staff_ids' => array_map('intval', (array) $staffIds),
            'room_ids' => array_map('intval', (array) $roomIds),
            'equipment_ids' => array_map('intval', (array) $equipmentIds),
        ];
        if (!$forUpdate || !empty($_POST['staff_group_ids_sync'])) {
            $data['staff_group_ids'] = $_POST['staff_group_ids'] ?? [];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existingService for update: canonical service row (branch for assignment rules)
     */
    private function validate(array $data, ?array $existingService): array
    {
        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'Name is required.';
        }
        if ($data['duration_minutes'] < 1) {
            $errors['duration_minutes'] = 'Duration must be at least 1 minute.';
        }
        if ($data['price'] < 0) {
            $errors['price'] = 'Price cannot be negative.';
        }
        if (isset($data['description']) && $data['description'] !== null && strlen($data['description']) > 65535) {
            $errors['description'] = 'Description cannot exceed 65535 bytes.';
        }
        if (array_key_exists('staff_group_ids', $data)) {
            if (!is_array($data['staff_group_ids'])) {
                $errors['staff_group_ids'] = 'Staff groups must be submitted as an array.';
            } else {
                $branchForGroups = $existingService !== null
                    ? $this->entityBranchId(
                        array_key_exists('branch_id', $data)
                            ? array_merge($existingService, ['branch_id' => $data['branch_id']])
                            : $existingService
                    )
                    : ($this->branchContext->getCurrentBranchId() ?? (!empty($data['branch_id']) ? (int) $data['branch_id'] : null));
                $msg = $this->service->validateStaffGroupIdsForService($branchForGroups, $data['staff_group_ids']);
                if ($msg !== null) {
                    $errors['staff_group_ids'] = $msg;
                }
            }
        }

        return $errors;
    }

    /**
     * After validate() passed, service-layer DomainException may still fire (defense in depth). Map staff-group
     * messages to the checkbox field; everything else to _general (e.g. branch enforcement).
     *
     * @return array<string, string>
     */
    private function mapServiceDomainExceptionToErrors(\DomainException $e): array
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'staff group') !== false) {
            return ['staff_group_ids' => $msg];
        }
        if (stripos($msg, 'vat rate') !== false) {
            return ['vat_rate_id' => $msg];
        }

        return ['_general' => $msg];
    }
}
