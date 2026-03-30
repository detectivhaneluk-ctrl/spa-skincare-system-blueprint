<?php

declare(strict_types=1);

/**
 * Read-only route smoke: client summary vs dedicated appointments surface.
 *
 * Usage (from repo root or system/):
 *   php system/scripts/read-only/smoke_client_appointments_routes_01.php
 */

use Core\Router\Router;
use Modules\Clients\Controllers\ClientController;

$scriptDir = __DIR__;
$systemPath = realpath($scriptDir . '/../..') ?: $scriptDir . '/../..';
$routerFile = $systemPath . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'register_clients.php';
$autoload = $systemPath . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'autoload.php';

if (!is_file($autoload) || !is_file($routerFile)) {
    fwrite(STDERR, "FAIL: missing autoload or register_clients.php under {$systemPath}\n");
    exit(1);
}

require_once $autoload;

$router = new Router();
$registrarRouter = $router;
require $routerFile;

$expect = static function (string $label, bool $ok): void {
    echo $label . '=' . ($ok ? 'PASS' : 'FAIL') . "\n";
    if (!$ok) {
        exit(1);
    }
};

$mShow = $router->match('GET', '/clients/42');
$mAppt = $router->match('GET', '/clients/42/appointments');
$mSales = $router->match('GET', '/clients/42/sales');
$mBilling = $router->match('GET', '/clients/42/billing');
$mPhotos = $router->match('GET', '/clients/42/photos');
$mMail = $router->match('GET', '/clients/42/mail-marketing');
$mDocs = $router->match('GET', '/clients/42/documents');
$mDocUpload = $router->match('POST', '/clients/42/documents/upload');
$mPhotoUpload = $router->match('POST', '/clients/42/photos/upload');
$mPhotoStatus = $router->match('GET', '/clients/42/photos/status');
$mPhotoDelete = $router->match('POST', '/clients/42/photos/7/delete');

$expect('match_clients_show_not_null', $mShow !== null);
$expect('match_clients_appointments_not_null', $mAppt !== null);
$expect('match_clients_sales_not_null', $mSales !== null);
$expect('match_clients_billing_not_null', $mBilling !== null);
$expect('match_clients_photos_not_null', $mPhotos !== null);
$expect('match_clients_mail_marketing_not_null', $mMail !== null);
$expect('match_clients_documents_not_null', $mDocs !== null);
$expect('match_clients_documents_upload_not_null', $mDocUpload !== null);
$expect('match_clients_photos_upload_not_null', $mPhotoUpload !== null);
$expect('match_clients_photos_status_not_null', $mPhotoStatus !== null);
$expect('match_clients_photos_delete_not_null', $mPhotoDelete !== null);

$hShow = $mShow['handler'];
$hAppt = $mAppt['handler'];

$expect(
    'handler_show_is_ClientController_show',
    is_array($hShow)
    && $hShow[0] === ClientController::class
    && $hShow[1] === 'show'
    && (string) ($mShow['params']['id'] ?? '') === '42'
);

$expect(
    'handler_appointments_is_ClientController_appointments',
    is_array($hAppt)
    && $hAppt[0] === ClientController::class
    && $hAppt[1] === 'appointments'
    && (string) ($mAppt['params']['id'] ?? '') === '42'
);

$hSales = $mSales['handler'];
$hBilling = $mBilling['handler'];
$hPhotos = $mPhotos['handler'];
$hMail = $mMail['handler'];
$hDocs = $mDocs['handler'];
$expect('handler_sales_is_salesTab', is_array($hSales) && $hSales[1] === 'salesTab');
$expect('handler_billing_is_billingTab', is_array($hBilling) && $hBilling[1] === 'billingTab');
$expect('handler_photos_is_photosTab', is_array($hPhotos) && $hPhotos[1] === 'photosTab');
$expect('handler_mail_is_mailMarketingTab', is_array($hMail) && $hMail[1] === 'mailMarketingTab');
$expect('handler_documents_is_documentsTab', is_array($hDocs) && $hDocs[1] === 'documentsTab');

$hDocUpload = $mDocUpload['handler'];
$hPhotoUpload = $mPhotoUpload['handler'];
$expect('handler_documents_upload', is_array($hDocUpload) && $hDocUpload[1] === 'uploadClientDocument');
$expect('handler_photos_upload', is_array($hPhotoUpload) && $hPhotoUpload[1] === 'uploadClientPhoto');

$hPhotoStatus = $mPhotoStatus['handler'];
$hPhotoDelete = $mPhotoDelete['handler'];
$expect('handler_photos_status', is_array($hPhotoStatus) && $hPhotoStatus[1] === 'clientPhotosLibraryStatus');
$expect('handler_photos_delete', is_array($hPhotoDelete) && $hPhotoDelete[1] === 'deleteClientPhoto'
    && (string) ($mPhotoDelete['params']['imageId'] ?? '') === '7');

$regSrc = (string) file_get_contents($routerFile);
$expect('tab_routes_require_sales_view', str_contains($regSrc, "salesTab") && str_contains($regSrc, "PermissionMiddleware::for('sales.view')"));
$expect('tab_routes_require_documents_view', str_contains($regSrc, "documentsTab") && str_contains($regSrc, "PermissionMiddleware::for('documents.view')"));

$header = (string) file_get_contents($systemPath . '/modules/clients/views/partials/client-ref-header-tabs.php');
$expect('tab_href_uses_appointments_route', str_contains($header, '$appointmentsUrl') && str_contains($header, '/appointments'));
$expect('tab_active_rdv_branch', str_contains($header, "clientRefActiveTab === 'rdv'"));
$expect('tab_sales_dedicated_route', str_contains($header, '$salesTabUrl'));
$expect('tab_documents_dedicated_route', str_contains($header, '$documentsTabUrl'));

$invCtl = (string) file_get_contents($systemPath . '/modules/sales/controllers/InvoiceController.php');
$expect('cashier_honors_client_id_get', str_contains($invCtl, "\$_GET['client_id']") && str_contains($invCtl, '$prefillClientId'));

$rdv = (string) file_get_contents($systemPath . '/modules/clients/views/partials/client-ref-rdv-workspace.php');
$expect('rdv_basepath_uses_clientRefRdvBasePath', str_contains($rdv, 'clientRefRdvBasePath'));

echo "overall=PASS\n";
