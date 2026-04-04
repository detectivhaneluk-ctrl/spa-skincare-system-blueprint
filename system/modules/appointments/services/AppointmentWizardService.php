<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Core\Contracts\ClientListProvider;
use Core\Contracts\RoomListProvider;
use Core\Contracts\ServiceListProvider;
use Core\Contracts\StaffListProvider;
use Modules\Clients\Repositories\ClientRepository;
use Modules\ServicesResources\Repositories\ServiceCategoryRepository;

/**
 * Business logic for the full-page appointment creation wizard.
 *
 * Phase 2 additions over Phase 1:
 * - Package mode is explicitly fail-closed (returns validation error instead of silently returning empty)
 * - Linked-chain mode: buildServiceLine now carries predecessor_index and price_snapshot
 * - Payment step: validateStep4Payment(), buildPaymentState(), getPaymentTotals()
 * - runSearch() respects continuation_from context for linked-chain continuation searches
 * - revalidateForCommit() verifies payment state is not the null placeholder
 * - resolveServicePrice() snapshots price at selection time
 *
 * This service is ONLY used by the full-page wizard flow.
 * Quick drawer and blocked-time flows do not touch this service.
 */
final class AppointmentWizardService
{
    /** Maximum days to scan for first_available and range date modes. */
    private const MAX_SEARCH_DAYS = 30;

    /** Maximum slots returned per staff member in a single date search. */
    private const MAX_SLOTS_PER_STAFF = 24;

    /**
     * Canonical set of payment modes for the appointment booking wizard.
     * This is wizard-local truth; no gateway integration is performed in this task.
     * "skip_payment" is an explicit conscious choice, not null/missing state.
     *
     * @var list<string>
     */
    public const PAYMENT_MODES = [
        'skip_payment',
        'credit_card',
        'nonintegrated_credit_card',
        'gift_voucher_card',
        'ach',
        'bank_transfer',
        'partner',
        'custom_payment',
    ];

    /** Human-readable labels keyed by payment mode value. */
    public const PAYMENT_MODE_LABELS = [
        'skip_payment'              => 'No payment now (skip)',
        'credit_card'               => 'Credit / Debit card',
        'nonintegrated_credit_card' => 'Credit card (non-integrated terminal)',
        'gift_voucher_card'         => 'Gift voucher / card',
        'ach'                       => 'ACH / direct debit',
        'bank_transfer'             => 'Bank transfer',
        'partner'                   => 'Partner / third-party',
        'custom_payment'            => 'Custom payment method',
    ];

