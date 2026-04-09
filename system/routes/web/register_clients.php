<?php

declare(strict_types=1);

use Core\Middleware\AuthMiddleware;
use Core\Middleware\SessionEarlyReleaseMiddleware;
use Core\Middleware\GuestMiddleware;
use Core\Middleware\PermissionMiddleware;

$router->get('/clients', [\Modules\Clients\Controllers\ClientController::class, 'index'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view')]);
$router->get('/clients/phone-exists', [\Modules\Clients\Controllers\ClientController::class, 'phoneExistsCheck'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.create')]);
$router->get('/clients/merge/job-status', [\Modules\Clients\Controllers\ClientController::class, 'mergeJobStatus'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit'), SessionEarlyReleaseMiddleware::class], ['session_early_release' => true]);
$router->post('/clients/merge', [\Modules\Clients\Controllers\ClientController::class, 'mergeAction'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->get('/clients/custom-fields', [\Modules\Clients\Controllers\ClientController::class, 'customFieldsIndex'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view')]);
$router->get('/clients/custom-fields/layouts', [\Modules\Clients\Controllers\ClientController::class, 'customFieldsLayouts'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/custom-fields/layouts/save', [\Modules\Clients\Controllers\ClientController::class, 'customFieldsLayoutsSave'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/custom-fields/layouts/add-item', [\Modules\Clients\Controllers\ClientController::class, 'customFieldsLayoutsAddItem'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/custom-fields/layouts/remove-item', [\Modules\Clients\Controllers\ClientController::class, 'customFieldsLayoutsRemoveItem'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->get('/clients/custom-fields/create', [\Modules\Clients\Controllers\ClientController::class, 'customFieldsCreate'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/custom-fields', [\Modules\Clients\Controllers\ClientController::class, 'customFieldsStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/custom-fields/{id:\d+}/delete', [\Modules\Clients\Controllers\ClientController::class, 'customFieldsDestroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/custom-fields/{id:\d+}', [\Modules\Clients\Controllers\ClientController::class, 'customFieldsUpdate'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->get('/clients/registrations', [\Modules\Clients\Controllers\ClientController::class, 'registrationsIndex'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view')]);
$router->get('/clients/registrations/create', [\Modules\Clients\Controllers\ClientController::class, 'registrationsCreate'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.create')]);
$router->post('/clients/registrations', [\Modules\Clients\Controllers\ClientController::class, 'registrationsStore'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.create')]);
$router->get('/clients/registrations/{id:\d+}', [\Modules\Clients\Controllers\ClientController::class, 'registrationsShow'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view')]);
$router->post('/clients/registrations/{id:\d+}/status', [\Modules\Clients\Controllers\ClientController::class, 'registrationsUpdateStatus'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/registrations/{id:\d+}/convert', [\Modules\Clients\Controllers\ClientController::class, 'registrationsConvert'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.create')]);
$router->get('/clients/create', [\Modules\Clients\Controllers\ClientController::class, 'create'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.create')]);
$router->post('/clients', [\Modules\Clients\Controllers\ClientController::class, 'store'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.create')]);
$router->post('/clients/flags/{id:\d+}/resolve', [\Modules\Clients\Controllers\ClientController::class, 'resolveIssueFlag'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/{id:\d+}/flags', [\Modules\Clients\Controllers\ClientController::class, 'addIssueFlag'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/{id:\d+}/notes', [\Modules\Clients\Controllers\ClientController::class, 'storeNote'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/{id:\d+}/notes/{noteId:\d+}/delete', [\Modules\Clients\Controllers\ClientController::class, 'destroyNote'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->get('/clients/{id:\d+}/commentaires', [\Modules\Clients\Controllers\ClientController::class, 'commentaires'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view')]);
/*
 * Client workspace secondary tabs: require clients.view plus the same module permission as the primary UI
 * (narrower than summary-only access; avoids exposing sales/files/marketing data without those capabilities).
 *
 * | Tab route           | RBAC |
 * |---------------------|------|
 * | /appointments       | clients.view + appointments.view |
 * | /sales, /billing    | clients.view + sales.view |
 * | /photos           | clients.view + documents.view (library); upload/delete also documents.edit |
 * | /documents        | clients.view + documents.view |
 * | /mail-marketing     | clients.view + marketing.view |
 */
$router->get('/clients/{id:\d+}/appointments', [\Modules\Clients\Controllers\ClientController::class, 'appointments'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view'), \Core\Middleware\PermissionMiddleware::for('appointments.view')]);
$router->get('/clients/{id:\d+}/sales', [\Modules\Clients\Controllers\ClientController::class, 'salesTab'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view'), \Core\Middleware\PermissionMiddleware::for('sales.view')]);
$router->get('/clients/{id:\d+}/billing', [\Modules\Clients\Controllers\ClientController::class, 'billingTab'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view'), \Core\Middleware\PermissionMiddleware::for('sales.view')]);
$router->get('/clients/{id:\d+}/photos/status', [\Modules\Clients\Controllers\ClientController::class, 'clientPhotosLibraryStatus'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view'), \Core\Middleware\PermissionMiddleware::for('documents.view')]);
$router->post('/clients/{id:\d+}/photos/{imageId:\d+}/delete', [\Modules\Clients\Controllers\ClientController::class, 'deleteClientPhoto'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view'), \Core\Middleware\PermissionMiddleware::for('documents.edit')]);
$router->get('/clients/{id:\d+}/photos', [\Modules\Clients\Controllers\ClientController::class, 'photosTab'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view'), \Core\Middleware\PermissionMiddleware::for('documents.view')]);
$router->get('/clients/{id:\d+}/mail-marketing', [\Modules\Clients\Controllers\ClientController::class, 'mailMarketingTab'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view'), \Core\Middleware\PermissionMiddleware::for('marketing.view')]);
$router->get('/clients/{id:\d+}/documents', [\Modules\Clients\Controllers\ClientController::class, 'documentsTab'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view'), \Core\Middleware\PermissionMiddleware::for('documents.view')]);
$router->post('/clients/{id:\d+}/documents/upload', [\Modules\Clients\Controllers\ClientController::class, 'uploadClientDocument'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view'), \Core\Middleware\PermissionMiddleware::for('documents.edit')]);
$router->post('/clients/{id:\d+}/photos/upload', [\Modules\Clients\Controllers\ClientController::class, 'uploadClientPhoto'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view'), \Core\Middleware\PermissionMiddleware::for('documents.edit')]);
$router->post('/clients/{id:\d+}/profile-notes', [\Modules\Clients\Controllers\ClientController::class, 'updateProfileNotes'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->get('/clients/{id}', [\Modules\Clients\Controllers\ClientController::class, 'show'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.view')]);
$router->get('/clients/{id}/edit', [\Modules\Clients\Controllers\ClientController::class, 'edit'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/{id}', [\Modules\Clients\Controllers\ClientController::class, 'update'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.edit')]);
$router->post('/clients/bulk-delete', [\Modules\Clients\Controllers\ClientController::class, 'bulkDestroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.delete')]);
$router->post('/clients/{id}/delete', [\Modules\Clients\Controllers\ClientController::class, 'destroy'], [AuthMiddleware::class, \Core\Middleware\TenantProtectedRouteMiddleware::class, \Core\Middleware\PermissionMiddleware::for('clients.delete')]);
