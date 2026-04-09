<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Contracts\StaffListProvider;
use Modules\Inventory\Repositories\ProductRepository;
use Modules\Sales\Services\VatRateService;
use Modules\ServicesResources\Repositories\RoomRepository;
use Modules\ServicesResources\Repositories\ServiceCategoryRepository;
use Modules\ServicesResources\Repositories\ServiceRepository;
use Modules\ServicesResources\Services\ServiceService;

final class ServiceController
{
    public function __construct(
        private ServiceRepository $repo,
        private ServiceService $service,
        private ServiceCategoryRepository $categoryRepo,
        private VatRateService $vatRateService,
        private BranchContext $branchContext,
        private StaffListProvider $staffListProvider,
        private RoomRepository $roomRepo,
        private ProductRepository $productRepo,
    ) {
    }

    public function index(): void
    {
        $categoryId = isset($_GET['category']) && $_GET['category'] !== '' ? (int) $_GET['category'] : null;
        $listBranchId = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        $trashView = isset($_GET['status']) && $_GET['status'] === 'trash';
        $services = $this->repo->list($categoryId, $listBranchId, $trashView);
        $categories = $this->categoryRepo->list($listBranchId);
        $serviceMapCategories = $this->categoryRepo->listWithCounts($listBranchId);
        $countActive = $this->repo->count($listBranchId, false);
        $countTrash = $this->repo->count($listBranchId, true);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/services-resources/views/services/index.php');
    }

