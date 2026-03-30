<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;

$router->get('/memberships', [\Modules\Memberships\Controllers\MembershipDefinitionController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.view')]);
$router->get('/memberships/create', [\Modules\Memberships\Controllers\MembershipDefinitionController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->post('/memberships', [\Modules\Memberships\Controllers\MembershipDefinitionController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->get('/memberships/{id:\d+}/edit', [\Modules\Memberships\Controllers\MembershipDefinitionController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->post('/memberships/{id:\d+}', [\Modules\Memberships\Controllers\MembershipDefinitionController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);

$router->get('/memberships/client-memberships', [\Modules\Memberships\Controllers\ClientMembershipController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.view')]);
$router->get('/memberships/client-memberships/assign', [\Modules\Memberships\Controllers\ClientMembershipController::class, 'assign'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->post('/memberships/client-memberships/assign', [\Modules\Memberships\Controllers\ClientMembershipController::class, 'storeAssign'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->post('/memberships/client-memberships/{id:\d+}/cancel', [\Modules\Memberships\Controllers\ClientMembershipController::class, 'cancel'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);

$router->post('/memberships/client-memberships/{id:\d+}/pause', [\Modules\Memberships\Controllers\MembershipLifecycleController::class, 'pause'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->post('/memberships/client-memberships/{id:\d+}/resume', [\Modules\Memberships\Controllers\MembershipLifecycleController::class, 'resume'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->post('/memberships/client-memberships/{id:\d+}/schedule-cancel-at-period-end', [\Modules\Memberships\Controllers\MembershipLifecycleController::class, 'scheduleCancelAtPeriodEnd'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->post('/memberships/client-memberships/{id:\d+}/revoke-scheduled-cancel', [\Modules\Memberships\Controllers\MembershipLifecycleController::class, 'revokeScheduledCancel'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);

$router->post('/memberships/sales', [\Modules\Memberships\Controllers\MembershipSaleController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);

$router->get('/memberships/refund-review', [\Modules\Memberships\Controllers\MembershipRefundReviewController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->post('/memberships/refund-review/sales/{id:\d+}/reconcile', [\Modules\Memberships\Controllers\MembershipRefundReviewController::class, 'reconcileSale'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->post('/memberships/refund-review/sales/{id:\d+}/acknowledge', [\Modules\Memberships\Controllers\MembershipRefundReviewController::class, 'acknowledgeSale'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->post('/memberships/refund-review/billing-cycles/{id:\d+}/reconcile', [\Modules\Memberships\Controllers\MembershipRefundReviewController::class, 'reconcileBillingCycle'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
$router->post('/memberships/refund-review/billing-cycles/{id:\d+}/acknowledge', [\Modules\Memberships\Controllers\MembershipRefundReviewController::class, 'acknowledgeBillingCycle'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('memberships.manage')]);
