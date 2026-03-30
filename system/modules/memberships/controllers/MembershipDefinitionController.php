<?php

declare(strict_types=1);

namespace Modules\Memberships\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Modules\Memberships\Repositories\MembershipDefinitionRepository;
use Modules\Memberships\Services\MembershipService;

final class MembershipDefinitionController
{
    public function __construct(
        private MembershipDefinitionRepository $definitions,
        private MembershipService $service,
        private BranchDirectory $branchDirectory,
        private BranchContext $branchContext
    ) {
    }

    public function index(): void
    {
        $tenantBranchId = $this->tenantBranchOrRedirect();
        $search = self::coerceGetString('search');
        $status = self::coerceGetString('status');

        $filters = [
            'search' => $search ?: null,
            'status' => $status ?: null,
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $items = $this->definitions->listInTenantScope($filters, $tenantBranchId, $perPage, ($page - 1) * $perPage);
        $total = $this->definitions->countInTenantScope($filters, $tenantBranchId);
        $flash = flash();
        $branches = $this->getBranches();
        require base_path('modules/memberships/views/definitions/index.php');
    }

    public function create(): void
    {
        $this->tenantBranchOrRedirect();
        $definition = [
            'status' => 'active',
            'public_online_eligible' => false,
            'duration_days' => 30,
            'billing_enabled' => false,
            'billing_interval_unit' => 'month',
            'billing_interval_count' => 1,
            'renewal_invoice_due_days' => 14,
            'billing_auto_renew_enabled' => true,
        ];
        $errors = [];
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/memberships/views/definitions/create.php');
    }

    public function store(): void
    {
        $this->tenantBranchOrRedirect();
        $data = $this->parseInput();
        $errors = $this->finalizeInputErrors($data);
        if (!empty($errors)) {
            $definition = $data;
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/memberships/views/definitions/create.php');
            return;
        }
        try {
            $this->service->createDefinition($data);
            flash('success', 'Membership definition created.');
            header('Location: /memberships');
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $definition = $data;
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/memberships/views/definitions/create.php');
        }
    }

    public function edit(int $id): void
    {
        $definition = $this->definitions->findInTenantScope($id, $this->tenantBranchOrRedirect());
        if (!$definition) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($definition)) {
            return;
        }
        $errors = [];
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/memberships/views/definitions/edit.php');
    }

