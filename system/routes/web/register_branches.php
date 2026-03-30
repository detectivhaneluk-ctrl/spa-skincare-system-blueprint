<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\GuestMiddleware;
use Core\Middleware\PermissionMiddleware;

$router->get('/branches', [\Modules\Branches\Controllers\BranchAdminController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.view')]);
$router->get('/branches/create', [\Modules\Branches\Controllers\BranchAdminController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.manage')]);
$router->post('/branches', [\Modules\Branches\Controllers\BranchAdminController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.manage')]);
$router->get('/branches/{id:\d+}/edit', [\Modules\Branches\Controllers\BranchAdminController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.manage')]);
$router->post('/branches/{id:\d+}', [\Modules\Branches\Controllers\BranchAdminController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.manage')]);
$router->post('/branches/{id:\d+}/delete', [\Modules\Branches\Controllers\BranchAdminController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('branches.manage')]);
