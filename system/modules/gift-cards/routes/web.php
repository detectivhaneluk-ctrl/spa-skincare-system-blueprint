<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;

$router->get('/gift-cards', [\Modules\GiftCards\Controllers\GiftCardController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('gift_cards.view')]);
$router->post('/gift-cards/bulk-update-expires-at', [\Modules\GiftCards\Controllers\GiftCardController::class, 'bulkUpdateExpiresAt'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('gift_cards.adjust')]);
$router->get('/gift-cards/issue', [\Modules\GiftCards\Controllers\GiftCardController::class, 'issue'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('gift_cards.create')]);
$router->post('/gift-cards/issue', [\Modules\GiftCards\Controllers\GiftCardController::class, 'storeIssue'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('gift_cards.issue')]);
$router->get('/gift-cards/{id}', [\Modules\GiftCards\Controllers\GiftCardController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('gift_cards.view')]);
$router->get('/gift-cards/{id}/redeem', [\Modules\GiftCards\Controllers\GiftCardController::class, 'redeemForm'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('gift_cards.redeem')]);
$router->post('/gift-cards/{id}/redeem', [\Modules\GiftCards\Controllers\GiftCardController::class, 'redeem'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('gift_cards.redeem')]);
$router->get('/gift-cards/{id}/adjust', [\Modules\GiftCards\Controllers\GiftCardController::class, 'adjustForm'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('gift_cards.adjust')]);
$router->post('/gift-cards/{id}/adjust', [\Modules\GiftCards\Controllers\GiftCardController::class, 'adjust'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('gift_cards.adjust')]);
$router->post('/gift-cards/{id}/cancel', [\Modules\GiftCards\Controllers\GiftCardController::class, 'cancel'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('gift_cards.cancel')]);
