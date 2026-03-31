<?php

declare(strict_types=1);

namespace Modules\Settings\Controllers;

use Core\App\Application;
use Core\Audit\AuditService;
use Modules\Sales\Services\PaymentMethodService;
use Modules\Sales\Support\PaymentMethodFamily;
use Modules\Settings\Support\SettingsShellSidebar;

final class PaymentMethodsController
{
    /** Global payment methods only (branch_id NULL). */
    private const ADMIN_BRANCH_ID = null;

    public function __construct(private PaymentMethodService $paymentMethodService)
    {
    }

    public function index(): void
    {
        $methodsRaw = $this->paymentMethodService->listForAdmin(self::ADMIN_BRANCH_ID);
        $methods = array_map(static fn (array $m) => PaymentMethodFamily::annotate($m), $methodsRaw);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        extract($this->sidebarPermissions());
        require base_path('modules/settings/views/payment-methods/index.php');
    }

    public function create(): void
    {
        $method = ['type_label' => '', 'name' => '', 'is_active' => true, 'sort_order' => 0];
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        extract($this->sidebarPermissions());
        require base_path('modules/settings/views/payment-methods/create.php');
    }

    public function store(): void
    {
        $typeLabel = trim((string) ($_POST['type_label'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $isActive = isset($_POST['is_active']) && (string) $_POST['is_active'] !== '0';
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $audit = Application::container()->get(AuditService::class);

        try {
            $id = $this->paymentMethodService->create(self::ADMIN_BRANCH_ID, [
                'type_label' => $typeLabel,
                'name' => $name,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]);
            $created = $this->paymentMethodService->getGlobalCatalogMethodForSettingsAdmin($id);
            $audit->log('payment_method_created', 'payment_method', $id, null, null, [
                'after' => $created,
            ]);
            flash('success', 'Payment method created.');
            header('Location: /settings/payment-methods');
            exit;
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            $method = ['type_label' => $typeLabel, 'name' => $name, 'is_active' => $isActive, 'sort_order' => $sortOrder];
            $errors = [$e->getMessage()];
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $flash = flash();
            extract($this->sidebarPermissions());
            require base_path('modules/settings/views/payment-methods/create.php');
            return;
        }
    }

    public function edit(int $id): void
    {
        $id = (int) $id;
        $method = $this->paymentMethodService->getGlobalCatalogMethodForSettingsAdmin($id);
        if ($method === null) {
            flash('error', 'Payment method not found.');
            header('Location: /settings/payment-methods');
            exit;
        }
        $method = PaymentMethodFamily::annotate($method);
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        extract($this->sidebarPermissions());
        require base_path('modules/settings/views/payment-methods/edit.php');
    }

    public function update(int $id): void
    {
        $id = (int) $id;
        $audit = Application::container()->get(AuditService::class);
        $method = $this->paymentMethodService->getGlobalCatalogMethodForSettingsAdmin($id);
        if ($method === null) {
            flash('error', 'Payment method not found.');
            header('Location: /settings/payment-methods');
            exit;
        }
        $typeLabel = trim((string) ($_POST['type_label'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $isActive = isset($_POST['is_active']) && (string) $_POST['is_active'] !== '0';
        $sortOrder = (int) ($_POST['sort_order'] ?? $method['sort_order']);

        try {
            $before = $method;
            $this->paymentMethodService->updateGlobalCatalogMethodForSettingsAdmin($id, [
                'type_label' => $typeLabel,
                'name' => $name,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]);
            $after = $this->paymentMethodService->getGlobalCatalogMethodForSettingsAdmin($id);
            $action = ((int) ($before['is_active'] ?? 1) === 1 && (int) ($after['is_active'] ?? 1) === 0)
                ? 'payment_method_deactivated'
                : 'payment_method_updated';
            $audit->log($action, 'payment_method', $id, null, null, [
                'before' => $before,
                'after' => $after,
            ]);
            flash('success', 'Payment method updated.');
            header('Location: /settings/payment-methods');
            exit;
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            $merged = array_merge($method, ['type_label' => $typeLabel, 'name' => $name, 'is_active' => $isActive, 'sort_order' => $sortOrder]);
            $method = PaymentMethodFamily::annotate($merged);
            $errors = [$e->getMessage()];
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $flash = flash();
            extract($this->sidebarPermissions());
            require base_path('modules/settings/views/payment-methods/edit.php');
            return;
        }
    }

    public function archive(int $id): void
    {
        $id = (int) $id;
        $audit = Application::container()->get(AuditService::class);
        $method = $this->paymentMethodService->getGlobalCatalogMethodForSettingsAdmin($id);
        if ($method === null) {
            flash('error', 'Payment method not found.');
            header('Location: /settings/payment-methods');
            exit;
        }

        try {
            $before = $method;
            $this->paymentMethodService->archiveGlobalCatalogMethodForSettingsAdmin($id);
            $after = $this->paymentMethodService->getGlobalCatalogMethodForSettingsAdmin($id);
            $audit->log('payment_method_archived', 'payment_method', $id, null, null, [
                'before' => $before,
                'after' => $after,
            ]);
            flash('success', 'Payment method archived.');
            header('Location: /settings/payment-methods');
            exit;
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /settings/payment-methods');
            exit;
        }
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
