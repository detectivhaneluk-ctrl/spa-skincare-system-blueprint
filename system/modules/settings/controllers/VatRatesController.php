<?php

declare(strict_types=1);

namespace Modules\Settings\Controllers;

use Core\App\Application;
use Core\Audit\AuditService;
use Modules\Sales\Services\VatRateService;
use Modules\Settings\Support\SettingsShellSidebar;

final class VatRatesController
{
    /**
     * Global VAT catalog only (`vat_rates.branch_id IS NULL`).
     * Index/create/store list and create global rows; edit/update refuse branch-scoped ids so URL guessing cannot mutate overlay rows.
     */
    private const ADMIN_BRANCH_ID = null;

    public function __construct(private VatRateService $vatRateService)
    {
    }

    public function index(): void
    {
        $rates = $this->vatRateService->listForAdmin(self::ADMIN_BRANCH_ID);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        extract($this->sidebarPermissions());
        require base_path('modules/settings/views/vat-rates/index.php');
    }

    public function create(): void
    {
        $rate = [
            'name' => '',
            'rate_percent' => '0',
            'is_flexible' => false,
            'price_includes_tax' => false,
            'applies_to_json' => [],
            'is_active' => true,
            'sort_order' => 0,
        ];
        $appliesToOptions = VatRateService::ALLOWED_APPLIES_TO;
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        extract($this->sidebarPermissions());
        require base_path('modules/settings/views/vat-rates/create.php');
    }

    public function store(): void
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $ratePercent = $_POST['rate_percent'] ?? '';
        $isFlexible = isset($_POST['is_flexible']) && (string) $_POST['is_flexible'] !== '0';
        $priceIncludesTax = isset($_POST['price_includes_tax']) && (string) $_POST['price_includes_tax'] !== '0';
        $appliesTo = is_array($_POST['applies_to'] ?? null) ? (array) $_POST['applies_to'] : [];
        $isActive = isset($_POST['is_active']) && (string) $_POST['is_active'] !== '0';
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $audit = Application::container()->get(AuditService::class);

        try {
            $id = $this->vatRateService->create(self::ADMIN_BRANCH_ID, [
                'name' => $name,
                'rate_percent' => $ratePercent,
                'is_flexible' => $isFlexible,
                'price_includes_tax' => $priceIncludesTax,
                'applies_to_json' => $appliesTo,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]);
            $created = $this->vatRateService->getGlobalCatalogRateForSettingsAdmin($id);
            $audit->log('vat_rate_created', 'vat_rate', $id, null, null, [
                'after' => $created,
            ]);
            flash('success', 'VAT rate created.');
            header('Location: /settings/vat-rates');
            exit;
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            $rate = [
                'name' => $name,
                'rate_percent' => $ratePercent,
                'is_flexible' => $isFlexible,
                'price_includes_tax' => $priceIncludesTax,
                'applies_to_json' => $appliesTo,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ];
            $appliesToOptions = VatRateService::ALLOWED_APPLIES_TO;
            $errors = [$e->getMessage()];
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $flash = flash();
            extract($this->sidebarPermissions());
            require base_path('modules/settings/views/vat-rates/create.php');
            return;
        }
    }

    public function edit(int $id): void
    {
        $id = (int) $id;
        $rate = $this->vatRateService->getGlobalCatalogRateForSettingsAdmin($id);
        if ($rate === null || !$this->isGlobalVatCatalogRow($rate)) {
            flash('error', 'VAT rate not found.');
            header('Location: /settings/vat-rates');
            exit;
        }
        $appliesToOptions = VatRateService::ALLOWED_APPLIES_TO;
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        extract($this->sidebarPermissions());
        require base_path('modules/settings/views/vat-rates/edit.php');
    }

    public function update(int $id): void
    {
        $id = (int) $id;
        $audit = Application::container()->get(AuditService::class);
        $rate = $this->vatRateService->getGlobalCatalogRateForSettingsAdmin($id);
        if ($rate === null || !$this->isGlobalVatCatalogRow($rate)) {
            flash('error', 'VAT rate not found.');
            header('Location: /settings/vat-rates');
            exit;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $ratePercent = $_POST['rate_percent'] ?? (string) $rate['rate_percent'];
        $isFlexible = isset($_POST['is_flexible']) && (string) $_POST['is_flexible'] !== '0';
        $priceIncludesTax = isset($_POST['price_includes_tax']) && (string) $_POST['price_includes_tax'] !== '0';
        $appliesTo = is_array($_POST['applies_to'] ?? null) ? (array) $_POST['applies_to'] : [];
        $isActive = isset($_POST['is_active']) && (string) $_POST['is_active'] !== '0';
        $sortOrder = (int) ($_POST['sort_order'] ?? $rate['sort_order']);
        $appliesToOptions = VatRateService::ALLOWED_APPLIES_TO;

        try {
            $before = $rate;
            $this->vatRateService->updateGlobalCatalogRateForSettingsAdmin($id, [
                'name' => $name,
                'rate_percent' => $ratePercent,
                'is_flexible' => $isFlexible,
                'price_includes_tax' => $priceIncludesTax,
                'applies_to_json' => $appliesTo,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]);
            $after = $this->vatRateService->getGlobalCatalogRateForSettingsAdmin($id);
            $action = ((int) ($before['is_active'] ?? 1) === 1 && (int) ($after['is_active'] ?? 1) === 0)
                ? 'vat_rate_deactivated'
                : 'vat_rate_updated';
            $audit->log($action, 'vat_rate', $id, null, null, [
                'before' => $before,
                'after' => $after,
            ]);
            flash('success', 'VAT rate updated.');
            header('Location: /settings/vat-rates');
            exit;
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            $rate = array_merge($rate, [
                'name' => $name,
                'rate_percent' => $ratePercent,
                'is_flexible' => $isFlexible,
                'price_includes_tax' => $priceIncludesTax,
                'applies_to_json' => $appliesTo,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]);
            $appliesToOptions = VatRateService::ALLOWED_APPLIES_TO;
            $errors = [$e->getMessage()];
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $flash = flash();
            extract($this->sidebarPermissions());
            require base_path('modules/settings/views/vat-rates/edit.php');
            return;
        }
    }

    public function archive(int $id): void
    {
        $id = (int) $id;
        $audit = Application::container()->get(AuditService::class);
        $rate = $this->vatRateService->getGlobalCatalogRateForSettingsAdmin($id);
        if ($rate === null || !$this->isGlobalVatCatalogRow($rate)) {
            flash('error', 'VAT rate not found.');
            header('Location: /settings/vat-rates');
            exit;
        }

        try {
            $before = $rate;
            $this->vatRateService->archiveGlobalCatalogRateForSettingsAdmin($id);
            $after = $this->vatRateService->getGlobalCatalogRateForSettingsAdmin($id);
            $audit->log('vat_rate_archived', 'vat_rate', $id, null, null, [
                'before' => $before,
                'after' => $after,
            ]);
            flash('success', 'VAT rate archived.');
            header('Location: /settings/vat-rates');
            exit;
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /settings/vat-rates');
            exit;
        }
    }

    /**
     * @param array<string, mixed> $rate Normalized row from {@see VatRateService::getById}.
     */
    private function isGlobalVatCatalogRow(array $rate): bool
    {
        return ($rate['branch_id'] ?? null) === null;
    }

    /**
     * @return array<string, bool>
     */
    private function sidebarPermissions(): array
    {
        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();

        return SettingsShellSidebar::permissionFlagsForUser($user);
    }
}
