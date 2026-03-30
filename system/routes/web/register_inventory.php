<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\GuestMiddleware;
use Core\Middleware\PermissionMiddleware;

$router->get('/inventory', [\Modules\Inventory\Controllers\InventoryController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.view')]);

$router->get('/inventory/products', [\Modules\Inventory\Controllers\ProductController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.view')]);
$router->get('/inventory/products/create', [\Modules\Inventory\Controllers\ProductController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.create')]);
$router->post('/inventory/products', [\Modules\Inventory\Controllers\ProductController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.create')]);
$router->get('/inventory/products/{id}', [\Modules\Inventory\Controllers\ProductController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.view')]);
$router->get('/inventory/products/{id}/edit', [\Modules\Inventory\Controllers\ProductController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.edit')]);
$router->post('/inventory/products/{id}', [\Modules\Inventory\Controllers\ProductController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.edit')]);
$router->post('/inventory/products/{id}/delete', [\Modules\Inventory\Controllers\ProductController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.delete')]);

$router->get('/inventory/product-categories', [\Modules\Inventory\Controllers\ProductCategoryController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.view')]);
$router->get('/inventory/product-categories/create', [\Modules\Inventory\Controllers\ProductCategoryController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.create')]);
$router->post('/inventory/product-categories', [\Modules\Inventory\Controllers\ProductCategoryController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.create')]);
$router->get('/inventory/product-categories/{id}', [\Modules\Inventory\Controllers\ProductCategoryController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.view')]);
$router->get('/inventory/product-categories/{id}/edit', [\Modules\Inventory\Controllers\ProductCategoryController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.edit')]);
$router->post('/inventory/product-categories/{id}', [\Modules\Inventory\Controllers\ProductCategoryController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.edit')]);
$router->post('/inventory/product-categories/{id}/delete', [\Modules\Inventory\Controllers\ProductCategoryController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.delete')]);

$router->get('/inventory/product-brands', [\Modules\Inventory\Controllers\ProductBrandController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.view')]);
$router->get('/inventory/product-brands/create', [\Modules\Inventory\Controllers\ProductBrandController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.create')]);
$router->post('/inventory/product-brands', [\Modules\Inventory\Controllers\ProductBrandController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.create')]);
$router->get('/inventory/product-brands/{id}', [\Modules\Inventory\Controllers\ProductBrandController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.view')]);
$router->get('/inventory/product-brands/{id}/edit', [\Modules\Inventory\Controllers\ProductBrandController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.edit')]);
$router->post('/inventory/product-brands/{id}', [\Modules\Inventory\Controllers\ProductBrandController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.edit')]);
$router->post('/inventory/product-brands/{id}/delete', [\Modules\Inventory\Controllers\ProductBrandController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.delete')]);

$router->get('/inventory/suppliers', [\Modules\Inventory\Controllers\SupplierController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.view')]);
$router->get('/inventory/suppliers/create', [\Modules\Inventory\Controllers\SupplierController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.create')]);
$router->post('/inventory/suppliers', [\Modules\Inventory\Controllers\SupplierController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.create')]);
$router->get('/inventory/suppliers/{id}', [\Modules\Inventory\Controllers\SupplierController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.view')]);
$router->get('/inventory/suppliers/{id}/edit', [\Modules\Inventory\Controllers\SupplierController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.edit')]);
$router->post('/inventory/suppliers/{id}', [\Modules\Inventory\Controllers\SupplierController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.edit')]);
$router->post('/inventory/suppliers/{id}/delete', [\Modules\Inventory\Controllers\SupplierController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.delete')]);

$router->get('/inventory/movements', [\Modules\Inventory\Controllers\StockMovementController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.view')]);
$router->get('/inventory/movements/create', [\Modules\Inventory\Controllers\StockMovementController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.adjust')]);
$router->post('/inventory/movements', [\Modules\Inventory\Controllers\StockMovementController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.adjust')]);

$router->get('/inventory/counts', [\Modules\Inventory\Controllers\InventoryCountController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.view')]);
$router->get('/inventory/counts/create', [\Modules\Inventory\Controllers\InventoryCountController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.create')]);
$router->post('/inventory/counts', [\Modules\Inventory\Controllers\InventoryCountController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('inventory.create')]);
