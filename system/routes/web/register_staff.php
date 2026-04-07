<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\GuestMiddleware;
use Core\Middleware\PermissionMiddleware;

// ── Staff list & wizard entry ──────────────────────────────────────────────────
$router->get('/staff', [\Modules\Staff\Controllers\StaffController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);
$router->get('/staff/create', [\Modules\Staff\Controllers\StaffController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->post('/staff', [\Modules\Staff\Controllers\StaffController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->post('/staff/bulk-trash', [\Modules\Staff\Controllers\StaffController::class, 'bulkTrash'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.delete')]);
$router->post('/staff/bulk-restore', [\Modules\Staff\Controllers\StaffController::class, 'bulkRestore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/bulk-permanent-delete', [\Modules\Staff\Controllers\StaffController::class, 'bulkPermanentDelete'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.delete')]);

// ── Group HTML admin (must be registered before /{id:\d+} patterns) ───────────
$router->get('/staff/groups/admin', [\Modules\Staff\Controllers\StaffGroupAdminController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);
$router->get('/staff/groups/admin/create', [\Modules\Staff\Controllers\StaffGroupAdminController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->post('/staff/groups/admin', [\Modules\Staff\Controllers\StaffGroupAdminController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->get('/staff/groups/admin/{id:\d+}/edit', [\Modules\Staff\Controllers\StaffGroupAdminController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/groups/admin/{id:\d+}', [\Modules\Staff\Controllers\StaffGroupAdminController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);

// ── /staff/groups canonical HTML page (operator-facing) ───────────────────────
$router->get('/staff/groups', [\Modules\Staff\Controllers\StaffGroupAdminController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);

// ── Group JSON API ─────────────────────────────────────────────────────────────
// GET list moved to /staff/groups/list so /staff/groups renders the HTML page.
$router->get('/staff/groups/list', [\Modules\Staff\Controllers\StaffGroupController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);
$router->post('/staff/groups', [\Modules\Staff\Controllers\StaffGroupController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->get('/staff/groups/{id:\d+}', [\Modules\Staff\Controllers\StaffGroupController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);
$router->post('/staff/groups/{id:\d+}', [\Modules\Staff\Controllers\StaffGroupController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/groups/{id:\d+}/deactivate', [\Modules\Staff\Controllers\StaffGroupController::class, 'deactivate'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/groups/{id:\d+}/staff/{staffId:\d+}/attach', [\Modules\Staff\Controllers\StaffGroupController::class, 'attachStaff'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/groups/{id:\d+}/staff/{staffId:\d+}/detach', [\Modules\Staff\Controllers\StaffGroupController::class, 'detachStaff'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->get('/staff/groups/{id:\d+}/permissions', [\Modules\Staff\Controllers\StaffGroupController::class, 'permissions'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);
$router->post('/staff/groups/{id:\d+}/permissions', [\Modules\Staff\Controllers\StaffGroupController::class, 'replacePermissions'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);

// ── Onboarding wizard steps ────────────────────────────────────────────────────
$router->get('/staff/{id:\d+}/onboarding/step2', [\Modules\Staff\Controllers\StaffController::class, 'onboardingStep2'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->post('/staff/{id:\d+}/onboarding/step2', [\Modules\Staff\Controllers\StaffController::class, 'saveStep2'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->get('/staff/{id:\d+}/onboarding/step3', [\Modules\Staff\Controllers\StaffController::class, 'onboardingStep3'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->post('/staff/{id:\d+}/onboarding/step3', [\Modules\Staff\Controllers\StaffController::class, 'saveStep3'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->get('/staff/{id:\d+}/onboarding/step4', [\Modules\Staff\Controllers\StaffController::class, 'onboardingStep4'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);
$router->post('/staff/{id:\d+}/onboarding/step4', [\Modules\Staff\Controllers\StaffController::class, 'saveStep4'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.create')]);

// ── Individual staff CRUD ─────────────────────────────────────────────────────
$router->get('/staff/{id}', [\Modules\Staff\Controllers\StaffController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.view')]);
$router->get('/staff/{id}/edit', [\Modules\Staff\Controllers\StaffController::class, 'editProfile'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id}', [\Modules\Staff\Controllers\StaffController::class, 'updateProfile'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id:\d+}/profile/services', [\Modules\Staff\Controllers\StaffController::class, 'updateProfileServices'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id:\d+}/profile/schedule', [\Modules\Staff\Controllers\StaffController::class, 'updateProfileSchedule'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id}/delete', [\Modules\Staff\Controllers\StaffController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.delete')]);
$router->post('/staff/{id:\d+}/restore', [\Modules\Staff\Controllers\StaffController::class, 'restore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id:\d+}/permanent-delete', [\Modules\Staff\Controllers\StaffController::class, 'permanentDelete'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.delete')]);
$router->post('/staff/{id}/schedules', [\Modules\Staff\Controllers\StaffController::class, 'scheduleStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id}/schedules/{scheduleId:\d+}/delete', [\Modules\Staff\Controllers\StaffController::class, 'scheduleDelete'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id}/breaks', [\Modules\Staff\Controllers\StaffController::class, 'breakStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
$router->post('/staff/{id}/breaks/{breakId:\d+}/delete', [\Modules\Staff\Controllers\StaffController::class, 'breakDelete'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('staff.edit')]);