    public function create(): void
    {
        $scope = $this->listScopeBranchId();
        $categories = $this->categoryRepo->list($scope);
        $catTreeRows = $this->loadCatTreeRows($scope);
        $vatRates = $this->vatRateService->listActive($scope);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $service = [
            'service_type'               => 'service',
            'description'                => null,
            'sku'                        => null,
            'barcode'                    => null,
            'processing_time_required'   => 0,
            'add_on'                     => 0,
            'requires_two_staff_members' => 0,
            'applies_to_employee'        => 1,
            'applies_to_room'            => 1,
            'requires_equipment'         => 0,
            'show_in_online_menu'        => 0,
            'staff_fee_mode'             => 'none',
            'staff_fee_value'            => null,
            'allow_on_gift_voucher_sale' => 0,
            'billing_code'               => null,
        ];
        if ($this->isDrawerRequest()) {
            $isCreate = true;
            $formAction = '/services-resources/services';
            $drawerTitle = 'New service';
            $drawerSubtitle = 'Basics — step 1 of 4';
            require base_path('modules/services-resources/views/services/drawer/service_step1.php');
            return;
        }
        require base_path('modules/services-resources/views/services/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput(true);
        $errors = $this->validate($data, null);
        if (!empty($errors)) {
            $scope = $this->listScopeBranchId();
            $categories = $this->categoryRepo->list($scope);
            $catTreeRows = $this->loadCatTreeRows($scope);
            $vatRates = $this->vatRateService->listActive($scope ?? ($data['branch_id'] ?? null));
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $service = $data;
            if ($this->isDrawerRequest()) {
                $isCreate = true;
                $formAction = '/services-resources/services';
                $drawerTitle = 'New service';
                $drawerSubtitle = 'Basics — step 1 of 4';
                $this->sendDrawerValidationHtml($this->captureServiceDrawerStep1Html());
            }
            require base_path('modules/services-resources/views/services/create.php');
            return;
        }
        try {
            $id = $this->service->create($data);
        } catch (\DomainException $e) {
            $scope = $this->listScopeBranchId();
            $categories = $this->categoryRepo->list($scope);
            $catTreeRows = $this->loadCatTreeRows($scope);
            $vatRates = $this->vatRateService->listActive($scope ?? ($data['branch_id'] ?? null));
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $service = $data;
            $errors = $this->mapServiceDomainExceptionToErrors($e);
            if ($this->isDrawerRequest()) {
                $isCreate = true;
                $formAction = '/services-resources/services';
                $drawerTitle = 'New service';
                $drawerSubtitle = 'Basics — step 1 of 4';
                $this->sendDrawerValidationHtml($this->captureServiceDrawerStep1Html());
            }
            require base_path('modules/services-resources/views/services/create.php');
            return;
        }
        if ($this->isDrawerRequest()) {
            $this->sendDrawerJsonSuccess(
                'Service created. The map will refresh — open the service from the list to continue setup (steps 2–4).',
                ['reload_host' => true]
            );
        }
        flash('success', 'Service created. Now select products used by this service.');
        header('Location: /services-resources/services/' . $id . '/step-2');
        exit;
    }

    public function show(int $id): void
    {
        $service = $this->repo->find($id);
        $serviceIsTrashed = false;
        if (!$service) {
            $service = $this->repo->findTrashed($id);
            $serviceIsTrashed = $service !== null;
        }
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
        $scope = $this->listScopeBranchId();
        $categories = $this->categoryRepo->list($scope);
        $catTreeRows = $this->loadCatTreeRows($scope);
        $vatRates = $this->vatRateService->listActive($scope ?? $this->entityBranchId($service));
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        if ($this->isDrawerRequest()) {
            $isCreate = false;
            $formAction = '/services-resources/services/' . (int) $service['id'];
            $drawerTitle = 'Edit service';
            $drawerSubtitle = 'Basics — step 1 of 4';
            require base_path('modules/services-resources/views/services/drawer/service_step1.php');
            return;
        }
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
        $data = $this->parseInput(false);
        $errors = $this->validate($data, $service);
        if (!empty($errors)) {
            $service = array_merge($service, $data);
            $scope = $this->listScopeBranchId();
            $categories = $this->categoryRepo->list($scope);
            $catTreeRows = $this->loadCatTreeRows($scope);
            $vatRates = $this->vatRateService->listActive($scope ?? $this->entityBranchId($service));
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            if ($this->isDrawerRequest()) {
                $isCreate = false;
                $formAction = '/services-resources/services/' . (int) $id;
                $drawerTitle = 'Edit service';
                $drawerSubtitle = 'Basics — step 1 of 4';
                $this->sendDrawerValidationHtml($this->captureServiceDrawerStep1Html());
            }
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
            $scope = $this->listScopeBranchId();
            $categories = $this->categoryRepo->list($scope);
            $catTreeRows = $this->loadCatTreeRows($scope);
            $vatRates = $this->vatRateService->listActive($scope ?? $this->entityBranchId($service));
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $errors = $this->mapServiceDomainExceptionToErrors($e);
            if ($this->isDrawerRequest()) {
                $isCreate = false;
                $formAction = '/services-resources/services/' . (int) $id;
                $drawerTitle = 'Edit service';
                $drawerSubtitle = 'Basics — step 1 of 4';
                $this->sendDrawerValidationHtml($this->captureServiceDrawerStep1Html());
            }
            require base_path('modules/services-resources/views/services/edit.php');
            return;
        }
        if ($this->isDrawerRequest()) {
            $this->sendDrawerJsonSuccess('Service definition updated.', ['reload_host' => true]);
        }
        flash('success', 'Service definition updated.');
        header('Location: /services-resources/services/' . $id . '/step-2');
        exit;
    }

    // -------------------------------------------------------------------------
    // STEP 2 — Products / consumables
    // -------------------------------------------------------------------------

    public function editStep2(int $id): void
    {
        $service = $this->repo->find($id);
        if (!$service) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($service)) {
            return;
        }
        $branchId = $this->listScopeBranchId();
        $products = $this->loadAvailableProducts($branchId);
        $selectedRows = $service['product_rows'] ?? [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        require base_path('modules/services-resources/views/services/step2.php');
    }

    public function updateStep2(int $id): void
    {
        $service = $this->repo->find($id);
        if (!$service) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($service)) {
            return;
        }

        $branchId = $this->listScopeBranchId();
        $products = $this->loadAvailableProducts($branchId);
        $validProductIds = array_flip(array_column($products, 'id'));

        $rawIds = $_POST['product_ids'] ?? [];
        $rawQtys = $_POST['quantity_used'] ?? [];
        $rawCosts = $_POST['unit_cost_snapshot'] ?? [];
        $errors = [];
        $rows = [];

        if (is_array($rawIds)) {
            foreach ($rawIds as $idx => $rawId) {
                $productId = (int) $rawId;
                if ($productId <= 0) {
                    continue;
                }
                if (!isset($validProductIds[$productId])) {
                    $errors['products'] = 'One or more submitted product IDs are not valid for this branch.';
                    break;
                }
                $qty = isset($rawQtys[$idx]) ? (float) $rawQtys[$idx] : 1.0;
                if ($qty <= 0) {
                    $errors['products'] = 'Quantity used must be greater than zero.';
                    break;
                }
                $costRaw = $rawCosts[$idx] ?? null;
                $snapshot = ($costRaw !== null && $costRaw !== '') ? (float) $costRaw : null;
                $rows[] = ['product_id' => $productId, 'quantity_used' => $qty, 'unit_cost_snapshot' => $snapshot];
            }
        }

        if (!empty($errors)) {
            $selectedRows = $service['product_rows'] ?? [];
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/services-resources/views/services/step2.php');
            return;
        }

        $this->repo->syncProducts($id, $rows);
        flash('success', 'Products updated.');
        header('Location: /services-resources/services/' . $id . '/step-3');
        exit;
    }

