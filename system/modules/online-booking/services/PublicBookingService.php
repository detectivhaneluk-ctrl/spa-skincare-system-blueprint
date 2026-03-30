<?php

declare(strict_types=1);

namespace Modules\OnlineBooking\Services;

use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Organization\OrganizationLifecycleGate;
use Modules\Appointments\Services\AppointmentService;
use Modules\Appointments\Services\AvailabilityService;
use Modules\Clients\Services\PublicClientResolutionService;
use Modules\OnlineBooking\Repositories\PublicBookingManageTokenRepository;
use Modules\Settings\Services\AppointmentCancellationReasonService;

/**
 * Public online booking: availability lookup and booking creation without auth.
 * Branch must be supplied in request; **new** booking create enforces open **required_before_appointment** intake (pre-booking assignments),
 * service-linked consent, and slot re-check. Token-based **reschedule** uses `AppointmentService::reschedule` (consent + slot checks on the move path; not a second new-booking intake gate).
 * No public per-client consent probe API.
 * Online booking settings (enabled, min_lead, max_days, allow_new_clients) enforced per branch.
 * Token-based **reschedule** applies the same **online_booking** min lead + max-days window as anonymous POST `/book`
 * (in addition to internal `validateTimes` / appointment settings applied inside `AppointmentService::reschedule`).
 *
 * Token **manage** endpoints (lookup, cancel, reschedule, reschedule slots) require branch-effective `online_booking.enabled`
 * and an active branch row. They do **not** require `online_booking.public_api_enabled` (magic links may be used off-catalog).
 */
final class PublicBookingService
{
    public const ERROR_MANAGE_LOOKUP_INVALID = 'Booking management link is invalid or expired.';
    /** Returned when `online_booking.enabled` is off or the appointment branch is inactive — self-service is closed. */
    public const ERROR_MANAGE_SELF_SERVICE_DISABLED = 'Online booking self-service is not available for this branch.';
    public const ERROR_MANAGE_CANCEL_UNAVAILABLE = 'Booking cannot be cancelled through this link.';
    public const ERROR_MANAGE_RESCHEDULE_UNAVAILABLE = 'Booking cannot be rescheduled through this link.';
    public const ERROR_MANAGE_RESCHEDULE_SLOTS_UNAVAILABLE = 'Reschedule slots are not available through this link.';
    private const MANAGE_TOKEN_EXPIRES_DAYS = 30;

    /** Public JSON `error` for paths that must not reveal client/consent/branch membership detail (PB-HARDEN-NEXT). */
    public const ERROR_PUBLIC_BOOKING_GENERIC = 'Booking could not be completed. Please contact the spa if you need help.';
    public const ERROR_ORGANIZATION_SUSPENDED = 'Tenant branch is unavailable.';

    /** When `online_booking.allow_new_clients` is false, anonymous public book has no supported identity path (PB-HARDEN-NEXT). */
    public const ERROR_ALLOW_NEW_CLIENTS_OFF = 'Online booking is not available for this request at this branch. Please contact the spa.';

    /** @var list<string> */
    private const PUBLIC_BOOKING_SAFE_APPOINTMENT_ERRORS = [
        'Selected slot is no longer available.',
        'Service is not active or has invalid duration.',
        'Selected staff is not active.',
        'Selected service is not active.',
        'Selected staff is not eligible for this service.',
        'Room is booked for another appointment at this time.',
        'Required intake forms must be completed before booking this appointment.',
        'Opening hours are not configured for this branch on the selected day.',
        'This branch is closed on the selected day.',
    ];

    public function __construct(
        private Database $db,
        private AvailabilityService $availability,
        private PublicClientResolutionService $publicClientResolution,
        private AppointmentService $appointments,
        private AuditService $audit,
        private SettingsService $settings,
        private PublicBookingManageTokenRepository $manageTokens,
        private OrganizationLifecycleGate $lifecycleGate,
        private AppointmentCancellationReasonService $cancellationReasons
    ) {
    }

