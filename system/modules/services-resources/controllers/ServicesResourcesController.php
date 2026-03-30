<?php

declare(strict_types=1);

namespace Modules\ServicesResources\Controllers;

use Core\App\Application;

final class ServicesResourcesController
{
    public function index(): void
    {
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/services-resources/views/index.php');
    }
}
