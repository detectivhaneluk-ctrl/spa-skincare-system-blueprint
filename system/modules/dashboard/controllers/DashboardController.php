<?php

declare(strict_types=1);

namespace Modules\Dashboard\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Modules\Dashboard\Services\TenantOperatorDashboardService;

/**
 * Tenant/salon operator home ({@code /dashboard}).
 * Protected by tenant-plane middleware at route level.
 */
final class DashboardController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private TenantOperatorDashboardService $tenantOperatorDashboard
    ) {
    }

    public function index(): void
    {
        $user = $this->auth->user();
        if (!$user) {
            header('Location: /login');
            exit;
        }
        $csrf = $this->session->csrfToken();
        $title = 'Dashboard';
        $tenantDashboard = $this->tenantOperatorDashboard->build((int) ($user['id'] ?? 0));
        ob_start();
        require base_path('modules/dashboard/views/index.php');
        $content = ob_get_clean();
        require shared_path('layout/base.php');
    }
}