    /**
     * Validate branch exists and is not deleted. Returns branch row or null.
     */
    public function validateBranch(int $branchId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, name, code FROM branches WHERE id = ? AND deleted_at IS NULL',
            [$branchId]
        );
        if (!$row) {
            return null;
        }
        if ($this->lifecycleGate->isBranchLinkedToSuspendedOrganization($branchId)) {
            return null;
        }
        return $row ?: null;
    }

    /**
     * Shared gate for public online booking: branch must exist and `online_booking.enabled`
     * must be true for that branch (branch row overrides global `branch_id = 0` per SettingsService::get).
     *
     * @return array{ok: true, ob: array{enabled: bool, public_api_enabled: bool, min_lead_minutes: int, max_days_ahead: int, allow_new_clients: bool}}|array{ok: false, error: string}
     */
    public function requireBranchPublicBookability(int $branchId, string $endpoint = 'unknown'): array
    {
        if ($this->validateBranch($branchId) === null) {
            $isSuspended = $this->lifecycleGate->isBranchLinkedToSuspendedOrganization($branchId);
            $this->logPolicyDenied($endpoint, $isSuspended ? 'organization_suspended' : 'branch_not_found_or_inactive', null, null, null, null, $branchId);
            return ['ok' => false, 'error' => $isSuspended ? self::ERROR_ORGANIZATION_SUSPENDED : 'Branch not found or inactive.'];
        }
        if ($this->settings->isPlatformFounderKillOnlineBooking()) {
            $this->logPolicyDenied($endpoint, 'platform_founder_kill_online_booking', $branchId);
            return ['ok' => false, 'error' => 'Online booking is temporarily unavailable.'];
        }
        $ob = $this->settings->getOnlineBookingSettings($branchId);
        if (!$ob['enabled']) {
            $this->logPolicyDenied($endpoint, 'online_booking_disabled', $branchId);
            return ['ok' => false, 'error' => 'Online booking is not enabled for this branch.'];
        }
        return ['ok' => true, 'ob' => $ob];
    }

    /**
     * Gate for anonymous public API only (GET slots, POST book, GET consent-check).
     * If `online_booking.enabled` is false, this returns the same outcome as {@see requireBranchPublicBookability} (unchanged).
     * If enabled but `online_booking.public_api_enabled` is false, anonymous public calls are blocked. Token manage flows
     * still require `online_booking.enabled` (and active branch) but ignore `public_api_enabled` — see manage methods in this class.
     *
     * @return array{ok: true, ob: array{enabled: bool, public_api_enabled: bool, min_lead_minutes: int, max_days_ahead: int, allow_new_clients: bool}}|array{ok: false, error: string}
     */
    public function requireBranchAnonymousPublicBookingApi(int $branchId, string $endpoint = 'unknown'): array
    {
        $gate = $this->requireBranchPublicBookability($branchId, $endpoint);
        if (!$gate['ok']) {
            return $gate;
        }
        if ($this->settings->isPlatformFounderKillAnonymousPublicApis()) {
            $this->logPolicyDenied($endpoint, 'platform_founder_kill_anonymous_public_apis', $branchId);
            return ['ok' => false, 'error' => 'Public online booking is temporarily unavailable.'];
        }
        $ob = $gate['ob'];
        if (!$ob['public_api_enabled']) {
            $this->logPolicyDenied($endpoint, 'public_booking_api_disabled', $branchId);
            return ['ok' => false, 'error' => 'Public online booking is not available for this branch.'];
        }

        return ['ok' => true, 'ob' => $ob];
    }

    /**
     * Public availability: slots for service/date at branch. branch_id is required.
     *
     * @return array{
     *   success: bool,
     *   data?: array{
     *     date: string,
     *     service_id: int,
     *     staff_id: int|null,
     *     slots: list<string>,
     *     branch_operating_hours: array{
     *       branch_hours_available: bool,
     *       is_closed_day: bool,
     *       open_time: ?string,
     *       close_time: ?string
     *     },
     *     availability_notice: ?string
     *   },
     *   error?: string
     * }
     */
    public function getPublicSlots(int $branchId, int $serviceId, string $date, ?int $staffId = null): array
    {
        $gate = $this->requireBranchAnonymousPublicBookingApi($branchId, 'slots');
        if (!$gate['ok']) {
            return ['success' => false, 'error' => $gate['error']];
        }
        $ob = $gate['ob'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return ['success' => false, 'error' => 'Date must be YYYY-MM-DD.'];
        }
        $today = date('Y-m-d');
        $maxDate = date('Y-m-d', strtotime($today . ' + ' . $ob['max_days_ahead'] . ' days'));
        if ($date < $today || $date > $maxDate) {
            return ['success' => false, 'error' => 'Date must be between today and ' . $maxDate . '.'];
        }
        $service = $this->availability->getActiveServiceForScope($serviceId, $branchId);
        if (!$service) {
            return ['success' => false, 'error' => 'Service not found or not active for this branch.'];
        }
        if ($staffId !== null && $staffId > 0) {
            $staff = $this->availability->getActiveStaffForScope($staffId, $branchId, $serviceId);
            if (!$staff) {
                return ['success' => false, 'error' => 'Staff not found or not active for this branch.'];
            }
        }
        $branchHoursMeta = $this->availability->getBranchOperatingHoursMeta($branchId, $date);
        $slots = $this->availability->getAvailableSlots($serviceId, $date, $staffId, $branchId);
        $apptSearchSettings = $this->settings->getAppointmentSettings($branchId);
        $minLeadSeconds = $ob['min_lead_minutes'] * 60;
        $now = time();
        $slots = array_values(array_filter($slots, static function (string $slot) use ($minLeadSeconds, $now, $date): bool {
            $slotTs = strtotime($date . ' ' . $slot);
            return $slotTs >= $now + $minLeadSeconds;
        }));
        return [
            'success' => true,
            'data' => [
                'date' => $date,
                'service_id' => $serviceId,
                'staff_id' => $staffId,
                'slots' => $slots,
                'check_staff_availability_in_search' => !empty($apptSearchSettings['check_staff_availability_in_search']),
                'branch_operating_hours' => [
                    'branch_hours_available' => (bool) ($branchHoursMeta['branch_hours_available'] ?? false),
                    'is_closed_day' => (bool) ($branchHoursMeta['is_closed_day'] ?? false),
                    'open_time' => $branchHoursMeta['open_time'] ?? null,
                    'close_time' => $branchHoursMeta['close_time'] ?? null,
                ],
                'availability_notice' => $branchHoursMeta['message'] ?? null,
            ],
        ];
    }

    /**
     * Create booking from public flow. Re-validates slot and enforces consent (admin rules; public response is sanitized).
     * created_by/updated_by set to null; audit with actor_user_id null.
     *
     * Public error contract (PB-HARDEN-NEXT): see `ERROR_PUBLIC_BOOKING_GENERIC`, `ERROR_ALLOW_NEW_CLIENTS_OFF`, and
     * `mapPublicSafeAppointmentError` (allowlisted operational messages only from the locked appointment pipeline).
     *
     * @return array{success: bool, appointment_id?: int, manage_token?: string, error?: string}
     */
    public function createBooking(
        int $branchId,
        int $serviceId,
        int $staffId,
        string $startTime,
        array $clientPayload,
        ?string $notes = null,
        ?int $clientMembershipId = null
    ): array {
        $gate = $this->requireBranchAnonymousPublicBookingApi($branchId, 'book');
        if (!$gate['ok']) {
            return ['success' => false, 'error' => self::ERROR_PUBLIC_BOOKING_GENERIC];
        }
        $ob = $gate['ob'];
        $startAt = $this->normalizeStartTime($startTime);
        if ($startAt === null) {
            $this->logRequestRejected('book', 'invalid_normalized_start_time', [
                'branch_id' => $branchId,
                'service_id' => $serviceId,
                'staff_id' => $staffId,
            ]);
            return ['success' => false, 'error' => self::ERROR_PUBLIC_BOOKING_GENERIC];
        }
        $now = time();
        $startTs = strtotime($startAt);
        $minLeadSeconds = $ob['min_lead_minutes'] * 60;
        if ($startTs < $now + $minLeadSeconds) {
            return ['success' => false, 'error' => self::ERROR_PUBLIC_BOOKING_GENERIC];
        }
        $today = date('Y-m-d');
        $maxDate = date('Y-m-d', strtotime($today . ' + ' . $ob['max_days_ahead'] . ' days'));
        $startDate = date('Y-m-d', $startTs);
        if ($startDate > $maxDate) {
            return ['success' => false, 'error' => self::ERROR_PUBLIC_BOOKING_GENERIC];
        }
        $service = $this->availability->getActiveServiceForScope($serviceId, $branchId);
        if (!$service) {
            return ['success' => false, 'error' => self::ERROR_PUBLIC_BOOKING_GENERIC];
        }
        $staff = $this->availability->getActiveStaffForScope($staffId, $branchId, $serviceId);
        if (!$staff) {
            return ['success' => false, 'error' => self::ERROR_PUBLIC_BOOKING_GENERIC];
        }
        if (!$ob['allow_new_clients']) {
            $this->logPolicyDenied('book', 'allow_new_clients_disabled', $branchId, $serviceId, $staffId, $startAt);
            return ['success' => false, 'error' => self::ERROR_ALLOW_NEW_CLIENTS_OFF];
        }

        $pdo = $this->db->connection();
        try {
            // Next transaction only: conflict checks after staff FOR UPDATE must see other commits (InnoDB default RR + long txn).
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            try {
                $resolved = $this->publicClientResolution->resolve($branchId, $clientPayload, 'public_booking', true);
                $clientId = $resolved['client_id'];
            } catch (\InvalidArgumentException) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $this->logRequestRejected('book', 'invalid_required_payload', [
                    'branch_id' => $branchId,
                    'service_id' => $serviceId,
                    'staff_id' => $staffId,
                    'normalized_start_at' => $startAt,
                ]);
                return ['success' => false, 'error' => self::ERROR_PUBLIC_BOOKING_GENERIC];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['success' => false, 'error' => self::ERROR_PUBLIC_BOOKING_GENERIC];
            }

            $id = $this->appointments->createFromPublicBooking(
                $branchId,
                $clientId,
                $serviceId,
                $staffId,
                $startAt,
                $notes,
                $clientMembershipId
            );
            $pdo->commit();
            $this->audit->log('public_client_resolution', 'appointment', $id, null, $branchId, [
                'client_id' => $clientId,
                'source' => 'public_booking',
                'resolution' => $resolved['reason'],
                'match_rule' => $resolved['match_rule'],
                'client_was_created' => $resolved['created'],
            ]);
        } catch (\DomainException | \InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => $this->mapPublicSafeAppointmentError($e)];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'error' => self::ERROR_PUBLIC_BOOKING_GENERIC];
        }

        $tokenPair = $this->issueManageToken($id, $branchId);

        return [
            'success' => true,
            'appointment_id' => $id,
            'manage_token' => $tokenPair['token'],
        ];
    }

    /**
     * @return array{
     *   success: bool,
     *   data?: array{
     *     appointment_id: int,
     *     status: string,
     *     start_at: string,
     *     end_at: string,
     *     actions: array{
     *       cancel_allowed: bool,
     *       reschedule_allowed: bool,
     *       reasons: array{
     *         cancel_blocked_reason_code: string|null,
     *         reschedule_blocked_reason_code: string|null
     *       }
     *     },
     *     cancellation_policy: array{
     *       enabled: bool,
     *       min_notice_hours: int,
     *       cancellation_allowed: bool,
     *       reason_required: bool,
     *       policy_text: string
     *     },
     *     cancellation_reasons: list<array{id:int,code:string,name:string}>,
     *     branch: array{id:int,name:string}|null,
     *     service: array{id:int,name:string}|null,
     *     staff: array{id:int,display_name:string}|null
     *   },
     *   error?: string
     * }
     */
    public function getManageLookupByToken(string $token): array
    {
        $resolved = $this->resolveValidManageToken($token);
        if ($resolved === null) {
            return ['success' => false, 'error' => self::ERROR_MANAGE_LOOKUP_INVALID];
        }

        $row = $resolved['row'];
        $tokenHash = $resolved['token_hash'];
        $deny = $this->gateManageTokenOnlineBookingSelfService($row, $tokenHash);
        if ($deny !== null) {
            return ['success' => false, 'error' => $deny];
        }
        $this->manageTokens->touchLastUsed((int) $row['token_id']);
        $this->logManageLookupViewed((int) $row['appointment_id'], (int) $row['branch_id'], $tokenHash);
        $actions = $this->computeManageActionPolicy($row);
        $branchId = isset($row['branch_id']) ? (int) $row['branch_id'] : null;
        $policy = $this->settings->getCancellationRuntimeEnforcement($branchId);
        $rawPolicy = $this->settings->getCancellationPolicySettings($branchId);
        $reasons = [];
        // Reason list visibility follows stored policy keys on getCancellationPolicySettings(), not getCancellationRuntimeEnforcement() (which omits reasons_enabled).
        if (!empty($rawPolicy['reasons_enabled']) && $this->cancellationReasons->isStorageReady()) {
            foreach ($this->cancellationReasons->listForCurrentOrganization(true) as $reason) {
                $appliesTo = (string) ($reason['applies_to'] ?? '');
                if ($appliesTo !== 'cancellation' && $appliesTo !== 'both') {
                    continue;
                }
                $reasons[] = [
                    'id' => (int) ($reason['id'] ?? 0),
                    'code' => (string) ($reason['code'] ?? ''),
                    'name' => (string) ($reason['name'] ?? ''),
                ];
            }
        }

        return [
            'success' => true,
            'data' => [
                'appointment_id' => (int) $row['appointment_id'],
                'status' => (string) $row['status'],
                'start_at' => (string) $row['start_at'],
                'end_at' => (string) $row['end_at'],
                'actions' => $actions,
                'cancellation_policy' => [
                    'enabled' => (bool) ($policy['cancellation_allowed'] ?? true),
                    'min_notice_hours' => (int) ($policy['min_notice_hours'] ?? 0),
                    'cancellation_allowed' => (bool) ($actions['cancel_allowed'] ?? false),
                    'reason_required' => (bool) ($policy['reason_effectively_required_for_cancellation'] ?? false),
                    'policy_text' => (string) ($rawPolicy['policy_text'] ?? ''),
                ],
                'cancellation_reasons' => $reasons,
                'branch' => isset($row['branch_id']) && isset($row['branch_name']) ? [
                    'id' => (int) $row['branch_id'],
                    'name' => (string) $row['branch_name'],
                ] : null,
                'service' => isset($row['service_id']) && isset($row['service_name']) ? [
                    'id' => (int) $row['service_id'],
                    'name' => (string) $row['service_name'],
                ] : null,
                'staff' => isset($row['staff_id']) ? [
                    'id' => (int) $row['staff_id'],
                    'display_name' => trim((string) (($row['staff_first_name'] ?? '') . ' ' . ($row['staff_last_name'] ?? ''))),
                ] : null,
            ],
        ];
    }

    /**
     * @return array{success: bool, appointment_id?: int, status?: string, cancelled_at?: string|null, error?: string}
     */
    public function cancelByManageToken(string $token, ?string $reason = null, ?int $cancellationReasonId = null): array
    {
        $resolved = $this->resolveValidManageToken($token);
        if ($resolved === null) {
            return ['success' => false, 'error' => self::ERROR_MANAGE_LOOKUP_INVALID];
        }

        $row = $resolved['row'];
        $tokenHash = $resolved['token_hash'];
        $deny = $this->gateManageTokenOnlineBookingSelfService($row, $tokenHash);
        if ($deny !== null) {
            return ['success' => false, 'error' => $deny];
        }
        $appointmentId = (int) $row['appointment_id'];
        $branchId = isset($row['branch_id']) ? (int) $row['branch_id'] : null;
        $actions = $this->computeManageActionPolicy($row);
        $status = (string) ($row['status'] ?? '');

        if ($status === 'cancelled') {
            $this->logManageCancelSucceeded($appointmentId, $branchId, $tokenHash, true);
            return ['success' => true, 'appointment_id' => $appointmentId, 'status' => 'cancelled', 'cancelled_at' => null];
        }

        if (!$actions['cancel_allowed']) {
            $reason = (string) ($actions['reasons']['cancel_blocked_reason_code'] ?? 'not_cancelable');
            $this->logManageCancelDenied($tokenHash, $reason);
            return ['success' => false, 'error' => self::ERROR_MANAGE_CANCEL_UNAVAILABLE];
        }

        $cancelPolicy = $this->settings->getCancellationRuntimeEnforcement($branchId);
        $reasonsStorageReady = $this->cancellationReasons->isStorageReady();
        $requiresStructuredReason = !empty($cancelPolicy['reason_effectively_required_for_cancellation']);
        if ($requiresStructuredReason && !$reasonsStorageReady) {
            $this->logManageCancelDenied($tokenHash, 'reason_storage_unavailable');
            return ['success' => false, 'error' => self::ERROR_MANAGE_CANCEL_UNAVAILABLE];
        }
        if ($cancellationReasonId !== null && $cancellationReasonId > 0 && !$reasonsStorageReady) {
            $this->logManageCancelDenied($tokenHash, 'reason_storage_unavailable');
            return ['success' => false, 'error' => self::ERROR_MANAGE_CANCEL_UNAVAILABLE];
        }

        try {
            $this->appointments->cancel($appointmentId, $reason, $cancellationReasonId);
        } catch (\DomainException | \RuntimeException | \InvalidArgumentException) {
            $this->logManageCancelDenied($tokenHash, 'not_cancelable');
            return ['success' => false, 'error' => self::ERROR_MANAGE_CANCEL_UNAVAILABLE];
        }

        $this->logManageCancelSucceeded($appointmentId, $branchId, $tokenHash, false);
        return ['success' => true, 'appointment_id' => $appointmentId, 'status' => 'cancelled', 'cancelled_at' => null];
    }

    /**
     * @return array{success: bool, appointment_id?: int, status?: string, start_at?: string, end_at?: string, error?: string}
     */
    public function rescheduleByManageToken(string $token, string $requestedStartAt): array
    {
        $resolved = $this->resolveValidManageToken($token);
        if ($resolved === null) {
            return ['success' => false, 'error' => self::ERROR_MANAGE_LOOKUP_INVALID];
        }

        $row = $resolved['row'];
        $tokenHash = $resolved['token_hash'];
        $deny = $this->gateManageTokenOnlineBookingSelfService($row, $tokenHash);
        if ($deny !== null) {
            return ['success' => false, 'error' => $deny];
        }
        $appointmentId = (int) $row['appointment_id'];
        $branchId = isset($row['branch_id']) ? (int) $row['branch_id'] : null;
        $status = (string) ($row['status'] ?? '');
        $currentStartAt = (string) ($row['start_at'] ?? '');
        $currentEndAt = (string) ($row['end_at'] ?? '');
        $actions = $this->computeManageActionPolicy($row);
        $normalizedStartAt = $this->normalizeManageRescheduleStartAt($requestedStartAt);
        if ($normalizedStartAt === null) {
            $this->logManageRescheduleDenied($tokenHash, 'invalid_requested_start_at', null);
            return ['success' => false, 'error' => self::ERROR_MANAGE_RESCHEDULE_UNAVAILABLE];
        }

        if (!$actions['reschedule_allowed']) {
            $reason = (string) ($actions['reasons']['reschedule_blocked_reason_code'] ?? 'not_reschedulable_or_unavailable');
            $this->logManageRescheduleDenied($tokenHash, $reason, $normalizedStartAt);
            return ['success' => false, 'error' => self::ERROR_MANAGE_RESCHEDULE_UNAVAILABLE];
        }
        $requestedStartTs = strtotime($normalizedStartAt);
        if ($requestedStartTs === false || $requestedStartTs <= time()) {
            $this->logManageRescheduleDenied($tokenHash, 'requested_start_in_past', $normalizedStartAt);
            return ['success' => false, 'error' => self::ERROR_MANAGE_RESCHEDULE_UNAVAILABLE];
        }

        if ($currentStartAt === $normalizedStartAt) {
            $this->logManageRescheduleDenied($tokenHash, 'same_slot_requested', $normalizedStartAt);
            return ['success' => false, 'error' => self::ERROR_MANAGE_RESCHEDULE_UNAVAILABLE];
        }

        if ($branchId === null || $branchId <= 0 || !$this->candidateStartPassesOnlineBookingScheduleWindow($branchId, $normalizedStartAt)) {
            $this->logManageRescheduleDenied($tokenHash, 'outside_online_booking_window', $normalizedStartAt);
            return ['success' => false, 'error' => self::ERROR_MANAGE_RESCHEDULE_UNAVAILABLE];
        }

        try {
            // Reuse authoritative reschedule domain path; preserves same service/staff/branch context.
            $this->appointments->reschedule($appointmentId, $normalizedStartAt, null, null, $currentStartAt, true);
        } catch (\DomainException | \RuntimeException | \InvalidArgumentException) {
            $this->logManageRescheduleDenied($tokenHash, 'not_reschedulable_or_unavailable', $normalizedStartAt);
            return ['success' => false, 'error' => self::ERROR_MANAGE_RESCHEDULE_UNAVAILABLE];
        }

        $updated = $this->manageTokens->findValidByTokenHash($tokenHash);
        if ($updated === null) {
            $this->logManageRescheduleDenied($tokenHash, 'post_update_token_resolution_failed', $normalizedStartAt);
            return ['success' => false, 'error' => self::ERROR_MANAGE_RESCHEDULE_UNAVAILABLE];
        }

        $this->logManageRescheduleSucceeded($appointmentId, $branchId, $tokenHash, $normalizedStartAt, false);
        return [
            'success' => true,
            'appointment_id' => $appointmentId,
            'status' => (string) ($updated['status'] ?? $status),
            'start_at' => (string) ($updated['start_at'] ?? $normalizedStartAt),
            'end_at' => (string) ($updated['end_at'] ?? $currentEndAt),
        ];
    }

    /**
     * @return array{
     *   success: bool,
     *   data?: array{
     *     appointment_id:int,
     *     date:string,
     *     branch_id:int,
     *     service_id:int,
     *     staff_id:int,
     *     slots:list<string>
     *   },
     *   error?: string
     * }
     */
    public function getManageRescheduleSlotsByToken(string $token, string $date): array
    {
        $resolved = $this->resolveValidManageToken($token);
        if ($resolved === null) {
            return ['success' => false, 'error' => self::ERROR_MANAGE_LOOKUP_INVALID];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date)) !== 1) {
            return ['success' => false, 'error' => self::ERROR_MANAGE_RESCHEDULE_SLOTS_UNAVAILABLE];
        }

        $row = $resolved['row'];
        $tokenHash = $resolved['token_hash'];
        $deny = $this->gateManageTokenOnlineBookingSelfService($row, $tokenHash);
        if ($deny !== null) {
            return ['success' => false, 'error' => $deny];
        }
        $appointmentId = (int) $row['appointment_id'];
        $branchId = isset($row['branch_id']) ? (int) $row['branch_id'] : 0;
        $serviceId = isset($row['service_id']) ? (int) $row['service_id'] : 0;
        $staffId = isset($row['staff_id']) ? (int) $row['staff_id'] : 0;
        if ($branchId <= 0 || $serviceId <= 0 || $staffId <= 0) {
            return ['success' => false, 'error' => self::ERROR_MANAGE_RESCHEDULE_SLOTS_UNAVAILABLE];
        }

        $ob = $this->settings->getOnlineBookingSettings($branchId);
        $today = date('Y-m-d');
        $maxDate = date('Y-m-d', strtotime($today . ' + ' . $ob['max_days_ahead'] . ' days'));
        if ($date < $today || $date > $maxDate) {
            return ['success' => false, 'error' => self::ERROR_MANAGE_RESCHEDULE_SLOTS_UNAVAILABLE];
        }

        $actions = $this->computeManageActionPolicy($row);
        if (!$actions['reschedule_allowed']) {
            return ['success' => false, 'error' => self::ERROR_MANAGE_RESCHEDULE_SLOTS_UNAVAILABLE];
        }

        $slots = $this->availability->getAvailableSlots($serviceId, $date, $staffId, $branchId);
        $now = time();
        $currentStartAt = (string) ($row['start_at'] ?? '');
        $currentStartDate = $currentStartAt !== '' ? date('Y-m-d', strtotime($currentStartAt)) : null;
        $currentStartTime = $currentStartAt !== '' ? date('H:i:s', strtotime($currentStartAt)) : null;

        $normalizedSlots = [];
        foreach ($slots as $slot) {
            $slotTs = strtotime($date . ' ' . $slot);
            if ($slotTs === false || $slotTs <= $now) {
                continue;
            }
            $slotTime = date('H:i:s', $slotTs);
            $candidateStartAt = $date . ' ' . $slotTime;
            // Keep behavior explicit and stable: exclude current appointment slot from alternatives.
            if ($currentStartDate === $date && $currentStartTime === $slotTime) {
                continue;
            }
            if (!$this->appointments->passesRescheduleWindowPolicy($serviceId, $branchId, $candidateStartAt)) {
                continue;
            }
            if (!$this->candidateStartPassesOnlineBookingScheduleWindow($branchId, $candidateStartAt)) {
                continue;
            }
            $normalizedSlots[] = $slotTime;
        }

        $apptSearch = $this->settings->getAppointmentSettings($branchId);

        return [
            'success' => true,
            'data' => [
                'appointment_id' => $appointmentId,
                'date' => $date,
                'branch_id' => $branchId,
                'service_id' => $serviceId,
                'staff_id' => $staffId,
                'slots' => $normalizedSlots,
                'check_staff_availability_in_search' => !empty($apptSearch['check_staff_availability_in_search']),
            ],
        ];
    }

    /**
     * Same min-lead + max-days-ahead rules as {@see createBooking} (merged `online_booking.*` for branch).
     * Ensures token self-service cannot pick a start time that anonymous public book would reject.
     */
    private function candidateStartPassesOnlineBookingScheduleWindow(int $branchId, string $startAtLocal): bool
    {
        if ($branchId <= 0) {
            return false;
        }
        $ob = $this->settings->getOnlineBookingSettings($branchId);
        $ts = strtotime(trim($startAtLocal));
        if ($ts === false) {
            return false;
        }
        $now = time();
        $minLeadSeconds = $ob['min_lead_minutes'] * 60;
        if ($ts < $now + $minLeadSeconds) {
            return false;
        }
        $today = date('Y-m-d');
        $maxDate = date('Y-m-d', strtotime($today . ' + ' . $ob['max_days_ahead'] . ' days'));
        $startDate = date('Y-m-d', $ts);

        return $startDate >= $today && $startDate <= $maxDate;
    }

    private function mapPublicSafeAppointmentError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'consent') !== false) {
            return self::ERROR_PUBLIC_BOOKING_GENERIC;
        }
        if (in_array($msg, self::PUBLIC_BOOKING_SAFE_APPOINTMENT_ERRORS, true)) {
            return $msg;
        }
        if (str_starts_with($msg, 'The selected time falls outside this branch\'s operating hours (')) {
            return $msg;
        }

        return self::ERROR_PUBLIC_BOOKING_GENERIC;
    }

    private function normalizeStartTime(string $startTime): ?string
    {
        $startTime = trim($startTime);
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{1,2}:\d{2}/', $startTime)) {
            $ts = strtotime($startTime);
            return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        }
        if (preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $startTime)) {
            $date = date('Y-m-d');
            $ts = strtotime($date . ' ' . $startTime);
            return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        }
        return null;
    }

    private function normalizeManageRescheduleStartAt(string $startAt): ?string
    {
        $startAt = trim($startAt);
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $startAt) !== 1) {
            return null;
        }
        $ts = strtotime($startAt);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Token manage requires branch-effective `online_booking.enabled` and an active branch (same merge as {@see SettingsService::getOnlineBookingSettings}).
     * Intentionally does not check `public_api_enabled`.
     *
     * @param array<string, mixed> $row Row from {@see resolveValidManageToken}
     * @return string|null {@see ERROR_MANAGE_SELF_SERVICE_DISABLED} when denied
     */
    private function gateManageTokenOnlineBookingSelfService(array $row, string $tokenHash): ?string
    {
        $branchId = isset($row['branch_id']) && $row['branch_id'] !== '' && $row['branch_id'] !== null
            ? (int) $row['branch_id']
            : null;
        $deny = $this->onlineBookingSelfServiceDeniedError($branchId);
        if ($deny !== null) {
            $this->audit->log('public_booking_manage_self_service_denied', 'public_booking', null, null, $branchId !== null && $branchId > 0 ? $branchId : null, [
                'marker_prefix' => substr($tokenHash, 0, 12),
                'deny_reason' => 'online_booking_disabled_or_branch_inactive',
            ]);
        }

        return $deny;
    }

    private function onlineBookingSelfServiceDeniedError(?int $branchId): ?string
    {
        if ($branchId === null || $branchId <= 0) {
            return self::ERROR_MANAGE_SELF_SERVICE_DISABLED;
        }
        if ($this->validateBranch($branchId) === null) {
            return self::ERROR_MANAGE_SELF_SERVICE_DISABLED;
        }
        if ($this->settings->isPlatformFounderKillOnlineBooking()) {
            return self::ERROR_MANAGE_SELF_SERVICE_DISABLED;
        }
        $ob = $this->settings->getOnlineBookingSettings($branchId);
        if (!$ob['enabled']) {
            return self::ERROR_MANAGE_SELF_SERVICE_DISABLED;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   cancel_allowed: bool,
     *   reschedule_allowed: bool,
     *   reasons: array{
     *     cancel_blocked_reason_code: string|null,
     *     reschedule_blocked_reason_code: string|null
     *   }
     * }
     */
    private function computeManageActionPolicy(array $row): array
    {
        $status = (string) ($row['status'] ?? '');
        $startAt = (string) ($row['start_at'] ?? '');
        $startTs = strtotime($startAt);
        $isPastStart = $startTs !== false && $startTs <= time();
        $branchId = isset($row['branch_id']) && (int) $row['branch_id'] > 0 ? (int) $row['branch_id'] : null;
        $cancelSettings = $this->settings->getCancellationRuntimeEnforcement($branchId);
        $insideCancelNoticeWindow = false;
        if ($startTs !== false && (int) ($cancelSettings['min_notice_hours'] ?? 0) > 0) {
            $insideCancelNoticeWindow = $startTs < (time() + (((int) $cancelSettings['min_notice_hours']) * 3600));
        }

        $cancelAllowed = true;
        $cancelReason = null;
        if (in_array($status, ['cancelled', 'completed', 'no_show'], true)) {
            $cancelAllowed = false;
            $cancelReason = 'invalid_status';
        } elseif (!(bool) ($cancelSettings['cancellation_allowed'] ?? true)) {
            $cancelAllowed = false;
            $cancelReason = 'cancellation_disabled';
        } elseif ($insideCancelNoticeWindow) {
            // Public self-service has no privileged override path.
            $cancelAllowed = false;
            $cancelReason = 'inside_notice_window';
        } elseif ($isPastStart) {
            $cancelAllowed = false;
            $cancelReason = 'past_start';
        }

        $rescheduleAllowed = true;
        $rescheduleReason = null;
        if (in_array($status, ['cancelled', 'completed', 'no_show'], true)) {
            $rescheduleAllowed = false;
            $rescheduleReason = 'invalid_status';
        } elseif ($isPastStart) {
            $rescheduleAllowed = false;
            $rescheduleReason = 'past_start';
        }

        return [
            'cancel_allowed' => $cancelAllowed,
            'reschedule_allowed' => $rescheduleAllowed,
            'reasons' => [
                'cancel_blocked_reason_code' => $cancelReason,
                'reschedule_blocked_reason_code' => $rescheduleReason,
            ],
        ];
    }

    private function logPolicyDenied(
        string $endpoint,
        string $denyReason,
        ?int $branchId,
        ?int $serviceId = null,
        ?int $staffId = null,
        ?string $normalizedStartAt = null,
        ?int $requestedBranchId = null
    ): void {
        $auditBranchId = $branchId !== null && $branchId > 0 ? $branchId : null;
        $this->audit->log('public_booking_policy_denied', 'public_booking', null, null, $auditBranchId, array_filter([
            'endpoint' => $endpoint,
            'deny_reason' => $denyReason,
            'branch_id' => $auditBranchId,
            'requested_branch_id' => $auditBranchId === null && $requestedBranchId !== null && $requestedBranchId > 0 ? $requestedBranchId : null,
            'service_id' => $serviceId !== null && $serviceId > 0 ? $serviceId : null,
            'staff_id' => $staffId !== null && $staffId > 0 ? $staffId : null,
            'normalized_start_at' => $normalizedStartAt,
        ], static fn ($value): bool => $value !== null));
    }

    private function logRequestRejected(string $endpoint, string $denyReason, array $metadata): void
    {
        $branchId = isset($metadata['branch_id']) && (int) $metadata['branch_id'] > 0 ? (int) $metadata['branch_id'] : null;
        $metadata['endpoint'] = $endpoint;
        $metadata['deny_reason'] = $denyReason;

        $this->audit->log(
            'public_booking_request_rejected',
            'public_booking',
            null,
            null,
            $branchId,
            array_filter($metadata, static fn ($value): bool => $value !== null)
        );
    }

    /**
     * @return array{token:string, hash:string}
     */
    private function issueManageToken(int $appointmentId, int $branchId): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + (self::MANAGE_TOKEN_EXPIRES_DAYS * 86400));
        $this->manageTokens->upsertForAppointment($appointmentId, $branchId, $tokenHash, $expiresAt);

        $markerPrefix = substr($tokenHash, 0, 12);
        $this->audit->log('public_booking_manage_token_issued', 'public_booking', $appointmentId, null, $branchId, [
            'marker_prefix' => $markerPrefix,
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $rawToken, 'hash' => $tokenHash];
    }

    private function logManageLookupViewed(int $appointmentId, int $branchId, string $tokenHash): void
    {
        $this->audit->log('public_booking_manage_lookup_viewed', 'public_booking', $appointmentId, null, $branchId, [
            'marker_prefix' => substr($tokenHash, 0, 12),
        ]);
    }

    private function logManageLookupDenied(string $tokenHash, string $denyReason): void
    {
        $this->audit->log('public_booking_manage_lookup_denied', 'public_booking', null, null, null, [
            'deny_reason' => $denyReason,
            'marker_prefix' => substr($tokenHash, 0, 12),
        ]);
    }

    /**
     * @return array{row: array<string, mixed>, token_hash: string}|null
     */
    private function resolveValidManageToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $row = $this->manageTokens->findValidByTokenHash($tokenHash);
        if ($row === null) {
            $this->logManageLookupDenied($tokenHash, 'invalid_or_inactive_token');
            return null;
        }

        return ['row' => $row, 'token_hash' => $tokenHash];
    }

    private function logManageCancelSucceeded(int $appointmentId, ?int $branchId, string $tokenHash, bool $alreadyCancelled): void
    {
        $this->audit->log('public_booking_manage_cancel_succeeded', 'public_booking', $appointmentId, null, $branchId, [
            'marker_prefix' => substr($tokenHash, 0, 12),
            'already_cancelled' => $alreadyCancelled,
        ]);
    }

    private function logManageCancelDenied(string $tokenHash, string $denyReason): void
    {
        $this->audit->log('public_booking_manage_cancel_denied', 'public_booking', null, null, null, [
            'deny_reason' => $denyReason,
            'marker_prefix' => substr($tokenHash, 0, 12),
        ]);
    }

    private function logManageRescheduleSucceeded(
        int $appointmentId,
        ?int $branchId,
        string $tokenHash,
        string $requestedStartAt,
        bool $noop
    ): void {
        $this->audit->log('public_booking_manage_reschedule_succeeded', 'public_booking', $appointmentId, null, $branchId, [
            'marker_prefix' => substr($tokenHash, 0, 12),
            'requested_start_at' => $requestedStartAt,
            'noop' => $noop,
        ]);
    }

    private function logManageRescheduleDenied(string $tokenHash, string $denyReason, ?string $requestedStartAt): void
    {
        $this->audit->log('public_booking_manage_reschedule_denied', 'public_booking', null, null, null, [
            'deny_reason' => $denyReason,
            'marker_prefix' => substr($tokenHash, 0, 12),
            'requested_start_at' => $requestedStartAt,
        ]);
    }
}
