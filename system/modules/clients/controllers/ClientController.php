<?php

declare(strict_types=1);

namespace Modules\Clients\Controllers;

use Core\App\Application;
use Core\App\Response;
use Core\App\SettingsService;
use Core\Errors\AccessDeniedException;
use Core\Errors\SafeDomainException;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Core\Permissions\PermissionService;
use Core\Contracts\ClientAppointmentProfileProvider;
use Core\Contracts\ClientGiftCardProfileProvider;
use Core\Contracts\ClientPackageProfileProvider;
use Core\Contracts\ClientSalesProfileProvider;
use Modules\Clients\Repositories\ClientIssueFlagRepository;
use Modules\Clients\Repositories\ClientRegistrationRequestRepository;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Clients\Services\ClientFieldCatalogService;
use Modules\Clients\Services\ClientInputValidator;
use Modules\Clients\Services\ClientIssueFlagService;
use Modules\Clients\Services\ClientMergeJobService;
use Modules\Clients\Services\ClientPageLayoutService;
use Modules\Clients\Services\ClientProfileImageService;
use Modules\Clients\Services\ClientProfileReadService;
use Modules\Clients\Services\ClientRegistrationService;
use Modules\Clients\Services\ClientService;
use Modules\Clients\Support\ClientRegistrationValidationException;

