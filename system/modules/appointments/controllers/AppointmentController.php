<?php

declare(strict_types=1);

namespace Modules\Appointments\Controllers;

use Core\App\Application;
use Core\App\Response;
use Core\App\SettingsService;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Core\Branch\TenantBranchAccessService;
use Core\Errors\AccessDeniedException;
use Core\Organization\OrganizationScopedBranchAssert;
use Core\Contracts\AppointmentPackageConsumptionProvider;
use Core\Contracts\ClientAppointmentProfileProvider;
use Core\Contracts\ClientListProvider;
use Core\Contracts\PackageAvailabilityProvider;
use Core\Contracts\RoomListProvider;
use Core\Contracts\ServiceListProvider;
use Core\Contracts\StaffListProvider;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Appointments\Repositories\BlockedSlotRepository;
use Modules\Appointments\Repositories\WaitlistRepository;
use Modules\Appointments\Services\AvailabilityService;
use Modules\Appointments\Services\AppointmentPrintSummaryService;
use Modules\Appointments\Services\AppointmentService;
use Modules\Appointments\Services\CalendarMonthSummaryService;
use Modules\Appointments\Services\AppointmentSeriesService;
use Modules\Appointments\Services\BlockedSlotService;
use Modules\Appointments\Services\WaitlistService;
use Modules\Settings\Services\BranchClosureDateService;
use Modules\Settings\Services\BranchOperatingHoursService;

final class AppointmentController
{
    /** JSON contract for `dayCalendar()` / GET `/calendar/day` (BKM-008). Increment when response shape breaks backward compatibility. */
    private const DAY_CALENDAR_CONTRACT_NAME = 'spa.day_calendar';

    private const DAY_CALENDAR_CONTRACT_VERSION = 1;

