<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;

/**
 * Secondary platform tools (access, branches, security, incidents) — not primary founder IA.
 */
final class PlatformFounderPlatformShellController
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
        $title = 'System';
        ob_start();
        require base_path('modules/organizations/views/platform_salons/platform_shell.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }
}
