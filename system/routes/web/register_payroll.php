<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\GuestMiddleware;
use Core\Middleware\PermissionMiddleware;

$router->get('/payroll/rules', [\Modules\Payroll\Controllers\PayrollRuleController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);
$router->get('/payroll/rules/create', [\Modules\Payroll\Controllers\PayrollRuleController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);
$router->post('/payroll/rules', [\Modules\Payroll\Controllers\PayrollRuleController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);
$router->get('/payroll/rules/{id:\d+}/edit', [\Modules\Payroll\Controllers\PayrollRuleController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);
$router->post('/payroll/rules/{id:\d+}', [\Modules\Payroll\Controllers\PayrollRuleController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);

$router->get('/payroll/runs', [\Modules\Payroll\Controllers\PayrollRunController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.view')]);
$router->get('/payroll/runs/create', [\Modules\Payroll\Controllers\PayrollRunController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);
$router->post('/payroll/runs', [\Modules\Payroll\Controllers\PayrollRunController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);
$router->get('/payroll/runs/{id:\d+}', [\Modules\Payroll\Controllers\PayrollRunController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.view')]);
$router->post('/payroll/runs/{id:\d+}/calculate', [\Modules\Payroll\Controllers\PayrollRunController::class, 'calculate'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);
$router->post('/payroll/runs/{id:\d+}/reopen', [\Modules\Payroll\Controllers\PayrollRunController::class, 'reopen'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);
$router->post('/payroll/runs/{id:\d+}/lock', [\Modules\Payroll\Controllers\PayrollRunController::class, 'lock'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);
$router->post('/payroll/runs/{id:\d+}/settle', [\Modules\Payroll\Controllers\PayrollRunController::class, 'settle'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);
$router->post('/payroll/runs/{id:\d+}/delete', [\Modules\Payroll\Controllers\PayrollRunController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('payroll.manage')]);
