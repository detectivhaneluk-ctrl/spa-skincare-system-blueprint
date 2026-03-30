<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\GuestMiddleware;
use Core\Middleware\PermissionMiddleware;

$router->get('/notifications', [\Modules\Notifications\Controllers\NotificationController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('notifications.view')]);
$router->post('/notifications/read-all', [\Modules\Notifications\Controllers\NotificationController::class, 'markAllRead'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('notifications.view')]);
$router->post('/notifications/{id:\d+}/read', [\Modules\Notifications\Controllers\NotificationController::class, 'markRead'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('notifications.view')]);
