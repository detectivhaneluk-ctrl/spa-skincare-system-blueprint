<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;

// Package definitions
$router->get('/packages', [\Modules\Packages\Controllers\PackageDefinitionController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.view')]);
$router->get('/packages/create', [\Modules\Packages\Controllers\PackageDefinitionController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.create')]);
$router->post('/packages', [\Modules\Packages\Controllers\PackageDefinitionController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.create')]);
$router->post('/packages/bulk-soft-delete', [\Modules\Packages\Controllers\PackageDefinitionController::class, 'bulkSoftDelete'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.edit')]);
$router->get('/packages/{id}/edit', [\Modules\Packages\Controllers\PackageDefinitionController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.edit')]);
$router->post('/packages/{id:\d+}/delete', [\Modules\Packages\Controllers\PackageDefinitionController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.edit')]);
$router->post('/packages/{id}', [\Modules\Packages\Controllers\PackageDefinitionController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.edit')]);

// Client package assignments and usage
$router->get('/packages/client-packages', [\Modules\Packages\Controllers\ClientPackageController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.view')]);
$router->get('/packages/client-packages/assign', [\Modules\Packages\Controllers\ClientPackageController::class, 'assign'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.assign')]);
$router->post('/packages/client-packages/assign', [\Modules\Packages\Controllers\ClientPackageController::class, 'storeAssign'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.assign')]);
$router->post('/packages/client-packages/bulk-cancel', [\Modules\Packages\Controllers\ClientPackageController::class, 'bulkCancel'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.cancel')]);
$router->get('/packages/client-packages/{id}', [\Modules\Packages\Controllers\ClientPackageController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.view')]);
$router->get('/packages/client-packages/{id}/use', [\Modules\Packages\Controllers\ClientPackageController::class, 'useForm'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.use')]);
$router->post('/packages/client-packages/{id}/use', [\Modules\Packages\Controllers\ClientPackageController::class, 'useStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.use')]);
$router->get('/packages/client-packages/{id}/adjust', [\Modules\Packages\Controllers\ClientPackageController::class, 'adjustForm'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.adjust')]);
$router->post('/packages/client-packages/{id}/adjust', [\Modules\Packages\Controllers\ClientPackageController::class, 'adjustStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.adjust')]);
$router->post('/packages/client-packages/{id}/reverse', [\Modules\Packages\Controllers\ClientPackageController::class, 'reverseStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.reverse')]);
$router->post('/packages/client-packages/{id}/cancel', [\Modules\Packages\Controllers\ClientPackageController::class, 'cancelStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('packages.cancel')]);
