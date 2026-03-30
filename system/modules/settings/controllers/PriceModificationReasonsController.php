<?php

declare(strict_types=1);

namespace Modules\Settings\Controllers;

use Core\App\Application;
use Modules\Settings\Services\PriceModificationReasonService;
use Modules\Settings\Support\SettingsShellSidebar;

final class PriceModificationReasonsController
{
    public function __construct(private PriceModificationReasonService $service)
    {
    }

    public function index(): void
    {
        $storageReady = $this->service->isStorageReady();
        $reasons = $storageReady ? $this->service->listForCurrentOrganization(false) : [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        extract($this->sidebarPermissions());
        require base_path('modules/settings/views/price-modification-reasons/index.php');
    }

    public function create(): void
    {
        $reason = ['code' => '', 'name' => '', 'description' => '', 'is_active' => true, 'sort_order' => 0];
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        extract($this->sidebarPermissions());
        require base_path('modules/settings/views/price-modification-reasons/create.php');
    }

    public function store(): void
    {
        $payload = [
            'code' => (string) ($_POST['code'] ?? ''),
            'name' => (string) ($_POST['name'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
            'is_active' => isset($_POST['is_active']) && (string) $_POST['is_active'] !== '0',
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];

        try {
            $this->service->create($payload);
            flash('success', 'Price modification reason created.');
            header('Location: /settings/price-modification-reasons');
            exit;
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            $reason = $payload;
            $errors = [$e->getMessage()];
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $flash = flash();
            extract($this->sidebarPermissions());
            require base_path('modules/settings/views/price-modification-reasons/create.php');
            return;
        }
    }

    public function edit(int $id): void
    {
        $reason = $this->service->findForCurrentOrganization((int) $id);
        if ($reason === null) {
            flash('error', 'Price modification reason not found.');
            header('Location: /settings/price-modification-reasons');
            exit;
        }
        $errors = [];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        extract($this->sidebarPermissions());
        require base_path('modules/settings/views/price-modification-reasons/edit.php');
    }

    public function update(int $id): void
    {
        $reason = $this->service->findForCurrentOrganization((int) $id);
        if ($reason === null) {
            flash('error', 'Price modification reason not found.');
            header('Location: /settings/price-modification-reasons');
            exit;
        }

        $payload = [
            'code' => (string) ($_POST['code'] ?? ''),
            'name' => (string) ($_POST['name'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
            'is_active' => isset($_POST['is_active']) && (string) $_POST['is_active'] !== '0',
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];

        try {
            $this->service->update((int) $id, $payload);
            flash('success', 'Price modification reason updated.');
            header('Location: /settings/price-modification-reasons');
            exit;
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            $reason = array_merge($reason, $payload);
            $errors = [$e->getMessage()];
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $flash = flash();
            extract($this->sidebarPermissions());
            require base_path('modules/settings/views/price-modification-reasons/edit.php');
            return;
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

