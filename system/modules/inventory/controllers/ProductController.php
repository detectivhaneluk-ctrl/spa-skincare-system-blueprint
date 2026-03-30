<?php

declare(strict_types=1);

namespace Modules\Inventory\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Inventory\Repositories\ProductBrandRepository;
use Modules\Inventory\Repositories\ProductCategoryRepository;
use Modules\Inventory\Repositories\ProductRepository;
use Modules\Inventory\Services\ProductService;

final class ProductController
{
    public function __construct(
        private ProductRepository $repo,
        private ProductService $service,
        private BranchDirectory $branchDirectory,
        private ProductCategoryRepository $productCategoryRepo,
        private ProductBrandRepository $productBrandRepo,
    ) {
    }

    public function index(): void
    {
        $tenantBranchId = $this->requireTenantBranchId();
        $search = trim($_GET['search'] ?? '');
        $filterCategory = trim($_GET['filter_category'] ?? '');
        $filterBrand = trim($_GET['filter_brand'] ?? '');
        $productType = trim($_GET['product_type'] ?? '');
        $isActive = $_GET['is_active'] ?? '';

        $filters = [
            'search' => $search ?: null,
            'taxonomy_category_substring' => $filterCategory !== '' ? $filterCategory : null,
            'taxonomy_brand_substring' => $filterBrand !== '' ? $filterBrand : null,
            'product_type' => $productType ?: null,
            'is_active' => $isActive === '' ? null : ((int) $isActive === 1),
        ];

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $products = $this->repo->listInTenantScope($filters, $tenantBranchId, $perPage, ($page - 1) * $perPage);
        $products = array_map(fn (array $p) => $this->withTaxonomyLabels($p), $products);
        $total = $this->repo->countInTenantScope($filters, $tenantBranchId);
        $flash = flash();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $branches = $this->getBranches();
        $productCreateHref = $this->productCreateHref();
        $productsIndexBranchContextId = $tenantBranchId;
        $productsIndexBranchContextLabel = null;
        if ($productsIndexBranchContextId !== null) {
            foreach ($branches as $b) {
                if ((int) ($b['id'] ?? 0) === $productsIndexBranchContextId) {
                    $productsIndexBranchContextLabel = (string) ($b['name'] ?? ('#' . $productsIndexBranchContextId));
                    break;
                }
            }
            if ($productsIndexBranchContextLabel === null) {
                $productsIndexBranchContextLabel = '#' . $productsIndexBranchContextId;
            }
        }
        require base_path('modules/inventory/views/products/index.php');
    }

    public function create(): void
    {
        $branches = $this->getBranches();
        $product = [
            'product_type' => 'retail',
            'is_active' => 1,
            'cost_price' => '0.00',
            'sell_price' => '0.00',
            'reorder_level' => '0',
            'initial_quantity' => '0',
        ];
        if (Application::container()->get(BranchContext::class)->getCurrentBranchId() === null) {
            $hintBranch = $this->optionalActiveBranchIdFromRequestGet();
            if ($hintBranch !== null) {
                $product['branch_id'] = $hintBranch;
            }
        }
        $taxonomy = $this->taxonomySelectOptions($product);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        require base_path('modules/inventory/views/products/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput(false);
        $errors = $this->validate($data, true);
        if (!empty($errors)) {
            $product = $data;
            $branches = $this->getBranches();
            $taxonomy = $this->taxonomySelectOptions($product);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/inventory/views/products/create.php');
            return;
        }

        try {
            $id = $this->service->create($data);
            flash('success', 'Product created.');
            header('Location: /inventory/products/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $product = $data;
            $branches = $this->getBranches();
            $taxonomy = $this->taxonomySelectOptions($product);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/inventory/views/products/create.php');
        }
    }

    public function show(int $id): void
    {
        $product = $this->repo->findInTenantScope($id, $this->requireTenantBranchId());
        if (!$product) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($product)) {
            return;
        }
        $product = $this->withTaxonomyLabels($product);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/inventory/views/products/show.php');
    }

    public function edit(int $id): void
    {
        $product = $this->repo->findInTenantScope($id, $this->requireTenantBranchId());
        if (!$product) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($product)) {
            return;
        }
        $product = $this->withTaxonomyLabels($product);
        $product['initial_quantity'] = '0';
        $branches = $this->getBranches();
        $taxonomy = $this->taxonomySelectOptions($product);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $inventoryBranchReassignmentLocked = Application::container()->get(BranchContext::class)->getCurrentBranchId() !== null;
        require base_path('modules/inventory/views/products/edit.php');
    }

