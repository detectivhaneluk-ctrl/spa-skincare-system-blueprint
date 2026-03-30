<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\GuestMiddleware;
use Core\Middleware\PermissionMiddleware;

$router->get('/sales', [\Modules\Sales\Controllers\SalesController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.view')]);
$router->get('/sales/register', [\Modules\Sales\Controllers\RegisterController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.view')]);
$router->post('/sales/register/open', [\Modules\Sales\Controllers\RegisterController::class, 'open'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.pay')]);
$router->post('/sales/register/{id:\d+}/close', [\Modules\Sales\Controllers\RegisterController::class, 'close'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.pay')]);
$router->post('/sales/register/{id:\d+}/movements', [\Modules\Sales\Controllers\RegisterController::class, 'move'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.pay')]);
$router->get('/sales/invoices', [\Modules\Sales\Controllers\InvoiceController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.view')]);
$router->get('/sales/invoices/create', [\Modules\Sales\Controllers\InvoiceController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.create')]);
$router->post('/sales/invoices', [\Modules\Sales\Controllers\InvoiceController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.create')]);
$router->get('/sales/invoices/{id}', [\Modules\Sales\Controllers\InvoiceController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.view')]);
$router->get('/sales/invoices/{id}/edit', [\Modules\Sales\Controllers\InvoiceController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.edit')]);
$router->post('/sales/invoices/{id}', [\Modules\Sales\Controllers\InvoiceController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.edit')]);
$router->post('/sales/invoices/{id}/cancel', [\Modules\Sales\Controllers\InvoiceController::class, 'cancel'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.edit')]);
$router->post('/sales/invoices/{id}/redeem-gift-card', [\Modules\Sales\Controllers\InvoiceController::class, 'redeemGiftCard'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.pay'), \Core\Middleware\PermissionMiddleware::for('gift_cards.redeem')]);
$router->post('/sales/invoices/{id}/delete', [\Modules\Sales\Controllers\InvoiceController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.delete')]);
$router->get('/sales/invoices/{id}/payments/create', [\Modules\Sales\Controllers\PaymentController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.pay')]);
$router->post('/sales/invoices/{id}/payments', [\Modules\Sales\Controllers\PaymentController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.pay')]);
$router->post('/sales/payments/{id:\d+}/refund', [\Modules\Sales\Controllers\PaymentController::class, 'refund'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.pay')]);
$router->get('/sales/public-commerce/awaiting-verification', [\Modules\PublicCommerce\Controllers\PublicCommerceStaffController::class, 'listAwaitingVerification'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.view')]);
$router->post('/sales/public-commerce/invoices/{invoiceId:\d+}/sync-fulfillment', [\Modules\PublicCommerce\Controllers\PublicCommerceStaffController::class, 'syncFulfillment'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('sales.pay')]);
