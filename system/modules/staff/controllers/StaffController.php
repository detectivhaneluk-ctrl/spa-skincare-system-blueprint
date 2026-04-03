<?php

declare(strict_types=1);

namespace Modules\Staff\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Media\Services\MediaAssetUploadService;
use Modules\ServicesResources\Repositories\ServiceRepository;
use Modules\Staff\Repositories\StaffBreakRepository;
use Modules\Staff\Repositories\StaffGroupRepository;
use Modules\Staff\Repositories\StaffRepository;
use Modules\Staff\Repositories\StaffScheduleRepository;
use Modules\Staff\Services\StaffBreakService;
use Modules\Staff\Services\StaffScheduleService;
use Modules\Staff\Services\StaffService;

final class StaffController
{
    // Canonical pay_type values — must match migration 130 ENUM exactly.
    private const PAY_TYPE_VALUES = [
        'none', 'flat_hourly', 'salary', 'commission', 'combination',
        'per_service_fee', 'per_service_fee_with_bonus',
        'per_service_fee_by_employee', 'service_commission_by_sales_tier',
    ];

    private const PAY_TYPE_CLASSES_VALUES = ['same_as_services', 'commission_by_attendee'];

    private const PAY_TYPE_PRODUCTS_VALUES = [
        'none', 'commission', 'commission_by_sales_tier', 'per_product_fee',
    ];

    public function __construct(
        private StaffRepository $repo,
        private StaffService $service,
        private StaffScheduleRepository $scheduleRepo,
        private StaffBreakRepository $breakRepo,
        private StaffScheduleService $scheduleService,
        private StaffBreakService $breakService,
        private MediaAssetUploadService $mediaUpload,
        private StaffGroupRepository $groupRepo,
        private BranchContext $branchContext,
        private OrganizationRepositoryScope $orgScope,
        private ServiceRepository $serviceRepo
    ) {
    }

