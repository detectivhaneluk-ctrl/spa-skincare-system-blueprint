<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;

/**
 * Placeholder shell for founder billing until subscription data is wired.
 */
final class PlatformFounderBillingShellController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session
    ) {
    }

    public function index(): void
    {
        if ($this->auth->user() === null) {
            header('Location: /login');
            exit;
        }
        $csrf = $this->session->csrfToken();
        $title = 'Billing';
        ob_start();
        require base_path('modules/organizations/views/platform_salons/billing_placeholder.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }
}