final class ClientController
{
    /** @var list<string> */
    private const CLIENT_RESUME_APPOINTMENT_STATUS_FILTERS = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];

    public function __construct(
        private ClientRepository $repo,
        private ClientService $service,
        private ClientAppointmentProfileProvider $appointmentsProfile,
        private ClientSalesProfileProvider $salesProfile,
        private ClientPackageProfileProvider $packagesProfile,
        private ClientGiftCardProfileProvider $giftCardsProfile,
        private ClientRegistrationRequestRepository $registrationRepo,
        private ClientRegistrationService $registrationService,
        private ClientIssueFlagRepository $issueFlagRepo,
        private ClientIssueFlagService $issueFlagService,
        private SettingsService $settings,
        private BranchContext $branchContext,
        private BranchDirectory $branchDirectory,
        private ClientPageLayoutService $pageLayouts,
        private ClientFieldCatalogService $fieldCatalog,
        private ClientInputValidator $inputValidator,
        private ClientProfileImageService $clientProfilePhotos,
        private ClientProfileReadService $profileRead,
        private ClientMergeJobService $clientMergeJobs,
    ) {
    }

    private function appointmentSettingsReadBranchId(): ?int
    {
        $bid = $this->branchContext->getCurrentBranchId();

        return $bid !== null && $bid > 0 ? $bid : null;
    }

    /**
     * @param list<array<string, mixed>> $customFieldDefinitions
     * @return list<string>
     */
    private function detailsLayoutKeysForForm(array $customFieldDefinitions): array
    {
        $base = $this->pageLayouts->tryDetailsLayoutKeys();

        return $this->mergeDetailsLayoutWithRequiredCustom($base, $customFieldDefinitions);
    }

    /**
     * @param list<string> $baseKeys
     * @param list<array<string, mixed>> $customFieldDefinitions
     * @return list<string>
     */
    private function mergeDetailsLayoutWithRequiredCustom(array $baseKeys, array $customFieldDefinitions): array
    {
        $extra = [];
        foreach ($customFieldDefinitions as $def) {
            if ((int) ($def['is_required'] ?? 0) !== 1) {
                continue;
            }
            $ck = $this->fieldCatalog->customFieldLayoutKey((int) $def['id']);
            if (!in_array($ck, $baseKeys, true)) {
                $extra[] = $ck;
            }
        }

        return array_merge($baseKeys, $extra);
    }

    public function index(): void
    {
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $filters = $search !== '' ? ['search' => $search] : [];
        $clients = $this->repo->list($filters, $perPage, ($page - 1) * $perPage);
        $total = $this->repo->count($filters);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($total === 0) {
            $page = 1;
        } elseif ($page > $totalPages) {
            $page = $totalPages;
            $clients = $this->repo->list($filters, $perPage, ($page - 1) * $perPage);
        }
        foreach ($clients as &$c) {
            $c['display_name'] = $this->service->getDisplayName($c);
            $c['display_phone'] = $this->service->getCanonicalPrimaryPhone($c);
        }
        unset($c);
        $sessionAuth = Application::container()->get(\Core\Auth\SessionAuth::class);
        $uid = $sessionAuth->id();
        $perm = Application::container()->get(PermissionService::class);
        $canDeepLinkMemberships = $uid !== null && $perm->has($uid, 'memberships.view');
        $canDeepLinkPackages = $uid !== null && $perm->has($uid, 'packages.view');
        $canDeepLinkGiftCards = $uid !== null && $perm->has($uid, 'gift_cards.view');
        $canDeleteClients = $uid !== null && $perm->has($uid, 'clients.delete');
        $canCreateClients = $uid !== null && $perm->has($uid, 'clients.create');
        $canMergeClients = $uid !== null && $perm->has($uid, 'clients.edit');
        $listFilters = $filters;
        $strongDupPair = null;
        $clientsDupBannerCount = 0;
        $clientsDupBannerShow = false;
        if ($this->repo->isNormalizedSearchSchemaReady()) {
            $strongDupPair = $this->repo->findFirstStrongDuplicatePair($listFilters);
            if ($strongDupPair !== null) {
                $anchor = $this->repo->find($strongDupPair['id_a']);
                if ($anchor !== null) {
                    $nameNorm = strtolower(trim((string) ($anchor['first_name'] ?? '') . ' ' . (string) ($anchor['last_name'] ?? '')));
                    $elc = (string) ($anchor['email_lc'] ?? '');
                    $pmd = (string) ($anchor['phone_mobile_digits'] ?? '');
                    $clientsDupBannerCount = $this->repo->countClientsMatchingStrongDuplicateIdentity(
                        $elc,
                        $pmd,
                        $nameNorm,
                        $listFilters
                    );
                    $clientsDupBannerShow = $clientsDupBannerCount >= 2;
                }
            }
        }
        $mergeModalPair = null;
        $mergeModalAutoOpen = false;
        if ($canMergeClients) {
            $mqA = (int) ($_GET['merge_primary'] ?? 0);
            $mqB = (int) ($_GET['merge_secondary'] ?? 0);
            if ($mqA > 0 && $mqB > 0 && $mqA !== $mqB) {
                $mergeModalPair = $this->buildMergeModalPairCards($mqA, $mqB);
                $mergeModalAutoOpen = $mergeModalPair !== null;
            } elseif ($mqA > 0) {
                $row = $this->repo->find($mqA);
                if ($row && $this->clientRowVisibleInCurrentBranchContext($row)) {
                    $crit = ['email' => null, 'phone' => null];
                    $em = trim((string) ($row['email'] ?? ''));
                    if ($em !== '') {
                        $crit['email'] = $em;
                    }
                    $ph = $this->service->getCanonicalPrimaryPhone($row);
                    if ($ph !== null && trim((string) $ph) !== '') {
                        $crit['phone'] = $ph;
                    }
                    if ($crit['email'] !== null || $crit['phone'] !== null) {
                        $dups = $this->service->findDuplicates($mqA, array_filter($crit, static fn ($v) => $v !== null));
                        foreach ($dups as $dup) {
                            $oid = (int) ($dup['id'] ?? 0);
                            if ($oid > 0 && $oid !== $mqA) {
                                $mergeModalPair = $this->buildMergeModalPairCards($mqA, $oid);
                                $mergeModalAutoOpen = $mergeModalPair !== null;
                                break;
                            }
                        }
                    }
                }
            } elseif ($strongDupPair !== null) {
                $mergeModalPair = $this->buildMergeModalPairCards($strongDupPair['id_a'], $strongDupPair['id_b']);
            }
        }
        $csrf = $sessionAuth->csrfToken();
        $flash = flash();
        require base_path('modules/clients/views/index.php');
    }

    public function bulkDestroy(): void
    {
        $action = trim((string) ($_POST['bulk_action'] ?? ''));
        if ($action !== 'delete') {
            flash('error', 'Choose a bulk action.');
            $this->redirectToClientsIndexPostContext();
            return;
        }
        $raw = $_POST['client_ids'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $idSet = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $idSet[$id] = true;
            }
        }
        $ids = array_keys($idSet);
        if ($ids === []) {
            flash('error', 'No clients selected.');
            $this->redirectToClientsIndexPostContext();
            return;
        }
        $out = $this->service->bulkDelete($ids);
        $deleted = $out['deleted'];
        $skipped = $out['skipped'];
        if ($deleted === 0) {
            flash('error', $skipped > 0 ? 'No clients could be deleted (check permissions or branch access).' : 'No clients could be deleted.');
        } elseif ($skipped === 0) {
            flash('success', $deleted === 1 ? '1 client deleted.' : "{$deleted} clients deleted.");
        } else {
            flash('warning', "{$deleted} deleted, {$skipped} skipped (not found, wrong branch, or not allowed).");
        }
        $this->redirectToClientsIndexPostContext();
    }

    /**
     * JSON: whether a normalized phone match exists (New Client inline hint). Tenant-scoped.
     */
    public function phoneExistsCheck(): void
    {
        $raw = trim((string) ($_GET['phone'] ?? ''));
        header('Content-Type: application/json; charset=utf-8');
        if ($raw === '') {
            echo json_encode(['match' => false, 'client_id' => null], JSON_UNESCAPED_UNICODE);

            return;
        }
        $rows = $this->service->findDuplicates(0, ['phone' => $raw]);
        $id = 0;
        if ($rows !== [] && isset($rows[0]['id'])) {
            $id = (int) $rows[0]['id'];
        }
        echo json_encode([
            'match' => $id > 0,
            'client_id' => $id > 0 ? $id : null,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function create(): void
    {
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $client = [];
        $marketing = $this->settings->getMarketingSettings($this->marketingSettingsReadBranchId(null));
        $customFieldDefinitions = $this->service->getCustomFieldDefinitions($this->customFieldDefinitionsBranchFilter(null), true);
        $customFieldValues = [];
        require base_path('modules/clients/views/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data, null);
        if (!empty($errors)) {
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $client = $data;
            $marketing = $this->settings->getMarketingSettings($this->marketingSettingsReadBranchId(null));
            $customFieldDefinitions = $this->service->getCustomFieldDefinitions($this->customFieldDefinitionsBranchFilter(null), true);
            $customFieldValues = is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : [];
            if ($this->isDrawerRequest()) {
                ob_start();
                require base_path('modules/clients/views/create.php');
                $html = ob_get_clean();
                $this->sendDrawerValidationHtml($html);
                return;
            }
            require base_path('modules/clients/views/create.php');
            return;
        }
        $id = $this->service->create($data);
        if ($this->isDrawerRequest()) {
            $this->sendDrawerClientCreated($id);
        }
        flash('success', 'Client created.');
        header('Location: /clients/' . $id);
        exit;
    }

    public function show(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $resumeApptFilters = $this->parseResumeAppointmentListQuery();
        $read = $this->profileRead->buildMainProfileReadModel($id, $client, $resumeApptFilters);
        $client = $read['client'];
        $shell = $read['shell'];
        $appointmentSummary = $shell['appointment_summary'];
        $recentAppointments = $shell['recent_appointments'];
        $salesSummary = $shell['sales_summary'];
        $mergedIntoId = $shell['merged_into_id'];
        $accountStatus = $shell['account_status'];
        $clientRefPrimaryPhotoUrl = $shell['primary_photo_url'] ?? null;
        $appt = $read['appointments'];
        $resumeApptList = $appt['resume_list'];
        $resumeApptStatusLabels = $appt['status_labels'];
        $perPageUsed = $appt['per_page_used'];
        $resumeApptTotalPages = $appt['total_pages'];
        $resumeApptLinkQuery = $appt['link_query'];
        $clientItineraryShowStaff = $appt['itinerary_show_staff'];
        $clientItineraryShowSpace = $appt['itinerary_show_space'];
        $resumeAddAppointmentUrl = $appt['add_appointment_url'];
        $duplicates = $read['duplicates'];
        $duplicateSearch = $read['duplicate_search'];
        $cf = $read['custom_fields'];
        $customFieldDefinitions = $cf['definitions'];
        $customFieldValuesRows = $cf['values'];
        $activeIssueFlags = $read['flags']['active_open'];
        $recentInvoices = $read['commerce']['recent_invoices'];
        $recentPayments = $read['commerce']['recent_payments'];
        $packageSummary = $read['packages']['summary'];
        $recentPackages = $read['packages']['recent'];
        $giftCardSummary = $read['gift_cards']['summary'];
        $recentGiftCards = $read['gift_cards']['recent'];
        $membershipSummary = $read['memberships']['summary'];
        $recentMemberships = $read['memberships']['recent'];
        $clientHistory = $read['audit']['history'];
        $perms = $read['permissions'];
        $canEditClients = $perms['can_edit_clients'];
        $canCreateAppointments = $perms['can_create_appointments'];
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $fieldCatalog = $this->fieldCatalog;
        $sidebarLayoutKeys = $read['layout']['sidebar_layout_keys'];
        $customFieldValues = $customFieldValuesRows;
        require base_path('modules/clients/views/show.php');
    }

    public function appointments(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $shell = $this->clientShellViewData($id, $client);
        $client = $shell['client'];
        $appointmentSummary = $shell['appointmentSummary'];
        $recentAppointments = $shell['recentAppointments'];
        $salesSummary = $shell['salesSummary'];
        $mergedIntoId = $shell['mergedIntoId'];
        $accountStatus = $shell['accountStatus'];
        $clientRefPrimaryPhotoUrl = $shell['clientRefPrimaryPhotoUrl'] ?? null;
        $resumeApptFilters = $this->parseResumeAppointmentListQuery();
        $resumeApptList = $this->appointmentsProfile->listForClientProfile($id, $resumeApptFilters);
        $resumeApptStatusLabels = $this->clientResumeAppointmentStatusLabels();
        $perPageUsed = max(1, (int) ($resumeApptList['per_page'] ?? 15));
        $resumeApptTotalPages = max(1, (int) ceil((int) ($resumeApptList['total'] ?? 0) / $perPageUsed));
        $resumeApptLinkQuery = $this->buildResumeAppointmentQueryForLinks($resumeApptFilters, $perPageUsed);
        $appointmentUi = $this->settings->getAppointmentSettings($this->appointmentSettingsReadBranchId());
        $clientItineraryShowStaff = (bool) ($appointmentUi['client_itinerary_show_staff'] ?? true);
        $clientItineraryShowSpace = (bool) ($appointmentUi['client_itinerary_show_space'] ?? false);
        $session = Application::container()->get(\Core\Auth\SessionAuth::class);
        $uid = $session->id();
        $canCreateAppointments = $uid !== null && Application::container()->get(PermissionService::class)->has($uid, 'appointments.create');
        $resumeAddAppointmentUrl = null;
        if ($canCreateAppointments) {
            $bid = (int) ($client['branch_id'] ?? 0);
            if ($bid <= 0) {
                $cb = $this->branchContext->getCurrentBranchId();
                $bid = ($cb !== null && $cb > 0) ? $cb : 0;
            }
            $resumeAddAppointmentUrl = '/appointments/create?client_id=' . $id . ($bid > 0 ? '&branch_id=' . $bid : '');
        }
        $csrf = $session->csrfToken();
        $fieldCatalog = $this->fieldCatalog;
        $sidebarLayoutKeys = $this->pageLayouts->trySidebarLayoutKeys();
        $customFieldDefinitions = $this->service->getCustomFieldDefinitions($this->customFieldDefinitionsBranchFilter($client), true);
        $customFieldValues = $this->service->getClientCustomFieldValuesMap($id);
        $clientRefRdvBasePath = '/clients/' . $id . '/appointments';
        require base_path('modules/clients/views/appointments.php');
    }

    /**
     * Shared shell variables for dedicated client tab pages (read-only surfaces).
     *
     * @return array<string, mixed>|null
     */
    private function clientTabPageData(int $id): ?array
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return null;
        }
        if (!$this->ensureBranchAccess($client)) {
            return null;
        }
        $shell = $this->clientShellViewData($id, $client);
        $c = $shell['client'];

        return [
            'client' => $c,
            'clientId' => $id,
            'appointmentSummary' => $shell['appointmentSummary'],
            'recentAppointments' => $shell['recentAppointments'],
            'salesSummary' => $shell['salesSummary'],
            'mergedIntoId' => $shell['mergedIntoId'],
            'accountStatus' => $shell['accountStatus'],
            'clientRefPrimaryPhotoUrl' => $shell['clientRefPrimaryPhotoUrl'] ?? null,
            'csrf' => Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken(),
            'fieldCatalog' => $this->fieldCatalog,
            'sidebarLayoutKeys' => $this->pageLayouts->trySidebarLayoutKeys(),
            'customFieldDefinitions' => $this->service->getCustomFieldDefinitions($this->customFieldDefinitionsBranchFilter($c), true),
            'customFieldValues' => $this->service->getClientCustomFieldValuesMap($id),
        ];
    }

    public function salesTab(int $id): void
    {
        $base = $this->clientTabPageData($id);
        if ($base === null) {
            return;
        }
        extract($base, EXTR_OVERWRITE);
        $invoiceNeedle = trim((string) ($_GET['sales_invoice'] ?? ''));
        $invoiceNeedle = $invoiceNeedle !== '' ? mb_substr($invoiceNeedle, 0, 80) : '';
        $fromYmd = trim((string) ($_GET['sales_date_from'] ?? ''));
        $toYmd = trim((string) ($_GET['sales_date_to'] ?? ''));
        $fromYmd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromYmd) ? $fromYmd : '';
        $toYmd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toYmd) ? $toYmd : '';
        if ($fromYmd !== '' && $toYmd !== '' && $fromYmd > $toYmd) {
            [$fromYmd, $toYmd] = [$toYmd, $fromYmd];
        }
        $page = max(1, (int) ($_GET['sales_page'] ?? 1));
        $perPage = max(1, min(50, (int) ($_GET['sales_per_page'] ?? 15)));
        $fromParam = $fromYmd !== '' ? $fromYmd : null;
        $toParam = $toYmd !== '' ? $toYmd : null;
        $pageResult = $this->salesProfile->listInvoicesForClientFiltered(
            $id,
            $invoiceNeedle,
            $fromParam,
            $toParam,
            $page,
            $perPage
        );
        $totalFiltered = (int) ($pageResult['total'] ?? 0);
        $totalPages = max(1, (int) ceil($totalFiltered / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $pageResult = $this->salesProfile->listInvoicesForClientFiltered(
                $id,
                $invoiceNeedle,
                $fromParam,
                $toParam,
                $page,
                $perPage
            );
            $totalFiltered = (int) ($pageResult['total'] ?? 0);
        }
        $salesInvoicePageRows = $pageResult['rows'] ?? [];
        $salesInvoiceFilters = [
            'invoice' => $invoiceNeedle,
            'date_from' => $fromYmd,
            'date_to' => $toYmd,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $totalFiltered,
            'total_pages' => $totalPages,
        ];
        $session = Application::container()->get(\Core\Auth\SessionAuth::class);
        $uid = $session->id();
        $canCreateSale = $uid !== null && Application::container()->get(PermissionService::class)->has($uid, 'sales.create');
        $salesNewInvoiceUrl = '/sales/invoices/create?client_id=' . $id;
        $salesProductLines = $this->salesProfile->listRecentProductInvoiceLines($id, 20);
        $clientRefActiveTab = 'sales';
        $clientRefSidebarContactCard = true;
        $salesTabBasePath = '/clients/' . $id . '/sales';
        require base_path('modules/clients/views/sales.php');
    }

    public function billingTab(int $id): void
    {
        $base = $this->clientTabPageData($id);
        if ($base === null) {
            return;
        }
        extract($base, EXTR_OVERWRITE);
        $recentPayments = $this->salesProfile->listRecentPayments($id, 15);
        $invoiceFilterName = trim((string) $client['first_name'] . ' ' . (string) $client['last_name']);
        $billingInvoicesListUrl = '/sales/invoices?client_name=' . rawurlencode($invoiceFilterName);
        $clientRefActiveTab = 'billing';
        $clientRefSidebarContactCard = true;
        require base_path('modules/clients/views/billing.php');
    }

    public function photosTab(int $id): void
    {
        $base = $this->clientTabPageData($id);
        if ($base === null) {
            return;
        }
        extract($base, EXTR_OVERWRITE);
        $clientProfilePhotoLibraryReady = $this->clientProfilePhotos->isLibraryStorageReady();
        $clientProfilePhotoMediaReady = $this->clientProfilePhotos->isMediaBackedUploadReady();
        $clientProfilePhotoImages = [];
        $clientPhotosError = null;
        $branchId = (int) ($client['branch_id'] ?? 0);
        if ($branchId <= 0) {
            $cb = $this->branchContext->getCurrentBranchId();
            $branchId = $cb !== null && $cb > 0 ? (int) $cb : 0;
        }
        if ($clientProfilePhotoLibraryReady && $branchId > 0) {
            try {
                $clientProfilePhotoImages = $this->clientProfilePhotos->listImages($id, $branchId);
            } catch (\Throwable $e) {
                $clientPhotosError = 'Could not load client photo library.';
                slog('error', 'clients.photos_tab.profile_images', $e->getMessage(), ['client_id' => $id]);
            }
        } elseif (!$clientProfilePhotoLibraryReady) {
            $clientPhotosError = null;
        } elseif ($branchId <= 0) {
            $clientPhotosError = 'Select a branch to load client photos for this workspace.';
        }
        $session = Application::container()->get(\Core\Auth\SessionAuth::class);
        $uid = $session->id();
        $canUploadClientPhotos = $uid !== null && Application::container()->get(PermissionService::class)->has($uid, 'documents.edit');
        $clientPhotosStatusUrl = '/clients/' . $id . '/photos/status';
        $clientRefActiveTab = 'photos';
        $clientRefSidebarContactCard = true;
        require base_path('modules/clients/views/photos.php');
    }

    /**
     * Comma-separated positive image ids for photo status polling (delta mode). Empty = full library refresh.
     *
     * @return list<int>
     */
    private function parseClientPhotoPollIdsQuery(): array
    {
        $raw = trim((string) ($_GET['ids'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/\s*,\s*/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return [];
        }
        $out = [];
        foreach ($parts as $p) {
            $n = (int) $p;
            if ($n > 0) {
                $out[$n] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * JSON polling for client photo library (delta when ?ids=… is present; full refresh when ids omitted).
     */
    public function clientPhotosLibraryStatus(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', 'Client not found.');

            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $branchId = (int) ($client['branch_id'] ?? 0);
        if ($branchId <= 0) {
            $cb = $this->branchContext->getCurrentBranchId();
            $branchId = $cb !== null && $cb > 0 ? (int) $cb : 0;
        }
        if ($branchId <= 0) {
            Response::jsonPublicApiError(403, 'FORBIDDEN', 'Branch context required.', ['reason' => 'branch_required']);

            return;
        }
        if (!$this->clientProfilePhotos->isLibraryStorageReady()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['poll_mode' => 'full', 'removed_image_ids' => [], 'images' => [], 'worker_hint' => [
                'worker_process_detected' => 'unknown',
                'probable_block_reason' => 'unknown',
                'block_detail' => '',
                'operator_command' => 'php scripts/dev-only/run_media_image_worker_loop.php',
                'large_fifo_backlog' => false,
                'max_pending_jobs_ahead' => 0,
                'stale_processing_rows_ahead_non_blocking' => 0,
                'processing_now_count' => 0,
                'spawn_last' => null,
                'drain_last' => [
                    'ok' => null,
                    'reason' => null,
                    'detail' => null,
                    'asset_id' => null,
                    'job_id' => null,
                ],
                'resolved_cli_php_binary' => null,
                'resolved_cli_php_source' => 'none',
                'resolved_node_binary' => null,
                'resolved_node_source' => 'none',
                'app_env' => (string) env('APP_ENV', 'production'),
            ]], JSON_UNESCAPED_UNICODE);

            return;
        }
        $requestedPollIds = $this->parseClientPhotoPollIdsQuery();
        $payload = $this->clientProfilePhotos->buildClientPhotoPollStatusPayload($id, $branchId, $requestedPollIds);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public function mailMarketingTab(int $id): void
    {
        $base = $this->clientTabPageData($id);
        if ($base === null) {
            return;
        }
        extract($base, EXTR_OVERWRITE);
        $marketingOptIn = (int) ($client['marketing_opt_in'] ?? 0) === 1;
        $marketingListMemberships = [];
        $marketingRecipientRows = [];
        $marketingListError = null;
        $marketingRecipientError = null;
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || (int) $branchId <= 0) {
            $marketingListError = 'Select a branch to load contact list membership for this workspace.';
        } else {
            try {
                $listRepo = Application::container()->get(\Modules\Marketing\Repositories\MarketingContactListRepository::class);
                $marketingListMemberships = $listRepo->listMembershipsForClient($id, (int) $branchId);
            } catch (\Throwable $e) {
                $marketingListError = 'Could not load contact list membership.';
                slog('error', 'clients.mail_marketing_tab', $e->getMessage(), ['client_id' => $id]);
            }
            try {
                $recRepo = Application::container()->get(\Modules\Marketing\Repositories\MarketingCampaignRecipientRepository::class);
                $marketingRecipientRows = $recRepo->listRecentForClient($id, 25);
            } catch (\Throwable $e) {
                $marketingRecipientError = 'Could not load campaign recipient history.';
                slog('error', 'clients.mail_marketing_tab.recipients', $e->getMessage(), ['client_id' => $id]);
            }
        }
        $clientRefActiveTab = 'mail_marketing';
        $clientRefSidebarContactCard = true;
        require base_path('modules/clients/views/mail-marketing.php');
    }

    public function documentsTab(int $id): void
    {
        $base = $this->clientTabPageData($id);
        if ($base === null) {
            return;
        }
        extract($base, EXTR_OVERWRITE);
        $clientOwnedFileRows = [];
        $clientDocumentsError = null;
        try {
            $docService = Application::container()->get(\Modules\Documents\Services\DocumentService::class);
            $clientOwnedFileRows = $docService->listByOwner('client', $id);
        } catch (\DomainException) {
            $clientDocumentsError = 'Could not load files linked to this client.';
        } catch (\Throwable $e) {
            $clientDocumentsError = 'Could not load files linked to this client.';
            slog('error', 'clients.documents_tab.files', $e->getMessage(), ['client_id' => $id]);
        }

        $clientConsentRows = [];
        $clientConsentsError = null;
        try {
            $consentService = Application::container()->get(\Modules\Documents\Services\ConsentService::class);
            $consentBranchId = $this->branchContext->getCurrentBranchId();
            $clientConsentRows = $consentService->listClientConsents($id, $consentBranchId);
        } catch (\Throwable $e) {
            $clientConsentsError = 'Could not load consent records.';
            slog('error', 'clients.documents_tab.consents', $e->getMessage(), ['client_id' => $id]);
        }

        $documentsApiConsentsUrl = '/documents/clients/' . $id . '/consents';
        $session = Application::container()->get(\Core\Auth\SessionAuth::class);
        $uid = $session->id();
        $canUploadClientDocuments = $uid !== null && Application::container()->get(PermissionService::class)->has($uid, 'documents.edit');
        $clientRefActiveTab = 'documents';
        $clientRefSidebarContactCard = true;
        require base_path('modules/clients/views/documents.php');
    }

    public function uploadClientDocument(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $docService = Application::container()->get(\Modules\Documents\Services\DocumentService::class);
        try {
            $docService->registerUpload($_FILES['file'] ?? [], [
                'owner_type' => 'client',
                'owner_id' => $id,
            ]);
            flash('success', 'Document uploaded.');
        } catch (\InvalidArgumentException|\DomainException $e) {
            flash('error', $this->operatorSafeErrorMessage($e));
        } catch (\Throwable) {
            flash('error', 'Upload failed.');
        }
        header('Location: /clients/' . $id . '/documents');
        exit;
    }

    public function uploadClientPhoto(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $branchId = (int) ($client['branch_id'] ?? 0);
        if ($branchId <= 0) {
            $cb = $this->branchContext->getCurrentBranchId();
            $branchId = $cb !== null && $cb > 0 ? (int) $cb : 0;
        }
        $wantsJson = $this->clientWorkspaceWantsJson();
        if ($branchId <= 0) {
            if ($wantsJson) {
                Response::jsonPublicApiError(403, 'FORBIDDEN', 'Branch context required.', ['reason' => 'branch_required']);
            }
            flash('error', 'Branch context is required.');
            header('Location: /clients/' . $id . '/photos');
            exit;
        }
        if (!$this->clientProfilePhotos->isLibraryStorageReady()) {
            if ($wantsJson) {
                Response::jsonPublicApiError(
                    409,
                    'PHOTO_LIBRARY_NOT_READY',
                    'Photo library is not ready.',
                    ['reason' => 'storage_not_ready']
                );
            }
            flash('error', 'Photo library is not ready. Contact support if this continues.');
            header('Location: /clients/' . $id . '/photos');
            exit;
        }
        if (!$this->clientProfilePhotos->isMediaBackedUploadReady()) {
            if ($wantsJson) {
                Response::jsonPublicApiError(
                    409,
                    'PHOTO_LIBRARY_NOT_READY',
                    'Photo uploads are not available on this server yet.',
                    ['reason' => 'media_pipeline_not_ready']
                );
            }
            flash('error', 'Photo uploads are not available on this server yet.');
            header('Location: /clients/' . $id . '/photos');
            exit;
        }
        $session = Application::container()->get(\Core\Auth\SessionAuth::class);
        $uid = $session->id();
        try {
            $imageId = $this->clientProfilePhotos->uploadImage(
                $branchId,
                $id,
                is_array($_FILES['image'] ?? null) ? $_FILES['image'] : [],
                isset($_POST['title']) ? (string) $_POST['title'] : null,
                $uid
            );
            if ($wantsJson) {
                $row = $this->clientProfilePhotos->presentImageRowById($imageId, $id, $branchId);
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(200);
                echo json_encode([
                    'ok' => true,
                    'message' => 'Image received. It is processing in the media pipeline; preview appears when ready.',
                    'image_id' => $imageId,
                    'image' => $row,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            flash('success', 'Image received. It is processing in the media pipeline; preview appears when ready.');
        } catch (SafeDomainException $e) {
            if ($wantsJson) {
                Response::jsonPublicApiError($e->httpStatus, $e->publicCode, $e->publicMessage);
            }
            flash('error', $e->publicMessage);
        } catch (\Throwable $e) {
            slog('error', 'clients.photo_upload.failed', $e->getMessage(), [
                'client_id' => $id,
                'branch_id' => $branchId,
            ]);
            if ($wantsJson) {
                Response::jsonPublicApiError(500, 'PHOTO_UPLOAD_FAILED', 'Image upload failed.');
            }
            flash('error', 'Image upload failed. Please try again or contact support.');
        }
        header('Location: /clients/' . $id . '/photos');
        exit;
    }

    public function deleteClientPhoto(int $id, int $imageId): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $branchId = (int) ($client['branch_id'] ?? 0);
        if ($branchId <= 0) {
            $cb = $this->branchContext->getCurrentBranchId();
            $branchId = $cb !== null && $cb > 0 ? (int) $cb : 0;
        }
        if ($branchId <= 0) {
            flash('error', 'Branch context is required.');
            header('Location: /clients/' . $id . '/photos');
            exit;
        }
        $session = Application::container()->get(\Core\Auth\SessionAuth::class);
        $uid = $session->id();
        try {
            $result = $this->clientProfilePhotos->softDeleteImage($branchId, $id, $imageId, $uid);
            $level = ($result['flash_type'] ?? 'success') === 'warning' ? 'warning' : 'success';
            flash($level, (string) ($result['flash_message'] ?? 'Photo removed.'));
        } catch (SafeDomainException $e) {
            flash('error', $e->publicMessage);
        } catch (\Throwable $e) {
            slog('error', 'clients.photo_delete.failed', $e->getMessage(), ['client_id' => $id, 'image_id' => $imageId]);
            flash('error', 'Could not remove photo. Please try again or contact support.');
        }
        header('Location: /clients/' . $id . '/photos');
        exit;
    }

    private function clientWorkspaceWantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest';
    }

    public function storeNote(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $content = (string) ($_POST['content'] ?? '');
        try {
            $this->service->addClientNote($id, $content);
            flash('success', 'Note added.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $this->operatorSafeErrorMessage($e));
        } catch (\Throwable) {
            flash('error', 'Could not add note.');
        }
        header('Location: /clients/' . $id . '/commentaires');
        exit;
    }

    public function destroyNote(int $id, int $noteId): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        try {
            $this->service->deleteClientNote($id, $noteId);
            flash('success', 'Note removed.');
        } catch (\RuntimeException) {
            flash('error', 'Could not remove note.');
        } catch (\Throwable) {
            flash('error', 'Could not remove note.');
        }
        header('Location: /clients/' . $id . '/commentaires');
        exit;
    }

    public function commentaires(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $shell = $this->clientShellViewData($id, $client);
        $client = $shell['client'];
        $appointmentSummary = $shell['appointmentSummary'];
        $recentAppointments = $shell['recentAppointments'];
        $salesSummary = $shell['salesSummary'];
        $mergedIntoId = $shell['mergedIntoId'];
        $accountStatus = $shell['accountStatus'];
        $clientRefPrimaryPhotoUrl = $shell['clientRefPrimaryPhotoUrl'] ?? null;
        $clientNotes = $this->repo->listNotes($id, 20);
        $session = Application::container()->get(\Core\Auth\SessionAuth::class);
        $uid = $session->id();
        $canEditClients = $uid !== null && Application::container()->get(PermissionService::class)->has($uid, 'clients.edit');
        $csrf = $session->csrfToken();
        $fieldCatalog = $this->fieldCatalog;
        $sidebarLayoutKeys = $this->pageLayouts->trySidebarLayoutKeys();
        $customFieldDefinitions = $this->service->getCustomFieldDefinitions($this->customFieldDefinitionsBranchFilter($client), true);
        $customFieldValues = $this->service->getClientCustomFieldValuesMap($id);
        require base_path('modules/clients/views/commentaires.php');
    }

    public function updateProfileNotes(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        try {
            $this->service->updateProfileNotes($id, $notes);
            flash('success', 'Client notes saved.');
        } catch (\Throwable) {
            flash('error', 'Could not save client notes.');
        }
        header('Location: /clients/' . $id . '/commentaires');
        exit;
    }

    public function edit(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $shell = $this->clientShellViewData($id, $client);
        $client = $shell['client'];
        $appointmentSummary = $shell['appointmentSummary'];
        $recentAppointments = $shell['recentAppointments'];
        $salesSummary = $shell['salesSummary'];
        $mergedIntoId = $shell['mergedIntoId'];
        $accountStatus = $shell['accountStatus'];
        $clientRefPrimaryPhotoUrl = $shell['clientRefPrimaryPhotoUrl'] ?? null;
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $marketing = $this->settings->getMarketingSettings($this->marketingSettingsReadBranchId($client));
        $customFieldDefinitions = $this->service->getCustomFieldDefinitions($this->customFieldDefinitionsBranchFilter($client), true);
        $customFieldValues = $this->service->getClientCustomFieldValuesMap($id);
        $fieldCatalog = $this->fieldCatalog;
        $detailsLayoutKeys = $this->detailsLayoutKeysForForm($customFieldDefinitions);
        $sidebarLayoutKeys = $this->pageLayouts->trySidebarLayoutKeys();
        require base_path('modules/clients/views/edit.php');
    }

    public function update(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $data = $this->parseInput($client);
        $errors = $this->validate($data, $client);
        if (!empty($errors)) {
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $client = array_merge($client, $data);
            $shell = $this->clientShellViewData($id, $client);
            $client = $shell['client'];
            $appointmentSummary = $shell['appointmentSummary'];
            $recentAppointments = $shell['recentAppointments'];
            $salesSummary = $shell['salesSummary'];
            $mergedIntoId = $shell['mergedIntoId'];
            $accountStatus = $shell['accountStatus'];
            $clientRefPrimaryPhotoUrl = $shell['clientRefPrimaryPhotoUrl'] ?? null;
            $marketing = $this->settings->getMarketingSettings($this->marketingSettingsReadBranchId($client));
            $customFieldDefinitions = $this->service->getCustomFieldDefinitions($this->customFieldDefinitionsBranchFilter($client), true);
            $customFieldValues = is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : [];
            $fieldCatalog = $this->fieldCatalog;
            $detailsLayoutKeys = $this->detailsLayoutKeysForForm($customFieldDefinitions);
            $sidebarLayoutKeys = $this->pageLayouts->trySidebarLayoutKeys();
            require base_path('modules/clients/views/edit.php');
            return;
        }
        $this->service->update($id, $data);
        flash('success', 'Client updated.');
        header('Location: /clients/' . $id);
        exit;
    }

    public function destroy(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $this->service->delete($id);
        flash('success', 'Client deleted.');
        header('Location: /clients');
        exit;
    }

    public function mergeAction(): void
    {
        $primaryId = (int) ($_POST['primary_id'] ?? 0);
        $secondaryId = (int) ($_POST['secondary_id'] ?? 0);
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $jsonOut = isset($_POST['merge_response']) && (string) $_POST['merge_response'] === 'json';
        $session = Application::container()->get(\Core\Auth\SessionAuth::class);
        $uid = $session->id();

        $respondJson = static function (bool $ok, string $message, array $extra = []) use ($jsonOut): void {
            if (!$jsonOut) {
                return;
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_merge([
                'success' => $ok,
                'message' => $message,
            ], $extra), JSON_UNESCAPED_UNICODE);
            exit;
        };

        if ($uid === null) {
            if ($jsonOut) {
                http_response_code(401);
                $respondJson(false, 'You must be signed in to queue a merge.');
            }
            flash('error', 'You must be signed in to queue a merge.');
            header('Location: /clients');
            exit;
        }
        try {
            $jobId = $this->clientMergeJobs->enqueueMergeJob($primaryId, $secondaryId, $notes, $uid);
            $msg = 'Merge has been queued (job #' . $jobId . '). It completes when the merge worker runs.';
            if ($jsonOut) {
                $respondJson(true, $msg, ['job_id' => $jobId]);
            }
            flash('success', $msg . ' Use the job status JSON endpoint to refresh state.');
            header('Location: /clients');
            exit;
        } catch (SafeDomainException $e) {
            if ($jsonOut) {
                http_response_code($e->httpStatus >= 400 && $e->httpStatus < 600 ? $e->httpStatus : 400);
                $respondJson(false, $e->publicMessage);
            }
            flash('error', $e->publicMessage);
            header('Location: /clients');
            exit;
        } catch (AccessDeniedException) {
            if ($jsonOut) {
                http_response_code(403);
                $respondJson(false, 'Tenant scope is not available for this merge request.');
            }
            flash('error', 'Tenant scope is not available for this merge request.');
            header('Location: /clients');
            exit;
        } catch (\Throwable $e) {
            slog('error', 'clients.merge_action', $e->getMessage(), [
                'primary_id' => $primaryId,
                'secondary_id' => $secondaryId,
            ]);
            if ($jsonOut) {
                http_response_code(500);
                $respondJson(false, 'Merge could not be queued. Please try again or contact support.');
            }
            flash('error', 'Merge could not be queued. Please try again or contact support.');
            header('Location: /clients');
            exit;
        }
    }

    /**
     * JSON: merge job status for the current tenant (operators poll or inspect after queue).
     */
    public function mergeJobStatus(): void
    {
        $jobId = (int) ($_GET['job_id'] ?? 0);
        if ($jobId <= 0) {
            Response::jsonPublicApiError(400, 'BAD_REQUEST', 'job_id is required.');

            return;
        }
        try {
            $job = $this->clientMergeJobs->getJobForCurrentTenant($jobId);
        } catch (AccessDeniedException) {
            Response::jsonPublicApiError(403, 'FORBIDDEN', 'Tenant scope is required.');

            return;
        }
        if ($job === null) {
            Response::jsonPublicApiError(404, 'NOT_FOUND', 'Merge job not found.');

            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'job_id' => (int) ($job['id'] ?? 0),
            'status' => (string) ($job['status'] ?? ''),
            'primary_client_id' => (int) ($job['primary_client_id'] ?? 0),
            'secondary_client_id' => (int) ($job['secondary_client_id'] ?? 0),
            'error_code' => $job['error_code'] ?? null,
            'error_message_public' => $job['error_message_public'] ?? null,
            'created_at' => $job['created_at'] ?? null,
            'started_at' => $job['started_at'] ?? null,
            'finished_at' => $job['finished_at'] ?? null,
            'current_step' => $job['current_step'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }

    public function customFieldsIndex(): void
    {
        $sessionAuth = Application::container()->get(\Core\Auth\SessionAuth::class);
        $uid = $sessionAuth->id();
        $perm = Application::container()->get(PermissionService::class);
        $canEditClientFields = $uid !== null && $perm->has($uid, 'clients.edit');

        $definitions = $this->service->getCustomFieldDefinitions(null, false);
        $csrf = $sessionAuth->csrfToken();
        $flash = flash();
        $layoutStorageReady = $this->pageLayouts->isLayoutStorageReady();
        $systemCatalog = $this->fieldCatalog->systemFieldsConfigurableForLayouts();
        $systemFieldDefinitions = $this->fieldCatalog->systemFieldDefinitions();
        $humanizeFieldType = static fn (string $t): string => ClientFieldCatalogService::humanizeFieldTypeLabel($t);

        $fieldLabels = [];
        foreach ($systemFieldDefinitions as $k => $meta) {
            $fieldLabels[(string) $k] = (string) ($meta['label'] ?? $k);
        }
        $customFieldLayoutTypes = [];
        foreach ($definitions as $d) {
            $ck = $this->fieldCatalog->customFieldLayoutKey((int) $d['id']);
            $fieldLabels[$ck] = (string) ($d['label'] ?? $ck);
            $customFieldLayoutTypes[$ck] = (string) ($d['field_type'] ?? 'text');
        }

        $profiles = [];
        $selectedProfileKey = trim((string) ($_GET['profile'] ?? 'customer_details'));
        $layoutItems = [];
        $availableToAdd = [];
        $orgId = 0;
        try {
            $orgId = $this->pageLayouts->requireOrganizationId();
            $profiles = $this->pageLayouts->listProfilesForAdmin($orgId);
            $validKeys = array_map(static fn (array $p) => (string) $p['profile_key'], $profiles);
            if (!in_array($selectedProfileKey, $validKeys, true)) {
                $selectedProfileKey = 'customer_details';
            }
            $shiftField = trim((string) ($_GET['shift_field'] ?? ''));
            $shiftDir = (string) ($_GET['shift'] ?? '');
            if ($shiftField !== '' && in_array($shiftDir, ['up', 'down'], true)) {
                if (!$layoutStorageReady) {
                    flash('error', ClientPageLayoutService::LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE);
                } elseif ($canEditClientFields) {
                    try {
                        $this->pageLayouts->shiftItemPosition($orgId, $selectedProfileKey, $shiftField, $shiftDir);
                        flash('success', 'Field order updated.');
                    } catch (\Throwable $e) {
                        slog('error', 'clients.layout_shift', $e->getMessage(), ['profile' => $selectedProfileKey]);
                        flash('error', $this->operatorSafeErrorMessage($e));
                    }
                }
                header('Location: /clients/custom-fields?profile=' . rawurlencode($selectedProfileKey));
                exit;
            }
            $layoutItems = $layoutStorageReady
                ? $this->pageLayouts->listLayoutItemsForComposer($orgId, $selectedProfileKey, $canEditClientFields)
                : [];
            $catalogKeys = array_keys($systemFieldDefinitions);
            $assigned = array_map(static fn (array $r) => (string) $r['field_key'], $layoutItems);
            foreach ($definitions as $d) {
                $catalogKeys[] = $this->fieldCatalog->customFieldLayoutKey((int) $d['id']);
            }
            $availableToAdd = $layoutStorageReady
                ? array_values(array_filter($catalogKeys, static fn (string $k) => !in_array($k, $assigned, true)))
                : [];
        } catch (\Throwable) {
            $profiles = [];
            $layoutItems = [];
            $availableToAdd = [];
        }

        $intakeImmutableKeys = ($selectedProfileKey ?? '') === 'customer_details'
            ? $this->fieldCatalog->customerDetailsImmutablePrefixKeys()
            : [];

        require base_path('modules/clients/views/custom-fields-composer.php');
    }

    public function customFieldsLayouts(): void
    {
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $target = '/clients/custom-fields' . ($qs !== '' ? '?' . $qs : '');
        header('Location: ' . $target, true, 302);
        exit;
    }

    public function customFieldsLayoutsSave(): void
    {
        $profileKey = '';
        try {
            $orgId = $this->pageLayouts->requireOrganizationId();
            if (!$this->pageLayouts->isLayoutStorageReady()) {
                throw new \DomainException(ClientPageLayoutService::LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE);
            }
            $profileKey = trim((string) ($_POST['profile_key'] ?? ''));
            if ($profileKey === '') {
                throw new \InvalidArgumentException('profile_key is required.');
            }
            $items = $_POST['items'] ?? [];
            if (!is_array($items)) {
                $items = [];
            }
            $rows = [];
            foreach ($items as $fkRaw => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $fk = trim((string) ($row['field_key'] ?? $fkRaw));
                if ($fk === '') {
                    continue;
                }
                $rows[] = [
                    'field_key' => $fk,
                    'position' => (int) ($row['position'] ?? 0),
                    'is_enabled' => !empty($row['is_enabled']) ? 1 : 0,
                    'display_label' => ($dl = trim((string) ($row['display_label'] ?? ''))) !== '' ? $dl : null,
                    'is_required' => array_key_exists('is_required', $row)
                        ? (!empty($row['is_required']) ? 1 : 0)
                        : null,
                ];
            }
            usort($rows, static fn (array $a, array $b) => $a['position'] <=> $b['position']);
            $pos = 0;
            foreach ($rows as &$r) {
                $r['position'] = $pos++;
            }
            unset($r);
            $this->pageLayouts->saveLayout($orgId, $profileKey, $rows);
            flash('success', 'Page layout saved.');
        } catch (\Throwable $e) {
            slog('error', 'clients.layout_save', $e->getMessage(), []);
            flash('error', $this->operatorSafeErrorMessage($e));
        }
        $pk = $profileKey !== '' ? $profileKey : 'customer_details';
        header('Location: /clients/custom-fields?profile=' . rawurlencode($pk));
        exit;
    }

    public function customFieldsLayoutsAddItem(): void
    {
        $profileKey = '';
        try {
            $orgId = $this->pageLayouts->requireOrganizationId();
            if (!$this->pageLayouts->isLayoutStorageReady()) {
                throw new \DomainException(ClientPageLayoutService::LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE);
            }
            $profileKey = trim((string) ($_POST['profile_key'] ?? ''));
            $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
            if ($profileKey === '' || $fieldKey === '') {
                throw new \InvalidArgumentException('profile_key and field_key are required.');
            }
            $items = $this->pageLayouts->listLayoutItems($orgId, $profileKey);
            foreach ($items as $it) {
                if ((string) $it['field_key'] === $fieldKey) {
                    throw new \InvalidArgumentException('Field is already on this layout.');
                }
            }
            $maxPos = 0;
            foreach ($items as $it) {
                $maxPos = max($maxPos, (int) ($it['position'] ?? 0));
            }
            $rows = [];
            foreach ($items as $it) {
                $rows[] = $this->pageLayouts->layoutRowFromStoredItem($it, (int) $it['position']);
            }
            $rows[] = [
                'field_key' => $fieldKey,
                'position' => $maxPos + 1,
                'is_enabled' => 1,
                'display_label' => null,
                'is_required' => null,
            ];
            usort($rows, static fn (array $a, array $b) => $a['position'] <=> $b['position']);
            $p = 0;
            foreach ($rows as &$r) {
                $r['position'] = $p++;
            }
            unset($r);
            $this->pageLayouts->saveLayout($orgId, $profileKey, $rows);
            flash('success', 'Field added to layout.');
        } catch (\Throwable $e) {
            slog('error', 'clients.layout_add_item', $e->getMessage(), []);
            flash('error', $this->operatorSafeErrorMessage($e));
        }
        $pk = $profileKey !== '' ? $profileKey : 'customer_details';
        header('Location: /clients/custom-fields?profile=' . rawurlencode($pk));
        exit;
    }

    public function customFieldsLayoutsRemoveItem(): void
    {
        $profileKey = '';
        try {
            $orgId = $this->pageLayouts->requireOrganizationId();
            if (!$this->pageLayouts->isLayoutStorageReady()) {
                throw new \DomainException(ClientPageLayoutService::LAYOUT_STORAGE_REQUIRES_MIGRATION_MESSAGE);
            }
            $profileKey = trim((string) ($_POST['profile_key'] ?? ''));
            $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
            if ($profileKey === '' || $fieldKey === '') {
                throw new \InvalidArgumentException('profile_key and field_key are required.');
            }
            if ($profileKey === 'customer_details' && $this->fieldCatalog->isCustomerDetailsImmutableKey($fieldKey)) {
                throw new \InvalidArgumentException('Cannot remove core intake fields from the customer details layout.');
            }
            $items = $this->pageLayouts->listLayoutItems($orgId, $profileKey);
            $rows = [];
            foreach ($items as $it) {
                if ((string) $it['field_key'] === $fieldKey) {
                    continue;
                }
                $rows[] = $this->pageLayouts->layoutRowFromStoredItem($it, (int) $it['position']);
            }
            usort($rows, static fn (array $a, array $b) => $a['position'] <=> $b['position']);
            $p = 0;
            foreach ($rows as &$r) {
                $r['position'] = $p++;
            }
            unset($r);
            $this->pageLayouts->saveLayout($orgId, $profileKey, $rows);
            flash('success', 'Field removed from layout.');
        } catch (\Throwable $e) {
            slog('error', 'clients.layout_remove_item', $e->getMessage(), []);
            flash('error', $this->operatorSafeErrorMessage($e));
        }
        $pk = $profileKey !== '' ? $profileKey : 'customer_details';
        header('Location: /clients/custom-fields?profile=' . rawurlencode($pk));
        exit;
    }

    public function customFieldsDestroy(int $id): void
    {
        try {
            $this->service->deleteCustomFieldDefinition($id);
            flash('success', 'Custom field removed.');
        } catch (\Throwable $e) {
            slog('error', 'clients.custom_field_delete', $e->getMessage(), ['id' => $id]);
            flash('error', $this->operatorSafeErrorMessage($e));
        }
        header('Location: /clients/custom-fields');
        exit;
    }

    public function customFieldsCreate(): void
    {
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $field = ['field_type' => 'text', 'is_active' => 1, 'is_required' => 0, 'sort_order' => 0];
        require base_path('modules/clients/views/custom-fields-create.php');
    }

    public function customFieldsStore(): void
    {
        $payload = [
            'field_key' => trim((string) ($_POST['field_key'] ?? '')),
            'label' => trim((string) ($_POST['label'] ?? '')),
            'field_type' => trim((string) ($_POST['field_type'] ?? 'text')),
            'options_json' => trim((string) ($_POST['options_json'] ?? '')) ?: null,
            'is_required' => isset($_POST['is_required']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
        try {
            $this->service->createCustomFieldDefinition($payload);
            flash('success', 'Custom field created.');
            header('Location: /clients/custom-fields');
            exit;
        } catch (\Throwable $e) {
            slog('error', 'clients.custom_field_create', $e->getMessage(), []);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $errors = ['_general' => $this->operatorSafeErrorMessage($e)];
            $field = $payload;
            require base_path('modules/clients/views/custom-fields-create.php');
        }
    }

    public function customFieldsUpdate(int $id): void
    {
        $payload = [
            'label' => trim((string) ($_POST['label'] ?? '')),
            'field_type' => trim((string) ($_POST['field_type'] ?? 'text')),
            'options_json' => trim((string) ($_POST['options_json'] ?? '')) ?: null,
            'is_required' => isset($_POST['is_required']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
        try {
            $this->service->updateCustomFieldDefinition($id, $payload);
            flash('success', 'Custom field updated.');
        } catch (\Throwable $e) {
            slog('error', 'clients.custom_field_update', $e->getMessage(), ['id' => $id]);
            flash('error', $this->operatorSafeErrorMessage($e));
        }
        header('Location: /clients/custom-fields');
        exit;
    }

    public function registrationsIndex(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $filters = [
            'status' => trim((string) ($_GET['status'] ?? '')),
            'source' => trim((string) ($_GET['source'] ?? '')),
            'branch_id' => trim((string) ($_GET['branch_id'] ?? '')),
        ];
        $registrations = $this->registrationRepo->list($filters, $perPage, ($page - 1) * $perPage);
        $total = $this->registrationRepo->count($filters);
        $sources = $this->registrationSources();
        $statusOptions = ['new', 'reviewed', 'converted', 'rejected'];
        $branches = $this->getBranches();
        $flash = flash();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/clients/views/registrations-index.php');
    }

    public function registrationsCreate(): void
    {
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $registration = [
            'status' => 'new',
            'source' => 'manual',
        ];
        $sources = $this->registrationSources();
        $branches = $this->getBranches();
        require base_path('modules/clients/views/registrations-create.php');
    }

    public function registrationsStore(): void
    {
        $payload = [
            'branch_id' => trim((string) ($_POST['branch_id'] ?? '')) ?: null,
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'email' => trim((string) ($_POST['email'] ?? '')) ?: null,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            'source' => trim((string) ($_POST['source'] ?? 'manual')),
            'status' => 'new',
        ];
        try {
            $id = $this->registrationService->create($payload);
            flash('success', 'Registration request created.');
            header('Location: /clients/registrations/' . $id);
            exit;
        } catch (ClientRegistrationValidationException $e) {
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $errors = $e->errors;
            if (!isset($errors['_general']) && $errors !== []) {
                $errors['_general'] = implode(' ', array_values($errors));
            }
            $registration = $payload;
            $sources = $this->registrationSources();
            $branches = $this->getBranches();
            require base_path('modules/clients/views/registrations-create.php');
        } catch (\Throwable $e) {
            slog('error', 'clients.registration_create', $e->getMessage(), []);
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $errors = ['_general' => $this->operatorSafeErrorMessage($e)];
            $registration = $payload;
            $sources = $this->registrationSources();
            $branches = $this->getBranches();
            require base_path('modules/clients/views/registrations-create.php');
        }
    }

    public function registrationsShow(int $id): void
    {
        $registration = $this->registrationRepo->find($id);
        if (!$registration) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($registration)) {
            return;
        }
        $search = trim((string) ($_GET['client_search'] ?? ''));
        $clients = $search !== '' ? $this->repo->list(['search' => $search], 20, 0) : [];
        $sources = $this->registrationSources();
        $statusOptions = ['new', 'reviewed', 'converted', 'rejected'];
        $branches = $this->getBranches();
        $flash = flash();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/clients/views/registrations-show.php');
    }

    public function registrationsUpdateStatus(int $id): void
    {
        $status = trim((string) ($_POST['status'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        try {
            $this->registrationService->updateStatus($id, $status, $notes);
            flash('success', 'Registration status updated.');
        } catch (\Throwable $e) {
            slog('error', 'clients.registration_status', $e->getMessage(), ['id' => $id]);
            flash('error', $this->operatorSafeErrorMessage($e));
        }
        header('Location: /clients/registrations/' . $id);
        exit;
    }

    public function registrationsConvert(int $id): void
    {
        $existingClientId = (int) ($_POST['existing_client_id'] ?? 0);
        if ($existingClientId <= 0) {
            $existingClientId = null;
        }
        try {
            $clientId = $this->registrationService->convert($id, $existingClientId);
            flash('success', 'Registration converted to client profile.');
            header('Location: /clients/' . $clientId);
            exit;
        } catch (\Throwable $e) {
            slog('error', 'clients.registration_convert', $e->getMessage(), ['id' => $id]);
            flash('error', $this->operatorSafeErrorMessage($e));
            header('Location: /clients/registrations/' . $id);
            exit;
        }
    }

    public function addIssueFlag(int $id): void
    {
        $client = $this->repo->find($id);
        if (!$client) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($client)) {
            return;
        }
        $payload = [
            'client_id' => $id,
            'branch_id' => trim((string) ($_POST['branch_id'] ?? '')) ?: ($client['branch_id'] ?? null),
            'type' => trim((string) ($_POST['type'] ?? '')),
            'title' => trim((string) ($_POST['title'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
        try {
            $this->issueFlagService->create($payload);
            flash('success', 'Issue flag created.');
        } catch (\Throwable $e) {
            slog('error', 'clients.issue_flag_create', $e->getMessage(), ['client_id' => $id]);
            flash('error', $this->operatorSafeErrorMessage($e));
        }
        header('Location: /clients/' . $id);
        exit;
    }

    public function resolveIssueFlag(int $id): void
    {
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $redirectClientId = (int) ($_POST['client_id'] ?? 0);
        try {
            $this->issueFlagService->resolve($id, $notes);
            flash('success', 'Issue flag resolved.');
        } catch (\Throwable $e) {
            slog('error', 'clients.issue_flag_resolve', $e->getMessage(), ['flag_id' => $id]);
            flash('error', $this->operatorSafeErrorMessage($e));
        }
        if ($redirectClientId > 0) {
            header('Location: /clients/' . $redirectClientId);
            exit;
        }
        header('Location: /clients');
        exit;
    }

    /**
     * Branch id for merged marketing settings reads via {@see SettingsService::getMarketingSettings}.
     * When a client row is available with a positive branch_id, that wins; otherwise the current request branch from {@see BranchContext}.
     */
    /**
     * Shared client workspace shell: display name, appointment meta inputs, sidebar metrics.
     *
     * @param array<string, mixed> $client
     * @return array{
     *   client: array<string, mixed>,
     *   appointmentSummary: array<string, mixed>,
     *   recentAppointments: list<array<string, mixed>>,
     *   salesSummary: array<string, mixed>,
     *   mergedIntoId: int,
     *   accountStatus: string
     * }
     */
    /**
     * @return array{status: ?string, date_mode: string, date_from: ?string, date_to: ?string, page: int, per_page: int}
     */
    private function parseResumeAppointmentListQuery(): array
    {
        $statusRaw = trim((string) ($_GET['appt_status'] ?? ''));
        $status = in_array($statusRaw, self::CLIENT_RESUME_APPOINTMENT_STATUS_FILTERS, true) ? $statusRaw : null;
        $modeRaw = trim((string) ($_GET['appt_date_mode'] ?? ''));
        $dateMode = $modeRaw === 'created' ? 'created' : 'appointment';
        $from = trim((string) ($_GET['appt_date_from'] ?? ''));
        $to = trim((string) ($_GET['appt_date_to'] ?? ''));
        $page = max(1, (int) ($_GET['appt_page'] ?? 1));
        $perPage = max(1, min(50, (int) ($_GET['appt_per_page'] ?? 15)));

        return [
            'status' => $status,
            'date_mode' => $dateMode,
            'date_from' => $from !== '' ? $from : null,
            'date_to' => $to !== '' ? $to : null,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array{status: ?string, date_mode: string, date_from: ?string, date_to: ?string, page: int, per_page: int} $filters
     *
     * @return array<string, string|int>
     */
    private function buildResumeAppointmentQueryForLinks(array $filters, int $perPage): array
    {
        $q = [
            'appt_date_mode' => ($filters['date_mode'] ?? 'appointment') === 'created' ? 'created' : 'appointment',
            'appt_per_page' => $perPage,
        ];
        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $q['appt_status'] = (string) $filters['status'];
        }
        if (($filters['date_from'] ?? null) !== null && $filters['date_from'] !== '') {
            $q['appt_date_from'] = (string) $filters['date_from'];
        }
        if (($filters['date_to'] ?? null) !== null && $filters['date_to'] !== '') {
            $q['appt_date_to'] = (string) $filters['date_to'];
        }

        return $q;
    }

    /**
     * @return array<string, string>
     */
    private function clientResumeAppointmentStatusLabels(): array
    {
        return [
            'scheduled' => 'Scheduled',
            'confirmed' => 'Confirmed',
            'in_progress' => 'In progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'no_show' => 'No show',
        ];
    }

    private function clientShellViewData(int $clientId, array $client): array
    {
        $client['display_name'] = $this->service->getDisplayName($client);
        $appointmentSummary = $this->appointmentsProfile->getSummary($clientId);
        $recentAppointments = $this->appointmentsProfile->listRecent($clientId, 10);
        $salesSummary = $this->salesProfile->getSummary($clientId);
        $mergedInto = $client['merged_into_client_id'] ?? null;
        $mergedIntoId = ($mergedInto !== null && $mergedInto !== '') ? (int) $mergedInto : 0;
        $accountStatus = $mergedIntoId > 0
            ? 'Merged — see client #' . $mergedIntoId
            : (((int) ($appointmentSummary['total'] ?? 0) === 0 && (int) ($salesSummary['invoice_count'] ?? 0) === 0) ? 'New / inactive' : 'Active');

        $branchIdForPhoto = (int) ($client['branch_id'] ?? 0);
        if ($branchIdForPhoto <= 0) {
            $cb = $this->branchContext->getCurrentBranchId();
            $branchIdForPhoto = $cb !== null && $cb > 0 ? (int) $cb : 0;
        }
        $clientRefPrimaryPhotoUrl = null;
        if ($branchIdForPhoto > 0 && $this->clientProfilePhotos->isLibraryStorageReady()) {
            $clientRefPrimaryPhotoUrl = $this->clientProfilePhotos->resolveSidebarPhotoPublicUrl($clientId, $branchIdForPhoto);
        }

        return [
            'client' => $client,
            'appointmentSummary' => $appointmentSummary,
            'recentAppointments' => $recentAppointments,
            'salesSummary' => $salesSummary,
            'mergedIntoId' => $mergedIntoId,
            'accountStatus' => $accountStatus,
            'clientRefPrimaryPhotoUrl' => $clientRefPrimaryPhotoUrl,
        ];
    }

    private function marketingSettingsReadBranchId(?array $client): ?int
    {
        if ($client !== null) {
            $raw = $client['branch_id'] ?? null;
            if ($raw !== null && $raw !== '') {
                $id = (int) $raw;
                if ($id > 0) {
                    return $id;
                }
            }
        }

        return Application::container()->get(BranchContext::class)->getCurrentBranchId();
    }

    /**
     * Canonical staff create/update payload keys and semantics — frozen contract for UI/API parity.
     *
     * @see system/docs/CLIENT-BACKEND-CONTRACT-FREEZE.md §2
     *
     * @param array<string, mixed>|null $current Existing client row on update; when set, omitted inputs keep prior values (layout-safe partial POST).
     */
    private function parseInput(?array $current = null): array
    {
        /** Nullable trimmed string: on update, missing POST key keeps DB value; present empty clears. */
        $sKeep = static function (string $key, ?array $current): ?string {
            if ($current === null || array_key_exists($key, $_POST)) {
                $t = trim((string) ($_POST[$key] ?? ''));

                return $t !== '' ? $t : null;
            }
            $v = $current[$key] ?? null;
            if ($v === null) {
                return null;
            }
            $t = trim((string) $v);

            return $t !== '' ? $t : null;
        };

        $textKeepRequired = static function (string $key, ?array $current): string {
            if ($current === null || array_key_exists($key, $_POST)) {
                return trim((string) ($_POST[$key] ?? ''));
            }

            return trim((string) ($current[$key] ?? ''));
        };

        $cb = static function (string $key, ?array $current, int $default = 0): int {
            if (!array_key_exists($key, $_POST)) {
                if ($current !== null && array_key_exists($key, $current)) {
                    return (int) $current[$key];
                }

                return $default;
            }

            return isset($_POST[$key]) && $_POST[$key] !== '' && $_POST[$key] !== '0' ? 1 : 0;
        };

        $data = [
            'first_name' => $textKeepRequired('first_name', $current),
            'last_name' => $textKeepRequired('last_name', $current),
            'email' => $sKeep('email', $current),
            'phone_home' => $sKeep('phone_home', $current),
            'phone_mobile' => $sKeep('phone_mobile', $current),
            'mobile_operator' => $sKeep('mobile_operator', $current),
            'phone_work' => $sKeep('phone_work', $current),
            'phone_work_ext' => $sKeep('phone_work_ext', $current),
            'home_address_1' => $sKeep('home_address_1', $current),
            'home_address_2' => $sKeep('home_address_2', $current),
            'home_city' => $sKeep('home_city', $current),
            'home_postal_code' => $sKeep('home_postal_code', $current),
            'home_country' => $sKeep('home_country', $current),
            'delivery_same_as_home' => $cb('delivery_same_as_home', $current, 0),
            'delivery_address_1' => $sKeep('delivery_address_1', $current),
            'delivery_address_2' => $sKeep('delivery_address_2', $current),
            'delivery_city' => $sKeep('delivery_city', $current),
            'delivery_postal_code' => $sKeep('delivery_postal_code', $current),
            'delivery_country' => $sKeep('delivery_country', $current),
            'birth_date' => $sKeep('birth_date', $current),
            'anniversary' => $sKeep('anniversary', $current),
            'gender' => $sKeep('gender', $current),
            'occupation' => $sKeep('occupation', $current),
            'language' => $sKeep('language', $current),
            'preferred_contact_method' => $sKeep('preferred_contact_method', $current),
            'marketing_opt_in' => $cb('marketing_opt_in', $current, 0),
            'receive_emails' => $cb('receive_emails', $current, 0),
            'receive_sms' => $cb('receive_sms', $current, 0),
            'booking_alert' => $sKeep('booking_alert', $current),
            'check_in_alert' => $sKeep('check_in_alert', $current),
            'check_out_alert' => $sKeep('check_out_alert', $current),
            'referral_information' => $sKeep('referral_information', $current),
            'referral_history' => $sKeep('referral_history', $current),
            'referred_by' => $sKeep('referred_by', $current),
            'customer_origin' => $sKeep('customer_origin', $current),
            'emergency_contact_name' => $sKeep('emergency_contact_name', $current),
            'emergency_contact_phone' => $sKeep('emergency_contact_phone', $current),
            'emergency_contact_relationship' => $sKeep('emergency_contact_relationship', $current),
            'inactive_flag' => $cb('inactive_flag', $current, 0),
            'notes' => $sKeep('notes', $current),
            'custom_fields' => is_array($_POST['custom_fields'] ?? null) ? $_POST['custom_fields'] : [],
        ];

        if ($current === null) {
            $addDelivery = isset($_POST['add_delivery']) && (string) $_POST['add_delivery'] === '1';
            $data['needs_delivery'] = $addDelivery ? 1 : 0;
            if (!$addDelivery) {
                $data['delivery_address_1'] = null;
                $data['delivery_address_2'] = null;
                $data['delivery_city'] = null;
                $data['delivery_postal_code'] = null;
                $data['delivery_country'] = null;
                $data['delivery_same_as_home'] = 0;
            } else {
                $data['delivery_same_as_home'] = 0;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $clientRowForBranchFilter Client row on update; null on create (uses current branch).
     * @return array<string, string>
     */
    private function validate(array $data, ?array $clientRowForBranchFilter): array
    {
        $bf = $this->customFieldDefinitionsBranchFilter($clientRowForBranchFilter);
        $definitions = $this->service->getCustomFieldDefinitions($bf, true);

        return $this->inputValidator->validate($data, $definitions, $clientRowForBranchFilter === null);
    }

    /**
     * Custom field definitions are stored per branch; resolve the branch for list/required validation.
     *
     * @param array<string, mixed>|null $clientRow
     */
    private function customFieldDefinitionsBranchFilter(?array $clientRow): ?int
    {
        if ($clientRow !== null) {
            $b = $clientRow['branch_id'] ?? null;
            if ($b !== null && $b !== '' && (int) $b > 0) {
                return (int) $b;
            }
        }
        $cb = $this->branchContext->getCurrentBranchId();

        return ($cb !== null && $cb > 0) ? $cb : null;
    }

    private function registrationSources(): array
    {
        return ['manual', 'web_form', 'online_booking', 'phone_call'];
    }

    private function operatorSafeErrorMessage(\Throwable $e): string
    {
        if ($e instanceof SafeDomainException) {
            return $e->publicMessage;
        }

        return 'Operation failed. Please try again or contact support.';
    }

    /**
     * Non-terminating branch check for assembling merge UI (list context).
     */
    private function clientRowVisibleInCurrentBranchContext(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null ? (int) $entity['branch_id'] : null;
            Application::container()->get(\Core\Branch\BranchContext::class)->assertBranchMatchOrGlobalEntity($branchId);

            return true;
        } catch (\DomainException) {
            return false;
        }
    }

    private function formatClientLastVisitLabel(int $clientId): string
    {
        try {
            $s = $this->appointmentsProfile->getSummary($clientId);
            $raw = $s['last_start_at'] ?? null;
            if (!is_string($raw) || $raw === '') {
                return '—';
            }
            $t = strtotime($raw);

            return $t ? date('M j, Y', $t) : $raw;
        } catch (\Throwable) {
            return '—';
        }
    }

    /**
     * @return list<array{slot:string,record_label:string,id:int,name:string,phone:string,email:string,last_visit:string}>|null
     */
    private function buildMergeModalPairCards(int $idA, int $idB): ?array
    {
        if ($idA <= 0 || $idB <= 0 || $idA === $idB) {
            return null;
        }
        $rowA = $this->repo->find($idA);
        $rowB = $this->repo->find($idB);
        if (!$rowA || !$rowB) {
            return null;
        }
        if (!$this->clientRowVisibleInCurrentBranchContext($rowA) || !$this->clientRowVisibleInCurrentBranchContext($rowB)) {
            return null;
        }
        $pack = static function (array $row, string $slot, string $recordLabel, ClientService $service, callable $lastVisit): array {
            $name = trim(trim((string) ($row['first_name'] ?? '')) . ' ' . trim((string) ($row['last_name'] ?? '')));
            if ($name === '') {
                $name = '—';
            }
            $phone = $service->getCanonicalPrimaryPhone($row);
            $phoneOut = ($phone !== null && trim((string) $phone) !== '') ? trim((string) $phone) : '—';
            $email = trim((string) ($row['email'] ?? ''));

            return [
                'slot' => $slot,
                'record_label' => $recordLabel,
                'id' => (int) $row['id'],
                'name' => $name,
                'phone' => $phoneOut,
                'email' => $email !== '' ? $email : '—',
                'last_visit' => $lastVisit((int) $row['id']),
            ];
        };
        $lv = fn (int $cid): string => $this->formatClientLastVisitLabel($cid);

        return [
            $pack($rowA, 'a', 'Record 1', $this->service, $lv),
            $pack($rowB, 'b', 'Record 2', $this->service, $lv),
        ];
    }

    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null ? (int) $entity['branch_id'] : null;
            Application::container()->get(\Core\Branch\BranchContext::class)->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }

    /** After bulk POST: restore list search + page from hidden fields. */
    private function redirectToClientsIndexPostContext(): void
    {
        $q = [];
        $listSearch = isset($_POST['list_search']) ? trim((string) $_POST['list_search']) : '';
        if ($listSearch !== '') {
            $q['search'] = $listSearch;
        }
        if (isset($_POST['list_page']) && (int) $_POST['list_page'] > 1) {
            $q['page'] = (string) (int) $_POST['list_page'];
        }
        $url = '/clients' . ($q !== [] ? ('?' . http_build_query($q)) : '');
        header('Location: ' . $url);
        exit;
    }

    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    private function isDrawerRequest(): bool
    {
        return (string) ($_GET['drawer'] ?? '') === '1'
            || (string) ($_SERVER['HTTP_X_APP_DRAWER'] ?? '') === '1';
    }

    private function sendDrawerValidationHtml(string $html): void
    {
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => ['message' => 'Please correct the errors below.'],
            'data' => ['html' => $html],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function sendDrawerClientCreated(int $id): void
    {
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'data' => [
                'message' => 'Client created.',
                'window_assign' => '/clients/' . $id,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
