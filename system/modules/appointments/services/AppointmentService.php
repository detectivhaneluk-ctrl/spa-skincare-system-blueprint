<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Core\App\Application;
use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Branch\TenantBranchAccessService;
use Core\Contracts\AppointmentPackageConsumptionProvider;
use Core\Errors\AccessDeniedException;
use Core\Kernel\RequestContextHolder;
use Core\Organization\OrganizationRepositoryScope;
use Core\Organization\OrganizationScopedBranchAssert;
use Core\Permissions\PermissionService;
use Core\Tenant\TenantOwnedDataScopeGuard;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Documents\Services\ConsentService;
use Modules\Intake\Services\IntakeFormService;
use Modules\Memberships\Services\MembershipService;
use Modules\Notifications\Services\NotificationService;
use Modules\Notifications\Services\OutboundTransactionalNotificationService;
use Modules\ServicesResources\Services\ServiceStaffGroupEligibilityService;
use Modules\Settings\Services\AppointmentCancellationReasonService;
use Modules\Settings\Services\BranchOperatingHoursService;
use Modules\Staff\Services\StaffGroupService;

/**
 * Appointments service with conflict detection.
 *
 * Scope: TenantContext (RequestContextHolder) enforces branch scope on all protected mutations.
 * All appointment retrieval for mutation paths uses canonical loadForUpdate(TenantContext, id)
 * which eliminates the old "load by id then assertBranchMatch" anti-pattern (BIG-04 migration).
 *
 * TenantOwnedDataScopeGuard is retained as a compatibility bridge for assertClientInScope,
 * assertServiceInScope, assertStaffInScope, assertRoomInScope — these delegate to org-scoped
 * repository lookups and will be migrated to canonical repository methods in a future phase.
 *
 * Document consents required for a service are asserted whenever client_id + service_id apply on slot create and on
 * scheduling moves (update + reschedule). Intake `required_before_appointment` blocks **new** slot/create appointments when
 * the client has open pre-appointment assignments — see {@see IntakeFormService::countBlockingRequiredPendingAssignmentsForNewBooking()}.
 */
