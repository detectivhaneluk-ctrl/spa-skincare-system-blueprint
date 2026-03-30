<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Core\Permissions\PermissionService;
use Modules\Organizations\Services\PlatformSalonDetailService;
use Modules\Organizations\Services\PlatformSalonRegistryService;

/**
 * Founder control plane — salon-centric registry and detail (primary mental model: tenant accounts).
 */
final class PlatformSalonController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private PermissionService $permissions,
        private PlatformSalonRegistryService $registry,
        private PlatformSalonDetailService $detail
    ) {
    }

    public function index(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $status = isset($_GET['status']) ? trim((string) $_GET['status']) : 'all';
        if (!in_array($status, ['all', 'active', 'suspended', 'archived'], true)) {
            $status = 'all';
        }
        $problemsOnly = isset($_GET['problems']) && (string) $_GET['problems'] === '1';
        $canManage = $this->permissions->has((int) $user['id'], 'platform.organizations.manage');
        $payload = $this->registry->buildList($q === '' ? null : $q, $status, $problemsOnly, $canManage);
        $csrf = $this->session->csrfToken();
        $title = 'Salons';
        $flash = flash();
        ob_start();
        require base_path('modules/organizations/views/platform_salons/index.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function show(int $id): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $id = (int) $id;
        $canManage = $this->permissions->has((int) $user['id'], 'platform.organizations.manage');
        $data = $this->detail->build($id, $canManage);
        if ($data === null) {
            flash('error', 'Salon not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        $csrf = $this->session->csrfToken();
        $title = (string) ($data['salon']['name'] ?? 'Salon');
        $flash = flash();
        ob_start();
        require base_path('modules/organizations/views/platform_salons/show.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }
}