    public function index(): void
    {
        $activeOnly = isset($_GET['active']) ? (bool) $_GET['active'] : true;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $filters = $activeOnly ? ['active' => true] : [];
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null) {
            $user = Application::container()->get(\Core\Auth\SessionAuth::class)->user();
            if ($user !== null && array_key_exists('branch_id', $user)) {
                $ub = $user['branch_id'];
                if ($ub !== null && $ub !== '') {
                    $branchId = (int) $ub;
                }
            }
        }
        if ($branchId !== null) {
            $filters['branch_id'] = $branchId;
        }
        $staff = $this->repo->list($filters, $perPage, ($page - 1) * $perPage);
        $total = $this->repo->count($filters);
        foreach ($staff as &$s) {
            $s['display_name'] = $this->service->getDisplayName($s);
        }
        unset($s);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $flash = flash();
        require base_path('modules/staff/views/index.php');
    }

    public function create(): void
    {
        $serviceTypes = $this->loadServiceTypes();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $staff = [];
        require base_path('modules/staff/views/create.php');
    }

    public function store(): void
    {
        $data = $this->parseInput();
        $errors = $this->validateStep1($data);

        if (!empty($errors)) {
            $serviceTypes = $this->loadServiceTypes();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $staff = $data;
            require base_path('modules/staff/views/create.php');
            return;
        }

        // Handle photo upload before opening any transaction (MediaAssetUploadService requirement).
        $photoAssetId = null;
        if (!empty($_FILES['photo']['tmp_name'])) {
            try {
                $accepted = $this->mediaUpload->acceptUpload($_FILES['photo']);
                $photoAssetId = (int) ($accepted['asset_id'] ?? 0) ?: null;
            } catch (\InvalidArgumentException | \DomainException $e) {
                $errors['photo'] = 'Photo upload failed: ' . $e->getMessage();
            }
        }

        $signatureAssetId = null;
        if (!empty($_FILES['signature']['tmp_name'])) {
            try {
                $accepted = $this->mediaUpload->acceptUpload($_FILES['signature']);
                $signatureAssetId = (int) ($accepted['asset_id'] ?? 0) ?: null;
            } catch (\InvalidArgumentException | \DomainException $e) {
                $errors['signature'] = 'Signature upload failed: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            $serviceTypes = $this->loadServiceTypes();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $staff = $data;
            require base_path('modules/staff/views/create.php');
            return;
        }

        if ($photoAssetId !== null) {
            $data['photo_media_asset_id'] = $photoAssetId;
        }
        if ($signatureAssetId !== null) {
            $data['signature_media_asset_id'] = $signatureAssetId;
        }

        $id = $this->service->create($data);
        flash('success', 'Employee info saved. Continue to Step 2.');
        header('Location: /staff/' . $id . '/onboarding/step2');
        exit;
    }

    public function onboardingStep2(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $staff['display_name'] = $this->service->getDisplayName($staff);
        $groups = $this->loadActiveGroups();
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $flash = flash();
        require base_path('modules/staff/views/onboarding_step2_compensation.php');
    }

    public function saveStep2(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }

        $data = $this->parseStep2Input();
        $errors = $this->validateStep2($data);

        if (!empty($errors)) {
            $staff = array_merge($staff, $data);
            $staff['display_name'] = $this->service->getDisplayName($staff);
            $groups = $this->loadActiveGroups();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $flash = [];
            require base_path('modules/staff/views/onboarding_step2_compensation.php');
            return;
        }

        try {
            $this->service->saveStep2($id, $data);
        } catch (\DomainException | \RuntimeException $e) {
            $errors['_general'] = $e->getMessage();
            $staff = array_merge($staff, $data);
            $staff['display_name'] = $this->service->getDisplayName($staff);
            $groups = $this->loadActiveGroups();
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $flash = [];
            require base_path('modules/staff/views/onboarding_step2_compensation.php');
            return;
        }

        flash('success', 'Compensation and benefits saved. Continue to Step 3.');
        header('Location: /staff/' . $id . '/onboarding/step3');
        exit;
    }

    public function onboardingStep3(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $staff['display_name'] = $this->service->getDisplayName($staff);
        $branchId = $this->resolveStaffBranchId($staff);
        $serviceGroups = $this->loadServicesGrouped($branchId);
        $assignedIds   = array_flip($this->serviceRepo->listAssignedServiceIdsForStaff($id));
        $csrf  = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $flash  = flash();
        require base_path('modules/staff/views/onboarding_step3_services.php');
    }

    public function saveStep3(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }

        $rawIds     = $_POST['service_ids'] ?? [];
        $serviceIds = is_array($rawIds)
            ? array_values(array_filter(array_map('intval', $rawIds), static fn (int $v): bool => $v > 0))
            : [];

        $branchId = $this->resolveStaffBranchId($staff);

        try {
            $this->serviceRepo->replaceAssignedServicesForStaff($id, $serviceIds, $branchId);
            $this->service->advanceOnboardingStep($id, 3);
        } catch (\DomainException | \RuntimeException $e) {
            $staff['display_name'] = $this->service->getDisplayName($staff);
            $serviceGroups = $this->loadServicesGrouped($branchId);
            $assignedIds   = array_flip($serviceIds);
            $errors = ['_general' => $e->getMessage()];
            $csrf   = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $flash  = [];
            require base_path('modules/staff/views/onboarding_step3_services.php');
            return;
        }

        header('Location: /staff/' . $id . '/onboarding/step4');
        exit;
    }

    public function onboardingStep4(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $staff['display_name'] = $this->service->getDisplayName($staff);
        $rawSchedule = $this->scheduleRepo->listByStaff($id);
        $schedule    = $this->indexScheduleByDay($rawSchedule);
        $isFirstVisit = empty($rawSchedule);
        $csrf   = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        $flash  = flash();
        require base_path('modules/staff/views/onboarding_step4_schedule.php');
    }

    public function saveStep4(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }

        $rawDays = $_POST['schedule'] ?? [];
        if (!is_array($rawDays)) {
            $rawDays = [];
        }

        try {
            $this->scheduleService->saveDefaultWeek($id, $rawDays);
            $this->service->completeOnboarding($id);
        } catch (\InvalidArgumentException | \DomainException | \RuntimeException $e) {
            $staff['display_name'] = $this->service->getDisplayName($staff);
            $rawSchedule = $this->scheduleRepo->listByStaff($id);
            $schedule    = $this->indexScheduleByDay($rawSchedule);
            $isFirstVisit = false;
            // Re-hydrate submitted values so the operator does not lose their edits
            foreach ($rawDays as $dowRaw => $day) {
                $dow = (int) $dowRaw;
                if (!empty($day['is_working'])) {
                    $schedule[$dow] = [
                        'day_of_week'      => $dow,
                        'start_time'       => $day['start_time'] ?? '',
                        'end_time'         => $day['end_time'] ?? '',
                        'lunch_start_time' => $day['lunch_start_time'] ?? null,
                        'lunch_end_time'   => $day['lunch_end_time'] ?? null,
                    ];
                } else {
                    unset($schedule[$dow]);
                }
            }
            $errors = ['_general' => $e->getMessage()];
            $csrf   = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $flash  = [];
            require base_path('modules/staff/views/onboarding_step4_schedule.php');
            return;
        }

        $_SESSION['flash']['success'] = 'Employee setup complete.';
        header('Location: /staff/' . $id);
        exit;
    }

    public function show(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $staff['display_name'] = $this->service->getDisplayName($staff);
        $schedules = $this->scheduleRepo->listByStaff($id);
        $breaks = $this->breakRepo->listByStaff($id);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/staff/views/show.php');
    }

    public function edit(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $users = Application::container()->get(\Core\App\Database::class)->fetchAll('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name');
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $errors = [];
        require base_path('modules/staff/views/edit.php');
    }

    public function update(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $data = $this->parseEditInput();
        $errors = $this->validateEdit($data);
        if (!empty($errors)) {
            $users = Application::container()->get(\Core\App\Database::class)->fetchAll('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name');
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            $staff = array_merge($staff, $data);
            require base_path('modules/staff/views/edit.php');
            return;
        }
        $this->service->update($id, $data);
        flash('success', 'Staff member updated.');
        header('Location: /staff/' . $id);
        exit;
    }

    public function destroy(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $this->service->delete($id);
        flash('success', 'Staff member deleted.');
        header('Location: /staff');
        exit;
    }

    public function scheduleStore(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $data = [
            'day_of_week' => (int) ($_POST['day_of_week'] ?? 0),
            'start_time' => trim((string) ($_POST['start_time'] ?? '')),
            'end_time' => trim((string) ($_POST['end_time'] ?? '')),
        ];
        try {
            $this->scheduleService->create($id, $data);
            flash('success', 'Schedule entry added.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /staff/' . $id);
        exit;
    }

    public function scheduleDelete(int $id, int $scheduleId): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        try {
            $this->scheduleService->delete($scheduleId);
            flash('success', 'Schedule entry removed.');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /staff/' . $id);
        exit;
    }

    public function breakStore(int $id): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        $data = [
            'day_of_week' => (int) ($_POST['day_of_week'] ?? 0),
            'start_time' => trim((string) ($_POST['start_time'] ?? '')),
            'end_time' => trim((string) ($_POST['end_time'] ?? '')),
            'title' => trim((string) ($_POST['title'] ?? '')),
        ];
        try {
            $this->breakService->create($id, $data);
            flash('success', 'Break entry added.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (\DomainException $e) {
            flash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /staff/' . $id);
        exit;
    }

    public function breakDelete(int $id, int $breakId): void
    {
        $staff = $this->repo->find($id);
        if (!$staff) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($staff)) {
            return;
        }
        try {
            $this->breakService->delete($breakId);
            flash('success', 'Break entry removed.');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        header('Location: /staff/' . $id);
        exit;
    }

    // ---------------------------------------------------------------------------
    // Step 1 wizard input parsing & validation
    // ---------------------------------------------------------------------------

    private function parseInput(): array
    {
        $specifyEndDate = !empty($_POST['specify_end_date']);
        $endDate = $specifyEndDate ? trim($_POST['employment_end_date'] ?? '') : null;
        $maxAppt = trim($_POST['max_appointments_per_day'] ?? '');
        $serviceTypeId = trim($_POST['service_type_id'] ?? '');

        return [
            'first_name'             => trim($_POST['first_name'] ?? ''),
            'last_name'              => trim($_POST['last_name'] ?? '') ?: null,
            'display_name'           => trim($_POST['display_name'] ?? '') ?: null,
            'gender'                 => trim($_POST['gender'] ?? ''),
            'email'                  => trim($_POST['email'] ?? ''),
            'create_login_requested' => !empty($_POST['create_login_requested']) ? 1 : 0,
            'staff_type'             => trim($_POST['staff_type'] ?? ''),
            'status'                 => trim($_POST['status'] ?? ''),
            'specify_end_date'       => $specifyEndDate,
            'employment_end_date'    => ($endDate !== null && $endDate !== '') ? $endDate : null,
            'max_appointments_per_day' => ($maxAppt !== '' && $maxAppt !== '0') ? (int) $maxAppt : null,
            'profile_description'    => trim($_POST['profile_description'] ?? '') ?: null,
            'employee_notes'         => trim($_POST['employee_notes'] ?? '') ?: null,
            'license_number'         => trim($_POST['license_number'] ?? '') ?: null,
            'license_expiration_date' => trim($_POST['license_expiration_date'] ?? '') ?: null,
            'service_type_id'        => ($serviceTypeId !== '') ? (int) $serviceTypeId : null,
            'street_1'               => trim($_POST['street_1'] ?? '') ?: null,
            'street_2'               => trim($_POST['street_2'] ?? '') ?: null,
            'city'                   => trim($_POST['city'] ?? '') ?: null,
            'postal_code'            => trim($_POST['postal_code'] ?? '') ?: null,
            'country'                => trim($_POST['country'] ?? '') ?: null,
            'home_phone'             => trim($_POST['home_phone'] ?? '') ?: null,
            'mobile_phone'           => trim($_POST['mobile_phone'] ?? '') ?: null,
            'preferred_phone'        => in_array($_POST['preferred_phone'] ?? '', ['home', 'mobile'], true) ? $_POST['preferred_phone'] : null,
            'sms_opt_in'             => !empty($_POST['sms_opt_in']) ? 1 : 0,
        ];
    }

    private function validateStep1(array $data): array
    {
        $errors = [];

        if ($data['first_name'] === '') {
            $errors['first_name'] = 'First name is required.';
        } elseif (mb_strlen($data['first_name']) > 100) {
            $errors['first_name'] = 'First name must be 100 characters or fewer.';
        }

        if ($data['last_name'] !== null && mb_strlen($data['last_name']) > 100) {
            $errors['last_name'] = 'Last name must be 100 characters or fewer.';
        }

        if (!in_array($data['gender'], ['male', 'female'], true)) {
            $errors['gender'] = 'Gender is required.';
        }

        if ($data['email'] === '') {
            $errors['email'] = 'Email is required.';
        } elseif (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'A valid email address is required.';
        }

        if (!in_array($data['staff_type'], ['freelancer', 'scheduled'], true)) {
            $errors['staff_type'] = 'Type is required.';
        }

        if (!in_array($data['status'], ['active', 'inactive'], true)) {
            $errors['status'] = 'Status is required.';
        }

        if ($data['max_appointments_per_day'] !== null && $data['max_appointments_per_day'] < 1) {
            $errors['max_appointments_per_day'] = 'Max appointments per day must be a positive number.';
        }

        if ($data['employment_end_date'] !== null) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $data['employment_end_date']);
            if ($parsed === false || $parsed->format('Y-m-d') !== $data['employment_end_date']) {
                $errors['employment_end_date'] = 'Employment end date must be a valid date (YYYY-MM-DD).';
            }
        }

        if ($data['license_expiration_date'] !== null) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $data['license_expiration_date']);
            if ($parsed === false || $parsed->format('Y-m-d') !== $data['license_expiration_date']) {
                $errors['license_expiration_date'] = 'License expiration date must be a valid date (YYYY-MM-DD).';
            }
        }

        return $errors;
    }

    // ---------------------------------------------------------------------------
    // Step 2 input parsing & validation
    // ---------------------------------------------------------------------------

    private function parseStep2Input(): array
    {
        $groupRaw = trim($_POST['primary_group_id'] ?? '');
        $vacRaw = trim($_POST['vacation_days'] ?? '');
        $sickRaw = trim($_POST['sick_days'] ?? '');
        $persRaw = trim($_POST['personal_days'] ?? '');

        return [
            'primary_group_id'   => ($groupRaw !== '') ? (int) $groupRaw : null,
            'pay_type'           => trim($_POST['pay_type'] ?? ''),
            'pay_type_classes'   => trim($_POST['pay_type_classes'] ?? ''),
            'pay_type_products'  => trim($_POST['pay_type_products'] ?? ''),
            'vacation_days'      => ($vacRaw !== '') ? (int) $vacRaw : null,
            'sick_days'          => ($sickRaw !== '') ? (int) $sickRaw : null,
            'personal_days'      => ($persRaw !== '') ? (int) $persRaw : null,
            'employee_number'    => trim($_POST['employee_number'] ?? '') ?: null,
            'has_dependents'     => !empty($_POST['has_dependents']) ? 1 : 0,
            'is_exempt'          => !empty($_POST['is_exempt']) ? 1 : 0,
        ];
    }

    private function validateStep2(array $data): array
    {
        $errors = [];

        if (!in_array($data['pay_type'], self::PAY_TYPE_VALUES, true)) {
            $errors['pay_type'] = 'Pay type is required.';
        }

        if (!in_array($data['pay_type_classes'], self::PAY_TYPE_CLASSES_VALUES, true)) {
            $errors['pay_type_classes'] = 'Pay type (classes/workshops) is required.';
        }

        if (!in_array($data['pay_type_products'], self::PAY_TYPE_PRODUCTS_VALUES, true)) {
            $errors['pay_type_products'] = 'Pay type (products) is required.';
        }

        if ($data['vacation_days'] === null) {
            $errors['vacation_days'] = 'Vacation days is required.';
        } elseif ($data['vacation_days'] < 0) {
            $errors['vacation_days'] = 'Vacation days must be zero or greater.';
        }

        if ($data['sick_days'] === null) {
            $errors['sick_days'] = 'Sick days is required.';
        } elseif ($data['sick_days'] < 0) {
            $errors['sick_days'] = 'Sick days must be zero or greater.';
        }

        if ($data['personal_days'] === null) {
            $errors['personal_days'] = 'Personal days is required.';
        } elseif ($data['personal_days'] < 0) {
            $errors['personal_days'] = 'Personal days must be zero or greater.';
        }

        return $errors;
    }

    // ---------------------------------------------------------------------------
    // Legacy edit form helpers (preserve original behavior for edit/update flow)
    // ---------------------------------------------------------------------------

    private function parseEditInput(): array
    {
        $uid = trim($_POST['user_id'] ?? '');
        return [
            'user_id'    => $uid !== '' ? (int) $uid : null,
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name'  => trim($_POST['last_name'] ?? '') ?: null,
            'phone'      => trim($_POST['phone'] ?? '') ?: null,
            'email'      => trim($_POST['email'] ?? '') ?: null,
            'job_title'  => trim($_POST['job_title'] ?? '') ?: null,
            'is_active'  => !empty($_POST['is_active']),
        ];
    }

    private function validateEdit(array $data): array
    {
        $errors = [];
        if ($data['first_name'] === '') {
            $errors['first_name'] = 'First name is required.';
        }
        return $errors;
    }

    // ---------------------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------------------

    private function loadServiceTypes(): array
    {
        try {
            return Application::container()->get(\Core\App\Database::class)->fetchAll(
                'SELECT id, name FROM staff_service_types WHERE is_active = 1 ORDER BY sort_order, name'
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadActiveGroups(): array
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId !== null && $branchId > 0) {
            return $this->groupRepo->listInTenantScope($branchId, ['active' => true], 200, 0);
        }
        $any = $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
        if ($any !== null && $any > 0) {
            return $this->groupRepo->listInTenantScope($any, ['active' => true], 200, 0);
        }
        return $this->groupRepo->list(['active' => true], 200, 0);
    }

    private function indexScheduleByDay(array $rows): array
    {
        $keyed = [];
        foreach ($rows as $row) {
            $keyed[(int) $row['day_of_week']] = $row;
        }
        return $keyed;
    }

    private function loadServicesGrouped(?int $branchId): array
    {
        $services = $this->serviceRepo->list(null, $branchId);
        $groups   = [];
        foreach ($services as $svc) {
            $catName = trim((string) ($svc['category_name'] ?? ''));
            $key     = $catName !== '' ? $catName : '(Uncategorised)';
            if (!isset($groups[$key])) {
                $groups[$key] = ['name' => $key, 'services' => []];
            }
            $groups[$key]['services'][] = $svc;
        }
        return $groups;
    }

    private function resolveStaffBranchId(array $staff): ?int
    {
        $ctx = $this->branchContext->getCurrentBranchId();
        if ($ctx !== null && $ctx > 0) {
            return $ctx;
        }
        $sb = $staff['branch_id'] ?? null;
        if ($sb !== null && $sb !== '') {
            return (int) $sb;
        }
        return $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();
    }

    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null ? (int) $entity['branch_id'] : null;
            $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }
}
