<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\PermissionMiddleware;

$router->get('/intake/templates', [\Modules\Intake\Controllers\IntakeAdminController::class, 'templatesIndex'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.view')]);
$router->get('/intake/templates/create', [\Modules\Intake\Controllers\IntakeAdminController::class, 'templatesCreate'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.edit')]);
$router->post('/intake/templates', [\Modules\Intake\Controllers\IntakeAdminController::class, 'templatesStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.edit')]);
$router->get('/intake/templates/{id:\d+}/edit', [\Modules\Intake\Controllers\IntakeAdminController::class, 'templatesEdit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.edit')]);
$router->post('/intake/templates/{id:\d+}', [\Modules\Intake\Controllers\IntakeAdminController::class, 'templatesUpdate'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.edit')]);
$router->post('/intake/templates/{id:\d+}/fields', [\Modules\Intake\Controllers\IntakeAdminController::class, 'templateFieldStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.edit')]);
$router->post('/intake/templates/{id:\d+}/fields/{fieldId:\d+}/delete', [\Modules\Intake\Controllers\IntakeAdminController::class, 'templateFieldDelete'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.edit')]);

$router->get('/intake/assign', [\Modules\Intake\Controllers\IntakeAdminController::class, 'assignForm'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.assign')]);
$router->post('/intake/assign', [\Modules\Intake\Controllers\IntakeAdminController::class, 'assignStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.assign')]);
$router->get('/intake/assignments', [\Modules\Intake\Controllers\IntakeAdminController::class, 'assignmentsIndex'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.view')]);
$router->post('/intake/assignments/{id:\d+}/cancel', [\Modules\Intake\Controllers\IntakeAdminController::class, 'assignmentCancel'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.assign')]);
$router->get('/intake/submissions/{id:\d+}', [\Modules\Intake\Controllers\IntakeAdminController::class, 'submissionShow'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('intake.view')]);

$router->get('/public/intake', [\Modules\Intake\Controllers\IntakePublicController::class, 'showForm'], []);
$router->post('/public/intake/submit', [\Modules\Intake\Controllers\IntakePublicController::class, 'submit'], [], ['csrf_exempt' => true]);
$router->get('/public/intake/thanks', [\Modules\Intake\Controllers\IntakePublicController::class, 'thanks'], []);