    // -------------------------------------------------------------------------
    // STEP 3 — Employees
    // -------------------------------------------------------------------------

    public function editStep3(int $id): void
    {
        $service = $this->repo->find($id);
        if (!$service) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($service)) {
            return;
        }
        $branchId = $this->listScopeBranchId();
        $staffList = $this->staffListProvider->list($branchId);
        $assignedIds = array_map('intval', $service['staff_ids'] ?? []);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        require base_path('modules/services-resources/views/services/step3.php');
    }

    public function updateStep3(int $id): void
    {
        $service = $this->repo->find($id);
        if (!$service) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($service)) {
            return;
        }

        $branchId = $this->listScopeBranchId();
        $staffList = $this->staffListProvider->list($branchId);
        $validStaffIds = array_flip(array_column($staffList, 'id'));

        $rawIds = $_POST['staff_ids'] ?? [];
        $errors = [];
        $safeIds = [];

        if (is_array($rawIds)) {
            foreach ($rawIds as $rawId) {
                $staffId = (int) $rawId;
                if ($staffId <= 0) continue;
                if (!isset($validStaffIds[$staffId])) {
                    $errors['staff'] = 'One or more submitted employee IDs are not valid for this branch.';
                    break;
                }
                $safeIds[] = $staffId;
            }
        }

        if (!empty($errors)) {
            $assignedIds = array_map('intval', $service['staff_ids'] ?? []);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/services-resources/views/services/step3.php');
            return;
        }

        // Only sync staff_ids — pass null for all other pivots so they are untouched.
        $this->repo->update($id, ['staff_ids' => $safeIds]);
        flash('success', 'Employees updated.');
        header('Location: /services-resources/services/' . $id . '/step-4');
        exit;
    }

    // -------------------------------------------------------------------------
    // STEP 4 — Rooms
    // -------------------------------------------------------------------------

    public function editStep4(int $id): void
    {
        $service = $this->repo->find($id);
        if (!$service) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($service)) {
            return;
        }
        $branchId = $this->listScopeBranchId();
        $rooms = $this->roomRepo->list($branchId);
        $assignedRoomIds = array_map('intval', $service['room_ids'] ?? []);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        require base_path('modules/services-resources/views/services/step4.php');
    }

    public function updateStep4(int $id): void
    {
        $service = $this->repo->find($id);
        if (!$service) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($service)) {
            return;
        }

        $branchId = $this->listScopeBranchId();
        $rooms = $this->roomRepo->list($branchId);
        $validRoomIds = array_flip(array_column($rooms, 'id'));

        $rawIds = $_POST['room_ids'] ?? [];
        $errors = [];
        $safeIds = [];

        if (is_array($rawIds)) {
            foreach ($rawIds as $rawId) {
                $roomId = (int) $rawId;
                if ($roomId <= 0) continue;
                if (!isset($validRoomIds[$roomId])) {
                    $errors['rooms'] = 'One or more submitted room IDs are not valid for this branch.';
                    break;
                }
                $safeIds[] = $roomId;
            }
        }

        if (!empty($errors)) {
            $assignedRoomIds = array_map('intval', $service['room_ids'] ?? []);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/services-resources/views/services/step4.php');
            return;
        }

        // Only sync room_ids — pass null for all other pivots so they are untouched.
        $this->repo->update($id, ['room_ids' => $safeIds]);
        flash('success', 'Service setup complete.');
        header('Location: /services-resources/services/' . $id);
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Load the available products for the current branch using the canonical
     * tenant-safe read. Falls back to empty when no branch is resolved.
     *
     * @return list<array<string,mixed>>
     */
    private function loadAvailableProducts(?int $branchId): array
    {
        if ($branchId === null || $branchId <= 0) {
            return [];
        }
        return $this->productRepo->listActiveForUnifiedCatalogInResolvedOrg($branchId);
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
        try {
            $this->service->delete($id);
        } catch (\DomainException $e) {
            if ($this->isDrawerRequest()) {
                @ini_set('display_errors', '0');
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                http_response_code(422);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error' => ['message' => $e->getMessage()],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            flash('error', $e->getMessage());
            header('Location: /services-resources/services');
            exit;
        }
        if ($this->isDrawerRequest()) {
            $this->sendDrawerJsonSuccess('Service moved to Trash.', ['reload_host' => true]);
        }
        flash('success', 'Service moved to Trash.');
        header('Location: /services-resources/services');
        exit;
    }

    public function bulkTrash(): void
    {
        $ids = $this->parsePostedServiceIds();
        if ($ids === []) {
            flash('error', 'No services selected.');
            $this->redirectToServicesIndexPostContext();
            return;
        }
        try {
            $n = $this->service->bulkTrash($ids);
        } catch (\DomainException | \RuntimeException $e) {
            flash('error', $e->getMessage());
            $this->redirectToServicesIndexPostContext();
            return;
        }
        if ($n === 0) {
            flash('error', 'No matching services could be moved to Trash.');
        } else {
            flash('success', $n === 1 ? '1 service moved to Trash.' : "{$n} services moved to Trash.");
        }
        $this->redirectToServicesIndexPostContext();
    }

    public function bulkRestore(): void
    {
        $ids = $this->parsePostedServiceIds();
        if ($ids === []) {
            flash('error', 'No services selected.');
            $this->redirectToServicesIndexPostContext();
            return;
        }
        try {
            $n = $this->service->bulkRestore($ids);
        } catch (\DomainException | \RuntimeException $e) {
            flash('error', $e->getMessage());
            $this->redirectToServicesIndexPostContext();
            return;
        }
        if ($n === 0) {
            flash('error', 'No services could be restored. They may be gone or conflict with another service SKU.');
        } else {
            flash('success', $n === 1 ? '1 service restored.' : "{$n} services restored.");
        }
        $this->redirectToServicesIndexPostContext();
    }

    public function bulkPermanentDelete(): void
    {
        $ids = $this->parsePostedServiceIds();
        if ($ids === []) {
            flash('error', 'No services selected.');
            $this->redirectToServicesIndexPostContext();
            return;
        }
        try {
            $out = $this->service->bulkPermanentlyDelete($ids);
        } catch (\Throwable $e) {
            if (function_exists('slog')) {
                \slog('error', 'services.bulk_permanent_delete', $e->getMessage(), []);
            }
            flash('error', 'Could not complete bulk permanent delete. Try again or contact support if this continues.');
            $this->redirectToServicesIndexPostContext();
            return;
        }
        $deleted = $out['deleted'];
        $blocked = $out['blocked'];
        if ($deleted === 0 && $blocked === []) {
            flash('error', 'No services could be permanently deleted (nothing matched your selection).');
        } elseif ($deleted === 0) {
            flash('error', $this->formatBulkPermanentAllBlockedSummary($blocked));
        } elseif ($blocked === []) {
            flash('success', $deleted === 1 ? '1 service permanently deleted.' : "{$deleted} services permanently deleted.");
        } else {
            flash('warning', $this->formatBulkPermanentPartialSummary($deleted, $blocked));
        }
        $this->redirectToServicesIndexPostContext();
    }

    public function restore(int $id): void
    {
        $row = $this->repo->findTrashed($id);
        if (!$row) {
            flash('error', 'That trashed service was not found.');
            header('Location: /services-resources/services?status=trash');
            exit;
        }
        if (!$this->ensureBranchAccess($row)) {
            return;
        }
        try {
            $this->service->restore($id);
            flash('success', 'Service restored.');
            header('Location: /services-resources/services');
            exit;
        } catch (\DomainException | \RuntimeException $e) {
            flash('error', $e->getMessage());
            header('Location: /services-resources/services?status=trash');
            exit;
        }
    }

    public function permanentDelete(int $id): void
    {
        $row = $this->repo->findTrashed($id);
        if (!$row) {
            flash('error', 'Only trashed services can be permanently deleted.');
            header('Location: /services-resources/services');
            exit;
        }
        if (!$this->ensureBranchAccess($row)) {
            return;
        }
        try {
            $this->service->permanentlyDelete($id);
            flash('success', 'Service permanently deleted.');
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            if (function_exists('slog')) {
                \slog('error', 'services.permanent_delete', $e->getMessage(), ['service_id' => $id]);
            }
            flash('error', 'This service cannot be permanently deleted because related records still exist.');
        }
        header('Location: /services-resources/services?status=trash');
        exit;
    }

    /**
     * @return list<int>
     */
    private function parsePostedServiceIds(): array
    {
        $raw = $_POST['service_ids'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<array{id: int, label: string, reason: string}> $blocked
     */
    private function formatBulkPermanentAllBlockedSummary(array $blocked): string
    {
        $n = count($blocked);
        $parts = [];
        foreach ($blocked as $b) {
            $parts[] = $b['label'] . ': ' . $this->truncateBulkPermanentReason((string) $b['reason']);
        }

        return 'No services were permanently deleted (' . $n . ' skipped). ' . implode(' · ', $parts);
    }

    /**
     * @param list<array{id: int, label: string, reason: string}> $blocked
     */
    private function formatBulkPermanentPartialSummary(int $deleted, array $blocked): string
    {
        $head = $deleted === 1
            ? '1 service permanently deleted.'
            : "{$deleted} services permanently deleted.";
        $maxShow = 5;
        $slice = array_slice($blocked, 0, $maxShow);
        $tailParts = [];
        foreach ($slice as $b) {
            $tailParts[] = $b['label'] . ' (' . $this->truncateBulkPermanentReason((string) $b['reason']) . ')';
        }
        $more = count($blocked) > $maxShow ? ' · +' . (count($blocked) - $maxShow) . ' more' : '';

        return $head . ' Not deleted (' . count($blocked) . '): ' . implode(' · ', $tailParts) . $more;
    }

    private function truncateBulkPermanentReason(string $reason, int $max = 200): string
    {
        if (strlen($reason) <= $max) {
            return $reason;
        }

        return substr($reason, 0, $max - 3) . '...';
    }

    private function redirectToServicesIndexPostContext(): void
    {
        $q = [];
        $cat = $_POST['list_category'] ?? '';
        if ($cat !== '' && $cat !== null) {
            $q[] = 'category=' . (int) $cat;
        }
        if (!empty($_POST['list_status']) && $_POST['list_status'] === 'trash') {
            $q[] = 'status=trash';
        }
        $url = '/services-resources/services' . ($q !== [] ? ('?' . implode('&', $q)) : '');
        header('Location: ' . $url);
        exit;
    }

    private function listScopeBranchId(): ?int
    {
        return $this->branchContext->getCurrentBranchId();
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
     * Build the flat ordered tree rows for the category picker in Step 1.
     * Returns an empty array when no categories exist yet.
     */
    private function loadCatTreeRows(?int $branchId): array
    {
        $raw = $this->categoryRepo->list($branchId);
        return $this->categoryRepo->buildTreeFlat($raw);
    }

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

    /**
     * Parse only the Step-1 fields from POST. Staff/room/equipment/group assignment
     * is not part of Step 1 — those fields are intentionally absent so that
     * existing resource assignments on an existing service are never touched here.
     *
     * For create, ServiceService::create() receives staff_group_ids=[] explicitly
     * (an empty initial set, which is correct — groups are assigned later).
     * For update, staff_group_ids is intentionally NOT included in the payload so
     * ServiceService::update() skips the pivot table entirely.
     */
    private function parseInput(bool $isCreate = false): array
    {
        $allowedServiceTypes = ['service', 'package_item', 'other'];
        $rawType = trim($_POST['service_type'] ?? 'service');
        $staffFeeAllowed = ['none', 'percentage', 'amount'];
        $rawFeeMode = trim($_POST['staff_fee_mode'] ?? 'none');

        $data = [
            'service_type'              => in_array($rawType, $allowedServiceTypes, true) ? $rawType : 'service',
            'category_id'               => trim($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null,
            'name'                      => trim($_POST['name'] ?? ''),
            'description'               => $this->normalizeDescriptionInput($_POST['description'] ?? null),
            'sku'                       => $this->normalizeNullableString($_POST['sku'] ?? null, 100),
            'barcode'                   => $this->normalizeNullableString($_POST['barcode'] ?? null, 100),
            'duration_minutes'          => (int) ($_POST['duration_minutes'] ?? 60),
            'buffer_before_minutes'     => (int) ($_POST['buffer_before_minutes'] ?? 0),
            'buffer_after_minutes'      => (int) ($_POST['buffer_after_minutes'] ?? 0),
            'processing_time_required'  => !empty($_POST['processing_time_required']) ? 1 : 0,
            'add_on'                    => !empty($_POST['add_on']) ? 1 : 0,
            'requires_two_staff_members' => !empty($_POST['requires_two_staff_members']) ? 1 : 0,
            'applies_to_employee'       => !empty($_POST['applies_to_employee']) ? 1 : 0,
            'applies_to_room'           => !empty($_POST['applies_to_room']) ? 1 : 0,
            'requires_equipment'        => !empty($_POST['requires_equipment']) ? 1 : 0,
            'price'                     => (float) ($_POST['price'] ?? 0),
            'vat_rate_id'               => trim($_POST['vat_rate_id'] ?? '') !== '' ? (int) $_POST['vat_rate_id'] : null,
            'is_active'                 => !empty($_POST['is_active']) ? 1 : 0,
            'show_in_online_menu'       => !empty($_POST['show_in_online_menu']) ? 1 : 0,
            'staff_fee_mode'            => in_array($rawFeeMode, $staffFeeAllowed, true) ? $rawFeeMode : 'none',
            'staff_fee_value'           => null,
            'allow_on_gift_voucher_sale' => !empty($_POST['allow_on_gift_voucher_sale']) ? 1 : 0,
            'billing_code'              => $this->normalizeNullableString($_POST['billing_code'] ?? null, 50),
            'branch_id'                 => trim($_POST['branch_id'] ?? '') !== '' ? (int) $_POST['branch_id'] : null,
        ];

        // staff_fee_value only meaningful when mode != none
        if ($data['staff_fee_mode'] !== 'none') {
            $rawVal = $_POST['staff_fee_value'] ?? '';
            $data['staff_fee_value'] = $rawVal !== '' ? round((float) $rawVal, 2) : null;
        }

        if ($isCreate) {
            $data['staff_group_ids'] = [];
        }

        return $data;
    }

    private function normalizeNullableString(mixed $raw, int $maxLen): ?string
    {
        if ($raw === null) return null;
        if (!is_string($raw)) return null;
        $t = trim($raw);
        if ($t === '') return null;
        return mb_substr($t, 0, $maxLen, 'UTF-8');
    }

    private function validate(array $data, ?array $existingService): array
    {
        $errors = [];

        // Section B — identity
        if ($data['name'] === '') {
            $errors['name'] = 'Service name is required.';
        }
        if (isset($data['description']) && $data['description'] !== null && strlen($data['description']) > 65535) {
            $errors['description'] = 'Description cannot exceed 65535 bytes.';
        }

        // Section C — booking
        if ($data['duration_minutes'] < 1) {
            $errors['duration_minutes'] = 'Duration must be at least 1 minute.';
        }
        if ($data['buffer_before_minutes'] < 0) {
            $errors['buffer_before_minutes'] = 'Prep time cannot be negative.';
        }
        if ($data['buffer_after_minutes'] < 0) {
            $errors['buffer_after_minutes'] = 'Cleanup time cannot be negative.';
        }

        // Section D — commercial
        if ($data['price'] < 0) {
            $errors['price'] = 'Price cannot be negative.';
        }
        if ($data['staff_fee_mode'] !== 'none') {
            if ($data['staff_fee_value'] === null) {
                $errors['staff_fee_value'] = 'Staff fee value is required when mode is percentage or amount.';
            } elseif ($data['staff_fee_value'] < 0) {
                $errors['staff_fee_value'] = 'Staff fee value cannot be negative.';
            } elseif ($data['staff_fee_mode'] === 'percentage' && $data['staff_fee_value'] > 100) {
                $errors['staff_fee_value'] = 'Staff fee percentage cannot exceed 100.';
            }
        }
        if (isset($data['billing_code']) && $data['billing_code'] !== null && strlen($data['billing_code']) > 50) {
            $errors['billing_code'] = 'Billing code cannot exceed 50 characters.';
        }
        if (isset($data['sku']) && $data['sku'] !== null && strlen($data['sku']) > 100) {
            $errors['sku'] = 'SKU cannot exceed 100 characters.';
        }
        if (isset($data['barcode']) && $data['barcode'] !== null && strlen($data['barcode']) > 100) {
            $errors['barcode'] = 'Barcode cannot exceed 100 characters.';
        }

        return $errors;
    }

    private function mapServiceDomainExceptionToErrors(\DomainException $e): array
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'vat rate') !== false) {
            return ['vat_rate_id' => $msg];
        }
        if (stripos($msg, 'sku') !== false) {
            return ['sku' => $msg];
        }

        return ['_general' => $msg];
    }

    private function isDrawerRequest(): bool
    {
        return (string) ($_GET['drawer'] ?? '') === '1'
            || (string) ($_SERVER['HTTP_X_APP_DRAWER'] ?? '') === '1';
    }

    /**
     * @param array<string, mixed> $extra
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

    private function captureServiceDrawerStep1Html(): string
    {
        ob_start();
        require base_path('modules/services-resources/views/services/drawer/service_step1.php');

        return ob_get_clean();
    }
}
