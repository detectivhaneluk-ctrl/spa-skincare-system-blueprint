<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\PlatformManagePostRateLimitMiddleware;
use Core\Middleware\PlatformPrincipalMiddleware;
use Core\Middleware\PermissionMiddleware;
use Modules\Organizations\Controllers\PlatformOrganizationRegistryController;
use Modules\Organizations\Controllers\PlatformOrganizationRegistryManageController;

$viewMw = [AuthMiddleware::class, PlatformPrincipalMiddleware::class, PermissionMiddleware::for('platform.organizations.view')];
$manageMw = [AuthMiddleware::class, PlatformPrincipalMiddleware::class, PermissionMiddleware::for('platform.organizations.manage'), PlatformManagePostRateLimitMiddleware::class];

$router->get('/platform/organizations', static function (): void {
    header('Location: /platform-admin/salons', true, 302);
    exit;
}, $viewMw);

$router->get('/platform/organizations/create', static function (): void {
    header('Location: /platform-admin/organizations/create', true, 302);
    exit;
}, $manageMw);

$router->get('/platform/organizations/{id:\d+}/edit', static function (int $id): void {
    header('Location: /platform-admin/organizations/' . $id . '/edit', true, 302);
    exit;
}, $manageMw);

$router->get('/platform/organizations/{id:\d+}', static function (int $id): void {
    header('Location: /platform-admin/salons/' . $id, true, 302);
    exit;
}, $viewMw);

$router->post('/platform/organizations', [PlatformOrganizationRegistryManageController::class, 'store'], $manageMw);
$router->post('/platform/organizations/{id:\d+}/suspend', [PlatformOrganizationRegistryManageController::class, 'suspend'], $manageMw);
$router->post('/platform/organizations/{id:\d+}/reactivate', [PlatformOrganizationRegistryManageController::class, 'reactivate'], $manageMw);
$router->post('/platform/organizations/{id:\d+}', [PlatformOrganizationRegistryManageController::class, 'update'], $manageMw);
