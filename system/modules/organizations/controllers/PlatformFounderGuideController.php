<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;

/**
 * In-product operator handbook — copy-only surface; no domain mutations.
 */
final class PlatformFounderGuideController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
    ) {
    }

    public function index(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $csrf = $this->session->csrfToken();
        $title = 'Operator guide';
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/founder_operator_guide.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }
}
