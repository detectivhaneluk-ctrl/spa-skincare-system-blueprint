<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\GuestMiddleware;
use Core\Middleware\PermissionMiddleware;

$router->get('/staff', [\Modules\Staff\Controllers\StaffController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);
$router->get('/staff/create', [\Modules\Staff\Controllers\StaffController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->post('/staff', [\Modules\Staff\Controllers\StaffController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->get('/staff/groups', [\Modules\Staff\Controllers\StaffGroupController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);
$router->post('/staff/groups', [\Modules\Staff\Controllers\StaffGroupController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->get('/staff/groups/{id:\d+}', [\Modules\Staff\Controllers\StaffGroupController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);
$router->post('/staff/groups/{id:\d+}', [\Modules\Staff\Controllers\StaffGroupController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/groups/{id:\d+}/deactivate', [\Modules\Staff\Controllers\StaffGroupController::class, 'deactivate'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/groups/{id:\d+}/staff/{staffId:\d+}/attach', [\Modules\Staff\Controllers\StaffGroupController::class, 'attachStaff'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/groups/{id:\d+}/staff/{staffId:\d+}/detach', [\Modules\Staff\Controllers\StaffGroupController::class, 'detachStaff'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->get('/staff/groups/{id:\d+}/permissions', [\Modules\Staff\Controllers\StaffGroupController::class, 'permissions'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);
$router->post('/staff/groups/{id:\d+}/permissions', [\Modules\Staff\Controllers\StaffGroupController::class, 'replacePermissions'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->get('/staff/{id}', [\Modules\Staff\Controllers\StaffController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);
$router->get('/staff/{id}/edit', [\Modules\Staff\Controllers\StaffController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id}', [\Modules\Staff\Controllers\StaffController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id}/delete', [\Modules\Staff\Controllers\StaffController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.delete')]);
$router->post('/staff/{id}/schedules', [\Modules\Staff\Controllers\StaffController::class, 'scheduleStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id}/schedules/{scheduleId:\d+}/delete', [\Modules\Staff\Controllers\StaffController::class, 'scheduleDelete'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id}/breaks', [\Modules\Staff\Controllers\StaffController::class, 'breakStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id}/breaks/{breakId:\d+}/delete', [\Modules\Staff\Controllers\StaffController::class, 'breakDelete'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