    /** @var list<string> */
    private const APPOINTMENT_LIST_STATUS_FILTER_ALLOWED = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];

    public function __construct(
        private AppointmentRepository $repo,
        private AppointmentService $service,
        private ClientListProvider $clientList,
        private ServiceListProvider $serviceList,
        private StaffListProvider $staffList,
        private RoomListProvider $roomList,
        private PackageAvailabilityProvider $packageAvailability,
        private AppointmentPackageConsumptionProvider $packageConsumption,
        private AvailabilityService $availability,
        private WaitlistRepository $waitlistRepo,
        private WaitlistService $waitlistService,
        private BlockedSlotRepository $blockedSlotRepo,
        private BlockedSlotService $blockedSlotService,
        private AppointmentSeriesService $seriesService,
        private BranchDirectory $branchDirectory,
        private BranchOperatingHoursService $branchOperatingHours,
        private BranchClosureDateService $branchClosureDates,
        private SettingsService $settings,
        private ClientAppointmentProfileProvider $appointmentsProfile,
        private AppointmentPrintSummaryService $appointmentPrintSummary,
        private TenantBranchAccessService $tenantBranchAccess,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
        private BranchContext $branchContext,
        private CalendarMonthSummaryService $calendarMonthSummary,
    ) {
    }

    /**
     * Context-aware access-denial exit for action catch blocks (catch \Throwable $e).
     * If $e is an AccessDeniedException: responds with 403 JSON for JSON/XHR requests,
     * or flashes an error and redirects to /dashboard for HTML requests.
     * No-op for any other exception type (caller handles remaining errors).
     */
    private function exitIfAccessDenied(\Throwable $e): void
    {
        if (!($e instanceof AccessDeniedException)) {
            return;
        }
        if ($this->wantsJsonRequest()) {
            Response::jsonPublicApiError(403, 'FORBIDDEN', 'Access denied.');
        }
        flash('error', 'Access denied.');
        header('Location: /dashboard');
        exit;
    }

    public function index(): void
    {
        try {
            $branchId = $this->resolveAppointmentBranchFromGetOrFail();
        } catch (\Core\Errors\AccessDeniedException $e) {
            $this->failAppointmentBranchAccessDenied($e);
        } catch (\DomainException $e) {
            $this->failAppointmentBranchResolution($e);
        }
        $fromDate = trim($_GET['from_date'] ?? '') ?: null;
        $toDate = trim($_GET['to_date'] ?? '') ?: null;
        $statusRaw = trim((string) ($_GET['status'] ?? ''));
        $statusFilter = in_array($statusRaw, self::APPOINTMENT_LIST_STATUS_FILTER_ALLOWED, true) ? $statusRaw : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $filters = array_filter([
            'branch_id' => $branchId,
            'from_date' => $fromDate ? $fromDate . ' 00:00:00' : null,
            'to_date' => $toDate ? $toDate . ' 23:59:59' : null,
            'status' => $statusFilter,
        ], fn ($v) => $v !== null && $v !== '');
        $status_filter_labels = [];
        foreach (self::APPOINTMENT_LIST_STATUS_FILTER_ALLOWED as $st) {
            $status_filter_labels[$st] = $this->service->formatStatusLabel($st);
        }
        $status_filter_selected = $statusFilter ?? '';
        $appointments = $this->repo->list($filters, $perPage, ($page - 1) * $perPage);
        $total = $this->repo->count($filters);
        $branches = $this->getBranches();
        foreach ($appointments as &$a) {
            $a['display_summary'] = $this->service->getDisplaySummary($a);
            $a['status_label'] = $this->service->formatStatusLabel(isset($a['status']) ? (string) $a['status'] : null);
        }
        unset($a);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        $workspace = $this->workspaceContext('list', $branchId, $fromDate);
        require base_path('modules/appointments/views/index.php');
    }

    public function create(): void
    {
        try {
            $branchId = $this->resolveAppointmentBranchFromGetOrFail();
        } catch (\Core\Errors\AccessDeniedException $e) {
            if ($this->wantsJsonRequest()) {
                Response::jsonPublicApiError(403, 'FORBIDDEN', 'Branch access denied.');
            }
            $this->failAppointmentBranchAccessDenied($e);
        } catch (\DomainException $e) {
            $this->failAppointmentBranchResolution($e);
        }
        $date = $this->queryDateOrNull();
        $clients = $this->clientList->list($branchId);
        $services = $this->serviceList->list($branchId);
        $staff = $this->staffList->list($branchId);
        $rooms = $this->roomList->list($branchId);
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        $errors = [];
        $appointment = ['status' => 'scheduled', 'date' => $date ?? '', 'branch_id' => $branchId];
        $prefillClientId = (int) ($_GET['client_id'] ?? 0);
        if ($prefillClientId > 0) {
            foreach ($clients as $cRow) {
                if ((int) ($cRow['id'] ?? 0) === $prefillClientId) {
                    $appointment['client_id'] = $prefillClientId;
                    break;
                }
            }
        }
        $prefillBranchGet = (int) ($_GET['branch_id'] ?? 0);
        if ($prefillBranchGet > 0) {
            foreach ($branches as $bRow) {
                if ((int) ($bRow['id'] ?? 0) === $prefillBranchGet) {
                    $appointment['branch_id'] = $prefillBranchGet;
                    break;
                }
            }
        }
        $workspace = $this->workspaceContext('new', $branchId, $date);
        if ($this->isDrawerRequest()) {
            $appointment['staff_id'] = trim((string) ($_GET['staff_id'] ?? '')) !== '' ? (int) $_GET['staff_id'] : ($appointment['staff_id'] ?? null);
            $appointment['room_id'] = trim((string) ($_GET['room_id'] ?? '')) !== '' ? (int) $_GET['room_id'] : ($appointment['room_id'] ?? null);
            $appointment['slot_minutes'] = max(5, (int) ($_GET['slot_minutes'] ?? 30));
            $prefillStaffId = isset($appointment['staff_id']) ? (int) $appointment['staff_id'] : 0;
            if ($prefillStaffId > 0) {
                $knownStaffIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $staff);
                if (!in_array($prefillStaffId, $knownStaffIds, true)) {
                    $extraStaff = Application::container()->get(\Modules\Staff\Repositories\StaffRepository::class)->find($prefillStaffId);
                    if (is_array($extraStaff)) {
                        $staff[] = $extraStaff;
                    } else {
                        $fallbackStaffLabel = trim((string) ($_GET['staff_label'] ?? ''));
                        if ($fallbackStaffLabel !== '') {
                            $staff[] = [
                                'id' => $prefillStaffId,
                                'first_name' => $fallbackStaffLabel,
                                'last_name' => '',
                            ];
                        }
                    }
                }
            }
            $prefillTime = $this->normalizeDrawerTimePrefill((string) ($_GET['time'] ?? ''));
            if ($prefillTime !== null) {
                $appointment['prefill_time'] = $prefillTime;
                $appointment['start_time'] = $prefillTime;
                $appointment['selected_start_time'] = ($appointment['date'] ?? '') !== '' ? ($appointment['date'] . ' ' . $prefillTime) : '';
                if (($appointment['date'] ?? '') !== '') {
                    $startTs = strtotime((string) $appointment['selected_start_time']);
                    if ($startTs !== false) {
                        $appointment['prefill_end_time'] = date('H:i', $startTs + ((int) $appointment['slot_minutes'] * 60));
                    }
                }
            }
            require base_path('modules/appointments/views/drawer/create.php');
            return;
        }
        require base_path('modules/appointments/views/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $this->renderCreateForm($data, $errors);
            return;
        }
        try {
            $rawPost = trim((string) ($_POST['branch_id'] ?? ''));
            $rawGet = trim((string) ($_GET['branch_id'] ?? ''));
            $branchRaw = $rawPost !== '' ? $rawPost : ($rawGet !== '' ? $rawGet : null);
            $data['branch_id'] = $this->resolveAppointmentBranchForPrincipalFromOptionalRequestId($branchRaw);
        } catch (\Core\Errors\AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException $e) {
            $errors['_general'] = $e->getMessage();
            $this->renderCreateForm($data, $errors);
            return;
        }
        try {
            $id = $this->service->create($data);
            flash('success', 'Appointment created.');
            header('Location: /appointments/' . $id);
            exit;
        } catch (\Core\Errors\AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException $e) {
            $errors['_conflict'] = $e->getMessage();
            $this->renderCreateForm($data, $errors);
        } catch (\InvalidArgumentException $e) {
            $errors['_general'] = $e->getMessage();
            $this->renderCreateForm($data, $errors);
        }
    }

    /**
     * Simplified booking endpoint.
     * POST /appointments/create
     */
    public function storeFromCreatePath(): void
    {
        $data = [
            'client_id' => (int) ($_POST['client_id'] ?? 0),
            'service_id' => (int) ($_POST['service_id'] ?? 0),
            'staff_id' => (int) ($_POST['staff_id'] ?? 0),
            'start_time' => trim((string) ($_POST['start_time'] ?? '')),
            'branch_id' => trim((string) ($_POST['branch_id'] ?? '')) !== '' ? (int) $_POST['branch_id'] : null,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            'client_membership_id' => trim((string) ($_POST['client_membership_id'] ?? '')) !== '' ? (int) $_POST['client_membership_id'] : null,
        ];
        if (trim((string) ($_POST['room_id'] ?? '')) !== '') {
            $data['room_id'] = (int) $_POST['room_id'];
        }

        try {
            $rawPost = trim((string) ($_POST['branch_id'] ?? ''));
            $rawGet = trim((string) ($_GET['branch_id'] ?? ''));
            $branchRaw = $rawPost !== '' ? $rawPost : ($rawGet !== '' ? $rawGet : null);
            $data['branch_id'] = $this->resolveAppointmentBranchForPrincipalFromOptionalRequestId($branchRaw);
        } catch (\Core\Errors\AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException $e) {
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => false,
                    'error' => ['message' => $e->getMessage()],
                ], 422);
            }
            flash('error', $e->getMessage());
            $branchForRedirect = trim((string) ($_POST['branch_id'] ?? ''));
            header('Location: /appointments/create' . ($branchForRedirect !== '' ? '?branch_id=' . (int) $branchForRedirect : ''));
            exit;
        }

        try {
            $id = $this->service->createFromSlot($data);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => true,
                    'data' => [
                        'message' => 'Appointment created.',
                        'refresh_calendar' => true,
                        'open_url' => '/appointments/' . $id,
                    ],
                ], 201);
            }
            flash('success', 'Appointment created.');
            header('Location: /appointments/' . $id);
            exit;
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => false,
                    'error' => ['message' => $e->getMessage()],
                ], 422);
            }
            flash('error', $e->getMessage());
            $branchForRedirect = isset($data['branch_id']) && $data['branch_id'] !== null ? (int) $data['branch_id'] : 0;
            header('Location: /appointments/create' . ($branchForRedirect > 0 ? '?branch_id=' . $branchForRedirect : ''));
            exit;
        }
    }

    public function show(int $id): void
    {
        $appointment = $this->repo->find($id);
        if (!$appointment) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($appointment)) {
            return;
        }
        $appointment['display_summary'] = $this->service->getDisplaySummary($appointment);
        $appointment = array_merge($appointment, $this->service->getShowDatetimeDisplay($appointment));
        $appointment = array_merge($appointment, $this->service->getShowHeaderDatetimeDisplay($appointment));
        $appointment['status_label'] = $this->service->formatStatusLabel(isset($appointment['status']) ? (string) $appointment['status'] : null);
        $appointment['status_select_labels'] = [
            'scheduled' => $this->service->formatStatusLabel('scheduled'),
            'confirmed' => $this->service->formatStatusLabel('confirmed'),
            'in_progress' => $this->service->formatStatusLabel('in_progress'),
            'completed' => $this->service->formatStatusLabel('completed'),
            'cancelled' => $this->service->formatStatusLabel('cancelled'),
            'no_show' => $this->service->formatStatusLabel('no_show'),
        ];
        $stForCheckIn = (string) ($appointment['status'] ?? 'scheduled');
        $appointment['can_mark_checked_in'] = in_array($stForCheckIn, ['scheduled', 'confirmed', 'in_progress'], true)
            && empty($appointment['checked_in_at']);
        $cinRaw = $appointment['checked_in_at'] ?? null;
        $appointment['checked_in_display'] = ($cinRaw !== null && $cinRaw !== '')
            ? $this->service->formatAppointmentDisplayDateTime((string) $cinRaw)
            : null;
        $branchContext = $appointment['branch_id'] !== null ? (int) $appointment['branch_id'] : null;
        $eligiblePackages = [];
        $clientAppointmentSummary = null;
        if (!empty($appointment['client_id'])) {
            $clientIdForSummary = (int) $appointment['client_id'];
            $eligiblePackages = $this->packageAvailability->listEligibleClientPackages($clientIdForSummary, $branchContext);
            $clientAppointmentSummary = $this->appointmentsProfile->getSummary($clientIdForSummary);
        }
        $staffOptions = $this->staffSelectOptionsForAppointment($appointment);
        $packageConsumptions = $this->packageConsumption->listAppointmentConsumptions((int) $appointment['id']);
        $flash = flash();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        if ($this->isDrawerRequest()) {
            require base_path('modules/appointments/views/drawer/show.php');
            return;
        }
        require base_path('modules/appointments/views/show.php');
    }

    /**
     * Dedicated printable HTML summary (server-rendered). Same access as {@see show()}: find, branch match, appointments.view.
     * Optional sections follow `appointments.print_show_*` via {@see AppointmentPrintSummaryService::compose}; header + client block always.
     */
    public function printSummaryPage(int $id): void
    {
        $appointment = $this->repo->find($id);
        if (!$appointment) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($appointment)) {
            return;
        }
        $printBranch = isset($appointment['branch_id']) && $appointment['branch_id'] !== '' && $appointment['branch_id'] !== null
            ? (int) $appointment['branch_id']
            : 0;
        $print = $this->appointmentPrintSummary->compose($appointment, $printBranch > 0 ? $printBranch : null);
        $title = 'Appointment #' . $id . ' — Print summary';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $hideNav = true;
        $mainClass = 'appointment-print-shell';
        ob_start();
        require base_path('modules/appointments/views/print.php');
        $content = ob_get_clean();
        require shared_path('layout/base.php');
    }

    public function checkInAction(int $id): void
    {
        $appointment = $this->repo->find($id);
        if (!$appointment) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($appointment)) {
            return;
        }
        try {
            $this->service->markCheckedIn($id);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => true,
                    'data' => [
                        'message' => 'Checked in recorded.',
                        'refresh_calendar' => true,
                        'reload_url' => '/appointments/' . $id,
                    ],
                ]);
            }
            flash('success', 'Checked in recorded.');
        } catch (AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException | \RuntimeException $e) {
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => false,
                    'error' => ['message' => $e->getMessage()],
                ], 422);
            }
            flash('error', $e->getMessage());
        }
        header('Location: /appointments/' . $id);
        exit;
    }

    public function consumePackage(int $id): void
    {
        $appointment = $this->repo->find($id);
        if (!$appointment) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($appointment)) {
            return;
        }

        $clientPackageId = (int) ($_POST['client_package_id'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 1);
        $notes = trim($_POST['notes'] ?? '') ?: null;

        if ($clientPackageId <= 0) {
            flash('error', 'client_package_id is required.');
            header('Location: /appointments/' . $id);
            exit;
        }
        if ($quantity <= 0) {
            flash('error', 'Quantity must be greater than zero.');
            header('Location: /appointments/' . $id);
            exit;
        }

        try {
            $this->service->consumePackageSessions($id, $clientPackageId, $quantity, $notes);
            flash('success', 'Package sessions consumed for appointment.');
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            flash('error', $e->getMessage());
        }
        header('Location: /appointments/' . $id);
        exit;
    }

    public function cancelAction(int $id): void
    {
        try {
            $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
            $reasonId = trim((string) ($_POST['cancellation_reason_id'] ?? '')) !== '' ? (int) $_POST['cancellation_reason_id'] : null;
            $this->service->cancel($id, $notes, $reasonId);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => true,
                    'data' => [
                        'message' => 'Appointment cancelled.',
                        'refresh_calendar' => true,
                        'reload_url' => '/appointments/' . $id,
                    ],
                ]);
            }
            flash('success', 'Appointment cancelled.');
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => false,
                    'error' => ['message' => $e->getMessage()],
                ], 422);
            }
            flash('error', $e->getMessage());
        }
        header('Location: /appointments/' . $id);
        exit;
    }

    public function rescheduleAction(int $id): void
    {
        $startTime = trim((string) ($_POST['start_time'] ?? ''));
        $staffRaw = trim((string) ($_POST['staff_id'] ?? ''));
        $staffId = $staffRaw !== '' ? (int) $staffRaw : null;
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $expectedCurrentStartAt = trim((string) ($_POST['expected_current_start_at'] ?? '')) ?: null;
        try {
            $this->service->reschedule($id, $startTime, $staffId, $notes, $expectedCurrentStartAt);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => true,
                    'data' => [
                        'appointment_id' => $id,
                        'message' => 'Appointment rescheduled.',
                        'refresh_calendar' => true,
                        'reload_url' => '/appointments/' . $id,
                    ],
                ]);
                return;
            }
            flash('success', 'Appointment rescheduled.');
        } catch (AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException | \InvalidArgumentException $e) {
            if ($this->wantsJsonRequest()) {
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());

                return;
            }
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            if ($this->wantsJsonRequest()) {
                Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to reschedule appointment.');

                return;
            }
            flash('error', $e->getMessage());
        }
        header('Location: /appointments/' . $id);
        exit;
    }

    public function updateStatusAction(int $id): void
    {
        $status = trim((string) ($_POST['status'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $cancellationReasonId = trim((string) ($_POST['cancellation_reason_id'] ?? '')) !== '' ? (int) $_POST['cancellation_reason_id'] : null;
        $noShowReasonId = trim((string) ($_POST['no_show_reason_id'] ?? '')) !== '' ? (int) $_POST['no_show_reason_id'] : null;
        try {
            $this->service->updateStatus($id, $status, $notes, $cancellationReasonId, $noShowReasonId);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => true,
                    'data' => [
                        'message' => 'Appointment status updated.',
                        'refresh_calendar' => true,
                        'reload_url' => '/appointments/' . $id,
                    ],
                ]);
            }
            flash('success', 'Appointment status updated.');
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => false,
                    'error' => ['message' => $e->getMessage()],
                ], 422);
            }
            flash('error', $e->getMessage());
        }
        header('Location: /appointments/' . $id);
        exit;
    }

    public function edit(int $id): void
    {
        $appointment = $this->repo->find($id);
        if (!$appointment) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($appointment)) {
            return;
        }
        $appointment = $this->addFormDatetimeFields($appointment);
        $branchId = $appointment['branch_id'] ?? null;
        $clients = $this->clientList->list($branchId);
        $services = $this->serviceList->list($branchId);
        $staff = $this->staffSelectOptionsForAppointment($appointment);
        $rooms = $this->roomList->list($branchId);
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        if ($this->isDrawerRequest()) {
            require base_path('modules/appointments/views/drawer/edit.php');
            return;
        }
        require base_path('modules/appointments/views/edit.php');
    }

    public function update(int $id): void
    {
        $appointment = $this->repo->find($id);
        if (!$appointment) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($appointment)) {
            return;
        }
        $data = $this->parseInput();
        $errors = $this->validate($data);
        if (!empty($errors)) {
            $appointment = array_merge($appointment, $data);
            $this->renderEditForm($id, $appointment, $errors);
            return;
        }
        try {
            $this->service->update($id, $data);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => true,
                    'data' => [
                        'message' => 'Appointment updated.',
                        'refresh_calendar' => true,
                        'open_url' => '/appointments/' . $id,
                    ],
                ]);
            }
            flash('success', 'Appointment updated.');
            header('Location: /appointments/' . $id);
            exit;
        } catch (\Core\Errors\AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException $e) {
            $errors['_conflict'] = $e->getMessage();
            $appointment = array_merge($appointment, $data);
            $this->renderEditForm($id, $appointment, $errors);
        } catch (\InvalidArgumentException $e) {
            $errors['_general'] = $e->getMessage();
            $appointment = array_merge($appointment, $data);
            $this->renderEditForm($id, $appointment, $errors);
        }
    }

    public function destroy(int $id): void
    {
        $this->service->delete($id);
        if ($this->wantsJsonRequest()) {
            $this->respondJson([
                'success' => true,
                'data' => [
                    'message' => 'Appointment deleted.',
                    'refresh_calendar' => true,
                    'close_drawer' => true,
                ],
            ]);
        }
        flash('success', 'Appointment deleted.');
        header('Location: /appointments');
        exit;
    }

    /**
     * Calendar day JSON (versioned contract — see `day_calendar_contract` in payload and booking concurrency doc §13).
     *
     * Stable v1 keys: date, branch_id, staff, appointments_by_staff, blocked_by_staff, time_grid, capabilities.
     * Additive (backward compatible): appointment_calendar_display (includes series_show_start_time / series_label_mode for
     * rows with series_id); each appointment may include series_id, display_flags, created_at, and client_no_show_alert.
     */
    public function dayCalendar(): void
    {
        $date = trim((string) ($_GET['date'] ?? ''));
        if ($date === '') {
            $date = date('Y-m-d');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $this->respondDayCalendarJsonError('VALIDATION_FAILED', 'date must be YYYY-MM-DD', 422);

            return;
        }
        try {
            $branchId = $this->resolveAppointmentBranchFromGetOrFail();
        } catch (\Core\Errors\AccessDeniedException $e) {
            $this->respondDayCalendarJsonError('FORBIDDEN', 'Branch access denied.', 403);

            return;
        } catch (\DomainException $e) {
            $this->respondDayCalendarJsonError('VALIDATION_FAILED', $e->getMessage(), 422);

            return;
        }
        $appointmentDisplaySettings = $this->settings->getAppointmentSettings($branchId);
        $staff = $this->availability->listActiveStaff($branchId);
        $appointmentsByStaff = $this->applyDayCalendarAppointmentDisplay(
            $this->availability->listDayAppointmentsGroupedByStaff($date, $branchId),
            $appointmentDisplaySettings,
            $this->appointmentsProfile
        );
        $blockedByStaff = $this->availability->listDayBlockedSlotsGroupedByStaff($date, $branchId);
        $grid = $this->availability->getDayGrid($date, $branchId);
        $branchHours = $this->branchOperatingHours->getDayHoursMeta($branchId, $date);
        $closureDateMeta = $this->resolveClosureDateMeta($branchId, $date);
        $effectiveBranchHours = $this->applyClosureDateOperationalPrecedence($branchHours, $closureDateMeta);
        $outOfEnvelopeCount = $this->countOutOfEnvelopeAppointments($appointmentsByStaff, $effectiveBranchHours);
        $closedDayRecordsCount = $this->countTotalRecords($appointmentsByStaff) + $this->countTotalRecords($blockedByStaff);
        $timeGrid = [
            'date' => $grid['date'] ?? $date,
            'slot_minutes' => isset($grid['slot_minutes']) ? (int) $grid['slot_minutes'] : 15,
            'day_start' => isset($grid['day_start']) && (string) $grid['day_start'] !== '' ? substr((string) $grid['day_start'], 0, 5) : '09:00',
            'day_end' => isset($grid['day_end']) && (string) $grid['day_end'] !== '' ? substr((string) $grid['day_end'], 0, 5) : '18:00',
        ];
        $timeGrid = $this->normalizeCalendarTimeGridBounds($timeGrid, $appointmentsByStaff, $blockedByStaff, $effectiveBranchHours);

        $this->respondJson(array_merge($this->dayCalendarContractEnvelope(), [
            'date' => $date,
            'branch_id' => $branchId,
            'branch_timezone' => \Core\App\ApplicationTimezone::getAppliedIdentifier() ?? 'UTC',
            'staff' => $staff,
            'appointments_by_staff' => $appointmentsByStaff,
            'appointment_calendar_display' => [
                'show_start_time' => $appointmentDisplaySettings['calendar_service_show_start_time'],
                'label_mode' => $appointmentDisplaySettings['calendar_service_label_mode'],
                'series_show_start_time' => $appointmentDisplaySettings['calendar_series_show_start_time'],
                'series_label_mode' => $appointmentDisplaySettings['calendar_series_label_mode'],
            ],
            'blocked_by_staff' => $blockedByStaff,
            'time_grid' => $timeGrid,
            'branch_operating_hours' => [
                'branch_hours_available' => (bool) ($effectiveBranchHours['branch_hours_available'] ?? false),
                'is_closed_day' => (bool) ($effectiveBranchHours['is_closed_day'] ?? false),
                'open_time' => $effectiveBranchHours['open_time'] ?? null,
                'close_time' => $effectiveBranchHours['close_time'] ?? null,
                'out_of_hours_appointments' => $outOfEnvelopeCount,
            ],
            'closure_date' => [
                'storage_ready' => (bool) ($closureDateMeta['storage_ready'] ?? false),
                'active' => (bool) ($closureDateMeta['active'] ?? false),
                'title' => $closureDateMeta['title'] ?? null,
                'notes' => $closureDateMeta['notes'] ?? null,
                'records_visible_count' => $closedDayRecordsCount,
            ],
        ]));
    }

    /**
     * JSON month summary for the appointments calendar control plane (per-day counts, closed-day truth, branch-local today).
     * Query: branch_id (resolved same as day calendar), year, month (optional — default from date=YYYY-MM-DD), date (selected day).
     */
    public function calendarMonthSummary(): void
    {
        $selRaw = trim((string) ($_GET['date'] ?? ''));
        if ($selRaw === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $selRaw) !== 1) {
            $selRaw = date('Y-m-d');
        }

        $y = (int) ($_GET['year'] ?? 0);
        $m = (int) ($_GET['month'] ?? 0);
        if ($y < 1970 || $y > 2100 || $m < 1 || $m > 12) {
            if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $selRaw, $mm)) {
                $y = (int) $mm[1];
                $m = (int) $mm[2];
            } else {
                $y = (int) date('Y');
                $m = (int) date('n');
            }
        }

        try {
            $branchId = $this->resolveAppointmentBranchFromGetOrFail();
        } catch (\Core\Errors\AccessDeniedException $e) {
            $this->respondMonthSummaryJsonError('FORBIDDEN', 'Branch access denied.', 403);

            return;
        } catch (\DomainException $e) {
            $this->respondMonthSummaryJsonError('VALIDATION_FAILED', $e->getMessage(), 422);

            return;
        }

        try {
            $payload = $this->calendarMonthSummary->buildPayload(
                $branchId,
                $y,
                $m,
                $selRaw,
                date('Y-m-d')
            );
            $this->respondJson($payload);
        } catch (\InvalidArgumentException $e) {
            $this->respondMonthSummaryJsonError('VALIDATION_FAILED', $e->getMessage(), 422);
        }
    }

    /**
     * JSON week summary (Mon–Sun) for the weekly-first smart calendar card.
     * Query: branch_id (same resolution as day calendar), date (selected YYYY-MM-DD).
     */
    public function calendarWeekSummary(): void
    {
        $selRaw = trim((string) ($_GET['date'] ?? ''));
        if ($selRaw === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $selRaw) !== 1) {
            $selRaw = date('Y-m-d');
        }

        try {
            $branchId = $this->resolveAppointmentBranchFromGetOrFail();
        } catch (\Core\Errors\AccessDeniedException $e) {
            $this->respondWeekSummaryJsonError('FORBIDDEN', 'Branch access denied.', 403);

            return;
        } catch (\DomainException $e) {
            $this->respondWeekSummaryJsonError('VALIDATION_FAILED', $e->getMessage(), 422);

            return;
        }

        try {
            $payload = $this->calendarMonthSummary->buildWeekPayload(
                $branchId,
                $selRaw,
                date('Y-m-d')
            );
            $this->respondJson($payload);
        } catch (\InvalidArgumentException $e) {
            $this->respondWeekSummaryJsonError('VALIDATION_FAILED', $e->getMessage(), 422);
        }
    }

    /**
     * Top-level fields shared by success and error responses for GET /calendar/day.
     *
     * @return array{day_calendar_contract: array{name: string, version: int}, capabilities: array{move_preview: bool}}
     */
    private function dayCalendarContractEnvelope(): array
    {
        return [
            'day_calendar_contract' => [
                'name' => self::DAY_CALENDAR_CONTRACT_NAME,
                'version' => self::DAY_CALENDAR_CONTRACT_VERSION,
            ],
            'capabilities' => [
                'move_preview' => false,
            ],
        ];
    }

    /**
     * Read-side display metadata for day calendar appointments (no persistence changes).
     *
     * @param array<int, list<array<string, mixed>>> $grouped
     * @param array<string, mixed> $apt {@see SettingsService::getAppointmentSettings}
     * @return array<int, list<array<string, mixed>>>
     */
    private function applyDayCalendarAppointmentDisplay(
        array $grouped,
        array $apt,
        ClientAppointmentProfileProvider $appointmentsProfile,
    ): array {
        $prebookEnabled = !empty($apt['prebook_display_enabled']);
        $preVal = max(1, min(9999, (int) ($apt['prebook_threshold_value'] ?? 2)));
        $preUnit = (($apt['prebook_threshold_unit'] ?? 'hours') === 'minutes') ? 'minutes' : 'hours';
        $minLeadSec = $preUnit === 'hours' ? $preVal * 3600 : $preVal * 60;

        $noShowAlertByClientId = [];

        foreach ($grouped as $sid => $list) {
            foreach ($list as $i => $a) {
                $flags = ['prebooked' => false];
                if ($prebookEnabled && !empty($a['created_at']) && !empty($a['start_at'])) {
                    $c = strtotime((string) $a['created_at']);
                    $s = strtotime((string) $a['start_at']);
                    if ($c !== false && $s !== false && $s >= $c) {
                        $flags['prebooked'] = ($s - $c) >= $minLeadSec;
                    }
                }
                $grouped[$sid][$i]['display_flags'] = $flags;

                $cid = isset($a['client_id']) ? (int) $a['client_id'] : 0;
                if ($cid > 0) {
                    if (!array_key_exists($cid, $noShowAlertByClientId)) {
                        $noShowAlertByClientId[$cid] = $appointmentsProfile->getSummary($cid)['no_show_alert'] ?? null;
                    }
                    $grouped[$sid][$i]['client_no_show_alert'] = $noShowAlertByClientId[$cid];
                } else {
                    $grouped[$sid][$i]['client_no_show_alert'] = null;
                }
            }
        }

        return $grouped;
    }

    public function dayCalendarPage(): void
    {
        $date = $this->queryDateOrNull() ?? date('Y-m-d');
        try {
            $branchId = $this->resolveAppointmentBranchFromGetOrFail();
        } catch (\Core\Errors\AccessDeniedException $e) {
            $this->failAppointmentBranchAccessDenied($e);
        } catch (\DomainException $e) {
            $this->failAppointmentBranchResolution($e);
        }
        $branches = $this->getBranches();
        $blockedSlots = $this->blockedSlotRepo->listForDate($date, $branchId);
        $staffOptions = $this->staffList->list($branchId);
        $flash = flash();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $workspace = $this->workspaceContext('calendar', $branchId, $date);
        $branchTimezone = \Core\App\ApplicationTimezone::getAppliedIdentifier() ?? 'UTC';
        $calendarWeekSummaryBootstrap = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            try {
                $calendarWeekSummaryBootstrap = $this->calendarMonthSummary->buildWeekPayload(
                    $branchId,
                    $date,
                    date('Y-m-d')
                );
            } catch (\Throwable) {
                $calendarWeekSummaryBootstrap = null;
            }
        }
        require base_path('modules/appointments/views/calendar-day.php');
    }

    public function waitlistPage(): void
    {
        $date = $this->queryDateOrNull() ?? date('Y-m-d');
        try {
            $branchId = $this->resolveAppointmentBranchFromGetOrFail();
        } catch (\Core\Errors\AccessDeniedException $e) {
            $this->failAppointmentBranchAccessDenied($e);
        } catch (\DomainException $e) {
            $this->failAppointmentBranchResolution($e);
        }
        $this->waitlistService->expireDueOffers($branchId);
        $branches = $this->getBranches();
        $status = trim((string) ($_GET['status'] ?? '')) ?: null;
        $serviceId = trim((string) ($_GET['service_id'] ?? '')) !== '' ? (int) $_GET['service_id'] : null;
        $preferredStaffId = trim((string) ($_GET['preferred_staff_id'] ?? '')) !== '' ? (int) $_GET['preferred_staff_id'] : null;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $filters = array_filter([
            'branch_id' => $branchId,
            'date' => $date,
            'status' => $status,
            'service_id' => $serviceId,
            'preferred_staff_id' => $preferredStaffId,
        ], static fn ($v): bool => $v !== null && $v !== '');
        $entries = $this->waitlistRepo->list($filters, $perPage, ($page - 1) * $perPage);
        $total = $this->waitlistRepo->count($filters);
        $services = $this->serviceList->list($branchId);
        $staff = $this->staffList->list($branchId);
        $suggestionStatus = (string) $status !== '' && in_array((string) $status, ['waiting', 'matched', 'offered'], true)
            ? $status
            : ['waiting', 'offered'];
        $suggestionFilters = array_filter([
            'branch_id' => $branchId,
            'date' => $date,
            'service_id' => $serviceId,
            'preferred_staff_id' => $preferredStaffId,
            'status' => $suggestionStatus,
        ], static fn ($v): bool => $v !== null && $v !== '');
        $suggestedEntries = $this->waitlistRepo->list($suggestionFilters, 10, 0);
        $flash = flash();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $workspace = $this->workspaceContext('waitlist', $branchId, $date);
        require base_path('modules/appointments/views/waitlist.php');
    }

    public function waitlistCreate(): void
    {
        try {
            $branchId = $this->resolveAppointmentBranchFromGetOrFail();
        } catch (\Core\Errors\AccessDeniedException $e) {
            $this->failAppointmentBranchAccessDenied($e);
        } catch (\DomainException $e) {
            $this->failAppointmentBranchResolution($e);
        }
        $date = $this->queryDateOrNull() ?? date('Y-m-d');
        $branches = $this->getBranches();
        $clients = $this->clientList->list($branchId);
        $services = $this->serviceList->list($branchId);
        $staff = $this->staffList->list($branchId);
        $workspace = $this->workspaceContext('waitlist', $branchId, $date);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $entry = ['preferred_date' => $date, 'status' => 'waiting'];
        require base_path('modules/appointments/views/waitlist-create.php');
    }

    public function waitlistStore(): void
    {
        $branchRaw = trim((string) ($_GET['branch_id'] ?? ''));
        if ($branchRaw === '') {
            $branchRaw = trim((string) ($_POST['branch_id'] ?? ''));
        }
        try {
            $canonicalBranchId = $this->resolveAppointmentBranchForPrincipalFromOptionalRequestId(
                $branchRaw !== '' ? $branchRaw : null
            );
        } catch (\Core\Errors\AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException $e) {
            $this->failAppointmentBranchResolution($e);
        }

        $payload = [
            'branch_id' => $canonicalBranchId,
            'client_id' => trim((string) ($_POST['client_id'] ?? '')) !== '' ? (int) $_POST['client_id'] : null,
            'service_id' => trim((string) ($_POST['service_id'] ?? '')) !== '' ? (int) $_POST['service_id'] : null,
            'preferred_staff_id' => trim((string) ($_POST['preferred_staff_id'] ?? '')) !== '' ? (int) $_POST['preferred_staff_id'] : null,
            'preferred_date' => trim((string) ($_POST['preferred_date'] ?? '')),
            'preferred_time_from' => trim((string) ($_POST['preferred_time_from'] ?? '')) ?: null,
            'preferred_time_to' => trim((string) ($_POST['preferred_time_to'] ?? '')) ?: null,
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
            'status' => 'waiting',
        ];
        try {
            $this->waitlistService->create($payload);
            flash('success', 'Waitlist entry created.');
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            flash('error', $e->getMessage());
        }
        header('Location: /appointments/waitlist' . $this->buildQueryString([
            'branch_id' => $payload['branch_id'],
            'date' => $payload['preferred_date'] !== '' ? $payload['preferred_date'] : null,
        ]));
        exit;
    }

    public function waitlistUpdateStatusAction(int $id): void
    {
        $status = trim((string) ($_POST['status'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $redirectBranchId = trim((string) ($_POST['redirect_branch_id'] ?? '')) !== '' ? (int) $_POST['redirect_branch_id'] : null;
        $redirectDate = trim((string) ($_POST['redirect_date'] ?? ''));
        try {
            $this->waitlistService->updateStatus($id, $status, $notes);
            flash('success', 'Waitlist status updated.');
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            flash('error', $e->getMessage());
        }
        header('Location: /appointments/waitlist' . $this->buildQueryString(array_filter([
            'branch_id' => $redirectBranchId,
            'date' => $redirectDate !== '' ? $redirectDate : null,
        ], static fn ($v): bool => $v !== null)));
        exit;
    }

    public function waitlistLinkAppointmentAction(int $id): void
    {
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $redirectBranchId = trim((string) ($_POST['redirect_branch_id'] ?? '')) !== '' ? (int) $_POST['redirect_branch_id'] : null;
        $redirectDate = trim((string) ($_POST['redirect_date'] ?? ''));
        if ($appointmentId <= 0) {
            flash('error', 'appointment_id is required.');
            header('Location: /appointments/waitlist' . $this->buildQueryString(array_filter([
                'branch_id' => $redirectBranchId,
                'date' => $redirectDate !== '' ? $redirectDate : null,
            ], static fn ($v): bool => $v !== null)));
            exit;
        }
        try {
            $this->waitlistService->linkToAppointment($id, $appointmentId);
            flash('success', 'Waitlist linked to appointment.');
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            flash('error', $e->getMessage());
        }
        header('Location: /appointments/waitlist' . $this->buildQueryString(array_filter([
            'branch_id' => $redirectBranchId,
            'date' => $redirectDate !== '' ? $redirectDate : null,
        ], static fn ($v): bool => $v !== null)));
        exit;
    }

    public function waitlistConvertAction(int $id): void
    {
        $branchRaw = trim((string) ($_POST['branch_id'] ?? ''));
        try {
            $canonicalBranchId = $this->resolveAppointmentBranchForPrincipalFromOptionalRequestId(
                $branchRaw !== '' ? $branchRaw : null
            );
        } catch (\Core\Errors\AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
            header('Location: /appointments/waitlist');
            exit;
        }
        $payload = [
            'client_id' => trim((string) ($_POST['client_id'] ?? '')) !== '' ? (int) $_POST['client_id'] : null,
            'service_id' => trim((string) ($_POST['service_id'] ?? '')) !== '' ? (int) $_POST['service_id'] : null,
            'staff_id' => trim((string) ($_POST['staff_id'] ?? '')) !== '' ? (int) $_POST['staff_id'] : null,
            'branch_id' => $canonicalBranchId,
            'start_time' => trim((string) ($_POST['start_time'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
        try {
            $appointmentId = $this->waitlistService->convertToAppointment($id, $payload);
            flash('success', 'Waitlist converted to appointment.');
            header('Location: /appointments/' . $appointmentId);
            exit;
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            flash('error', $e->getMessage());
            header('Location: /appointments/waitlist' . $this->buildQueryString(array_filter([
                'branch_id' => $canonicalBranchId,
            ], static fn ($v): bool => $v !== null)));
            exit;
        }
    }

    public function blockedSlotStore(): void
    {
        $payload = [
            'branch_id' => trim((string) ($_POST['branch_id'] ?? '')) !== '' ? (int) $_POST['branch_id'] : null,
            'staff_id' => trim((string) ($_POST['staff_id'] ?? '')) !== '' ? (int) $_POST['staff_id'] : null,
            'title' => trim((string) ($_POST['title'] ?? '')),
            'block_date' => trim((string) ($_POST['block_date'] ?? '')),
            'start_time' => trim((string) ($_POST['start_time'] ?? '')),
            'end_time' => trim((string) ($_POST['end_time'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];
        try {
            $this->blockedSlotService->create($payload);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => true,
                    'data' => [
                        'message' => 'Blocked slot created.',
                        'refresh_calendar' => true,
                        'reload_url' => '/appointments/blocked-slots/panel' . $this->buildQueryString([
                            'branch_id' => $payload['branch_id'],
                            'date' => $payload['block_date'] !== '' ? $payload['block_date'] : null,
                        ]),
                    ],
                ]);
            }
            flash('success', 'Blocked slot created.');
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => false,
                    'error' => ['message' => $e->getMessage()],
                ], 422);
            }
            flash('error', $e->getMessage());
        }
        header('Location: /appointments/calendar/day' . $this->buildQueryString([
            'branch_id' => $payload['branch_id'],
            'date' => $payload['block_date'] !== '' ? $payload['block_date'] : null,
        ]));
        exit;
    }

    public function blockedSlotDelete(int $id): void
    {
        $date = trim((string) ($_POST['date'] ?? ''));
        $branchId = trim((string) ($_POST['branch_id'] ?? '')) !== '' ? (int) $_POST['branch_id'] : null;
        try {
            $this->blockedSlotService->delete($id);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => true,
                    'data' => [
                        'message' => 'Blocked slot deleted.',
                        'refresh_calendar' => true,
                        'reload_url' => '/appointments/blocked-slots/panel' . $this->buildQueryString([
                            'branch_id' => $branchId,
                            'date' => $date !== '' ? $date : null,
                        ]),
                    ],
                ]);
            }
            flash('success', 'Blocked slot deleted.');
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => false,
                    'error' => ['message' => $e->getMessage()],
                ], 422);
            }
            flash('error', $e->getMessage());
        }
        header('Location: /appointments/calendar/day' . $this->buildQueryString([
            'branch_id' => $branchId,
            'date' => $date !== '' ? $date : null,
        ]));
        exit;
    }

    public function blockedSlotsPanel(): void
    {
        $date = $this->queryDateOrNull() ?? date('Y-m-d');
        try {
            $branchId = $this->resolveAppointmentBranchFromGetOrFail();
        } catch (\Core\Errors\AccessDeniedException $e) {
            if ($this->wantsJsonRequest()) {
                Response::jsonPublicApiError(403, 'FORBIDDEN', 'Branch access denied.');
            }
            $this->failAppointmentBranchAccessDenied($e);
        } catch (\DomainException $e) {
            if ($this->wantsJsonRequest()) {
                $this->respondJson([
                    'success' => false,
                    'error' => ['message' => $e->getMessage()],
                ], 422);
            }
            $this->failAppointmentBranchResolution($e);
        }
        $branches = $this->getBranches();
        $blockedSlots = $this->blockedSlotRepo->listForDate($date, $branchId);
        $staffOptions = $this->staffList->list($branchId);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/appointments/views/drawer/blocked-slots.php');
    }

    /**
     * Internal/admin JSON: create `appointment_series` and materialize occurrences sequentially from the plan.
     *
     * Truth: each row uses service duration for `end_at` (not the recurrence generator’s `end_at` field).
     * `start_time`/`end_time` must match that duration. On first unresolvable slot conflict, creation stops
     * and leaves a partial series (see `skipped_conflict_count` / `first_conflict_date`); further dates
     * require POST /appointments/series/materialize (no cron in-repo).
     */
    public function storeSeriesAction(): void
    {
        $payload = [
            'branch_id' => trim((string) ($_POST['branch_id'] ?? '')) !== '' ? (int) $_POST['branch_id'] : null,
            'client_id' => trim((string) ($_POST['client_id'] ?? '')) !== '' ? (int) $_POST['client_id'] : null,
            'service_id' => trim((string) ($_POST['service_id'] ?? '')) !== '' ? (int) $_POST['service_id'] : null,
            'staff_id' => trim((string) ($_POST['staff_id'] ?? '')) !== '' ? (int) $_POST['staff_id'] : null,
            'recurrence_type' => trim((string) ($_POST['recurrence_type'] ?? '')),
            'weekday' => trim((string) ($_POST['weekday'] ?? '')) !== '' ? (int) $_POST['weekday'] : null,
            'start_date' => trim((string) ($_POST['start_date'] ?? '')),
            'end_date' => trim((string) ($_POST['end_date'] ?? '')) ?: null,
            'occurrences_count' => trim((string) ($_POST['occurrences_count'] ?? '')) !== '' ? (int) $_POST['occurrences_count'] : null,
            'start_time' => trim((string) ($_POST['start_time'] ?? '')),
            'end_time' => trim((string) ($_POST['end_time'] ?? '')),
            'notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];

        try {
            $result = $this->seriesService->createSeriesWithOccurrences($payload);
            if (((int) ($result['created_count'] ?? 0)) <= 0) {
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'Series creation did not create any appointments.');

                return;
            }
            $this->respondJson([
                'success' => true,
                'data' => $result,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Core\Errors\AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to create appointment series.');
        }
    }

    /**
     * Materialize up to `max_batch` future planned starts not yet present for this `series_id` (idempotent).
     * POST /appointments/series/materialize — manual/cron-adjacent; no in-repo scheduler calls this.
     */
    public function seriesMaterializeAction(): void
    {
        $in = $this->parseJsonOrPostPayload();
        $seriesId = (int) ($in['series_id'] ?? 0);
        $maxBatch = isset($in['max_batch']) ? (int) $in['max_batch'] : 26;
        try {
            $result = $this->seriesService->materializeFutureOccurrences($seriesId, $maxBatch);
            $this->respondJson(['success' => true, 'data' => $result]);
        } catch (\InvalidArgumentException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Core\Errors\AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to materialize series occurrences.');
        }
    }

    /**
     * Cancel scope is only `whole` or `forward` (truncate plan from `from_date` + cancel matching rows).
     * Bulk paths bypass branch cancellation settings; single-occurrence cancel uses normal policy.
     * POST /appointments/series/cancel — scope, series_id, optional from_date (forward), notes.
     */
    public function seriesCancelAction(): void
    {
        $in = $this->parseJsonOrPostPayload();
        $seriesId = (int) ($in['series_id'] ?? 0);
        $scope = strtolower(trim((string) ($in['scope'] ?? '')));
        $fromDate = trim((string) ($in['from_date'] ?? ''));
        $notes = trim((string) ($in['notes'] ?? '')) ?: null;
        try {
            if ($scope === 'whole') {
                $result = $this->seriesService->cancelEntireSeries($seriesId, $notes);
            } elseif ($scope === 'forward') {
                if ($fromDate === '') {
                    Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'from_date is required for forward scope.');
                    return;
                }
                $result = $this->seriesService->cancelSeriesForwardFrom($seriesId, $fromDate, $notes);
            } else {
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'scope must be whole or forward.');
                return;
            }
            $this->respondJson(['success' => true, 'data' => $result]);
        } catch (\InvalidArgumentException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Core\Errors\AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to cancel series.');
        }
    }

    /**
     * Internal JSON/form: cancel one series-linked appointment (normal cancellation policy applies).
     * POST /appointments/series/occurrence/cancel — appointment_id, optional notes.
     */
    public function seriesOccurrenceCancelAction(): void
    {
        $in = $this->parseJsonOrPostPayload();
        $appointmentId = (int) ($in['appointment_id'] ?? 0);
        $notes = trim((string) ($in['notes'] ?? '')) ?: null;
        try {
            $this->seriesService->cancelSeriesOccurrence($appointmentId, $notes);
            $this->respondJson(['success' => true]);
        } catch (\InvalidArgumentException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Core\Errors\AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Throwable $e) {
            $this->exitIfAccessDenied($e);
            Response::jsonPublicApiError(500, 'SERVER_ERROR', 'Failed to cancel series occurrence.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonOrPostPayload(): array
    {
        $ct = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
        if (str_contains($ct, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            return [];
        }

        /** @var array<string, mixed> */
        return $_POST;
    }

    public function slots(): void
    {
        $serviceId = (int) ($_GET['service_id'] ?? 0);
        $date = trim((string) ($_GET['date'] ?? ''));
        $staffIdRaw = trim((string) ($_GET['staff_id'] ?? ''));
        $staffId = $staffIdRaw !== '' ? (int) $staffIdRaw : null;
        try {
            $branchId = $this->resolveAppointmentBranchFromGetOrFail();
        } catch (\Core\Errors\AccessDeniedException $e) {
            Response::jsonPublicApiError(403, 'FORBIDDEN', 'Branch access denied.');
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());

            return;
        }

        if ($serviceId <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'service_id is required.');

            return;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'date must be YYYY-MM-DD.');

            return;
        }

        $service = $this->availability->getActiveServiceForScope($serviceId, $branchId);
        if (!$service) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'Service is not active for the requested scope.');

            return;
        }

        if ($staffId !== null) {
            $staff = $this->availability->getActiveStaffForScope($staffId, $branchId, $serviceId);
            if (!$staff) {
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'Staff is not active for the requested scope.');

                return;
            }
        }

        $roomIdForSlots = null;
        $roomRaw = trim((string) ($_GET['room_id'] ?? ''));
        if ($roomRaw !== '') {
            $roomIdForSlots = (int) $roomRaw;
            if ($roomIdForSlots <= 0) {
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'room_id must be a positive integer when provided.');

                return;
            }
            $roomAllowed = false;
            foreach ($this->roomList->list($branchId) as $r) {
                if ((int) ($r['id'] ?? 0) === $roomIdForSlots) {
                    $roomAllowed = true;
                    break;
                }
            }
            if (!$roomAllowed) {
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'Room is not available for the requested branch scope.');

                return;
            }
        }

        $branchHoursMeta = $this->availability->getBranchOperatingHoursMeta($branchId, $date);
        $slots = $this->availability->getAvailableSlots($serviceId, $date, $staffId, $branchId, 'internal', $roomIdForSlots);
        $apptSettings = $this->settings->getAppointmentSettings($branchId);
        $this->respondJson([
            'success' => true,
            'data' => [
                'date' => $date,
                'service_id' => $serviceId,
                'staff_id' => $staffId,
                'room_id' => $roomIdForSlots,
                'slots' => $slots,
                'check_staff_availability_in_search' => !empty($apptSettings['check_staff_availability_in_search']),
                'allow_staff_booking_on_off_days' => !empty($apptSettings['allow_staff_booking_on_off_days']),
                'branch_operating_hours' => [
                    'branch_hours_available' => (bool) ($branchHoursMeta['branch_hours_available'] ?? false),
                    'is_closed_day' => (bool) ($branchHoursMeta['is_closed_day'] ?? false),
                    'open_time' => $branchHoursMeta['open_time'] ?? null,
                    'close_time' => $branchHoursMeta['close_time'] ?? null,
                ],
                'availability_notice' => $branchHoursMeta['message'] ?? null,
            ],
        ]);
    }

    /**
     * Staff availability for a single date or date range (reusable backend shape).
     * GET ?date=Y-m-d for one day, or ?date_from=Y-m-d&date_to=Y-m-d for range. Branch from BranchContext.
     */
    public function staffAvailabilityAction(int $id): void
    {
        if ($id <= 0) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'Invalid staff id.');

            return;
        }
        try {
            $branchId = $this->resolveAppointmentBranchFromGetOrFail();
        } catch (\Core\Errors\AccessDeniedException $e) {
            Response::jsonPublicApiError(403, 'FORBIDDEN', 'Branch access denied.');
        } catch (\DomainException $e) {
            Response::jsonPublicApiError(422, 'VALIDATION_FAILED', $e->getMessage());
            return;
        }
        $date = trim((string) ($_GET['date'] ?? ''));
        $dateFrom = trim((string) ($_GET['date_from'] ?? ''));
        $dateTo = trim((string) ($_GET['date_to'] ?? ''));
        if ($date !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'date must be YYYY-MM-DD.');

                return;
            }
            $day = $this->availability->getStaffAvailabilityForDate($id, $date, $branchId);
            if ($day === null) {
                Response::jsonPublicApiError(404, 'NOT_FOUND', 'Staff not found or not active for scope.');

                return;
            }
            $this->respondJson(['success' => true, 'data' => $day]);
            return;
        }
        if ($dateFrom !== '' && $dateTo !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) !== 1) {
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'date_from and date_to must be YYYY-MM-DD.');

                return;
            }
            if (strtotime($dateTo) < strtotime($dateFrom)) {
                Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'date_to must not be before date_from.');

                return;
            }
            $days = $this->availability->getStaffAvailabilityForDateRange($id, $dateFrom, $dateTo, $branchId);
            $this->respondJson(['success' => true, 'data' => $days]);
            return;
        }
        Response::jsonPublicApiError(422, 'VALIDATION_FAILED', 'Provide date or date_from and date_to.');
    }

    private function parseInput(): array
    {
        $branchId = trim($_POST['branch_id'] ?? '') ? (int) $_POST['branch_id'] : null;
        $clientMembershipId = trim($_POST['client_membership_id'] ?? '') ? (int) $_POST['client_membership_id'] : null;
        $clientId = trim($_POST['client_id'] ?? '') ? (int) $_POST['client_id'] : null;
        $serviceId = trim($_POST['service_id'] ?? '') ? (int) $_POST['service_id'] : null;
        $staffId = trim($_POST['staff_id'] ?? '') ? (int) $_POST['staff_id'] : null;
        $roomId = trim($_POST['room_id'] ?? '') ? (int) $_POST['room_id'] : null;
        $date = trim($_POST['date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $startAt = $date && $startTime ? $date . ' ' . (strpos($startTime, ':') !== false ? $startTime : $startTime . ':00') : null;
        $endAt = $date && $endTime ? $date . ' ' . (strpos($endTime, ':') !== false ? $endTime : $endTime . ':00') : null;

        // end_at auto-calculation: when end_time not provided but service + start_at are, compute from service duration
        if (!$endAt && $startAt && $serviceId) {
            $service = $this->serviceList->find($serviceId);
            if ($service && ($service['duration_minutes'] ?? 0) > 0) {
                $endAt = date('Y-m-d H:i:s', strtotime($startAt) + (int) $service['duration_minutes'] * 60);
            }
        }

        $status = $_POST['status'] ?? 'scheduled';
        if (!in_array($status, ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'], true)) {
            $status = 'scheduled';
        }
        return [
            'client_id' => $clientId,
            'service_id' => $serviceId,
            'staff_id' => $staffId,
            'room_id' => $roomId,
            'branch_id' => $branchId,
            'client_membership_id' => $clientMembershipId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status' => $status,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'cancellation_reason_id' => trim((string) ($_POST['cancellation_reason_id'] ?? '')) !== '' ? (int) $_POST['cancellation_reason_id'] : null,
            'no_show_reason_id' => trim((string) ($_POST['no_show_reason_id'] ?? '')) !== '' ? (int) $_POST['no_show_reason_id'] : null,
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (empty($data['client_id'])) {
            $errors['client_id'] = 'Client is required.';
        }
        if (empty($data['start_at'])) {
            $errors['start_time'] = 'Start date and time are required.';
        }
        if (empty($data['end_at'])) {
            $errors['end_time'] = 'End time is required (or select a service to auto-calculate from duration).';
        }
        if (!empty($data['start_at']) && !empty($data['end_at']) && strtotime($data['end_at']) <= strtotime($data['start_at'])) {
            $errors['end_time'] = 'End time must be after start time.';
        }
        return $errors;
    }

    private function renderCreateForm(array $data, array $errors): void
    {
        $branchId = $data['branch_id'] ?? null;
        $clients = $this->clientList->list($branchId);
        $services = $this->serviceList->list($branchId);
        $staff = $this->staffList->list($branchId);
        $rooms = $this->roomList->list($branchId);
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        $appointment = $this->addFormDatetimeFields($data);
        $appointment['status'] = $appointment['status'] ?? 'scheduled';
        $workspace = $this->workspaceContext('new', $branchId, $appointment['date'] ?? null);
        if ($this->wantsJsonRequest()) {
            $html = $this->renderPartialToString('modules/appointments/views/drawer/create.php', get_defined_vars());
            $this->respondJson([
                'success' => false,
                'error' => ['message' => 'Please fix the highlighted booking fields.'],
                'data' => ['html' => $html],
            ], 422);
        }
        require base_path('modules/appointments/views/create.php');
        exit;
    }

    private function renderEditForm(int $id, array $appointment, array $errors): void
    {
        $branchId = $appointment['branch_id'] ?? null;
        $clients = $this->clientList->list($branchId);
        $services = $this->serviceList->list($branchId);
        $staff = $this->staffSelectOptionsForAppointment($appointment);
        $rooms = $this->roomList->list($branchId);
        $branches = $this->getBranches();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $appointment = $this->addFormDatetimeFields($appointment);
        if ($this->wantsJsonRequest()) {
            $html = $this->renderPartialToString('modules/appointments/views/drawer/edit.php', get_defined_vars());
            $this->respondJson([
                'success' => false,
                'error' => ['message' => 'Please fix the highlighted appointment fields.'],
                'data' => ['html' => $html],
            ], 422);
        }
        require base_path('modules/appointments/views/edit.php');
        exit;
    }

    /**
     * For booking contexts with a service, only staff allowed by service_staff + staff_groups scope + service_staff_groups.
     * Preserves the current assignee in the list even if configuration changed (display-only; saves still validate).
     *
     * @return list<array<string, mixed>>
     */
    private function staffSelectOptionsForAppointment(array $appointment): array
    {
        $branchId = isset($appointment['branch_id']) && $appointment['branch_id'] !== '' && $appointment['branch_id'] !== null
            ? (int) $appointment['branch_id']
            : null;
        $serviceId = isset($appointment['service_id']) && (int) ($appointment['service_id'] ?? 0) > 0
            ? (int) $appointment['service_id']
            : null;
        $rows = $serviceId !== null
            ? $this->availability->listStaffSelectableForService($serviceId, $branchId)
            : $this->staffList->list($branchId);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $cur = isset($appointment['staff_id']) ? (int) $appointment['staff_id'] : 0;
        if ($cur > 0 && !in_array($cur, $ids, true)) {
            $extra = Application::container()->get(\Modules\Staff\Repositories\StaffRepository::class)->find($cur);
            if (is_array($extra)) {
                $rows[] = $extra;
            }
        }

        return $rows;
    }

    private function addFormDatetimeFields(array $a): array
    {
        if (!empty($a['start_at'])) {
            $t = strtotime($a['start_at']);
            $a['date'] = date('Y-m-d', $t);
            $a['start_time'] = date('H:i', $t);
        } else {
            $a['date'] = $a['date'] ?? '';
            $a['start_time'] = $a['start_time'] ?? '';
        }
        if (!empty($a['end_at'])) {
            $a['end_time'] = date('H:i', strtotime($a['end_at']));
        } else {
            $a['end_time'] = $a['end_time'] ?? '';
        }
        return $a;
    }

    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null ? (int) $entity['branch_id'] : null;
            Application::container()->get(\Core\Branch\BranchContext::class)->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\Core\Errors\AccessDeniedException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handleException($e);
            exit;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }

    private function getBranches(): array
    {
        $all = $this->branchDirectory->getActiveBranchesForSelection();
        $userId = Application::container()->get(\Core\Auth\SessionAuth::class)->id();
        if ($userId === null || $userId <= 0) {
            return $all;
        }
        $allowed = $this->tenantBranchAccess->allowedBranchIdsForUser((int) $userId);
        if (empty($allowed)) {
            return $all;
        }
        return array_values(array_filter(
            $all,
            fn (array $b): bool => in_array((int) ($b['id'] ?? 0), $allowed, true)
        ));
    }

    /**
     * C-002: Canonical internal appointment branch — validated `branch_id` query param when present, else session
     * branch from {@see BranchContext}. No silent fallback to a default that ignores the URL selector.
     * Callers that catch \DomainException must redirect to a page that does NOT itself require branch resolution
     * (e.g. /dashboard) to avoid infinite redirect loops. Use {@see failAppointmentBranchResolution()} for this.
     *
     * @throws \DomainException
     */
    private function resolveAppointmentBranchFromGetOrFail(): int
    {
        $raw = trim((string) ($_GET['branch_id'] ?? ''));

        return $this->resolveAppointmentBranchForPrincipalFromOptionalRequestId($raw !== '' ? $raw : null);
    }

    /**
     * @throws AccessDeniedException when branch is not allowed for the current principal or fails org assert
     * @throws \DomainException for invalid branch_id, missing session branch, or unauthenticated user
     */
    private function resolveAppointmentBranchForPrincipalFromOptionalRequestId(?string $branchIdRaw): int
    {
        $trimmed = $branchIdRaw !== null ? trim($branchIdRaw) : '';
        if ($trimmed !== '') {
            $id = (int) $trimmed;
            if ($id <= 0) {
                throw new \DomainException('Invalid branch_id.');
            }
            $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($id);
            $userId = Application::container()->get(\Core\Auth\SessionAuth::class)->id();
            if ($userId === null || $userId <= 0) {
                throw new \DomainException('Authentication required.');
            }
            $allowed = $this->tenantBranchAccess->allowedBranchIdsForUser((int) $userId);
            if (!in_array($id, $allowed, true)) {
                throw new AccessDeniedException('Branch is not allowed for this principal.');
            }

            return $id;
        }

        $ctx = $this->branchContext->getCurrentBranchId();
        if ($ctx === null || $ctx <= 0) {
            throw new \DomainException('branch_id is required when no active session branch is selected.');
        }
        $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization((int) $ctx);
        $userId = Application::container()->get(\Core\Auth\SessionAuth::class)->id();
        if ($userId === null || $userId <= 0) {
            throw new \DomainException('Authentication required.');
        }
        $allowed = $this->tenantBranchAccess->allowedBranchIdsForUser((int) $userId);
        if (!in_array((int) $ctx, $allowed, true)) {
            throw new AccessDeniedException('Branch is not allowed for this principal.');
        }

        return (int) $ctx;
    }

    /**
     * Fail-closed branch resolution: flash the error and land on a page that does not itself
     * require branch resolution. Redirecting back to /appointments creates an infinite loop when
     * no session branch is active and no branch_id is in the URL.
     */
    private function failAppointmentBranchResolution(\DomainException $e): never
    {
        flash('error', $e->getMessage());
        header('Location: /dashboard');
        exit;
    }

    /**
     * Fail-closed forbidden-branch access: flash an honest message and redirect to a safe page.
     * HTML appointments pages must never render a raw error page for expected authorization failures.
     * For JSON/XHR callers, respond with 403 JSON before calling this.
     */
    private function failAppointmentBranchAccessDenied(AccessDeniedException $e): never
    {
        flash('error', 'You do not have access to the requested branch.');
        header('Location: /dashboard');
        exit;
    }

    private function queryDateOrNull(): ?string
    {
        $raw = trim((string) ($_GET['date'] ?? ''));
        if ($raw === '') {
            return null;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : null;
    }

    private function workspaceContext(string $activeTab, ?int $branchId = null, ?string $date = null): array
    {
        $listQuery = [];
        $calendarQuery = [];
        $waitlistQuery = [];

        if ($branchId !== null) {
            $listQuery['branch_id'] = $branchId;
            $calendarQuery['branch_id'] = $branchId;
            $waitlistQuery['branch_id'] = $branchId;
        }
        if ($date !== null && $date !== '') {
            $calendarQuery['date'] = $date;
            $waitlistQuery['date'] = $date;
        }

        $newAppointmentQuery = [];
        if ($branchId !== null) {
            $newAppointmentQuery['branch_id'] = $branchId;
        }
        if ($date !== null && $date !== '') {
            $newAppointmentQuery['date'] = $date;
        }

        return [
            'active_tab' => $activeTab,
            'tabs' => [
                ['id' => 'calendar', 'label' => 'Calendar', 'url' => '/appointments/calendar/day' . $this->buildQueryString($calendarQuery)],
                ['id' => 'list', 'label' => 'List', 'url' => '/appointments' . $this->buildQueryString($listQuery)],
                ['id' => 'waitlist', 'label' => 'Waitlist', 'url' => '/appointments/waitlist' . $this->buildQueryString($waitlistQuery)],
            ],
            'new_appointment_url' => '/appointments/create' . $this->buildQueryString($newAppointmentQuery),
        ];
    }

    /**
     * @param array<string, scalar> $query
     */
    private function buildQueryString(array $query): string
    {
        if ($query === []) {
            return '';
        }
        return '?' . http_build_query($query);
    }

    /**
     * Preserve day-calendar contract metadata for error consumers (same keys as success payload root).
     *
     * @param non-empty-string $code
     */
    private function respondDayCalendarJsonError(string $code, string $message, int $status = 422): void
    {
        Response::jsonPublicApiError($status, $code, $message, $this->dayCalendarContractEnvelope());
    }

    /**
     * @param non-empty-string $code
     */
    private function respondMonthSummaryJsonError(string $code, string $message, int $status = 422): void
    {
        Response::jsonPublicApiError($status, $code, $message, $this->calendarMonthSummary->contractEnvelope());
    }

    /**
     * @param non-empty-string $code
     */
    private function respondWeekSummaryJsonError(string $code, string $message, int $status = 422): void
    {
        Response::jsonPublicApiError($status, $code, $message, $this->calendarMonthSummary->weekContractEnvelope());
    }

    private function respondJson(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function wantsJsonRequest(): bool
    {
        $accept = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')));
        if ($accept !== '' && str_contains($accept, 'application/json')) {
            return true;
        }
        $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));

        return $requestedWith === 'xmlhttprequest';
    }

    private function isDrawerRequest(): bool
    {
        return (string) ($_GET['drawer'] ?? '') === '1'
            || (string) ($_SERVER['HTTP_X_APP_DRAWER'] ?? '') === '1';
    }

    private function normalizeDrawerTimePrefill(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function renderPartialToString(string $relativePath, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require base_path($relativePath);

        return (string) ob_get_clean();
    }

    /**
     * @param array<string,mixed> $timeGrid
     * @param array<int,mixed> $appointmentsByStaff
     * @param array<int,mixed> $blockedByStaff
     * @param array<string,mixed> $branchHours
     * @return array<string,mixed>
     */
    private function normalizeCalendarTimeGridBounds(array $timeGrid, array $appointmentsByStaff, array $blockedByStaff, array $branchHours): array
    {
        $dayStart = $this->normalizeTimeToHourMinute((string) ($timeGrid['day_start'] ?? '09:00')) ?? '09:00';
        $dayEnd = $this->normalizeTimeToHourMinute((string) ($timeGrid['day_end'] ?? '18:00')) ?? '18:00';

        $bounds = $this->findEventTimeBounds($appointmentsByStaff, $blockedByStaff);
        $branchHoursAvailable = ($branchHours['branch_hours_available'] ?? false) === true;
        $isClosedDay = ($branchHours['is_closed_day'] ?? false) === true;

        if ($branchHoursAvailable && $isClosedDay) {
            $dayStart = $bounds['start'] ?? '00:00';
            $dayEnd = $bounds['end'] ?? '23:30';
        } elseif ($branchHoursAvailable && !$isClosedDay) {
            $open = $this->normalizeTimeToHourMinute((string) ($branchHours['open_time'] ?? ''));
            $close = $this->normalizeTimeToHourMinute((string) ($branchHours['close_time'] ?? ''));

            // Open configured day: branch hours are the primary visible envelope.
            if ($open !== null && $close !== null) {
                $dayStart = $open;
                $dayEnd = $close;

                // Preserve truth visibility by expanding only for real outside-hours records.
                if ($bounds !== null) {
                    if (strcmp($bounds['start'], $dayStart) < 0) {
                        $dayStart = $bounds['start'];
                    }
                    if (strcmp($bounds['end'], $dayEnd) > 0) {
                        $dayEnd = $bounds['end'];
                    }
                }
            } elseif ($bounds !== null) {
                if (strcmp($bounds['start'], $dayStart) < 0) {
                    $dayStart = $bounds['start'];
                }
                if (strcmp($bounds['end'], $dayEnd) > 0) {
                    $dayEnd = $bounds['end'];
                }
            }
        } elseif ($bounds !== null) {
            if (strcmp($bounds['start'], $dayStart) < 0) {
                $dayStart = $bounds['start'];
            }
            if (strcmp($bounds['end'], $dayEnd) > 0) {
                $dayEnd = $bounds['end'];
            }
        }

        if (strcmp($dayEnd, $dayStart) <= 0) {
            $dayEnd = $dayStart === '23:30' ? '23:59' : '23:30';
        }

        $timeGrid['day_start'] = $dayStart;
        $timeGrid['day_end'] = $dayEnd;

        return $timeGrid;
    }

    /**
     * @param array<int,mixed> $appointmentsByStaff
     * @param array<string,mixed> $branchHours
     */
    private function countOutOfEnvelopeAppointments(array $appointmentsByStaff, array $branchHours): int
    {
        if (($branchHours['branch_hours_available'] ?? false) !== true) {
            return 0;
        }
        if (($branchHours['is_closed_day'] ?? false) === true) {
            $count = 0;
            foreach ($appointmentsByStaff as $staffRows) {
                if (!is_array($staffRows)) {
                    continue;
                }
                $count += count($staffRows);
            }

            return $count;
        }
        $open = $this->normalizeTimeToHourMinute((string) ($branchHours['open_time'] ?? ''));
        $close = $this->normalizeTimeToHourMinute((string) ($branchHours['close_time'] ?? ''));
        if ($open === null || $close === null) {
            return 0;
        }

        $count = 0;
        foreach ($appointmentsByStaff as $staffRows) {
            if (!is_array($staffRows)) {
                continue;
            }
            foreach ($staffRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $start = $this->normalizeTimeToHourMinute((string) substr((string) ($row['start_at'] ?? ''), 11, 5));
                $end = $this->normalizeTimeToHourMinute((string) substr((string) ($row['end_at'] ?? ''), 11, 5));
                if ($start === null || $end === null) {
                    continue;
                }
                if (strcmp($start, $open) < 0 || strcmp($end, $close) > 0) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param array<int,mixed> $appointmentsByStaff
     * @param array<int,mixed> $blockedByStaff
     * @return array{start:string,end:string}|null
     */
    private function findEventTimeBounds(array $appointmentsByStaff, array $blockedByStaff): ?array
    {
        $min = null;
        $max = null;
        foreach ([$appointmentsByStaff, $blockedByStaff] as $dataset) {
            foreach ($dataset as $rows) {
                if (!is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $startRaw = isset($row['start_at']) ? substr((string) $row['start_at'], 11, 5) : '';
                    $endRaw = isset($row['end_at']) ? substr((string) $row['end_at'], 11, 5) : '';
                    $start = $this->normalizeTimeToHourMinute($startRaw);
                    $end = $this->normalizeTimeToHourMinute($endRaw);
                    if ($start !== null && ($min === null || strcmp($start, $min) < 0)) {
                        $min = $start;
                    }
                    if ($end !== null && ($max === null || strcmp($end, $max) > 0)) {
                        $max = $end;
                    }
                }
            }
        }
        if ($min === null || $max === null) {
            return null;
        }

        return ['start' => $min, 'end' => $max];
    }

    private function normalizeTimeToHourMinute(string $value): ?string
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^\d{2}:\d{2}$/', $v) === 1) {
            return $v;
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $v) === 1) {
            return substr($v, 0, 5);
        }

        return null;
    }

    /**
     * @return array{storage_ready:bool,active:bool,title:?string,notes:?string}
     */
    private function resolveClosureDateMeta(?int $branchId, string $date): array
    {
        $storageReady = $this->branchClosureDates->isStorageReady();
        if (!$storageReady || $branchId === null || $branchId <= 0) {
            return [
                'storage_ready' => $storageReady,
                'active' => false,
                'title' => null,
                'notes' => null,
            ];
        }

        foreach ($this->branchClosureDates->listForBranch($branchId) as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['closure_date'] ?? '') !== $date) {
                continue;
            }

            return [
                'storage_ready' => true,
                'active' => true,
                'title' => (string) ($row['title'] ?? ''),
                'notes' => isset($row['notes']) && $row['notes'] !== null ? (string) $row['notes'] : null,
            ];
        }

        return [
            'storage_ready' => true,
            'active' => false,
            'title' => null,
            'notes' => null,
        ];
    }

    /**
     * @param array<string,mixed> $branchHours
     * @param array<string,mixed> $closureDateMeta
     * @return array<string,mixed>
     */
    private function applyClosureDateOperationalPrecedence(array $branchHours, array $closureDateMeta): array
    {
        if (($closureDateMeta['active'] ?? false) !== true) {
            return $branchHours;
        }

        $effective = $branchHours;
        $effective['branch_hours_available'] = true;
        $effective['is_closed_day'] = true;
        $effective['is_configured_day'] = true;
        $effective['open_time'] = null;
        $effective['close_time'] = null;

        return $effective;
    }

    /**
     * @param array<int,mixed> $grouped
     */
    private function countTotalRecords(array $grouped): int
    {
        $count = 0;
        foreach ($grouped as $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $count += count($rows);
        }

        return $count;
    }
}
