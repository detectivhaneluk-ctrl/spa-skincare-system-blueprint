<?php

declare(strict_types=1);

namespace Modules\Inventory\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Inventory\Repositories\ProductRepository;
use Modules\Inventory\Repositories\StockMovementRepository;
use Modules\Inventory\Services\StockMovementService;

final class StockMovementController
{
    public function __construct(
        private StockMovementRepository $repo,
        private StockMovementService $service,
        private ProductRepository $productRepo,
        private BranchDirectory $branchDirectory
    ) {
    }

    public function index(): void
    {
        $branchId = $this->requireTenantBranchId();
        $movementType = trim($_GET['movement_type'] ?? '');
        $productId = trim($_GET['product_id'] ?? '') !== '' ? (int) $_GET['product_id'] : null;

        $filters = [
            'movement_type' => $movementType ?: null,
            'product_id' => $productId,
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $movements = $this->repo->listInTenantScope($filters, $branchId, $perPage, ($page - 1) * $perPage);
        $total = $this->repo->countInTenantScope($filters, $branchId);
        $products = $this->productRepo->listInTenantScope([], $branchId, 500, 0);
        $branches = $this->getBranches();
        $flash = flash();
        require base_path('modules/inventory/views/movements/index.php');
    }

    public function create(): void
    {
        $products = $this->productRepo->listInTenantScope([], $this->requireTenantBranchId(), 500, 0);
        $branches = $this->getBranches();
        $errors = [];
        $movement = [
            'movement_type' => 'purchase_in',
            'quantity' => '',
        ];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/inventory/views/movements/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $products = $this->productRepo->listInTenantScope([], $this->requireTenantBranchId(), 500, 0);
            $branches = $this->getBranches();
            $movement = $data;
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/inventory/views/movements/create.php');
            return;
        }

        try {
            $this->service->createManual($data);
            flash('success', 'Stock movement recorded and stock updated.');
            header('Location: /inventory/movements');
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $products = $this->productRepo->listInTenantScope([], $this->requireTenantBranchId(), 500, 0);
            $branches = $this->getBranches();
            $movement = $data;
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/inventory/views/movements/create.php');
        }
    }

    private function parseInput(): array
    {
        $branchRaw = trim($_POST['branch_id'] ?? '');
        return [
            'product_id' => (int) ($_POST['product_id'] ?? 0),
            'movement_type' => trim($_POST['movement_type'] ?? ''),
            'quantity' => (float) ($_POST['quantity'] ?? 0),
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'branch_id' => $branchRaw === '' ? null : (int) $branchRaw,
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (empty($data['product_id'])) {
            $errors['product_id'] = 'Product is required.';
        }
        if (!in_array($data['movement_type'], StockMovementService::MANUAL_ENTRY_MOVEMENT_TYPES, true)) {
            $errors['movement_type'] = 'Invalid movement type for manual entry.';
        }
        if ((float) $data['quantity'] == 0.0) {
            $errors['quantity'] = 'Quantity must be non-zero.';
        }
        return $errors;
    }

    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    private function requireTenantBranchId(): int
    {
        $branchId = Application::container()->get(BranchContext::class)->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for inventory movement routes.');
        }

        return $branchId;
    }
}
