<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Modules\Organizations\Services\FounderAccessPresenter;
use Modules\Organizations\Services\PlatformControlPlaneOverviewService;

/**
 * Platform / founder control plane home.
 */
final class PlatformControlPlaneController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private PlatformControlPlaneOverviewService $overview,
        private FounderAccessPresenter $founderPresenter,
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
        $title = 'Founder dashboard';
        $platform = $this->overview->build();
        $founderPresenter = $this->founderPresenter;

        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/index.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

}