    public function update(int $id): void
    {
        $current = $this->definitions->findInTenantScope($id, $this->tenantBranchOrRedirect());
        if (!$current) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($current)) {
            return;
        }
        $data = $this->parseInput();
        $errors = $this->finalizeInputErrors($data);
        if (!empty($errors)) {
            $definition = array_merge($current, $data);
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/memberships/views/definitions/edit.php');
            return;
        }
        try {
            $this->service->updateDefinition($id, $data);
            flash('success', 'Membership definition updated.');
            header('Location: /memberships');
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $definition = array_merge($current, $data);
            $branches = $this->getBranches();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/memberships/views/definitions/edit.php');
        }
    }

    private function parseInput(): array
    {
        $price = $this->parseOptionalMoneyPost('price');
        $renewalPrice = $this->parseOptionalMoneyPost('renewal_price');

        return [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'branch_id' => trim((string) ($_POST['branch_id'] ?? '')) === '' ? null : (int) $_POST['branch_id'],
            'duration_days' => (int) ($_POST['duration_days'] ?? 0),
            'price' => $price['value'],
            'status' => trim((string) ($_POST['status'] ?? 'active')),
            'billing_enabled' => isset($_POST['billing_enabled']) && (string) $_POST['billing_enabled'] === '1',
            'billing_interval_unit' => trim((string) ($_POST['billing_interval_unit'] ?? '')),
            'billing_interval_count' => (int) ($_POST['billing_interval_count'] ?? 0),
            'renewal_price' => $renewalPrice['value'],
            'renewal_invoice_due_days' => (int) ($_POST['renewal_invoice_due_days'] ?? 14),
            'billing_auto_renew_enabled' => isset($_POST['billing_auto_renew_enabled']) && (string) $_POST['billing_auto_renew_enabled'] === '1',
            'public_online_eligible' => isset($_POST['public_online_eligible']) && (string) $_POST['public_online_eligible'] === '1',
            '_parse_errors' => array_filter(
                [
                    'price' => $price['error'],
                    'renewal_price' => $renewalPrice['error'],
                ],
                static fn ($m) => $m !== null && $m !== ''
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function finalizeInputErrors(array &$data): array
    {
        $parse = $data['_parse_errors'] ?? [];
        unset($data['_parse_errors']);
        if (!is_array($parse)) {
            $parse = [];
        }

        return array_merge($parse, $this->validate($data));
    }

    /**
     * Strict non-negative decimal for schema money columns (no scientific notation, no thousands separators).
     *
     * @return array{value: ?float, error: ?string}
     */
    private function parseOptionalMoneyPost(string $key): array
    {
        $raw = trim((string) ($_POST[$key] ?? ''));
        if ($raw === '') {
            return ['value' => null, 'error' => null];
        }
        if (!preg_match('/^\d+(\.\d{1,4})?$/', $raw)) {
            return ['value' => null, 'error' => 'Enter a valid amount.'];
        }
        $v = round((float) $raw, 2);
        if (!is_finite($v)) {
            return ['value' => null, 'error' => 'Enter a valid amount.'];
        }

        return ['value' => $v, 'error' => null];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validate(array $data): array
    {
        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = 'Name is required.';
        }
        if (!in_array($data['status'], MembershipService::DEFINITION_STATUSES, true)) {
            $errors['status'] = 'Invalid status.';
        }
        if ((int) $data['duration_days'] <= 0) {
            $errors['duration_days'] = 'Duration (days) must be greater than zero.';
        }
        if ($data['price'] !== null) {
            if (!is_finite((float) $data['price'])) {
                $errors['price'] = 'Price is invalid.';
            } elseif ((float) $data['price'] < 0) {
                $errors['price'] = 'Price cannot be negative.';
            }
        }
        $dueDays = (int) ($data['renewal_invoice_due_days'] ?? 14);
        if ($dueDays < 0 || $dueDays > 3660) {
            $errors['renewal_invoice_due_days'] = 'Renewal invoice due days must be between 0 and 3660.';
        }
        $intervalCount = (int) ($data['billing_interval_count'] ?? 0);
        if ($intervalCount < 0 || $intervalCount > 10000) {
            $errors['billing_interval_count'] = 'Billing interval count must be between 0 and 10000.';
        }
        if (!empty($data['billing_enabled'])) {
            $unit = (string) ($data['billing_interval_unit'] ?? '');
            if (!in_array($unit, MembershipService::DEFINITION_BILLING_INTERVAL_UNITS, true)) {
                $errors['billing_interval_unit'] = 'Choose a billing interval unit.';
            }
            if ($intervalCount < 1) {
                $errors['billing_interval_count'] = 'Billing interval count must be at least 1.';
            }
            if ($data['renewal_price'] !== null) {
                if (!is_finite((float) $data['renewal_price'])) {
                    $errors['renewal_price'] = 'Renewal price is invalid.';
                } elseif ((float) $data['renewal_price'] < 0) {
                    $errors['renewal_price'] = 'Renewal price cannot be negative.';
                }
            }
            $priceNum = $data['price'] !== null ? (float) $data['price'] : 0.0;
            $renewalNum = $data['renewal_price'] !== null ? (float) $data['renewal_price'] : 0.0;
            if ($renewalNum <= 0 && $priceNum <= 0) {
                $errors['renewal_price'] = 'Enter a positive renewal price or a positive initial price.';
            }
        }
        return $errors;
    }

    private static function coerceGetString(string $key): string
    {
        $v = $_GET[$key] ?? '';

        return is_string($v) ? trim($v) : '';
    }

    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null ? (int) $entity['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }

    private function tenantBranchOrRedirect(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            flash('error', 'Tenant branch context is required for membership definition routes.');
            header('Location: /memberships');
            exit;
        }

        return $branchId;
    }
}
