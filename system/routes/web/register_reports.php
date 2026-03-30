<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\GuestMiddleware;
use Core\Middleware\PermissionMiddleware;

$router->get('/reports/revenue-summary', [\Modules\Reports\Controllers\ReportController::class, 'revenueSummary'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('reports.view')]);
$router->get('/reports/payments-by-method', [\Modules\Reports\Controllers\ReportController::class, 'paymentsByMethod'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('reports.view')]);
$router->get('/reports/refunds-summary', [\Modules\Reports\Controllers\ReportController::class, 'refundsSummary'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('reports.view')]);
$router->get('/reports/appointments-volume', [\Modules\Reports\Controllers\ReportController::class, 'appointmentsVolume'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('reports.view')]);
$router->get('/reports/new-clients', [\Modules\Reports\Controllers\ReportController::class, 'newClients'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('reports.view')]);
$router->get('/reports/staff-appointment-count', [\Modules\Reports\Controllers\ReportController::class, 'staffAppointmentCount'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('reports.view')]);
$router->get('/reports/gift-card-liability', [\Modules\Reports\Controllers\ReportController::class, 'giftCardLiability'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('reports.view')]);
$router->get('/reports/inventory-movements', [\Modules\Reports\Controllers\ReportController::class, 'inventoryMovements'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('reports.view')]);
$router->get('/reports/vat-distribution', [\Modules\Reports\Controllers\ReportController::class, 'vatDistribution'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('reports.view')]);