final class AppointmentService
{
    private const VALID_STATUSES = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];
    private const TERMINAL_STATUSES = ['completed', 'cancelled', 'no_show'];
    private const STATUS_TRANSITIONS = [
        'scheduled' => ['confirmed', 'cancelled', 'no_show'],
        'confirmed' => ['scheduled', 'in_progress', 'cancelled', 'no_show'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
    ];

    public function __construct(
        private AppointmentRepository $repo,
        private AuditService $audit,
        private AppointmentPackageConsumptionProvider $packageConsumption,
        private Database $db,
        private AvailabilityService $availability,
        private RequestContextHolder $contextHolder,
        private ConsentService $consentService,
        private SettingsService $settings,
        private PermissionService $permissions,
        private NotificationService $notifications,
        private OutboundTransactionalNotificationService $outboundTransactional,
        private MembershipService $membershipService,
        private StaffGroupService $staffGroupService,
        private ServiceStaffGroupEligibilityService $serviceStaffGroupEligibility,
        private BranchOperatingHoursService $branchOperatingHours,
        private IntakeFormService $intakeForms,
        private TenantOwnedDataScopeGuard $tenantScopeGuard,
        private AppointmentCancellationReasonService $cancellationReasons,
        private TenantBranchAccessService $tenantBranchAccess,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    public function create(array $data): int
    {
        $id = $this->transactional(function () use ($data): int {
            $this->contextHolder->requireContext()->requireResolvedTenant();
            $data = $this->applyTenantCreateBranchResolution($data);
            $this->validateStatus($data['status'] ?? 'scheduled');
            $this->lockActiveStaffAndServiceRows($this->nullablePositiveId($data, 'staff_id'), $this->nullablePositiveId($data, 'service_id'));
            $this->validateTimes($data);
            $this->assertActiveEntities($data);
            $branchIdForScope = isset($data['branch_id']) && $data['branch_id'] !== null && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null;
            $this->assertStaffInGroupScope($this->nullablePositiveId($data, 'staff_id'), $branchIdForScope);
            $this->assertStaffAllowedForServiceGroups(
                $this->nullablePositiveId($data, 'staff_id'),
                $this->nullablePositiveId($data, 'service_id'),
                $branchIdForScope
            );
            $this->assertRequiredConsents($data);
            $this->assertWithinBranchOperatingHours(
                isset($data['start_at']) ? (string) $data['start_at'] : null,
                isset($data['end_at']) ? (string) $data['end_at'] : null,
                isset($data['branch_id']) && $data['branch_id'] !== null && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null
            );
            $bookingBranch = $branchIdForScope;
            if ($bookingBranch !== null && $bookingBranch <= 0) {
                $bookingBranch = null;
            }
            $this->assertNoBlockingRequiredIntakeForNewBooking((int) ($data['client_id'] ?? 0), $bookingBranch, 'appointment_create', $this->currentUserId());
            $conflicts = $this->checkConflicts($data, 0);
            if (!empty($conflicts)) {
                throw new \DomainException(implode(' ', $conflicts));
            }
            $userId = $this->currentUserId();
            $data['created_by'] = $userId;
            $data['updated_by'] = $userId;
            $membershipOpt = $this->nullablePositiveId($data, 'client_membership_id');
            $id = $this->repo->create($data);
            if ($membershipOpt !== null) {
                $clientId = (int) ($data['client_id'] ?? 0);
                $branchId = $data['branch_id'] !== null && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null;
                $this->membershipService->consumeBenefitForAppointment(
                    $membershipOpt,
                    $id,
                    $clientId,
                    $branchId,
                    (string) ($data['start_at'] ?? '')
                );
            }
            $this->audit->log('appointment_created', 'appointment', $id, $userId, $data['branch_id'] ?? null, [
                'appointment' => $data,
            ], 'success', 'booking');
            \slog('info', 'critical_path.booking', 'appointment_created', [
                'appointment_id' => $id,
                'branch_id' => $data['branch_id'] ?? null,
            ]);
            return $id;
        }, 'appointment create', true);
        try {
            $this->outboundTransactional->enqueueAppointmentConfirmation($id);
        } catch (\Throwable $e) {
            slog('error', 'appointments.outbound.confirmation', $e->getMessage(), ['appointment_id' => $id]);
        }

        // WAVE-06: invalidate calendar display cache for the affected date/branch after successful creation.
        try {
            $aptDateRaw = (string) ($data['start_at'] ?? '');
            $aptDate = $aptDateRaw !== '' ? date('Y-m-d', strtotime($aptDateRaw)) : null;
            $aptBranchId = isset($data['branch_id']) && $data['branch_id'] !== null && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null;
            if ($aptDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $aptDate) === 1) {
                $this->availability->invalidateDayCalendarCache($aptDate, $aptBranchId);
            }
        } catch (\Throwable) {}

        return $id;
    }

    public function update(int $id, array $data): void
    {
        $waitlistSlotFreedSnapshot = null;
        $clearedSeriesIdFromMove = null;
        $captureUpdateInvalidation = null;
        $this->transactional(function () use ($id, $data, &$waitlistSlotFreedSnapshot, &$clearedSeriesIdFromMove, &$captureUpdateInvalidation): void {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $current = $this->repo->loadForUpdate($ctx, $id);
            if (!$current) {
                throw new \RuntimeException('Appointment not found');
            }
            $currentBranchId = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;

            $schedulingMutated = $this->schedulingPatchDiffersFromCurrent($current, $data);
            $merged = array_merge($current, $data);

            if ($schedulingMutated) {
                $svcId = $this->nullablePositiveId($merged, 'service_id');
                $stfId = $this->nullablePositiveId($merged, 'staff_id');
                if ($svcId === null || $stfId === null) {
                    throw new \DomainException(
                        'Changing appointment time, staff, room, or service requires both an active service and staff. Assign both, or leave scheduling fields unchanged.'
                    );
                }
                $startRaw = trim((string) ($merged['start_at'] ?? ''));
                if ($startRaw === '') {
                    throw new \InvalidArgumentException('Start and end time are required.');
                }
                $startAt = $this->normalizeDateTime(str_replace('T', ' ', $startRaw));
                $branchForSlot = $merged['branch_id'] !== null && $merged['branch_id'] !== '' ? (int) $merged['branch_id'] : null;
                $roomIdMerged = $this->nullablePositiveId($merged, 'room_id');

                $move = $this->buildServiceBasedMovePatchAfterAppointmentLock(
                    $id,
                    $current,
                    $startAt,
                    $stfId,
                    $svcId,
                    $branchForSlot,
                    [
                        'mode' => 'patch_from_merged',
                        'room_id' => $roomIdMerged,
                    ],
                    [
                        'client_id' => $merged['client_id'] ?? null,
                        'service_id' => $svcId,
                        'branch_id' => $branchForSlot,
                    ],
                    false
                );
                $data = array_merge($data, $move['patch']);
                $clearedSeriesIdFromMove = $move['cleared_series_id'] ?? null;
            } else {
                $this->lockActiveStaffAndServiceRows($this->nullablePositiveId($data, 'staff_id'), $this->nullablePositiveId($data, 'service_id'));
                $this->validateTimes($data);
            }

            $nextStatus = (string) ($data['status'] ?? $current['status']);
            $this->validateStatus($nextStatus);
            $this->assertStatusTransition((string) ($current['status'] ?? 'scheduled'), $nextStatus);
            if ($nextStatus === 'cancelled') {
                $cancelPolicy = $this->settings->getCancellationRuntimeEnforcement($currentBranchId);
                $cancelReasonId = $this->nullablePositiveId($data, 'cancellation_reason_id');
                if (!empty($cancelPolicy['reason_effectively_required_for_cancellation']) && $cancelReasonId === null) {
                    throw new \DomainException('A cancellation reason is required.');
                }
                if ($cancelReasonId !== null) {
                    $cancelReason = $this->cancellationReasons->findActiveReasonForCurrentOrganization($cancelReasonId, 'cancellation');
                    if ($cancelReason === null) {
                        throw new \DomainException('Invalid cancellation reason.');
                    }
                    $data['cancellation_reason_id'] = (int) $cancelReason['id'];
                }
            }
            if ($nextStatus === 'no_show') {
                $noShowReasonId = $this->nullablePositiveId($data, 'no_show_reason_id');
                if ($noShowReasonId !== null) {
                    $noShowReason = $this->cancellationReasons->findActiveReasonForCurrentOrganization($noShowReasonId, 'no_show');
                    if ($noShowReason === null) {
                        throw new \DomainException('Invalid no-show reason.');
                    }
                    $data['no_show_reason_id'] = (int) $noShowReason['id'];
                }
            }
            $this->assertActiveEntities($data);
            $merged = array_merge($current, $data);
            $this->assertStaffInGroupScope($this->nullablePositiveId($merged, 'staff_id'), $merged['branch_id'] ?? null);
            $this->assertStaffAllowedForServiceGroups(
                $this->nullablePositiveId($merged, 'staff_id'),
                $this->nullablePositiveId($merged, 'service_id'),
                $merged['branch_id'] !== null && $merged['branch_id'] !== '' ? (int) $merged['branch_id'] : null
            );

            if (!$schedulingMutated) {
                $conflicts = $this->checkConflicts($data, $id);
                if (!empty($conflicts)) {
                    throw new \DomainException(implode(' ', $conflicts));
                }
            }

            $beforeAudit = $this->repo->find($id);
            $data['updated_by'] = $this->currentUserId();
            $this->repo->update($id, $data);
            // WAVE-06: capture dates/branch for calendar cache invalidation after transaction commits.
            $captureUpdateInvalidation = [
                'old_start_at' => (string) ($current['start_at'] ?? ''),
                'new_start_at' => isset($data['start_at']) && $data['start_at'] !== '' ? (string) $data['start_at'] : null,
                'branch_id' => $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null,
            ];
            $afterAudit = $this->repo->find($id);
            $this->audit->log('appointment_updated', 'appointment', $id, $this->currentUserId(), $current['branch_id'] ?? null, [
                'before' => $beforeAudit ?? $current,
                'after' => $afterAudit ?? array_merge($current, $data),
            ]);
            $branchForAudit = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            if ($clearedSeriesIdFromMove !== null && $clearedSeriesIdFromMove > 0) {
                $this->audit->log('series_occurrence_detached_from_series', 'appointment', $id, $this->currentUserId(), $branchForAudit, [
                    'former_series_id' => $clearedSeriesIdFromMove,
                    'reason' => 'scheduling_start_changed',
                ]);
            }
            if ($schedulingMutated) {
                $waitlistSlotFreedSnapshot = $current;
            }
        }, 'appointment update', true);
        if ($waitlistSlotFreedSnapshot !== null) {
            $this->invokeWaitlistAutoOfferAfterSlotFreed($waitlistSlotFreedSnapshot, $id);
        }
        // WAVE-06: invalidate calendar display cache after appointment update (old date + new date if scheduling changed).
        if ($captureUpdateInvalidation !== null) {
            try {
                $oldStart = $captureUpdateInvalidation['old_start_at'];
                $newStart = $captureUpdateInvalidation['new_start_at'];
                $updBranchId = $captureUpdateInvalidation['branch_id'];
                $oldDate = $oldStart !== '' ? date('Y-m-d', strtotime($oldStart)) : null;
                $newDate = $newStart !== null && $newStart !== '' ? date('Y-m-d', strtotime($newStart)) : null;
                if ($oldDate !== null && $oldDate !== false && preg_match('/^\d{4}-\d{2}-\d{2}$/', $oldDate) === 1) {
                    $this->availability->invalidateDayCalendarCache($oldDate, $updBranchId);
                }
                if ($newDate !== null && $newDate !== $oldDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) === 1) {
                    $this->availability->invalidateDayCalendarCache($newDate, $updBranchId);
                }
            } catch (\Throwable) {}
        }
    }

    /**
     * @param array{internal_series_bypass_cancellation_policy?: bool} $options
     *         When internal_series_bypass_cancellation_policy is true, skips global cancellation settings
     *         (enabled / min-notice / reason-required / override) for narrow internal series bulk/forward cancel only.
     */
    public function cancel(int $id, ?string $notes = null, ?int $cancellationReasonId = null, array $options = []): void
    {
        $ctx = $this->contextHolder->requireContext();
        $ctx->requireResolvedTenant();

        $pdo = $this->db->connection();
        $started = false;
        $beforeSnapshot = null;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
                $started = true;
            }

            $locked = $this->repo->loadForUpdate($ctx, $id);
            if (!$locked) {
                throw new \RuntimeException('Appointment not found');
            }

            if ((string) ($locked['status'] ?? '') === 'cancelled') {
                if ($started) {
                    $pdo->commit();
                }

                return;
            }

            $bypassPolicy = !empty($options['internal_series_bypass_cancellation_policy']);
            $branchId = $locked['branch_id'] !== null && $locked['branch_id'] !== '' ? (int) $locked['branch_id'] : null;
            $insideNoticeWindow = false;

            if (!$bypassPolicy) {
                $cancelSettings = $this->settings->getCancellationRuntimeEnforcement($branchId);
                if (!$cancelSettings['cancellation_allowed']) {
                    throw new \DomainException('Cancellation is disabled.');
                }
                $reasonRequired = !empty($cancelSettings['reason_effectively_required_for_cancellation']);
                if ($reasonRequired && ($cancellationReasonId === null || $cancellationReasonId <= 0)) {
                    throw new \DomainException('A cancellation reason is required.');
                }
                $startAt = $locked['start_at'] ?? null;
                $minNoticeHours = (int) $cancelSettings['min_notice_hours'];
                if ($minNoticeHours > 0 && $startAt !== null && $startAt !== '') {
                    $cutoff = time() + ($minNoticeHours * 3600);
                    if (strtotime((string) $startAt) < $cutoff) {
                        $insideNoticeWindow = true;
                    }
                }
                if ($insideNoticeWindow) {
                    $allowOverride = $cancelSettings['allow_privileged_override'];
                    $userId = $this->currentUserId();
                    $hasOverride = $userId !== null && $this->permissions->has($userId, 'appointments.cancel_override');
                    if (!$allowOverride || !$hasOverride) {
                        throw new \DomainException('Cancellation is not allowed within ' . $minNoticeHours . ' hour(s) of the appointment.');
                    }
                }
            }

            $this->assertStatusTransition((string) ($locked['status'] ?? 'scheduled'), 'cancelled');

            $beforeSnapshot = $this->repo->find($id);
            if ($beforeSnapshot === null) {
                throw new \RuntimeException('Appointment not found');
            }

            $update = ['status' => 'cancelled', 'updated_by' => $this->currentUserId()];
            if ($cancellationReasonId !== null && $cancellationReasonId > 0) {
                $reason = $this->cancellationReasons->findActiveReasonForCurrentOrganization($cancellationReasonId, 'cancellation');
                if ($reason === null) {
                    throw new \DomainException('Invalid cancellation reason.');
                }
                $update['cancellation_reason_id'] = (int) $reason['id'];
            }
            if ($notes !== null && $notes !== '') {
                $currentNotes = trim((string) ($locked['notes'] ?? ''));
                $update['notes'] = trim($currentNotes . "\n" . '[cancel] ' . $notes);
            }
            $this->repo->update($id, $update);

            $this->membershipService->releaseBenefitUsageForCancelledAppointment($id, $locked);

            $after = $this->repo->find($id);
            $auditMeta = [
                'before' => $beforeSnapshot,
                'after' => $after,
                'notes' => $notes,
                'cancellation_reason_id' => $update['cancellation_reason_id'] ?? null,
                'fee_policy_snapshot' => $this->settings->getCancellationPolicySettings($branchId),
            ];
            if ($insideNoticeWindow) {
                $auditMeta['cancelled_via_override'] = true;
            }
            $this->audit->log('appointment_cancelled', 'appointment', $id, $this->currentUserId(), $locked['branch_id'] !== null ? (int) $locked['branch_id'] : null, $auditMeta);
            $branchIdForNotif = $locked['branch_id'] !== null && $locked['branch_id'] !== '' ? (int) $locked['branch_id'] : null;
            try {
                $this->notifications->create([
                    'branch_id' => $branchIdForNotif,
                    'user_id' => null,
                    'type' => 'appointment_cancelled',
                    'title' => $insideNoticeWindow ? 'Appointment cancelled (override)' : 'Appointment cancelled',
                    'message' => 'Appointment #' . $id . ' was cancelled.' . ($insideNoticeWindow ? ' (Within minimum notice; override used.)' : ''),
                    'entity_type' => 'appointment',
                    'entity_id' => $id,
                ]);
            } catch (\Throwable $notifEx) {
                slog('warning', 'notifications.appointment_cancelled', $notifEx->getMessage(), [
                    'appointment_id' => $id,
                    'branch_id' => $branchIdForNotif,
                ]);
            }
            try {
                $this->outboundTransactional->enqueueAppointmentCancelled($id);
            } catch (\Throwable $outEx) {
                slog('error', 'appointments.outbound.cancelled', $outEx->getMessage(), [
                    'appointment_id' => $id,
                    'branch_id' => $branchIdForNotif,
                ]);
            }
            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Failed to cancel appointment.');
        }
        if ($beforeSnapshot !== null) {
            $this->invokeWaitlistAutoOfferAfterSlotFreed($beforeSnapshot, $id);
        }
        // WAVE-06: invalidate calendar display cache after successful cancellation.
        try {
            $lockedStartAt = (string) ($locked['start_at'] ?? '');
            $cancelDate = $lockedStartAt !== '' ? date('Y-m-d', strtotime($lockedStartAt)) : null;
            $cancelBranchId = isset($locked['branch_id']) && $locked['branch_id'] !== null && $locked['branch_id'] !== '' ? (int) $locked['branch_id'] : null;
            if ($cancelDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cancelDate) === 1) {
                $this->availability->invalidateDayCalendarCache($cancelDate, $cancelBranchId);
            }
        } catch (\Throwable) {}
    }

    public function reschedule(
        int $id,
        string $startTime,
        ?int $staffId = null,
        ?string $notes = null,
        ?string $expectedCurrentStartAt = null,
        bool $forPublicBookingAvailabilityChannel = false
    ): void {
        $ctx = $this->contextHolder->requireContext();
        $scope = $ctx->requireResolvedTenant();
        $startAt = $this->normalizeDateTime($startTime);
        $expectedStartAt = null;
        if ($expectedCurrentStartAt !== null && trim($expectedCurrentStartAt) !== '') {
            $expectedStartAt = $this->normalizeDateTime($expectedCurrentStartAt);
        }

        $pdo = $this->db->connection();
        $started = false;
        $current = null;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
                $started = true;
            }

            $current = $this->repo->loadForUpdate($ctx, $id);
            if (!$current) {
                throw new \RuntimeException('Appointment not found');
            }
            $currentBranchId = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            $currentStatus = (string) ($current['status'] ?? 'scheduled');
            if (in_array($currentStatus, self::TERMINAL_STATUSES, true)) {
                throw new \DomainException('Cannot reschedule appointment in status: ' . $currentStatus);
            }
            $currentStartAt = trim((string) ($current['start_at'] ?? ''));
            if ($expectedStartAt !== null && $currentStartAt !== $expectedStartAt) {
                throw new \DomainException('Appointment schedule has changed. Please refresh and retry.');
            }
            if ($currentStartAt === $startAt) {
                throw new \DomainException('Requested schedule matches current appointment time.');
            }

            $resolvedStaffId = $staffId !== null ? $staffId : (int) ($current['staff_id'] ?? 0);
            if ($resolvedStaffId <= 0) {
                throw new \DomainException('Staff is required for rescheduling.');
            }
            $serviceId = (int) ($current['service_id'] ?? 0);
            if ($serviceId <= 0) {
                throw new \DomainException('Service is required for rescheduling.');
            }

            $branchForSlot = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            $move = $this->buildServiceBasedMovePatchAfterAppointmentLock(
                $id,
                $current,
                $startAt,
                $resolvedStaffId,
                $serviceId,
                $branchForSlot,
                [
                    'mode' => 'preserve_column',
                    'conflict_room_id' => $this->nullablePositiveId($current, 'room_id'),
                ],
                [
                    'client_id' => $current['client_id'] ?? null,
                    'service_id' => $serviceId,
                    'branch_id' => $branchForSlot,
                ],
                $forPublicBookingAvailabilityChannel
            );
            $duration = $move['duration_minutes'];
            $update = $move['patch'];
            if ($notes !== null && $notes !== '') {
                $currentNotes = trim((string) ($current['notes'] ?? ''));
                $update['notes'] = trim($currentNotes . "\n" . '[reschedule] ' . $notes);
            }
            $this->repo->update($id, $update);

            $after = $this->repo->find($id);
            $branchForAudit = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            $this->audit->log('appointment_rescheduled', 'appointment', $id, $this->currentUserId(), $branchForAudit, [
                'before' => $current,
                'after' => $after,
                'duration_minutes' => $duration,
                'notes' => $notes,
            ], 'success', 'booking');
            \slog('info', 'critical_path.booking', 'appointment_rescheduled', [
                'appointment_id' => $id,
                'branch_id' => $branchForAudit,
            ]);
            $clearedSeriesId = $move['cleared_series_id'] ?? null;
            if ($clearedSeriesId !== null && $clearedSeriesId > 0) {
                $this->audit->log('series_occurrence_detached_from_series', 'appointment', $id, $this->currentUserId(), $branchForAudit, [
                    'former_series_id' => $clearedSeriesId,
                    'reason' => 'scheduling_start_changed',
                ]);
            }

            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Failed to reschedule appointment.');
        }
        if ($current !== null) {
            $this->invokeWaitlistAutoOfferAfterSlotFreed($current, $id);
        }
        try {
            $this->outboundTransactional->enqueueAppointmentRescheduled($id);
        } catch (\Throwable $e) {
            slog('error', 'appointments.outbound.rescheduled', $e->getMessage(), ['appointment_id' => $id]);
        }
        // WAVE-06: invalidate calendar display cache for both old and new date/branch after reschedule.
        if ($current !== null) {
            try {
                $oldDate = date('Y-m-d', strtotime((string) ($current['start_at'] ?? '')));
                $newDate = date('Y-m-d', strtotime($startAt));
                $reschBranchId = isset($current['branch_id']) && $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $oldDate) === 1) {
                    $this->availability->invalidateDayCalendarCache($oldDate, $reschBranchId);
                }
                if ($newDate !== $oldDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) === 1) {
                    $this->availability->invalidateDayCalendarCache($newDate, $reschBranchId);
                }
            } catch (\Throwable) {}
        }
    }

    public function updateStatus(int $id, string $newStatus, ?string $notes = null, ?int $cancellationReasonId = null, ?int $noShowReasonId = null): void
    {
        $ctx = $this->contextHolder->requireContext();
        $scope = $ctx->requireResolvedTenant();
        $newStatus = trim($newStatus);
        $this->validateStatus($newStatus);

        $pdo = $this->db->connection();
        $started = false;
        $committedConfirmed = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $current = $this->repo->loadForUpdate($ctx, $id);
            if (!$current) {
                throw new \RuntimeException('Appointment not found');
            }
            $currentBranchId = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            $currentStatus = (string) ($current['status'] ?? 'scheduled');
            if ($currentStatus === $newStatus) {
                if ($started) {
                    $pdo->commit();
                }
                return;
            }
            $this->assertStatusTransition($currentStatus, $newStatus);

            $update = ['status' => $newStatus, 'updated_by' => $this->currentUserId()];
            if ($newStatus === 'cancelled') {
                $cancelPolicy = $this->settings->getCancellationRuntimeEnforcement($currentBranchId);
                if (!empty($cancelPolicy['reason_effectively_required_for_cancellation']) && ($cancellationReasonId === null || $cancellationReasonId <= 0)) {
                    throw new \DomainException('A cancellation reason is required.');
                }
                if ($cancellationReasonId !== null && $cancellationReasonId > 0) {
                    $reason = $this->cancellationReasons->findActiveReasonForCurrentOrganization($cancellationReasonId, 'cancellation');
                    if ($reason === null) {
                        throw new \DomainException('Invalid cancellation reason.');
                    }
                    $update['cancellation_reason_id'] = (int) $reason['id'];
                }
            }
            if ($newStatus === 'no_show' && $noShowReasonId !== null && $noShowReasonId > 0) {
                // In this wave, required-reason policy applies to cancellation only.
                // No-show accepts optional structured reason when provided.
                $reason = $this->cancellationReasons->findActiveReasonForCurrentOrganization($noShowReasonId, 'no_show');
                if ($reason === null) {
                    throw new \DomainException('Invalid no-show reason.');
                }
                $update['no_show_reason_id'] = (int) $reason['id'];
            }
            if ($notes !== null && $notes !== '') {
                $currentNotes = trim((string) ($current['notes'] ?? ''));
                $update['notes'] = trim($currentNotes . "\n" . '[status:' . $newStatus . '] ' . $notes);
            }
            $this->repo->update($id, $update);

            $after = $this->repo->find($id);
            $this->audit->log('appointment_status_updated', 'appointment', $id, $this->currentUserId(), $current['branch_id'] !== null ? (int) $current['branch_id'] : null, [
                'before_status' => $currentStatus,
                'after_status' => $newStatus,
                'before' => $current,
                'after' => $after,
                'notes' => $notes,
                'cancellation_reason_id' => $update['cancellation_reason_id'] ?? null,
                'no_show_reason_id' => $update['no_show_reason_id'] ?? null,
            ]);

            if ($started) {
                $pdo->commit();
            }
            $committedConfirmed = $newStatus === 'confirmed';
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \DomainException || $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \DomainException('Failed to update appointment status.');
        }
        if ($committedConfirmed) {
            try {
                $this->outboundTransactional->enqueueAppointmentConfirmation($id);
            } catch (\Throwable $e) {
                slog('error', 'appointments.outbound.confirmation_status', $e->getMessage(), ['appointment_id' => $id]);
            }
        }
        // WAVE-06: invalidate calendar display cache after any status change.
        try {
            $statusAptStartAt = (string) ($current['start_at'] ?? '');
            $statusDate = $statusAptStartAt !== '' ? date('Y-m-d', strtotime($statusAptStartAt)) : null;
            if ($statusDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $statusDate) === 1) {
                $this->availability->invalidateDayCalendarCache($statusDate, $currentBranchId);
            }
        } catch (\Throwable) {}
    }

    public function delete(int $id): void
    {
        $aptBefore = null;
        $this->transactional(function () use ($id, &$aptBefore): void {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $apt = $this->repo->loadVisible($ctx, $id);
            if (!$apt) {
                throw new \RuntimeException('Appointment not found');
            }
            $aptBefore = $apt;
            $aptBranchId = $apt['branch_id'] !== null && $apt['branch_id'] !== '' ? (int) $apt['branch_id'] : null;
            $this->repo->softDelete($id);
            $this->audit->log('appointment_deleted', 'appointment', $id, $this->currentUserId(), $apt['branch_id'] ?? null, [
                'appointment' => $apt,
            ]);
        }, 'appointment delete');
        if ($aptBefore !== null) {
            $this->invokeWaitlistAutoOfferAfterSlotFreed($aptBefore, $id);
        }
        // WAVE-06: invalidate calendar display cache after deletion.
        if ($aptBefore !== null) {
            try {
                $delStartAt = (string) ($aptBefore['start_at'] ?? '');
                $delDate = $delStartAt !== '' ? date('Y-m-d', strtotime($delStartAt)) : null;
                $delBranchId = isset($aptBefore['branch_id']) && $aptBefore['branch_id'] !== null && $aptBefore['branch_id'] !== '' ? (int) $aptBefore['branch_id'] : null;
                if ($delDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $delDate) === 1) {
                    $this->availability->invalidateDayCalendarCache($delDate, $delBranchId);
                }
            } catch (\Throwable) {}
        }
    }

    public function consumePackageSessions(
        int $appointmentId,
        int $clientPackageId,
        int $quantity,
        ?string $notes = null
    ): void {
        $appointment = $this->repo->find($appointmentId);
        if (!$appointment) {
            throw new \RuntimeException('Appointment not found.');
        }
        if (($appointment['status'] ?? '') !== 'completed') {
            throw new \DomainException('Only completed appointments can consume package sessions.');
        }
        if (in_array((string) ($appointment['status'] ?? ''), ['cancelled', 'no_show'], true)) {
            throw new \DomainException('Cancelled/no-show appointments cannot consume package sessions.');
        }
        if (empty($appointment['client_id'])) {
            throw new \DomainException('Appointment client is required for package consumption.');
        }

        $branchContext = $appointment['branch_id'] !== null ? (int) $appointment['branch_id'] : null;
        $this->packageConsumption->consumeForCompletedAppointment(
            $appointmentId,
            (int) $appointment['client_id'],
            $clientPackageId,
            $quantity,
            $branchContext,
            $notes
        );

        $this->audit->log('appointment_package_consumed', 'appointment', $appointmentId, $this->currentUserId(), $branchContext, [
            'client_id' => (int) $appointment['client_id'],
            'client_package_id' => $clientPackageId,
            'quantity' => $quantity,
            'notes' => $notes,
        ]);
    }

    /**
     * Internal check-in: records client arrival time. Does not change {@code status} (orthogonal to in_progress/completed).
     * Allowed when status is scheduled, confirmed, or in_progress; idempotent if {@code checked_in_at} is already set.
     */
    public function markCheckedIn(int $id): void
    {
        $this->transactional(function () use ($id): void {
            $ctx = $this->contextHolder->requireContext();
            $scope = $ctx->requireResolvedTenant();
            $current = $this->repo->loadForUpdate($ctx, $id);
            if (!$current) {
                throw new \RuntimeException('Appointment not found');
            }
            $currentBranchId = $current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null;
            if (!empty($current['checked_in_at'])) {
                return;
            }
            $status = (string) ($current['status'] ?? 'scheduled');
            if (!in_array($status, ['scheduled', 'confirmed', 'in_progress'], true)) {
                throw new \DomainException('Check-in is only available for scheduled, confirmed, or in-progress appointments.');
            }
            $ts = date('Y-m-d H:i:s');
            $actor = $this->currentUserId();
            $n = $this->repo->markCheckedIn($id, $ts, $actor, $actor);
            if ($n < 1) {
                throw new \RuntimeException('Appointment not found');
            }
            $after = $this->repo->find($id);
            $this->audit->log('appointment_checked_in', 'appointment', $id, $actor, $current['branch_id'] !== null ? (int) $current['branch_id'] : null, [
                'before' => $current,
                'after' => $after,
                'checked_in_at' => $ts,
            ]);
        }, 'appointment check-in', true);
    }

    public function getDisplaySummary(array $apt): string
    {
        $client = trim(($apt['client_first_name'] ?? '') . ' ' . ($apt['client_last_name'] ?? ''));
        $date = $this->formatAppointmentDisplayDateTime(isset($apt['start_at']) ? (string) $apt['start_at'] : null);

        return $client . ' @ ' . $date;
    }

    /**
     * Presentation-only: human-readable start/end strings for the admin appointment show page.
     * Does not read or alter stored `start_at` / `end_at` semantics; uses PHP default timezone (see ApplicationTimezone).
     *
     * @return array{display_start_at: string, display_end_at: string}
     */
    public function getShowDatetimeDisplay(array $apt): array
    {
        return [
            'display_start_at' => $this->formatAppointmentDisplayDateTime(isset($apt['start_at']) ? (string) $apt['start_at'] : null),
            'display_end_at' => $this->formatAppointmentDisplayDateTime(isset($apt['end_at']) ? (string) $apt['end_at'] : null),
        ];
    }

    /**
     * Presentation-only: compact date + time range for the admin appointment show header subtitle.
     * Raw `start_at` / `end_at` are not modified. On parse failure, falls back to trimmed raw strings.
     *
     * @return array{display_date_only: string, display_time_range: string}
     */
    public function getShowHeaderDatetimeDisplay(array $apt): array
    {
        $startRaw = trim((string) ($apt['start_at'] ?? ''));
        $endRaw = trim((string) ($apt['end_at'] ?? ''));

        return [
            'display_date_only' => $this->formatAppointmentHeaderDateOnly($startRaw),
            'display_time_range' => $this->formatAppointmentHeaderTimeRange($startRaw, $endRaw),
        ];
    }

    /**
     * Presentation-only label for admin appointment status display. Raw stored values are unchanged elsewhere.
     */
    public function formatStatusLabel(?string $status): string
    {
        $raw = trim((string) ($status ?? ''));
        if ($raw === '') {
            return '—';
        }

        return match ($raw) {
            'scheduled' => 'Scheduled',
            'confirmed' => 'Confirmed',
            'in_progress' => 'In progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'no_show' => 'No show',
            default => $raw,
        };
    }

    /**
     * Human-readable appointment datetime for list/show display. Service-local; no custom timezone override.
     * On parse failure, returns the trimmed non-empty raw string (never empty when input was non-empty).
     */
    public function formatAppointmentDisplayDateTime(?string $raw): string
    {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') {
            return '';
        }
        $normalized = str_replace('T', ' ', $raw);
        $ts = strtotime($normalized);
        if ($ts === false) {
            return $raw;
        }

        return date('l, F j, Y \a\t g:i A', $ts);
    }

    private function formatAppointmentHeaderDateOnly(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $normalized = str_replace('T', ' ', $raw);
        $ts = strtotime($normalized);
        if ($ts === false) {
            return $raw;
        }

        return date('l, F j, Y', $ts);
    }

    private function formatAppointmentHeaderTimeRange(string $startRaw, string $endRaw): string
    {
        if ($startRaw === '' && $endRaw === '') {
            return '';
        }
        $startTs = $startRaw !== '' ? strtotime(str_replace('T', ' ', $startRaw)) : false;
        $endTs = $endRaw !== '' ? strtotime(str_replace('T', ' ', $endRaw)) : false;

        if ($startTs !== false && $endTs !== false) {
            return date('g:i A', $startTs) . ' – ' . date('g:i A', $endTs);
        }
        if ($startTs !== false && $endRaw === '') {
            return date('g:i A', $startTs);
        }
        if ($startTs !== false && $endTs === false && $endRaw !== '') {
            return date('g:i A', $startTs) . ' – ' . $endRaw;
        }
        if ($startTs === false && $endTs !== false && $startRaw !== '') {
            return $startRaw . ' – ' . date('g:i A', $endTs);
        }
        if ($startRaw !== '' && $endRaw !== '') {
            return $startRaw . ' – ' . $endRaw;
        }

        return $startRaw !== '' ? $startRaw : $endRaw;
    }

    public function createFromSlot(array $data): int
    {
        $this->contextHolder->requireContext()->requireResolvedTenant();
        $data = $this->applyTenantCreateBranchResolution($data);
        $clientId = (int) ($data['client_id'] ?? 0);
        $serviceId = (int) ($data['service_id'] ?? 0);
        $staffId = (int) ($data['staff_id'] ?? 0);
        $startAt = trim((string) ($data['start_time'] ?? ''));
        if ($clientId <= 0 || $serviceId <= 0 || $staffId <= 0 || $startAt === '') {
            throw new \InvalidArgumentException('client_id, service_id, staff_id, start_time are required.');
        }
        $startAt = $this->normalizeDateTime($startAt);
        $notes = trim((string) ($data['notes'] ?? '')) ?: null;
        $extra = [];
        if ($this->nullablePositiveId($data, 'client_membership_id') !== null) {
            $extra['client_membership_id'] = (int) $data['client_membership_id'];
        }
        $internalSlotRoomId = $this->nullablePositiveId($data, 'room_id');

        return $this->insertNewSlotAppointmentWithLocks(
            $clientId,
            $serviceId,
            $staffId,
            $startAt,
            true,
            null,
            $data,
            $notes,
            $this->currentUserId(),
            $this->currentUserId(),
            'slot_booking_core',
            $this->currentUserId(),
            $extra,
            false,
            $internalSlotRoomId
        );
    }

    /**
     * Public online booking: same staff/service row locks + slot re-check + insert as createFromSlot.
     * Does not run validateTimes — caller must enforce online-booking window rules (min lead, max days).
     *
     * @param string $startAtNormalized Already `Y-m-d H:i:s`
     * @param ?int $clientMembershipId When set, persisted on the appointment and benefit usage recorded like internal slot booking
     */
    public function createFromPublicBooking(
        int $branchId,
        int $clientId,
        int $serviceId,
        int $staffId,
        string $startAtNormalized,
        ?string $notes,
        ?int $clientMembershipId = null
    ): int {
        if ($clientId <= 0 || $serviceId <= 0 || $staffId <= 0 || trim($startAtNormalized) === '') {
            throw new \InvalidArgumentException('branch_id, client_id, service_id, staff_id, and start_at are required.');
        }

        $extra = [];
        if ($clientMembershipId !== null && $clientMembershipId > 0) {
            $extra['client_membership_id'] = $clientMembershipId;
        }

        return $this->insertNewSlotAppointmentWithLocks(
            $clientId,
            $serviceId,
            $staffId,
            $startAtNormalized,
            false,
            $branchId,
            null,
            $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            null,
            null,
            'public_booking',
            null,
            $extra,
            true,
            null
        );
    }

    public function createFromSeriesOccurrence(
        int $seriesId,
        int $branchId,
        int $clientId,
        int $serviceId,
        int $staffId,
        string $startAtNormalized,
        ?string $notes
    ): int {
        if ($seriesId <= 0) {
            throw new \InvalidArgumentException('series_id is required.');
        }
        if ($clientId <= 0 || $serviceId <= 0 || $staffId <= 0 || trim($startAtNormalized) === '') {
            throw new \InvalidArgumentException('branch_id, client_id, service_id, staff_id, and start_at are required.');
        }

        return $this->insertNewSlotAppointmentWithLocks(
            $clientId,
            $serviceId,
            $staffId,
            $startAtNormalized,
            true,
            $branchId,
            null,
            $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            $this->currentUserId(),
            $this->currentUserId(),
            'series_booking',
            $this->currentUserId(),
            ['series_id' => $seriesId],
            false,
            null
        );
    }

    /**
     * Read-only window-policy validation for a reschedule candidate start time.
     * Reuses the same duration + validateTimes logic used by the reschedule mutation path.
     */
    public function passesRescheduleWindowPolicy(int $serviceId, ?int $branchId, string $startAt): bool
    {
        if ($serviceId <= 0) {
            return false;
        }

        $duration = $this->availability->getServiceDurationMinutes($serviceId, $branchId);
        if ($duration <= 0) {
            return false;
        }

        $endAt = date('Y-m-d H:i:s', strtotime($startAt) + ($duration * 60));

        try {
            $this->validateTimes([
                'start_at' => $startAt,
                'end_at' => $endAt,
                'branch_id' => $branchId,
            ]);
        } catch (\DomainException | \InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * Positive DB id or null (missing / zero treated as unset).
     */
    private function nullablePositiveId(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            return null;
        }
        $v = (int) $data[$key];

        return $v > 0 ? $v : null;
    }

    /**
     * Serialize concurrent booking checks on staff/service (BKM-003). Order: staff then service — matches slot pipeline.
     *
     * @throws \DomainException if id > 0 but row missing or inactive after lock
     */
    private function lockActiveStaffAndServiceRows(?int $staffId, ?int $serviceId): void
    {
        if ($staffId !== null && $staffId > 0) {
            $staffFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('st');
            $staffLock = $this->db->fetchOne(
                'SELECT st.id FROM staff st
                 WHERE st.id = ? AND st.deleted_at IS NULL AND st.is_active = 1' . $staffFrag['sql'] . '
                 FOR UPDATE',
                array_merge([$staffId], $staffFrag['params'])
            );
            if (!$staffLock) {
                throw new \DomainException('Selected staff is not active.');
            }
        }
        if ($serviceId !== null && $serviceId > 0) {
            $serviceFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('svc');
            $serviceRow = $this->db->fetchOne(
                'SELECT svc.id, svc.branch_id, svc.is_active, svc.deleted_at
                 FROM services svc
                 WHERE svc.id = ?' . $serviceFrag['sql'] . '
                 FOR UPDATE',
                array_merge([$serviceId], $serviceFrag['params'])
            );
            if (!$serviceRow || !empty($serviceRow['deleted_at']) || (int) ($serviceRow['is_active'] ?? 0) !== 1) {
                throw new \DomainException('Selected service is not active.');
            }
        }
    }

    /**
     * True when incoming PATCH (admin form) alters scheduling-related columns vs the locked row.
     * Used to route time-affecting edits through the locked service-duration move pipeline (BKM-004).
     */
    private function schedulingPatchDiffersFromCurrent(array $current, array $incoming): bool
    {
        foreach (['start_at', 'end_at', 'staff_id', 'room_id', 'service_id', 'branch_id'] as $k) {
            if (!array_key_exists($k, $incoming)) {
                continue;
            }
            if ($this->schedulingScalarDiffers($current[$k] ?? null, $incoming[$k], $k)) {
                return true;
            }
        }

        return false;
    }

    private function schedulingScalarDiffers(mixed $before, mixed $after, string $key): bool
    {
        if (in_array($key, ['staff_id', 'room_id', 'service_id', 'branch_id'], true)) {
            $b = ($before === null || $before === '') ? 0 : (int) $before;
            $a = ($after === null || $after === '') ? 0 : (int) $after;

            return $b !== $a;
        }

        return trim((string) $before) !== trim((string) $after);
    }

    /**
     * Authoritative locked move for service-based appointments (BKM-004).
     *
     * Precondition: caller holds `appointments` row `FOR UPDATE` for `$appointmentId` / `$current`.
     * Lock order after precondition: staff → service (via `lockActiveStaffAndServiceRows`), then availability reads.
     *
     * Room: when `room_id` is set, {@see lockRoomRowAssertCanonicallyFreeOrThrow} (canonical {@see AppointmentRepository::hasRoomConflict}).
     *
     * @param array{mode: 'preserve_column', conflict_room_id?: ?int}|array{mode: 'patch_from_merged', room_id?: ?int} $roomConfig
     * @param array{client_id: mixed, service_id: int, branch_id: ?int}|null $consentData When non-null, runs `assertRequiredConsents` after row locks (parity with slot insert pipeline).
     *
     * @return array{patch: array<string, mixed>, end_at: string, duration_minutes: int, cleared_series_id: ?int}
     */
    private function buildServiceBasedMovePatchAfterAppointmentLock(
        int $appointmentId,
        array $current,
        string $startAt,
        int $staffId,
        int $serviceId,
        ?int $branchId,
        array $roomConfig,
        ?array $consentData,
        bool $forPublicBookingAvailabilityChannel = false
    ): array {
        $currentStatus = (string) ($current['status'] ?? 'scheduled');
        if (in_array($currentStatus, self::TERMINAL_STATUSES, true)) {
            throw new \DomainException('Cannot reschedule or move appointment in status: ' . $currentStatus);
        }

        $duration = $this->availability->getServiceDurationMinutes($serviceId, $branchId);
        if ($duration <= 0) {
            throw new \DomainException('Service is not active or has invalid duration.');
        }
        $ctx = $this->contextHolder->requireContext();
        $scope = $ctx->requireResolvedTenant();
        if ($branchId === null || $branchId <= 0 || $branchId !== $scope['branch_id']) {
            throw new AccessDeniedException('Branch is outside tenant scope.');
        }
        $this->tenantScopeGuard->assertServiceInScope($serviceId, $branchId);
        $this->tenantScopeGuard->assertStaffInScope($staffId, $branchId);
        $endAt = date('Y-m-d H:i:s', strtotime($startAt) + ($duration * 60));

        $this->lockActiveStaffAndServiceRows($staffId, $serviceId);
        $this->assertStaffInGroupScope($staffId, $branchId);
        $this->assertStaffAllowedForServiceGroups($staffId, $serviceId, $branchId);

        $this->validateTimes(['start_at' => $startAt, 'end_at' => $endAt, 'branch_id' => $branchId]);
        $this->assertWithinBranchOperatingHours($startAt, $endAt, $branchId);

        if ($consentData !== null) {
            $this->assertRequiredConsents([
                'client_id' => $consentData['client_id'] ?? null,
                'service_id' => $consentData['service_id'],
                'branch_id' => $consentData['branch_id'] ?? null,
            ], $appointmentId, 'appointment_scheduling_move');
        }

        if (!$this->availability->isSlotAvailable($serviceId, $staffId, $startAt, $appointmentId, $branchId, false, $forPublicBookingAvailabilityChannel)) {
            throw new \DomainException('Selected slot is no longer available.');
        }

        $roomForOverlap = null;
        $patch = [
            'staff_id' => $staffId,
            'service_id' => $serviceId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'updated_by' => $this->currentUserId(),
        ];

        if (($roomConfig['mode'] ?? '') === 'preserve_column') {
            $roomForOverlap = isset($roomConfig['conflict_room_id']) && (int) $roomConfig['conflict_room_id'] > 0
                ? (int) $roomConfig['conflict_room_id']
                : null;
        } elseif (($roomConfig['mode'] ?? '') === 'patch_from_merged') {
            $roomForOverlap = isset($roomConfig['room_id']) && (int) $roomConfig['room_id'] > 0
                ? (int) $roomConfig['room_id']
                : null;
            $patch['room_id'] = $roomForOverlap;
        } else {
            throw new \InvalidArgumentException('Invalid room config for service move.');
        }

        if ($roomForOverlap !== null && $roomForOverlap > 0) {
            $this->tenantScopeGuard->assertRoomInScope($roomForOverlap, $branchId);
            $this->lockRoomRowAssertCanonicallyFreeOrThrow(
                $roomForOverlap,
                $startAt,
                $endAt,
                $branchId,
                $appointmentId,
                $forPublicBookingAvailabilityChannel
            );
        }

        $clearedSeriesId = null;
        $seriesIdRaw = $current['series_id'] ?? null;
        $seriesIdInt = ($seriesIdRaw !== null && $seriesIdRaw !== '') ? (int) $seriesIdRaw : 0;
        if ($seriesIdInt > 0) {
            $rawPrev = trim((string) ($current['start_at'] ?? ''));
            if ($rawPrev !== '') {
                $prevNorm = $this->normalizeDateTime(str_replace('T', ' ', $rawPrev));
                if ($prevNorm !== $startAt) {
                    $patch['series_id'] = null;
                    $clearedSeriesId = $seriesIdInt;
                }
            }
        }

        return [
            'patch' => $patch,
            'end_at' => $endAt,
            'duration_minutes' => $duration,
            'cleared_series_id' => $clearedSeriesId,
        ];
    }

    /**
     * Shared pipeline: transaction (if needed), staff FOR UPDATE, service FOR UPDATE, optional validateTimes,
     * consent, isSlotAvailable, insert, audit. Matches internal slot booking concurrency contract (BKM-001/002).
     *
     * @param array<string, mixed>|null $branchResolutionData When $explicitBranchId is null, branch_id is resolved like createFromSlot from this array + locked service row.
     * @param ?int $internalSlotOptionalRoomId When non-null and {@code $forPublicBookingAvailabilityChannel} is false (internal slot booking only),
     *        persists {@code room_id} after {@see TenantOwnedDataScopeGuard::assertRoomInScope} and {@see lockRoomRowAssertCanonicallyFreeOrThrow}.
     *        Ignored for public booking channel.
     */
    private function insertNewSlotAppointmentWithLocks(
        int $clientId,
        int $serviceId,
        int $staffId,
        string $startAt,
        bool $runValidateTimes,
        ?int $explicitBranchId,
        ?array $branchResolutionData,
        ?string $notes,
        ?int $createdBy,
        ?int $updatedBy,
        string $auditSource,
        ?int $auditActorUserId,
        array $extraCreateFields = [],
        bool $forPublicBookingAvailabilityChannel = false,
        ?int $internalSlotOptionalRoomId = null,
    ): int {
        $durationScope = $explicitBranchId;
        if (($durationScope === null || $durationScope <= 0) && $branchResolutionData !== null && array_key_exists('branch_id', $branchResolutionData)) {
            $candidate = $branchResolutionData['branch_id'];
            if ($candidate !== null && $candidate !== '' && (int) $candidate > 0) {
                $durationScope = (int) $candidate;
            }
        }
        if ($durationScope === null || $durationScope <= 0) {
            $ctx0 = $this->contextHolder->requireContext();
            $s0 = $ctx0->requireResolvedTenant();
            $durationScope = (int) ($s0['branch_id'] ?? 0);
        }
        $duration = $this->availability->getServiceDurationMinutes($serviceId, $durationScope);
        if ($duration <= 0) {
            throw new \DomainException('Service is not active or has invalid duration.');
        }
        $endAt = date('Y-m-d H:i:s', strtotime($startAt) + ($duration * 60));

        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
                $started = true;
            }

            $this->lockActiveStaffAndServiceRows($staffId, $serviceId);

            $serviceFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('svc');
            $serviceRow = $this->db->fetchOne(
                'SELECT svc.id, svc.branch_id, svc.is_active, svc.deleted_at
                 FROM services svc
                 WHERE svc.id = ?' . $serviceFrag['sql'],
                array_merge([$serviceId], $serviceFrag['params'])
            );

            if ($explicitBranchId !== null) {
                $branchId = $explicitBranchId;
            } elseif ($branchResolutionData !== null) {
                $branchId = array_key_exists('branch_id', $branchResolutionData)
                    ? ($branchResolutionData['branch_id'] !== null && $branchResolutionData['branch_id'] !== ''
                        ? (int) $branchResolutionData['branch_id']
                        : null)
                    : ($serviceRow['branch_id'] !== null ? (int) $serviceRow['branch_id'] : null);
            } else {
                $branchId = $serviceRow['branch_id'] !== null ? (int) $serviceRow['branch_id'] : null;
            }

            $scope = $this->contextHolder->requireContext()->requireResolvedTenant();
            if ($branchId === null || $branchId <= 0) {
                throw new AccessDeniedException('Branch is outside tenant scope.');
            }
            if (!$forPublicBookingAvailabilityChannel) {
                $this->assertInternalSlotBookingBranchAllowedForPrincipal((int) $branchId);
            } elseif ((int) $branchId !== (int) $scope['branch_id']) {
                throw new AccessDeniedException('Branch is outside tenant scope.');
            }
            $this->tenantScopeGuard->assertClientInScope($clientId, $branchId);
            $this->tenantScopeGuard->assertServiceInScope($serviceId, $branchId);
            $this->tenantScopeGuard->assertStaffInScope($staffId, $branchId);

            $this->assertStaffInGroupScope($staffId, $branchId);
            $this->assertStaffAllowedForServiceGroups($staffId, $serviceId, $branchId);

            $bookingBranch = $branchId;
            if ($bookingBranch !== null && $bookingBranch <= 0) {
                $bookingBranch = null;
            }
            $this->assertNoBlockingRequiredIntakeForNewBooking($clientId, $bookingBranch, $auditSource, $auditActorUserId);

            if ($runValidateTimes) {
                $this->validateTimes(['start_at' => $startAt, 'end_at' => $endAt, 'branch_id' => $branchId]);
            }
            $this->assertWithinBranchOperatingHours($startAt, $endAt, $branchId);

            $this->assertRequiredConsents([
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'branch_id' => $branchId,
            ]);

            if (!$this->availability->isSlotAvailable($serviceId, $staffId, $startAt, null, $branchId, false, $forPublicBookingAvailabilityChannel)) {
                throw new \DomainException('Selected slot is no longer available.');
            }

            $roomToPersist = null;
            if (
                !$forPublicBookingAvailabilityChannel
                && $internalSlotOptionalRoomId !== null
                && $internalSlotOptionalRoomId > 0
            ) {
                $roomToPersist = $internalSlotOptionalRoomId;
                $this->tenantScopeGuard->assertRoomInScope($roomToPersist, $branchId);
                $this->lockRoomRowAssertCanonicallyFreeOrThrow(
                    $roomToPersist,
                    $startAt,
                    $endAt,
                    $branchId,
                    0,
                    $forPublicBookingAvailabilityChannel
                );
            }

            $create = [
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'staff_id' => $staffId,
                'room_id' => $roomToPersist,
                'branch_id' => $branchId,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => 'scheduled',
                'notes' => $notes,
                'created_by' => $createdBy,
                'updated_by' => $updatedBy,
            ];
            if (!empty($extraCreateFields)) {
                $create = array_merge($create, $extraCreateFields);
            }
            $id = $this->repo->create($create);

            $membershipOpt = $this->nullablePositiveId($create, 'client_membership_id');
            if ($membershipOpt !== null) {
                $this->membershipService->consumeBenefitForAppointment(
                    $membershipOpt,
                    $id,
                    $clientId,
                    $branchId,
                    $startAt
                );
            }

            $this->audit->log('appointment_created', 'appointment', $id, $auditActorUserId, $branchId, [
                'appointment' => $create,
                'source' => $auditSource,
            ], 'success', 'booking');
            \slog('info', 'critical_path.booking', 'appointment_created', [
                'appointment_id' => $id,
                'branch_id' => $branchId,
                'source' => $auditSource,
            ]);

            if ($started) {
                $pdo->commit();
            }
            // WAVE-06: invalidate calendar display cache after successful slot appointment creation.
            try {
                $slotDate = date('Y-m-d', strtotime($startAt));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $slotDate) === 1) {
                    $this->availability->invalidateDayCalendarCache($slotDate, $branchId > 0 ? $branchId : null);
                }
            } catch (\Throwable) {}
            try {
                $this->outboundTransactional->enqueueAppointmentConfirmation($id);
            } catch (\Throwable $e) {
                slog('error', 'appointments.outbound.confirmation_slot', $e->getMessage(), [
                    'appointment_id' => $id,
                    'branch_id' => $branchId,
                ]);
            }

            return $id;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \DomainException('Failed to create appointment from slot.');
        }
    }

    /**
     * Write-time room serialization + canonical overlap ({@see AppointmentRepository::hasRoomConflict}).
     * Caller must have enforced room in scope via {@see assertActiveEntities} / move pipeline when applicable.
     * When {@code $forPublicBookingAvailabilityChannel} is true, room exclusivity is always enforced regardless of
     * {@code appointments.allow_room_overbooking}.
     */
    private function lockRoomRowAssertCanonicallyFreeOrThrow(
        int $roomId,
        string $startAt,
        string $endAt,
        int $branchId,
        int $excludeAppointmentId,
        bool $forPublicBookingAvailabilityChannel = false,
    ): void {
        $enforceRoom = $forPublicBookingAvailabilityChannel
            || $this->settings->shouldEnforceAppointmentRoomExclusivity($branchId);
        if (!$enforceRoom) {
            return;
        }
        $this->repo->lockRoomRowForConflictCheck($roomId);
        if ($this->repo->hasRoomConflict($roomId, $startAt, $endAt, $branchId, $excludeAppointmentId)) {
            throw new \DomainException('Room is booked for another appointment at this time.');
        }
    }

    /**
     * @return string[] Conflict messages
     */
    private function checkConflicts(array $data, int $excludeId): array
    {
        $start = $data['start_at'] ?? null;
        $end = $data['end_at'] ?? null;
        $staffId = isset($data['staff_id']) && $data['staff_id'] !== null ? (int) $data['staff_id'] : null;
        $roomId = $this->nullablePositiveId($data, 'room_id');
        $branchId = isset($data['branch_id']) && $data['branch_id'] !== null ? (int) $data['branch_id'] : null;
        $serviceId = isset($data['service_id']) && $data['service_id'] !== null ? (int) $data['service_id'] : null;
        $conflicts = [];
        $enforceRoomExclusivity = $this->settings->shouldEnforceAppointmentRoomExclusivity($branchId);
        if ($roomId !== null && $enforceRoomExclusivity) {
            $this->repo->lockRoomRowForConflictCheck($roomId);
        }
        if ($staffId !== null && $start && $end) {
            $bufferBefore = 0;
            $bufferAfter = 0;
            if ($serviceId !== null && $serviceId > 0) {
                $timing = $this->availability->getServiceTiming($serviceId, $branchId);
                if ($timing !== null) {
                    $bufferBefore = $timing['buffer_before_minutes'];
                    $bufferAfter = $timing['buffer_after_minutes'];
                }
            }
            if (!$this->availability->isStaffWindowAvailable($staffId, (string) $start, (string) $end, $branchId, $excludeId, $bufferBefore, $bufferAfter, true, false)) {
                $conflicts[] = 'Staff time is unavailable (overlap, blocked, or outside schedule).';
            }
        }
        if (
            $roomId !== null
            && $start
            && $end
            && $enforceRoomExclusivity
            && $this->repo->hasRoomConflict($roomId, (string) $start, (string) $end, $branchId, $excludeId)
        ) {
            $conflicts[] = 'Room is booked for another appointment at this time.';
        }
        return $conflicts;
    }

    private function validateTimes(array $data): void
    {
        $start = $data['start_at'] ?? null;
        $end = $data['end_at'] ?? null;
        if (!$start || !$end) {
            throw new \InvalidArgumentException('Start and end time are required.');
        }
        if (strtotime($end) <= strtotime($start)) {
            throw new \InvalidArgumentException('End time must be after start time.');
        }
        $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null ? (int) $data['branch_id'] : null;
        $aptSettings = $this->settings->getAppointmentSettings($branchId);
        $now = time();
        $startTs = strtotime($start);
        $startDate = date('Y-m-d', $startTs);
        $today = date('Y-m-d', $now);
        if (!$aptSettings['allow_past_booking'] && $startTs < $now) {
            throw new \DomainException('Booking in the past is not allowed.');
        }
        $minLeadSeconds = $aptSettings['min_lead_minutes'] * 60;
        if ($startTs < $now + $minLeadSeconds) {
            throw new \DomainException('Booking must be at least ' . $aptSettings['min_lead_minutes'] . ' minute(s) in advance.');
        }
        $maxDate = date('Y-m-d', strtotime($today . ' + ' . $aptSettings['max_days_ahead'] . ' days'));
        if ($startDate > $maxDate) {
            throw new \DomainException('Booking cannot be more than ' . $aptSettings['max_days_ahead'] . ' days ahead.');
        }
    }

    private function assertWithinBranchOperatingHours(?string $startAt, ?string $endAt, ?int $branchId): void
    {
        $startAt = trim((string) ($startAt ?? ''));
        $endAt = trim((string) ($endAt ?? ''));
        if ($startAt === '' || $endAt === '' || $branchId === null || $branchId <= 0) {
            return;
        }
        $date = substr($startAt, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        $meta = $this->branchOperatingHours->getDayHoursMeta($branchId, $date);
        if (($meta['branch_hours_available'] ?? false) !== true || ($meta['is_configured_day'] ?? false) !== true) {
            throw new \DomainException('Opening hours are not configured for this branch on the selected day.');
        }
        if (($meta['is_closed_day'] ?? false) === true) {
            throw new \DomainException('This branch is closed on the selected day.');
        }

        $open = isset($meta['open_time']) ? trim((string) $meta['open_time']) : '';
        $close = isset($meta['close_time']) ? trim((string) $meta['close_time']) : '';
        if ($open === '' || $close === '') {
            throw new \DomainException('Opening hours are not configured for this branch on the selected day.');
        }

        $startHm = substr($startAt, 11, 5);
        $endHm = substr($endAt, 11, 5);
        if (strlen($startHm) !== 5 || strlen($endHm) !== 5) {
            return;
        }

        $apt = $this->settings->getAppointmentSettings($branchId);
        $allowEndAfterClosing = !empty($apt['allow_end_after_closing']);
        $beforeOpen = strcmp($startHm, $open) < 0;
        $afterClose = strcmp($endHm, $close) > 0;
        if ($beforeOpen || ($afterClose && !$allowEndAfterClosing)) {
            throw new \DomainException(
                'The selected time falls outside this branch\'s operating hours (' . $open . '-' . $close . ').'
            );
        }
    }

    /**
     * Document consents required for the service (see ConsentService). When client_id and service_id are both set,
     * enforcement runs; otherwise this is a no-op (e.g. appointments without a service have no service-linked consent rules).
     *
     * @param ?int $auditAppointmentId When consent fails and this is a positive id, an audit row is written before throw.
     * @param ?string $auditDenialSource Stable token for metadata (e.g. appointment_scheduling_move).
     */
    private function assertRequiredConsents(array $data, ?int $auditAppointmentId = null, ?string $auditDenialSource = null): void
    {
        $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
        $serviceId = isset($data['service_id']) && $data['service_id'] !== '' ? (int) $data['service_id'] : null;
        $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null ? (int) $data['branch_id'] : null;
        if ($clientId === null || $clientId <= 0 || $serviceId === null || $serviceId <= 0) {
            return;
        }
        $check = $this->consentService->checkClientConsentsForService($clientId, $serviceId, $branchId);
        if ($check['ok']) {
            return;
        }
        if ($auditAppointmentId !== null && $auditAppointmentId > 0) {
            $this->audit->log('appointment_consent_policy_denied', 'appointment', $auditAppointmentId, $this->currentUserId(), $branchId, [
                'source' => $auditDenialSource ?? 'unknown',
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'missing' => array_map(static fn (array $m): string => (string) ($m['code'] ?? ''), $check['missing']),
                'expired' => array_map(static fn (array $e): string => (string) ($e['code'] ?? ''), $check['expired']),
            ]);
        }
        $parts = [];
        foreach ($check['missing'] as $m) {
            $parts[] = $m['name'] . ' (missing)';
        }
        foreach ($check['expired'] as $e) {
            $parts[] = $e['name'] . ' (expired ' . ($e['expires_at'] ?? '') . ')';
        }
        throw new \DomainException('Required consent missing or expired: ' . implode('; ', $parts));
    }

    private function validateStatus(string $status): void
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid status.');
        }
    }

    private function assertStatusTransition(string $from, string $to): void
    {
        $allowed = self::STATUS_TRANSITIONS[$from] ?? [];
        if ($from === $to) {
            return;
        }
        if (!in_array($to, $allowed, true)) {
            throw new \DomainException('Invalid status transition: ' . $from . ' -> ' . $to);
        }
    }

    private function assertStaffInGroupScope(?int $staffId, $branchId): void
    {
        if ($staffId === null || $staffId <= 0) {
            return;
        }
        $resolvedBranchId = $branchId !== null && $branchId !== '' ? (int) $branchId : null;
        if (!$this->staffGroupService->isStaffInScopeForBranch($staffId, $resolvedBranchId)) {
            throw new \DomainException('Selected staff is not in scope for this branch.');
        }
    }

    private function assertStaffAllowedForServiceGroups(?int $staffId, ?int $serviceId, $branchId): void
    {
        if ($staffId === null || $staffId <= 0 || $serviceId === null || $serviceId <= 0) {
            return;
        }
        $resolvedBranchId = $branchId !== null && $branchId !== '' ? (int) $branchId : null;
        $this->serviceStaffGroupEligibility->assertStaffAllowedForService($staffId, $serviceId, $resolvedBranchId);
    }

    private function assertActiveEntities(array $data): void
    {
        $ctx = $this->contextHolder->requireContext();
        $scope = $ctx->requireResolvedTenant();
        $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null ? (int) $data['branch_id'] : $scope['branch_id'];
        if ($branchId <= 0 || $branchId !== $scope['branch_id']) {
            throw new AccessDeniedException('Branch is outside tenant scope.');
        }
        if (!empty($data['client_id'])) {
            $this->tenantScopeGuard->assertClientInScope((int) $data['client_id'], $branchId);
        }
        if (!empty($data['service_id'])) {
            $this->tenantScopeGuard->assertServiceInScope((int) $data['service_id'], $branchId);
            $serviceFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('svc');
            $service = $this->db->fetchOne(
                'SELECT svc.id FROM services svc
                 WHERE svc.id = ? AND svc.deleted_at IS NULL AND svc.is_active = 1' . $serviceFrag['sql'],
                array_merge([(int) $data['service_id']], $serviceFrag['params'])
            );
            if (!$service) {
                throw new \DomainException('Selected service is not active.');
            }
        }
        if (!empty($data['staff_id'])) {
            $this->tenantScopeGuard->assertStaffInScope((int) $data['staff_id'], $branchId);
            $staffFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('st');
            $staff = $this->db->fetchOne(
                'SELECT st.id FROM staff st
                 WHERE st.id = ? AND st.deleted_at IS NULL AND st.is_active = 1' . $staffFrag['sql'],
                array_merge([(int) $data['staff_id']], $staffFrag['params'])
            );
            if (!$staff) {
                throw new \DomainException('Selected staff is not active.');
            }
        }
        if (!empty($data['room_id'])) {
            $this->tenantScopeGuard->assertRoomInScope((int) $data['room_id'], $branchId);
        }
    }

    /**
     * After a real slot is vacated (cancel/delete/reschedule/admin update that changes time/staff/service/room),
     * offer the freed window to the waitlist when settings allow.
     */
    private function invokeWaitlistAutoOfferAfterSlotFreed(array $appointmentRowBeforeChange, int $appointmentId): void
    {
        try {
            Application::container()->get(\Modules\Appointments\Services\WaitlistService::class)
                ->onAppointmentSlotFreed($appointmentRowBeforeChange, $appointmentId);
        } catch (\Throwable $e) {
            $bid = isset($appointmentRowBeforeChange['branch_id']) && $appointmentRowBeforeChange['branch_id'] !== '' && $appointmentRowBeforeChange['branch_id'] !== null
                ? (int) $appointmentRowBeforeChange['branch_id']
                : null;
            slog('error', 'appointments.waitlist_auto_offer_hook', $e->getMessage(), [
                'appointment_id' => $appointmentId,
                'branch_id' => $bid,
            ]);
        }
    }

    private function normalizeDateTime(string $input): string
    {
        $value = trim($input);
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?$/', $value) === 1) {
            return strlen($value) === 16 ? $value . ':00' : $value;
        }
        throw new \InvalidArgumentException('start_time must be YYYY-MM-DD HH:MM format.');
    }

    private function assertNoBlockingRequiredIntakeForNewBooking(
        int $clientId,
        ?int $bookingBranchId,
        string $auditSource,
        ?int $auditActorUserId
    ): void {
        if ($this->intakeForms->countBlockingRequiredPendingAssignmentsForNewBooking($clientId, $bookingBranchId) <= 0) {
            return;
        }
        $this->audit->log('appointment_create_denied_required_intake', 'appointment', null, $auditActorUserId, $bookingBranchId, [
            'source' => $auditSource,
            'client_id' => $clientId,
            'booking_branch_id' => $bookingBranchId,
        ]);
        throw new \DomainException('Required intake forms must be completed before booking this appointment.');
    }

    /**
     * C-002: internal slot/create branch must match org context and tenant principal allow-list (not only session pin).
     *
     * @throws AccessDeniedException when branch is outside org assert or principal allow-list
     * @throws \DomainException for invalid branch id or unauthenticated user
     */
    private function assertInternalSlotBookingBranchAllowedForPrincipal(int $bookingBranchId): void
    {
        if ($bookingBranchId <= 0) {
            throw new \DomainException('Invalid branch for appointment booking.');
        }
        $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($bookingBranchId);
        $userId = $this->currentUserId();
        if ($userId === null || $userId <= 0) {
            throw new \DomainException('Authentication required.');
        }
        $allowed = $this->tenantBranchAccess->allowedBranchIdsForUser($userId);
        if (!in_array($bookingBranchId, $allowed, true)) {
            throw new AccessDeniedException('Branch is not allowed for this principal.');
        }
    }

    /**
     * C-002: same validated branch semantics as appointment read APIs — explicit branch_id or active session branch.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function applyTenantCreateBranchResolution(array $data): array
    {
        $ctx = $this->contextHolder->requireContext();
        ['branch_id' => $contextBranch] = $ctx->requireResolvedTenant();

        $explicit = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
            ? (int) $data['branch_id']
            : null;
        if ($explicit !== null && $explicit > 0) {
            $this->assertInternalSlotBookingBranchAllowedForPrincipal($explicit);
            $data['branch_id'] = $explicit;

            return $data;
        }

        if ($contextBranch === null || $contextBranch <= 0) {
            throw new \DomainException('branch_id is required when no active session branch is selected.');
        }
        $this->assertInternalSlotBookingBranchAllowedForPrincipal($contextBranch);
        $data['branch_id'] = $contextBranch;

        return $data;
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function transactional(callable $callback, string $action, bool $readCommittedNext = false): mixed
    {
        $pdo = $this->db->connection();
        $startedTransaction = false;
        try {
            if (!$pdo->inTransaction()) {
                if ($readCommittedNext) {
                    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                }
                $pdo->beginTransaction();
                $startedTransaction = true;
            }
            $result = $callback();
            if ($startedTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'appointments.service_transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \DomainException('Appointment operation failed.');
        }
    }
}
