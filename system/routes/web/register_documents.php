<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\GuestMiddleware;
use Core\Middleware\PermissionMiddleware;

$router->get('/documents/definitions', [\Modules\Documents\Controllers\DocumentController::class, 'listDefinitions'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.view')]);
$router->post('/documents/definitions', [\Modules\Documents\Controllers\DocumentController::class, 'createDefinition'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.edit')]);
$router->get('/documents/clients/{id:\d+}/consents', [\Modules\Documents\Controllers\DocumentController::class, 'listClientConsents'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.view')]);
$router->post('/documents/clients/{id:\d+}/consents/sign', [\Modules\Documents\Controllers\DocumentController::class, 'signClientConsent'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.edit')]);
$router->get('/documents/clients/{id:\d+}/consents/check', [\Modules\Documents\Controllers\DocumentController::class, 'checkClientConsents'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.view')]);
$router->post('/documents/files', [\Modules\Documents\Controllers\DocumentController::class, 'uploadDocument'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.edit')]);
$router->get('/documents/files', [\Modules\Documents\Controllers\DocumentController::class, 'listOwnerDocuments'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.view')]);
$router->get('/documents/files/{id:\d+}/download', [\Modules\Documents\Controllers\DocumentController::class, 'downloadDocument'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.view')]);
$router->get('/documents/files/{id:\d+}', [\Modules\Documents\Controllers\DocumentController::class, 'showDocument'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.view')]);
$router->post('/documents/files/{id:\d+}/relink', [\Modules\Documents\Controllers\DocumentController::class, 'relinkDocument'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.edit')]);
$router->post('/documents/files/{id:\d+}/detach', [\Modules\Documents\Controllers\DocumentController::class, 'detachDocument'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.edit')]);
$router->post('/documents/files/{id:\d+}/archive', [\Modules\Documents\Controllers\DocumentController::class, 'archiveDocument'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.edit')]);
$router->post('/documents/files/{id:\d+}/delete', [\Modules\Documents\Controllers\DocumentController::class, 'deleteDocument'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, PermissionMiddleware::for('documents.edit')]);
