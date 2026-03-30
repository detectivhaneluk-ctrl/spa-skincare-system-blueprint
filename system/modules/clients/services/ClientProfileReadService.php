<?php

declare(strict_types=1);

namespace Modules\Clients\Services;

use Core\App\SettingsService;
use Core\Auth\SessionAuth;
use Core\Branch\BranchContext;
use Core\Contracts\ClientAppointmentProfileProvider;
use Core\Contracts\ClientGiftCardProfileProvider;
use Core\Contracts\ClientPackageProfileProvider;
use Core\Contracts\ClientSalesProfileProvider;
use Core\Permissions\PermissionService;
use Modules\Clients\Repositories\ClientIssueFlagRepository;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Clients\Support\ClientNormalizedSearchSchemaReadiness;

/**
 * Single backend read-composition layer for the main client profile surface ({@see ClientController::show}).
 *
 * Top-level read-model contract (stable keys):
 * - client: display-enriched client row for the profile header/shell
 * - shell: sidebar/summary block (appointments + sales rollups, photo, account line)
 * - appointments: resume list + filters-derived link query + itinerary UI flags + add URL
 * - duplicates: duplicate candidate rows for the profile strip (empty when migration 119 columns are absent)
 * - duplicate_search: readiness + honest blocked_reason when duplicate SQL is intentionally not run
 * - custom_fields: definitions + values map
 * - flags: open issue flags
 * - commerce: recent invoices and payments
 * - packages: summary + recent rows
 * - gift_cards: summary + recent rows
 * - audit: decoded audit history rows
 * - layout: sidebar layout keys
 * - permissions: can_edit_clients, can_create_appointments
 * - _read_steps: coarse count of logical read operations (dev/tuning; not a DB query guarantee)
 */
final class ClientProfileReadService
{
    public function __construct(
        private ClientRepository $repo,
        private ClientService $service,
        private ClientAppointmentProfileProvider $appointmentsProfile,
        private ClientSalesProfileProvider $salesProfile,
        private ClientPackageProfileProvider $packagesProfile,
        private ClientGiftCardProfileProvider $giftCardsProfile,
        private ClientIssueFlagRepository $issueFlagRepo,
        private SettingsService $settings,
        private BranchContext $branchContext,
        private ClientProfileImageService $clientProfilePhotos,
        private ClientPageLayoutService $pageLayouts,
        private PermissionService $permissionService,
        private SessionAuth $sessionAuth,
    ) {
    }

