<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\GuestMiddleware;
use Core\Middleware\PermissionMiddleware;
use Core\Middleware\TenantPrincipalMiddleware;
use Core\Middleware\TenantProtectedRouteMiddleware;

$router->get('/', [\Core\Router\RootController::class, 'handle'], [AuthMiddleware::class]);
$router->get('/tenant-entry', [\Modules\Auth\Controllers\TenantEntryController::class, 'resolve'], [AuthMiddleware::class, TenantPrincipalMiddleware::class]);
$router->get('/dashboard', [\Modules\Dashboard\Controllers\DashboardController::class, 'index'], [AuthMiddleware::class, TenantProtectedRouteMiddleware::class]);
$router->post('/account/branch-context', [\Modules\Auth\Controllers\BranchContextController::class, 'switch'], [AuthMiddleware::class, TenantPrincipalMiddleware::class]);
$router->get('/login', [\Modules\Auth\Controllers\LoginController::class, 'show'], [GuestMiddleware::class]);
$router->post('/login', [\Modules\Auth\Controllers\LoginController::class, 'attempt'], [GuestMiddleware::class]);
$router->get('/password/reset', [\Modules\Auth\Controllers\PasswordResetController::class, 'showRequestForm'], [GuestMiddleware::class]);
$router->post('/password/reset', [\Modules\Auth\Controllers\PasswordResetController::class, 'submitRequest'], [GuestMiddleware::class]);
$router->get('/password/reset/complete', [\Modules\Auth\Controllers\PasswordResetController::class, 'showCompleteForm'], [GuestMiddleware::class]);
$router->post('/password/reset/complete', [\Modules\Auth\Controllers\PasswordResetController::class, 'submitComplete'], [GuestMiddleware::class]);
$router->post('/logout', [\Modules\Auth\Controllers\LoginController::class, 'logout'], [AuthMiddleware::class]);
$router->post('/support-entry/stop', [\Modules\Auth\Controllers\SupportEntryController::class, 'postStop'], [AuthMiddleware::class]);
$router->get('/account/password', [\Modules\Auth\Controllers\AccountPasswordController::class, 'show'], [AuthMiddleware::class]);
$router->post('/account/password', [\Modules\Auth\Controllers\AccountPasswordController::class, 'update'], [AuthMiddleware::class]);

$router->get('/api/public/booking/slots', [\Modules\OnlineBooking\Controllers\PublicBookingController::class, 'slots'], []);
$router->post('/api/public/booking/book', [\Modules\OnlineBooking\Controllers\PublicBookingController::class, 'book'], [], ['csrf_exempt' => true]);
$router->get('/api/public/booking/consent-check', [\Modules\OnlineBooking\Controllers\PublicBookingController::class, 'consentCheck'], []);
$router->post('/api/public/booking/manage', [\Modules\OnlineBooking\Controllers\PublicBookingController::class, 'manageLookup'], [], ['csrf_exempt' => true]);
$router->post('/api/public/booking/manage/slots', [\Modules\OnlineBooking\Controllers\PublicBookingController::class, 'manageRescheduleSlots'], [], ['csrf_exempt' => true]);
$router->post('/api/public/booking/manage/cancel', [\Modules\OnlineBooking\Controllers\PublicBookingController::class, 'manageCancel'], [], ['csrf_exempt' => true]);
$router->post('/api/public/booking/manage/reschedule', [\Modules\OnlineBooking\Controllers\PublicBookingController::class, 'manageReschedule'], [], ['csrf_exempt' => true]);

$router->get('/api/public/commerce/catalog', [\Modules\PublicCommerce\Controllers\PublicCommerceController::class, 'catalog'], []);
$router->post('/api/public/commerce/purchase', [\Modules\PublicCommerce\Controllers\PublicCommerceController::class, 'initiate'], [], ['csrf_exempt' => true]);
$router->post('/api/public/commerce/purchase/finalize', [\Modules\PublicCommerce\Controllers\PublicCommerceController::class, 'finalize'], [], ['csrf_exempt' => true]);
$router->post('/api/public/commerce/purchase/status', [\Modules\PublicCommerce\Controllers\PublicCommerceController::class, 'status'], [], ['csrf_exempt' => true]);
