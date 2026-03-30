<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Core\App\SettingsService;
use Core\Contracts\AppointmentPackageConsumptionProvider;
use Core\Contracts\ClientAppointmentProfileProvider;
use Core\Contracts\ClientPackageProfileProvider;
use Core\Contracts\ClientSalesProfileProvider;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Clients\Services\ClientProfileAccessService;

/**
 * Read-only composition for {@see \Modules\Appointments\Controllers\AppointmentController::printSummaryPage} — no persistence.
 *
 * Domain scope: one appointment row (`appointments.staff_id` is singular). Same-day list = other appointments
 * for that staff_id on the appointment’s calendar day, org/branch-scoped via {@see AppointmentRepository::list}.
 *
 * Optional sections obey {@see SettingsService::getAppointmentSettings} (`print_show_*`) for the request branch.
 */
final class AppointmentPrintSummaryService
{
    private const STAFF_DAY_LIMIT = 40;

    private const SERVICE_HISTORY_LIMIT = 10;

    private const PACKAGE_RECENT_LIMIT = 8;

    private const PRODUCT_PURCHASE_HISTORY_LIMIT = 15;

    public function __construct(
        private AppointmentRepository $appointments,
        private AppointmentService $appointmentService,
        private ClientProfileAccessService $profileAccess,
        private ClientAppointmentProfileProvider $appointmentsProfile,
        private AppointmentPackageConsumptionProvider $packageConsumption,
        private ClientPackageProfileProvider $clientPackagesProfile,
        private ClientSalesProfileProvider $salesProfile,
        private SettingsService $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $appointment Row from {@see AppointmentRepository::find}
     * @param int|null $branchIdForSettings {@see BranchContext::getCurrentBranchId()} when &gt; 0, else org-effective read
     *
     * @return array{
     *   appointment: array<string, mixed>,
     *   client_contact: array<string, string|null>|null,
     *   staff_same_day: list<array<string, mixed>>,
     *   staff_same_day_scope: string,
     *   service_history: list<array<string, mixed>>,
     *   package_usages: list<array<string, mixed>>,
     *   packages_recent: list<array<string, mixed>>,
     *   product_purchase_lines: list<array<string, mixed>>,
     *   section_visibility: array{
     *     staff_appointment_list: bool,
     *     client_service_history: bool,
     *     package_detail: bool,
     *     client_product_purchase_history: bool
     *   }
     * }
     */
    public function compose(array $appointment, ?int $branchIdForSettings = null): array
    {
        $settingsBranch = ($branchIdForSettings !== null && $branchIdForSettings > 0) ? $branchIdForSettings : null;
        $aptSt = $this->settings->getAppointmentSettings($settingsBranch);
        $showStaffDay = !empty($aptSt['print_show_staff_appointment_list']);
        $showHistory = !empty($aptSt['print_show_client_service_history']);
        $showPackages = !empty($aptSt['print_show_package_detail']);
        $showProductPurchase = !empty($aptSt['print_show_client_product_purchase_history']);

        $a = $appointment;
        $a['display_summary'] = $this->appointmentService->getDisplaySummary($a);
        $a = array_merge($a, $this->appointmentService->getShowDatetimeDisplay($a));
        $a = array_merge($a, $this->appointmentService->getShowHeaderDatetimeDisplay($a));
        $a['status_label'] = $this->appointmentService->formatStatusLabel(isset($a['status']) ? (string) $a['status'] : null);

        $clientId = isset($a['client_id']) && (int) $a['client_id'] > 0 ? (int) $a['client_id'] : 0;

        $clientContact = null;
        if ($clientId > 0) {
            $row = $this->profileAccess->resolveForProviderRead($clientId);
            if ($row !== null) {
                $clientContact = [
                    'display_name' => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')) ?: null,
                    'phone' => isset($row['phone']) && (string) $row['phone'] !== '' ? (string) $row['phone'] : null,
                    'email' => isset($row['email']) && (string) $row['email'] !== '' ? (string) $row['email'] : null,
                    'notes' => isset($row['notes']) && (string) $row['notes'] !== '' ? (string) $row['notes'] : null,
                ];
            }
        }

        $staffSameDay = [];
        $staffDayScope = 'no_staff';
        if ($showStaffDay) {
            $sid = isset($a['staff_id']) && $a['staff_id'] !== null && $a['staff_id'] !== '' ? (int) $a['staff_id'] : 0;
            $startRaw = (string) ($a['start_at'] ?? '');
            $dayTs = strtotime($startRaw);
            if ($sid > 0 && $dayTs !== false) {
                $staffDayScope = 'primary_staff_same_calendar_day';
                $day = date('Y-m-d', $dayTs);
                $filters = [
                    'staff_id' => $sid,
                    'from_date' => $day . ' 00:00:00',
                    'to_date' => $day . ' 23:59:59',
                ];
                if (isset($a['branch_id']) && $a['branch_id'] !== null && $a['branch_id'] !== '') {
                    $filters['branch_id'] = (int) $a['branch_id'];
                }
                $rows = $this->appointments->list($filters, self::STAFF_DAY_LIMIT, 0);
                usort($rows, static fn (array $x, array $y): int => strcmp((string) ($x['start_at'] ?? ''), (string) ($y['start_at'] ?? '')));
                foreach ($rows as $r) {
                    $staffSameDay[] = [
                        'id' => (int) ($r['id'] ?? 0),
                        'start_at' => (string) ($r['start_at'] ?? ''),
                        'end_at' => (string) ($r['end_at'] ?? ''),
                        'status' => (string) ($r['status'] ?? ''),
                        'service_name' => $r['service_name'] ?? null,
                        'client_label' => trim((string) ($r['client_first_name'] ?? '') . ' ' . (string) ($r['client_last_name'] ?? '')) ?: null,
                    ];
                }
            }
        } else {
            $staffDayScope = 'section_disabled';
        }

        $serviceHistory = [];
        if ($showHistory && $clientId > 0) {
            $serviceHistory = $this->appointmentsProfile->listRecent($clientId, self::SERVICE_HISTORY_LIMIT);
        }

        $packageUsages = [];
        $packagesRecent = [];
        if ($showPackages) {
            $packageUsages = $this->packageConsumption->listAppointmentConsumptions((int) $a['id']);
            if ($clientId > 0) {
                $packagesRecent = $this->clientPackagesProfile->listRecent($clientId, self::PACKAGE_RECENT_LIMIT);
            }
        }

        $productPurchaseLines = [];
        if ($showProductPurchase && $clientId > 0) {
            $productPurchaseLines = $this->salesProfile->listRecentProductInvoiceLines($clientId, self::PRODUCT_PURCHASE_HISTORY_LIMIT);
        }

        return [
            'appointment' => $a,
            'client_contact' => $clientContact,
            'staff_same_day' => $staffSameDay,
            'staff_same_day_scope' => $staffDayScope,
            'service_history' => $serviceHistory,
            'package_usages' => $packageUsages,
            'packages_recent' => $packagesRecent,
            'product_purchase_lines' => $productPurchaseLines,
            'section_visibility' => [
                'staff_appointment_list' => $showStaffDay,
                'client_service_history' => $showHistory,
                'package_detail' => $showPackages,
                'client_product_purchase_history' => $showProductPurchase,
            ],
        ];
    }
}
