<?php

declare(strict_types=1);

namespace Modules\Inventory\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Inventory\Repositories\InventoryCountRepository;
use Modules\Inventory\Repositories\ProductRepository;
use Modules\Inventory\Services\InventoryCountService;

final class InventoryCountController
{
    public function __construct(
        private InventoryCountRepository $repo,
        private InventoryCountService $service,
        private ProductRepository $productRepo,
        private BranchDirectory $branchDirectory
    ) {
    }

    public function index(): void
    {
        $branchId = $this->requireTenantBranchId();
        $productId = trim($_GET['product_id'] ?? '') !== '' ? (int) $_GET['product_id'] : null;

        $filters = [
            'product_id' => $productId,
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $counts = $this->repo->listInTenantScope($filters, $branchId, $perPage, ($page - 1) * $perPage);
        $total = $this->repo->countInTenantScope($filters, $branchId);
        $products = $this->productRepo->listInTenantScope([], $branchId, 500, 0);
        $branches = $this->getBranches();
        $flash = flash();
        require base_path('modules/inventory/views/counts/index.php');
    }

    public function create(): void
    {
        $products = $this->productRepo->listInTenantScope([], $this->requireTenantBranchId(), 500, 0);
        $branches = $this->getBranches();
        $errors = [];
        $count = [
            'counted_quantity' => '',
            'apply_adjustment' => 1,
        ];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/inventory/views/counts/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $products = $this->productRepo->listInTenantScope([], $this->requireTenantBranchId(), 500, 0);
            $branches = $this->getBranches();
            $count = $data;
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/inventory/views/counts/create.php');
            return;
        }

        try {
            $result = $this->service->create($data);
            $msg = 'Inventory count recorded.';
            if (!empty($result['adjusted'])) {
                $msg .= ' Stock adjustment movement created.';
            }
            flash('success', $msg);
            header('Location: /inventory/counts');
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $products = $this->productRepo->listInTenantScope([], $this->requireTenantBranchId(), 500, 0);
            $branches = $this->getBranches();
            $count = $data;
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/inventory/views/counts/create.php');
        }
    }

    private function parseInput(): array
    {
        $branchRaw = trim($_POST['branch_id'] ?? '');
        return [
            'product_id' => (int) ($_POST['product_id'] ?? 0),
            'counted_quantity' => (float) ($_POST['counted_quantity'] ?? 0),
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'branch_id' => $branchRaw === '' ? null : (int) $branchRaw,
            'apply_adjustment' => isset($_POST['apply_adjustment']) ? 1 : 0,
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (empty($data['product_id'])) {
            $errors['product_id'] = 'Product is required.';
        }
        if ($data['counted_quantity'] < 0) {
            $errors['counted_quantity'] = 'Counted quantity cannot be negative.';
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
            throw new \DomainException('Tenant branch context is required for inventory count routes.');
        }

        return $branchId;
    }
}
