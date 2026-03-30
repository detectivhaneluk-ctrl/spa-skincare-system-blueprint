<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\PermissionMiddleware;

$router->post('/media/assets', [\Modules\Media\Controllers\MediaAssetController::class, 'upload'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('media.upload')]);