    public function update(int $id): void
    {
        $current = $this->repo->findInTenantScope($id, $this->requireTenantBranchId());
        if (!$current) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($current)) {
            return;
        }

        $data = $this->parseInput(true);
        $errors = $this->validate($data, false);
        if (!empty($errors)) {
            $product = array_merge($this->withTaxonomyLabels($current), $data);
            $branches = $this->getBranches();
            $taxonomy = $this->taxonomySelectOptions($product);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $inventoryBranchReassignmentLocked = Application::container()->get(BranchContext::class)->getCurrentBranchId() !== null;
            require base_path('modules/inventory/views/products/edit.php');
            return;
        }

        try {
            $this->service->update($id, $data);
            flash('success', 'Product updated.');
            header('Location: /inventory/products/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $product = array_merge($this->withTaxonomyLabels($current), $data);
            $branches = $this->getBranches();
            $taxonomy = $this->taxonomySelectOptions($product);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $inventoryBranchReassignmentLocked = Application::container()->get(BranchContext::class)->getCurrentBranchId() !== null;
            require base_path('modules/inventory/views/products/edit.php');
        }
    }

    public function destroy(int $id): void
    {
        $product = $this->repo->findInTenantScope($id, $this->requireTenantBranchId());
        if (!$product) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($product)) {
            return;
        }
        $this->service->delete($id);
        flash('success', 'Product deleted.');
        header('Location: /inventory/products');
        exit;
    }

    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null ? (int) $entity['branch_id'] : null;
            Application::container()->get(BranchContext::class)->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }

    /**
     * @param array<string, mixed>|null $product Row or draft payload used to infer branch when BranchContext is unset (HQ mode).
     * @return array{categories: list<array<string, mixed>>, brands: list<array<string, mixed>>}
     */
    private function taxonomySelectOptions(?array $product = null): array
    {
        $productBranchId = $this->resolveEffectiveProductBranchIdForTaxonomySelect($product);

        return [
            'categories' => $this->productCategoryRepo->listSelectableForProductBranch($productBranchId),
            'brands' => $this->productBrandRepo->listSelectableForProductBranch($productBranchId),
        ];
    }

    /**
     * Branch-context scope wins; otherwise use the product's branch_id (empty = global product → global-only taxonomy options).
     */
    private function resolveEffectiveProductBranchIdForTaxonomySelect(?array $product): ?int
    {
        $ctx = Application::container()->get(BranchContext::class)->getCurrentBranchId();
        if ($ctx !== null) {
            return $ctx;
        }
        if ($product === null) {
            return null;
        }
        $bid = $product['branch_id'] ?? null;
        if ($bid === null || $bid === '') {
            return null;
        }

        return (int) $bid;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function withTaxonomyLabels(array $product): array
    {
        $tenantBranchId = $this->requireTenantBranchId();
        $out = $product;
        $out['product_category_label'] = null;
        $out['product_brand_label'] = null;
        if (isset($product['product_category_id']) && $product['product_category_id'] !== '' && $product['product_category_id'] !== null) {
            $c = $this->productCategoryRepo->findInTenantScope((int) $product['product_category_id'], $tenantBranchId);
            $out['product_category_label'] = $c['name'] ?? null;
        }
        if (isset($product['product_brand_id']) && $product['product_brand_id'] !== '' && $product['product_brand_id'] !== null) {
            $b = $this->productBrandRepo->findInTenantScope((int) $product['product_brand_id'], $tenantBranchId);
            $out['product_brand_label'] = $b['name'] ?? null;
        }

        $legacyCat = trim((string) ($product['category'] ?? ''));
        $legacyBrand = trim((string) ($product['brand'] ?? ''));
        $normCat = $out['product_category_label'] !== null && $out['product_category_label'] !== ''
            ? trim((string) $out['product_category_label'])
            : null;
        $normBrand = $out['product_brand_label'] !== null && $out['product_brand_label'] !== ''
            ? trim((string) $out['product_brand_label'])
            : null;

        $out['category_display'] = $normCat ?? ($legacyCat !== '' ? $legacyCat : null);
        $out['brand_display'] = $normBrand ?? ($legacyBrand !== '' ? $legacyBrand : null);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseInput(bool $forUpdate): array
    {
        $branchRaw = trim($_POST['branch_id'] ?? '');
        $branchId = $branchRaw === '' ? null : (int) $branchRaw;
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'sku' => strtoupper(trim($_POST['sku'] ?? '')),
            'barcode' => trim($_POST['barcode'] ?? '') ?: null,
            'category' => trim($_POST['category'] ?? '') ?: null,
            'brand' => trim($_POST['brand'] ?? '') ?: null,
            'product_type' => trim($_POST['product_type'] ?? ''),
            'cost_price' => (float) ($_POST['cost_price'] ?? 0),
            'sell_price' => (float) ($_POST['sell_price'] ?? 0),
            'vat_rate' => trim($_POST['vat_rate'] ?? '') === '' ? null : (float) $_POST['vat_rate'],
            'reorder_level' => (float) ($_POST['reorder_level'] ?? 0),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'branch_id' => $branchId,
            'initial_quantity' => (float) ($_POST['initial_quantity'] ?? 0),
        ];

        return array_merge($data, $this->parseNormalizedTaxonomyIdsFromPost($forUpdate));
    }

    /**
     * Normalized product taxonomy FKs from POST.
     *
     * Shipped create/edit forms always include `product_category_id` and `product_brand_id` `<select>` fields; the
     * "— None —" option posts an empty string, which is stored as `null` (no normalized FK). A non-empty value is
     * cast to int for assignability validation and persist.
     *
     * - **Update:** if a key is absent from `$_POST`, it is omitted from the returned array so `ProductService`
     *   merge keeps the existing DB value. If present, an empty string clears the FK.
     * - **Create:** if a key is absent from `$_POST`, it is omitted (INSERT defaults nullable FK to NULL). If
     *   present, an empty string clears the FK for insert.
     *
     * @return array<string, int|null>
     */
    private function parseNormalizedTaxonomyIdsFromPost(bool $forUpdate): array
    {
        $out = [];
        foreach (['product_category_id', 'product_brand_id'] as $field) {
            if ($forUpdate && !array_key_exists($field, $_POST)) {
                continue;
            }
            if (!$forUpdate && !array_key_exists($field, $_POST)) {
                continue;
            }
            $raw = trim((string) ($_POST[$field] ?? ''));
            $out[$field] = $raw === '' ? null : (int) $_POST[$field];
        }

        return $out;
    }

    private function validate(array $data, bool $isCreate): array
    {
        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'Name is required.';
        }
        if ($data['sku'] === '') {
            $errors['sku'] = 'SKU is required.';
        }
        if (!in_array($data['product_type'], ProductService::PRODUCT_TYPES, true)) {
            $errors['product_type'] = 'Invalid product type.';
        }
        if ($data['cost_price'] < 0) {
            $errors['cost_price'] = 'Cost price cannot be negative.';
        }
        if ($data['sell_price'] < 0) {
            $errors['sell_price'] = 'Sell price cannot be negative.';
        }
        if ($data['vat_rate'] !== null && ($data['vat_rate'] < 0 || $data['vat_rate'] > 100)) {
            $errors['vat_rate'] = 'VAT rate must be between 0 and 100.';
        }
        if ($data['reorder_level'] < 0) {
            $errors['reorder_level'] = 'Reorder level cannot be negative.';
        }
        if ($isCreate && $data['initial_quantity'] < 0) {
            $errors['initial_quantity'] = 'Initial quantity cannot be negative.';
        }
        return $errors;
    }

    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    /**
     * HQ (no branch context): optional `GET branch_id` on create pre-seeds draft `branch_id` and normalized taxonomy selects.
     * Ignored when branch context is set (context drives branch + options). Invalid / inactive ids ignored.
     */
    private function optionalActiveBranchIdFromRequestGet(): ?int
    {
        $raw = trim((string) ($_GET['branch_id'] ?? ''));
        if ($raw === '' || strtolower($raw) === 'global') {
            return null;
        }
        if (!ctype_digit($raw)) {
            return null;
        }
        $id = (int) $raw;
        if ($id <= 0 || !$this->branchDirectory->isActiveBranchId($id)) {
            return null;
        }

        return $id;
    }

    private function productCreateHref(): string
    {
        $href = '/inventory/products/create';
        if (Application::container()->get(BranchContext::class)->getCurrentBranchId() !== null) {
            return $href;
        }
        $hint = $this->optionalActiveBranchIdFromRequestGet();
        if ($hint !== null) {
            return $href . '?branch_id=' . $hint;
        }

        return $href;
    }

    private function requireTenantBranchId(): int
    {
        $branchId = Application::container()->get(BranchContext::class)->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for inventory product routes.');
        }

        return $branchId;
    }
}