    public function __construct(
        private AvailabilityService $availability,
        private AppointmentService $appointmentService,
        private ClientListProvider $clientList,
        private ClientRepository $clientRepo,
        private ServiceListProvider $serviceList,
        private StaffListProvider $staffList,
        private RoomListProvider $roomList,
        private ServiceCategoryRepository $categoryRepo,
    ) {
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 1 — Availability Search
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validate step 1 search criteria.
     * Package mode is explicitly fail-closed in this phase.
     *
     * @param array<string, mixed> $data
     * @return array<string, string> $errors  Empty means valid.
     */
    public function validateStep1(array $data): array
    {
        $errors = [];

        $mode = (string) ($data['mode'] ?? 'service');

        // Package mode is not yet supported: fail-closed with explicit error.
        if ($mode === 'package') {
            $errors['mode'] = 'Package-based booking is not yet available. Please use Service search mode.';

            return $errors;
        }

        if ($mode !== 'service') {
            $errors['mode'] = 'Please select a valid search mode.';

            return $errors;
        }

        $serviceId = (int) ($data['service_id'] ?? 0);
        if ($serviceId <= 0) {
            $errors['service_id'] = 'Please select a service.';
        }

        $guests = (int) ($data['guests'] ?? 1);
        if ($guests < 1) {
            $errors['guests'] = 'Guests must be at least 1.';
        }

        $dateMode = (string) ($data['date_mode'] ?? 'exact');
        if (!in_array($dateMode, ['exact', 'first_available', 'range'], true)) {
            $errors['date_mode'] = 'Please select a valid date mode.';
        }

        if ($dateMode === 'exact') {
            $date = (string) ($data['date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                $errors['date'] = 'Please enter a valid date (YYYY-MM-DD).';
            }
        } elseif ($dateMode === 'range') {
            $dateFrom = (string) ($data['date_from'] ?? '');
            $dateTo   = (string) ($data['date_to'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) !== 1) {
                $errors['date_from'] = 'Please enter a valid start date.';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) !== 1) {
                $errors['date_to'] = 'Please enter a valid end date.';
            }
            if (empty($errors['date_from']) && empty($errors['date_to']) && $dateTo < $dateFrom) {
                $errors['date_to'] = 'End date must be on or after start date.';
            }
        }

        return $errors;
    }

    /**
     * Run availability search. Supports optional continuation context for linked-chain mode.
     *
     * When $continuation is provided, slots are filtered to those starting at or after the
     * continuation_from_time on continuation_from_date. This drives the step 1 re-entry
     * in linked-chain "Add Another Service" flow.
     *
     * @param array<string, mixed>      $criteria      Validated search criteria from wizard state.
     * @param array<string, mixed>|null $continuation  {date: YYYY-MM-DD, after_time: HH:MM}
     * @return list<array<string, mixed>>
     */
    public function runSearch(array $criteria, int $branchId, ?array $continuation = null): array
    {
        $serviceId = (int) ($criteria['service_id'] ?? 0);
        if ($serviceId <= 0) {
            return [];
        }

        $roomId = isset($criteria['room_id']) && (int) $criteria['room_id'] > 0
            ? (int) $criteria['room_id']
            : null;

        $filterStaffId = isset($criteria['staff_id']) && (int) $criteria['staff_id'] > 0
            ? (int) $criteria['staff_id']
            : null;

        $durationMinutes = $this->availability->getServiceDurationMinutes($serviceId, $branchId);
        $serviceName     = $this->resolveServiceName($serviceId, $branchId);

        $eligibleStaff = $this->resolveEligibleStaff($serviceId, $branchId, $filterStaffId);
        if (empty($eligibleStaff)) {
            return [];
        }

        $datesToSearch = $this->resolveDatesToSearch($criteria, $continuation);
        if (empty($datesToSearch)) {
            return [];
        }

        // If continuation is set, only search the continuation date (ignore full date range).
        $continuationDate      = $continuation !== null ? (string) ($continuation['date'] ?? '') : null;
        $continuationAfterTime = $continuation !== null ? (string) ($continuation['after_time'] ?? '00:00') : null;

        $results = [];

        foreach ($datesToSearch as $date) {
            $dayResults = [];

            foreach ($eligibleStaff as $staffRow) {
                $staffId   = (int) ($staffRow['id'] ?? 0);
                $staffName = trim(($staffRow['first_name'] ?? '') . ' ' . ($staffRow['last_name'] ?? ''));

                $slots = $this->availability->getAvailableSlots(
                    $serviceId,
                    $date,
                    $staffId,
                    $branchId,
                    'internal',
                    $roomId
                );

                $count = 0;
                foreach ($slots as $time) {
                    // In linked-chain continuation: skip slots before or at the continuation time.
                    if ($continuationDate !== null && $date === $continuationDate && $continuationAfterTime !== null) {
                        if ($time <= $continuationAfterTime) {
                            continue;
                        }
                    }

                    if (++$count > self::MAX_SLOTS_PER_STAFF) {
                        break;
                    }

                    $resultKey  = implode(':', [$serviceId, $staffId, $date, $time]);
                    $dayResults[] = [
                        'result_key'       => $resultKey,
                        'service_id'       => $serviceId,
                        'service_name'     => $serviceName,
                        'staff_id'         => $staffId,
                        'staff_name'       => $staffName,
                        'date'             => $date,
                        'time'             => $time,
                        'duration_minutes' => $durationMinutes,
                    ];
                }
            }

            $results = array_merge($results, $dayResults);

            // For first_available mode: stop after the first day that yields results.
            if ((string) ($criteria['date_mode'] ?? 'exact') === 'first_available' && !empty($dayResults)) {
                break;
            }
        }

        return $results;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 2 — Resource Allocation (linked-chain aware)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validate a step 2 selection (adding a service line from an availability result).
     *
     * @param array<string, mixed>   $data   POST data.
     * @param list<array>            $availabilityResults  From wizard state.
     * @return array<string, string>
     */
    public function validateStep2Selection(array $data, array $availabilityResults): array
    {
        $errors    = [];
        $resultKey = trim((string) ($data['result_key'] ?? ''));

        if ($resultKey === '') {
            $errors['result_key'] = 'Please select an available time slot.';

            return $errors;
        }

        $found = $this->findResultByKey($resultKey, $availabilityResults);
        if ($found === null) {
            $errors['result_key'] = 'The selected slot is no longer available. Please search again.';
        }

        return $errors;
    }

    /**
     * Build a service_line array from a validated result_key.
     * Phase 2: includes predecessor_index (for linked-chain) and price_snapshot.
     *
     * @param list<array<string, mixed>> $availabilityResults
     * @return array<string, mixed>|null  Null if the key is not found.
     */
    public function buildServiceLine(
        string $resultKey,
        array $availabilityResults,
        int $lineIndex,
        int $branchId,
        bool $lockToStaff = false,
        bool $requested = false,
        ?int $predecessorIndex = null
    ): ?array {
        $result = $this->findResultByKey($resultKey, $availabilityResults);
        if ($result === null) {
            return null;
        }

        $serviceId     = (int) $result['service_id'];
        $priceSnapshot = $this->resolveServicePrice($serviceId, $branchId);

        return [
            'index'             => $lineIndex,
            'predecessor_index' => $predecessorIndex,
            'result_key'        => $resultKey,
            'service_id'        => $serviceId,
            'service_name'      => (string) $result['service_name'],
            'staff_id'          => (int) $result['staff_id'],
            'staff_name'        => (string) $result['staff_name'],
            'room_id'           => null,
            'date'              => (string) $result['date'],
            'start_time'        => (string) $result['time'],
            'duration_minutes'  => (int) $result['duration_minutes'],
            'lock_to_staff'     => $lockToStaff,
            'requested'         => $requested,
            'price_snapshot'    => $priceSnapshot,
        ];
    }

    /**
     * Compute the continuation context for "Add Another Service (Linked)".
     * Returns the date and after_time derived from the end of the last service_line.
     *
     * @param list<array<string, mixed>> $serviceLines
     * @return array{date: string, after_time: string}|null  Null if no lines exist.
     */
    public function buildContinuationContext(array $serviceLines): ?array
    {
        if (empty($serviceLines)) {
            return null;
        }

        // Use the last service line's end time as the continuation point.
        $lastLine     = end($serviceLines);
        $date         = (string) ($lastLine['date'] ?? '');
        $startTime    = (string) ($lastLine['start_time'] ?? '');
        $durationMins = (int) ($lastLine['duration_minutes'] ?? 0);

        if ($date === '' || $startTime === '') {
            return null;
        }

        // Compute end time = start + duration.
        $startTs  = strtotime($date . ' ' . $startTime . ':00');
        if ($startTs === false) {
            return null;
        }
        $endTs    = $startTs + ($durationMins * 60);
        $afterTime = date('H:i', $endTs);
        $endDate   = date('Y-m-d', $endTs);

        return [
            'date'       => $endDate,
            'after_time' => $afterTime,
        ];
    }

    /**
     * When a service line is removed in linked-chain mode, detach any successors that
     * referenced it as predecessor (set their predecessor_index to null).
     * Successors are not deleted — they become standalone-like within the chain.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    public function detachSuccessors(array $lines, int $removedIndex): array
    {
        foreach ($lines as &$line) {
            if ((int) ($line['predecessor_index'] ?? -1) === $removedIndex) {
                $line['predecessor_index'] = null;
            }
        }
        unset($line);

        return $lines;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 3 — Customer Attach / Draft
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validate step 3 customer data.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public function validateStep3Client(array $data): array
    {
        $errors = [];
        $mode   = (string) ($data['client_mode'] ?? 'existing');

        if ($mode === 'existing') {
            $clientId = (int) ($data['client_id'] ?? 0);
            if ($clientId <= 0) {
                $errors['client_id'] = 'Please select or search for a client.';
            }
        } elseif ($mode === 'new') {
            $firstName = trim((string) ($data['first_name'] ?? ''));
            $lastName  = trim((string) ($data['last_name'] ?? ''));
            if ($firstName === '') {
                $errors['first_name'] = 'First name is required.';
            }
            if ($lastName === '') {
                $errors['last_name'] = 'Last name is required.';
            }
            $phone = trim((string) ($data['phone'] ?? ''));
            $email = trim((string) ($data['email'] ?? ''));
            if ($phone === '' && $email === '') {
                $errors['phone'] = 'Please provide at least a phone number or email.';
            }
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Please enter a valid email address.';
            }
        } else {
            $errors['client_mode'] = 'Invalid client mode.';
        }

        return $errors;
    }

    /**
     * Build the client state sub-array from step 3 POST data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function buildClientState(array $data): array
    {
        $mode = (string) ($data['client_mode'] ?? 'existing');

        if ($mode === 'existing') {
            return [
                'mode'      => 'existing',
                'client_id' => (int) ($data['client_id'] ?? 0),
                'draft'     => [],
            ];
        }

        $draft = [
            'first_name'           => trim((string) ($data['first_name'] ?? '')),
            'last_name'            => trim((string) ($data['last_name'] ?? '')),
            'phone'                => trim((string) ($data['phone'] ?? '')),
            'email'                => trim((string) ($data['email'] ?? '')),
            'receive_emails'       => !empty($data['receive_emails']),
            'gender'               => trim((string) ($data['gender'] ?? '')),
            'birth_date'           => trim((string) ($data['birth_date'] ?? '')),
            'home_address_1'       => trim((string) ($data['home_address_1'] ?? '')),
            'home_city'            => trim((string) ($data['home_city'] ?? '')),
            'home_postal_code'     => trim((string) ($data['home_postal_code'] ?? '')),
            'home_country'         => trim((string) ($data['home_country'] ?? '')),
            'referral_information' => trim((string) ($data['referral_information'] ?? '')),
            'customer_origin'      => trim((string) ($data['customer_origin'] ?? '')),
            'marketing_opt_in'     => !empty($data['marketing_opt_in']),
        ];

        return [
            'mode'      => 'new',
            'client_id' => null,
            'draft'     => $draft,
        ];
    }

    /**
     * Search clients by phone, email, or name for the AJAX lookup on step 3.
     *
     * @return list<array{id: int, name: string, email: string, phone: string}>
     */
    public function searchClients(string $query, int $branchId): array
    {
        $query = trim($query);
        if ($query === '' || $branchId <= 0) {
            return [];
        }

        $rows = $this->clientRepo->list(
            ['search' => $query, 'branch_id' => $branchId],
            20,
            0
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'    => (int) ($row['id'] ?? 0),
                'name'  => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'email' => (string) ($row['email'] ?? ''),
                'phone' => (string) ($row['phone'] ?? ''),
            ];
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 4 — Payment
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Validate step 4 payment data.
     * Every submitted mode must be in PAYMENT_MODES. Explicit skip is valid.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public function validateStep4Payment(array $data): array
    {
        $errors      = [];
        $paymentMode = trim((string) ($data['payment_mode'] ?? ''));

        if ($paymentMode === '') {
            $errors['payment_mode'] = 'Please select a payment method (or choose "No payment now").';

            return $errors;
        }

        if (!in_array($paymentMode, self::PAYMENT_MODES, true)) {
            $errors['payment_mode'] = 'Invalid payment method selected.';
        }

        return $errors;
    }

    /**
     * Build the payment state sub-array from step 4 POST data.
     * Includes a totals snapshot so the review step has accurate figures.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $state  Current wizard state (for totals calculation).
     * @return array<string, mixed>
     */
    public function buildPaymentState(array $data, array $state): array
    {
        $paymentMode = trim((string) ($data['payment_mode'] ?? 'skip_payment'));
        $skipReason  = trim((string) ($data['skip_reason'] ?? ''));
        $totals      = $this->getPaymentTotals($state);

        return [
            'mode'              => $paymentMode,
            'skip_reason'       => $paymentMode === 'skip_payment' ? ($skipReason !== '' ? $skipReason : null) : null,
            'totals'            => $totals,
            'hold_reservation'  => !empty($data['hold_reservation']),
        ];
    }

    /**
     * Compute totals snapshot from current service_lines' price_snapshots.
     * Returns the sub-total, tax (0 — tax calculation is a future phase), and total.
     *
     * @param array<string, mixed> $state
     * @return array{subtotal: float, tax: float, total: float, currency: string, line_count: int}
     */
    public function getPaymentTotals(array $state): array
    {
        $serviceLines = $state['service_lines'] ?? [];
        $subtotal     = 0.0;

        foreach ($serviceLines as $line) {
            $price     = (float) ($line['price_snapshot'] ?? 0.0);
            $subtotal += $price;
        }

        return [
            'subtotal'   => round($subtotal, 2),
            'tax'        => 0.0,
            'total'      => round($subtotal, 2),
            'currency'   => 'GBP',
            'line_count' => count($serviceLines),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Commit
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Revalidate wizard state before commit.
     * Phase 2: also validates payment state is not the null placeholder.
     *
     * @param array<string, mixed> $state
     * @throws \DomainException
     */
    public function revalidateForCommit(array $state): void
    {
        $branchId     = (int) ($state['branch_id'] ?? 0);
        $serviceLines = $state['service_lines'] ?? [];

        if (empty($serviceLines)) {
            throw new \DomainException('No service lines selected. Please go back and choose a time slot.');
        }

        foreach ($serviceLines as $idx => $line) {
            $lineNum   = $idx + 1;
            $serviceId = (int) ($line['service_id'] ?? 0);
            $staffId   = (int) ($line['staff_id'] ?? 0);
            $date      = (string) ($line['date'] ?? '');
            $startTime = (string) ($line['start_time'] ?? '');

            if ($serviceId <= 0 || $staffId <= 0 || $date === '' || $startTime === '') {
                throw new \DomainException("Service line {$lineNum} is incomplete. Please return to step 1 and search again.");
            }

            $startAt = $date . ' ' . $startTime . ':00';

            $available = $this->availability->isSlotAvailable(
                $serviceId,
                $staffId,
                $startAt,
                null,
                $branchId,
                true,
                false
            );

            if (!$available) {
                throw new \DomainException(
                    "The slot for service line {$lineNum} ({$date} at {$startTime} with {$line['staff_name']}) is no longer available. Please go back and choose another time."
                );
            }
        }

        // Verify existing client is still live on this branch.
        $client = $state['client'] ?? [];
        if ((string) ($client['mode'] ?? '') === 'existing') {
            $clientId = (int) ($client['client_id'] ?? 0);
            if ($clientId <= 0) {
                throw new \DomainException('No client selected. Please go back to step 3 and select a client.');
            }
            $row = $this->clientRepo->findLiveReadOnBranch($clientId, $branchId);
            if ($row === null) {
                throw new \DomainException('The selected client is no longer active on this branch. Please go back and select another client.');
            }
        }

        // Validate payment state is non-placeholder (real step 4 must have been completed).
        $payment     = $state['payment'] ?? [];
        $paymentMode = (string) ($payment['mode'] ?? '');

        if ($paymentMode === 'none' || $paymentMode === '') {
            throw new \DomainException('Payment method has not been set. Please complete step 4 before confirming.');
        }

        if (!in_array($paymentMode, self::PAYMENT_MODES, true)) {
            throw new \DomainException('Invalid payment state. Please go back to step 4 and select a payment method.');
        }
    }

    /**
     * Commit the wizard state: create client if new draft, then create appointment(s).
     * Returns the ID of the first (primary) appointment created.
     *
     * @param array<string, mixed> $state
     * @throws \DomainException|\InvalidArgumentException
     */
    public function commit(array $state, int $userId): int
    {
        $branchId     = (int) ($state['branch_id'] ?? 0);
        $serviceLines = $state['service_lines'] ?? [];
        $client       = $state['client'] ?? [];

        $clientId = $this->resolveClientId($client, $branchId, $userId);

        $firstAppointmentId = null;
        foreach ($serviceLines as $line) {
            $serviceId = (int) ($line['service_id'] ?? 0);
            $staffId   = (int) ($line['staff_id'] ?? 0);
            $date      = (string) ($line['date'] ?? '');
            $time      = (string) ($line['start_time'] ?? '');
            $roomId    = isset($line['room_id']) && (int) $line['room_id'] > 0
                ? (int) $line['room_id']
                : null;

            $payload = [
                'client_id'  => $clientId,
                'service_id' => $serviceId,
                'staff_id'   => $staffId,
                'start_time' => $date . ' ' . $time,
                'branch_id'  => $branchId,
            ];
            if ($roomId !== null) {
                $payload['room_id'] = $roomId;
            }

            $apptId = $this->appointmentService->createFromSlot($payload);

            if ($firstAppointmentId === null) {
                $firstAppointmentId = $apptId;
            }
        }

        if ($firstAppointmentId === null) {
            throw new \DomainException('No appointment was created. Please try again.');
        }

        return $firstAppointmentId;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Reference data helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function getCategories(int $branchId): array
    {
        return $this->categoryRepo->list($branchId);
    }

    /** @return list<array<string, mixed>> */
    public function getServices(int $branchId): array
    {
        return $this->serviceList->list($branchId);
    }

    /** @return list<array<string, mixed>> */
    public function getStaff(int $branchId): array
    {
        return $this->staffList->list($branchId);
    }

    /** @return list<array<string, mixed>> */
    public function getRooms(int $branchId): array
    {
        return $this->roomList->list($branchId);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $availabilityResults
     * @return array<string, mixed>|null
     */
    private function findResultByKey(string $resultKey, array $availabilityResults): ?array
    {
        foreach ($availabilityResults as $result) {
            if ((string) ($result['result_key'] ?? '') === $resultKey) {
                return $result;
            }
        }

        return null;
    }

    private function resolveServiceName(int $serviceId, int $branchId): string
    {
        $services = $this->serviceList->list($branchId);
        foreach ($services as $svc) {
            if ((int) ($svc['id'] ?? 0) === $serviceId) {
                return (string) ($svc['name'] ?? '');
            }
        }

        return '';
    }

    /**
     * Resolve service price at the time of selection (price_snapshot).
     * Uses the 'price' column from the services table.
     * Returns 0.0 if not found or not priced.
     */
    private function resolveServicePrice(int $serviceId, int $branchId): float
    {
        $services = $this->serviceList->list($branchId);
        foreach ($services as $svc) {
            if ((int) ($svc['id'] ?? 0) === $serviceId) {
                return (float) ($svc['price'] ?? 0.0);
            }
        }

        return 0.0;
    }

    /**
     * Returns the staff rows to iterate over for the availability search.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveEligibleStaff(int $serviceId, int $branchId, ?int $filterStaffId): array
    {
        if ($filterStaffId !== null && $filterStaffId > 0) {
            $allStaff = $this->staffList->list($branchId);
            foreach ($allStaff as $row) {
                if ((int) ($row['id'] ?? 0) === $filterStaffId) {
                    return [$row];
                }
            }

            return [];
        }

        return $this->availability->listStaffSelectableForService($serviceId, $branchId);
    }

    /**
     * Resolve the list of dates to search based on date_mode.
     * When a continuation context is provided, only the continuation date is searched.
     *
     * @param array<string, mixed>      $criteria
     * @param array<string, mixed>|null $continuation
     * @return list<string>  YYYY-MM-DD strings
     */
    private function resolveDatesToSearch(array $criteria, ?array $continuation = null): array
    {
        // Linked-chain continuation overrides normal date search: only continuation date.
        if ($continuation !== null) {
            $contDate = (string) ($continuation['date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $contDate) === 1) {
                return [$contDate];
            }
        }

        $mode = (string) ($criteria['date_mode'] ?? 'exact');

        if ($mode === 'exact') {
            $date = (string) ($criteria['date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                return [];
            }

            return [$date];
        }

        if ($mode === 'first_available') {
            $dates = [];
            $ts    = time();
            for ($i = 0; $i < self::MAX_SEARCH_DAYS; $i++) {
                $dates[] = date('Y-m-d', strtotime("+{$i} day", $ts));
            }

            return $dates;
        }

        if ($mode === 'range') {
            $dateFrom = (string) ($criteria['date_from'] ?? '');
            $dateTo   = (string) ($criteria['date_to'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) !== 1
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) !== 1) {
                return [];
            }
            $dates = [];
            $ts    = strtotime($dateFrom);
            $end   = strtotime($dateTo);
            $count = 0;
            while ($ts <= $end && $count < self::MAX_SEARCH_DAYS) {
                $dates[] = date('Y-m-d', $ts);
                $ts      = strtotime('+1 day', $ts);
                $count++;
            }

            return $dates;
        }

        return [];
    }

    /**
     * Resolve a concrete client_id: returns existing client_id or creates a new client from draft.
     *
     * @param array<string, mixed> $client
     * @throws \DomainException
     */
    private function resolveClientId(array $client, int $branchId, int $userId): int
    {
        $mode = (string) ($client['mode'] ?? 'existing');

        if ($mode === 'existing') {
            $id = (int) ($client['client_id'] ?? 0);
            if ($id <= 0) {
                throw new \DomainException('Client ID is missing.');
            }

            return $id;
        }

        if ($mode === 'new') {
            $draft = $client['draft'] ?? [];
            $data  = array_filter([
                'first_name'           => trim((string) ($draft['first_name'] ?? '')),
                'last_name'            => trim((string) ($draft['last_name'] ?? '')),
                'phone'                => trim((string) ($draft['phone'] ?? '')),
                'email'                => trim((string) ($draft['email'] ?? '')),
                'receive_emails'       => !empty($draft['receive_emails']) ? 1 : 0,
                'gender'               => trim((string) ($draft['gender'] ?? '')),
                'birth_date'           => trim((string) ($draft['birth_date'] ?? '')) ?: null,
                'home_address_1'       => trim((string) ($draft['home_address_1'] ?? '')),
                'home_city'            => trim((string) ($draft['home_city'] ?? '')),
                'home_postal_code'     => trim((string) ($draft['home_postal_code'] ?? '')),
                'home_country'         => trim((string) ($draft['home_country'] ?? '')),
                'referral_information' => trim((string) ($draft['referral_information'] ?? '')),
                'customer_origin'      => trim((string) ($draft['customer_origin'] ?? '')),
                'marketing_opt_in'     => !empty($draft['marketing_opt_in']) ? 1 : 0,
                'branch_id'            => $branchId,
                'created_by'           => $userId,
                'updated_by'           => $userId,
            ], fn ($v) => $v !== null && $v !== '');

            if (empty($data['first_name']) || empty($data['last_name'])) {
                throw new \DomainException('Client first name and last name are required.');
            }

            return $this->clientRepo->create($data);
        }

        throw new \DomainException('Invalid client mode in wizard state.');
    }
}