    /**
     * @param array<string, mixed> $clientRow
     * @param array{status: ?string, date_mode: string, date_from: ?string, date_to: ?string, page: int, per_page: int} $resumeApptFilters
     *
     * @return array{
     *   client: array<string, mixed>,
     *   shell: array<string, mixed>,
     *   appointments: array<string, mixed>,
     *   duplicates: list<array<string, mixed>>,
     *   duplicate_search: array{ready: bool, blocked_reason: ?string},
     *   custom_fields: array{definitions: list<array<string, mixed>>, values: array<string, mixed>},
     *   flags: array{active_open: list<array<string, mixed>>},
     *   commerce: array{recent_invoices: mixed, recent_payments: mixed},
     *   packages: array{summary: mixed, recent: mixed},
     *   gift_cards: array{summary: mixed, recent: mixed},
     *   audit: array{history: list<array<string, mixed>>},
     *   layout: array{sidebar_layout_keys: mixed},
     *   permissions: array{can_edit_clients: bool, can_create_appointments: bool},
     *   _read_steps: int
     * }
     */
    public function buildMainProfileReadModel(int $clientId, array $clientRow, array $resumeApptFilters): array
    {
        $steps = 0;
        $bump = static function () use (&$steps): void {
            $steps++;
        };

        $shellBlock = $this->buildShellViewData($clientId, $clientRow, $bump);
        $client = $shellBlock['client'];

        $bump();
        $resumeApptList = $this->appointmentsProfile->listForClientProfile($clientId, $resumeApptFilters);
        $resumeApptStatusLabels = $this->clientResumeAppointmentStatusLabels();
        $perPageUsed = max(1, (int) ($resumeApptList['per_page'] ?? 15));
        $resumeApptTotalPages = max(1, (int) ceil((int) ($resumeApptList['total'] ?? 0) / $perPageUsed));
        $resumeApptLinkQuery = $this->buildResumeAppointmentQueryForLinks($resumeApptFilters, $perPageUsed);

        $dupPhone = $this->service->getCanonicalPrimaryPhone($client);
        $bump();
        $dupSchemaReady = $this->repo->isNormalizedSearchSchemaReady();
        $duplicates = $dupSchemaReady
            ? $this->service->findDuplicates($clientId, [
                'email' => $client['email'],
                'phone' => $dupPhone ?? $client['phone'],
            ])
            : [];
        $duplicateSearch = [
            'ready' => $dupSchemaReady,
            'blocked_reason' => $dupSchemaReady ? null : ClientNormalizedSearchSchemaReadiness::PUBLIC_UNAVAILABLE_MESSAGE,
        ];

        $bump();
        $customFieldValues = $this->service->getClientCustomFieldValuesMap($clientId);
        $bump();
        $customFieldDefinitions = $this->service->getCustomFieldDefinitions($this->customFieldDefinitionsBranchFilter($client), true);

        $bump();
        $activeIssueFlags = $this->issueFlagRepo->listByClient($clientId, 'open', 50);

        $bump();
        $appointmentUi = $this->settings->getAppointmentSettings($this->appointmentSettingsReadBranchId());
        $clientItineraryShowStaff = (bool) ($appointmentUi['client_itinerary_show_staff'] ?? true);
        $clientItineraryShowSpace = (bool) ($appointmentUi['client_itinerary_show_space'] ?? false);

        $bump();
        $recentInvoices = $this->salesProfile->listRecentInvoices($clientId, 10);
        $bump();
        $recentPayments = $this->salesProfile->listRecentPayments($clientId, 10);

        $bump();
        $packageSummary = $this->packagesProfile->getSummary($clientId);
        $bump();
        $recentPackages = $this->packagesProfile->listRecent($clientId, 10);

        $bump();
        $giftCardSummary = $this->giftCardsProfile->getSummary($clientId);
        $bump();
        $recentGiftCards = $this->giftCardsProfile->listRecent($clientId, 10);

        $bump();
        $clientHistory = $this->repo->listAuditHistory($clientId, 20);
        foreach ($clientHistory as &$h) {
            $h['metadata'] = !empty($h['metadata_json']) ? json_decode((string) $h['metadata_json'], true) : null;
        }
        unset($h);

        $uid = $this->sessionAuth->id();
        $canEditClients = $uid !== null && $this->permissionService->has($uid, 'clients.edit');
        $canCreateAppointments = $uid !== null && $this->permissionService->has($uid, 'appointments.create');
        $resumeAddAppointmentUrl = null;
        if ($canCreateAppointments) {
            $bid = (int) ($client['branch_id'] ?? 0);
            if ($bid <= 0) {
                $cb = $this->branchContext->getCurrentBranchId();
                $bid = ($cb !== null && $cb > 0) ? $cb : 0;
            }
            $resumeAddAppointmentUrl = '/appointments/create?client_id=' . $clientId . ($bid > 0 ? '&branch_id=' . $bid : '');
        }

        $bump();
        $sidebarLayoutKeys = $this->pageLayouts->trySidebarLayoutKeys();

        return [
            'client' => $client,
            'shell' => [
                'appointment_summary' => $shellBlock['appointment_summary'],
                'recent_appointments' => $shellBlock['recent_appointments'],
                'sales_summary' => $shellBlock['sales_summary'],
                'merged_into_id' => $shellBlock['merged_into_id'],
                'account_status' => $shellBlock['account_status'],
                'primary_photo_url' => $shellBlock['primary_photo_url'],
            ],
            'appointments' => [
                'resume_list' => $resumeApptList,
                'resume_filters' => $resumeApptFilters,
                'status_labels' => $resumeApptStatusLabels,
                'per_page_used' => $perPageUsed,
                'total_pages' => $resumeApptTotalPages,
                'link_query' => $resumeApptLinkQuery,
                'itinerary_show_staff' => $clientItineraryShowStaff,
                'itinerary_show_space' => $clientItineraryShowSpace,
                'add_appointment_url' => $resumeAddAppointmentUrl,
            ],
            'duplicates' => $duplicates,
            'duplicate_search' => $duplicateSearch,
            'custom_fields' => [
                'definitions' => $customFieldDefinitions,
                'values' => $customFieldValues,
            ],
            'flags' => [
                'active_open' => $activeIssueFlags,
            ],
            'commerce' => [
                'recent_invoices' => $recentInvoices,
                'recent_payments' => $recentPayments,
            ],
            'packages' => [
                'summary' => $packageSummary,
                'recent' => $recentPackages,
            ],
            'gift_cards' => [
                'summary' => $giftCardSummary,
                'recent' => $recentGiftCards,
            ],
            'audit' => [
                'history' => $clientHistory,
            ],
            'layout' => [
                'sidebar_layout_keys' => $sidebarLayoutKeys,
            ],
            'permissions' => [
                'can_edit_clients' => $canEditClients,
                'can_create_appointments' => $canCreateAppointments,
            ],
            '_read_steps' => $steps,
        ];
    }

    private function appointmentSettingsReadBranchId(): ?int
    {
        $bid = $this->branchContext->getCurrentBranchId();

        return $bid !== null && $bid > 0 ? $bid : null;
    }

    /**
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

    /**
     * @param array<string, mixed> $client
     * @param callable(): void $bump
     *
     * @return array{
     *   client: array<string, mixed>,
     *   appointment_summary: array<string, mixed>,
     *   recent_appointments: list<array<string, mixed>>,
     *   sales_summary: array<string, mixed>,
     *   merged_into_id: int,
     *   account_status: string,
     *   primary_photo_url: ?string
     * }
     */
    private function buildShellViewData(int $clientId, array $client, callable $bump): array
    {
        $client['display_name'] = $this->service->getDisplayName($client);
        $bump();
        $appointmentSummary = $this->appointmentsProfile->getSummary($clientId);
        $bump();
        $recentAppointments = $this->appointmentsProfile->listRecent($clientId, 10);
        $bump();
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
        $primaryPhotoUrl = null;
        if ($branchIdForPhoto > 0 && $this->clientProfilePhotos->isLibraryStorageReady()) {
            $bump();
            $primaryPhotoUrl = $this->clientProfilePhotos->resolveSidebarPhotoPublicUrl($clientId, $branchIdForPhoto);
        }

        return [
            'client' => $client,
            'appointment_summary' => $appointmentSummary,
            'recent_appointments' => $recentAppointments,
            'sales_summary' => $salesSummary,
            'merged_into_id' => $mergedIntoId,
            'account_status' => $accountStatus,
            'primary_photo_url' => $primaryPhotoUrl,
        ];
    }
}
