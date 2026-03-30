<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Core\App\Application;
use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Appointments\Repositories\WaitlistRepository;
use Modules\Notifications\Services\NotificationService;
use Modules\Notifications\Services\OutboundTransactionalNotificationService;

/**
 * Waitlist lifecycle: `offered` means an offer window exists (timestamps), not that the client was reached.
 * Outbound email is queued separately; see {@see OutboundTransactionalNotificationService::enqueueWaitlistOffer} and audit `waitlist_offer_outreach_*`.
 * When the offer ends (expiry, status change, link, convert), still-queued customer emails for that entry are suppressed so dispatch cannot send after state says the offer is gone ({@see suppressStaleWaitlistOfferOutbound}).
 */
final class WaitlistService
{
    /** MySQL GET_LOCK name (max 64 chars); global — all sweep entry points share one engine. */
    private const EXPIRY_SWEEP_MYSQL_LOCK = 'spa_waitlist_expiry_sweep';

    /** Prefix for per–slot-context advisory locks (name must stay under MySQL's 64-char GET_LOCK limit). */
    private const SLOT_AUTO_OFFER_LOCK_PREFIX = 'wl_slot_offer_';

    private const VALID_STATUSES = ['waiting', 'offered', 'matched', 'booked', 'cancelled'];
    private const STATUS_TRANSITIONS = [
        'waiting' => ['offered', 'matched', 'booked', 'cancelled'],
        'offered' => ['waiting', 'matched', 'booked', 'cancelled'],
        'matched' => ['booked', 'cancelled', 'waiting'],
        'booked' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private WaitlistRepository $repo,
        private AppointmentRepository $appointmentRepo,
        private AppointmentService $appointments,
        private Database $db,
        private AuditService $audit,
        private BranchContext $branchContext,
        private SettingsService $settings,
        private NotificationService $notifications,
        private OutboundTransactionalNotificationService $outboundTransactional,
        private AvailabilityService $availability
    ) {
    }

    public function create(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null;
            $waitlistSettings = $this->settings->getWaitlistSettings($branchId);
            if (!$waitlistSettings['enabled']) {
                throw new \DomainException('Waitlist is disabled for this branch.');
            }
            $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null;
            if ($clientId !== null) {
                $activeCount = $this->repo->countActiveByClient($clientId, $branchId);
                if ($activeCount >= $waitlistSettings['max_active_per_client']) {
                    throw new \DomainException('Maximum active waitlist entries per client (' . $waitlistSettings['max_active_per_client'] . ') reached.');
                }
            }
            $preferredDate = trim((string) ($data['preferred_date'] ?? ''));
            if ($preferredDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $preferredDate) !== 1) {
                throw new \InvalidArgumentException('preferred_date must be YYYY-MM-DD.');
            }
            $status = trim((string) ($data['status'] ?? 'waiting'));
            if ($status === 'offered') {
                throw new \InvalidArgumentException('Invalid waitlist status.');
            }
            if (!in_array($status, self::VALID_STATUSES, true)) {
                throw new \InvalidArgumentException('Invalid waitlist status.');
            }
            $from = trim((string) ($data['preferred_time_from'] ?? ''));
            $to = trim((string) ($data['preferred_time_to'] ?? ''));
            if ($from !== '' && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $from) !== 1) {
                throw new \InvalidArgumentException('preferred_time_from must be HH:MM.');
            }
            if ($to !== '' && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $to) !== 1) {
                throw new \InvalidArgumentException('preferred_time_to must be HH:MM.');
            }
            $from = $from !== '' ? (strlen($from) === 5 ? $from . ':00' : $from) : null;
            $to = $to !== '' ? (strlen($to) === 5 ? $to . ':00' : $to) : null;
            if ($from !== null && $to !== null && strcmp($to, $from) <= 0) {
                throw new \InvalidArgumentException('preferred_time_to must be after preferred_time_from.');
            }

            $payload = [
                'branch_id' => $data['branch_id'] ?? null,
                'client_id' => isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : null,
                'service_id' => isset($data['service_id']) && $data['service_id'] !== '' ? (int) $data['service_id'] : null,
                'preferred_staff_id' => isset($data['preferred_staff_id']) && $data['preferred_staff_id'] !== '' ? (int) $data['preferred_staff_id'] : null,
                'preferred_date' => $preferredDate,
                'preferred_time_from' => $from,
                'preferred_time_to' => $to,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'status' => $status,
                'created_by' => $this->currentUserId(),
                'matched_appointment_id' => null,
            ];

            $id = $this->repo->create($payload);
            $this->audit->log('appointment_waitlist_created', 'appointment_waitlist', $id, $this->currentUserId(), $payload['branch_id'], [
                'waitlist' => $payload,
            ]);
            return $id;
        }, 'waitlist create');
    }

    public function updateStatus(int $id, string $nextStatus, ?string $notes = null): void
    {
        $offeredAfterUpdate = false;
        $this->transactional(function () use ($id, $nextStatus, $notes, &$offeredAfterUpdate): void {
            $row = $this->repo->find($id);
            if (!$row) {
                throw new \RuntimeException('Waitlist entry not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null);
            $entryBranchId = $row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null;
            $from = (string) ($row['status'] ?? 'waiting');
            $to = trim($nextStatus);
            if (!in_array($to, self::VALID_STATUSES, true)) {
                throw new \InvalidArgumentException('Invalid waitlist status.');
            }
            if ($from === $to) {
                return;
            }
            if (!$this->settings->getWaitlistSettings($entryBranchId)['enabled'] && $to !== 'cancelled') {
                throw new \DomainException('Waitlist is disabled for this branch.');
            }
            $allowed = self::STATUS_TRANSITIONS[$from] ?? [];
            if (!in_array($to, $allowed, true)) {
                throw new \DomainException('Invalid waitlist status transition: ' . $from . ' -> ' . $to);
            }

            $patch = ['status' => $to];
            if ($to === 'waiting') {
                $patch['matched_appointment_id'] = null;
            }
            if ($to === 'offered') {
                $entryBranchId = $row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null;
                $patch = array_merge($patch, $this->offerTimestampsForBranch($entryBranchId));
            }
            if ($from === 'offered' && $to !== 'offered') {
                $this->suppressStaleWaitlistOfferOutbound($id, $entryBranchId, 'status_change');
                $patch['offer_started_at'] = null;
                $patch['offer_expires_at'] = null;
            }
            if ($notes !== null && $notes !== '') {
                $existingNotes = trim((string) ($row['notes'] ?? ''));
                $patch['notes'] = trim($existingNotes . "\n" . '[status:' . $to . '] ' . $notes);
            }
            $this->repo->update($id, $patch);

            $this->audit->log('appointment_waitlist_status_changed', 'appointment_waitlist', $id, $this->currentUserId(), $row['branch_id'] !== null ? (int) $row['branch_id'] : null, [
                'before_status' => $from,
                'after_status' => $to,
                'notes' => $notes,
            ]);
            $offeredAfterUpdate = $to === 'offered';
        }, 'waitlist status update');
        if ($offeredAfterUpdate) {
            $fresh = $this->repo->find($id);
            if ($fresh !== null && (string) ($fresh['status'] ?? '') === 'offered') {
                $started = trim((string) ($fresh['offer_started_at'] ?? ''));
                $exp = $fresh['offer_expires_at'] ?? null;
                $expStr = $exp !== null && $exp !== '' ? (string) $exp : null;
                $this->enqueueWaitlistOfferOutreach(
                    $id,
                    $fresh,
                    $started !== '' ? $started : date('Y-m-d H:i:s'),
                    $expStr,
                    'manual_status_offered'
                );
            }
        }
    }

    public function linkToAppointment(int $id, int $appointmentId): void
    {
        $this->transactional(function () use ($id, $appointmentId): void {
            $row = $this->repo->find($id);
            if (!$row) {
                throw new \RuntimeException('Waitlist entry not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null);
            $entryBranchId = $row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null;
            if (!$this->settings->getWaitlistSettings($entryBranchId)['enabled']) {
                throw new \DomainException('Waitlist is disabled for this branch.');
            }
            if ((string) ($row['status'] ?? '') === 'cancelled') {
                throw new \DomainException('Cannot link a cancelled waitlist entry.');
            }
            $appointment = $this->appointmentRepo->find($appointmentId);
            if (!$appointment) {
                throw new \RuntimeException('Appointment not found for linking.');
            }

            $rowBranchId = $this->normalizeBranchId($row['branch_id'] ?? null);
            $appointmentBranchId = $this->normalizeBranchId($appointment['branch_id'] ?? null);
            if ($appointmentBranchId !== $rowBranchId) {
                throw new \DomainException('Cannot link waitlist entry to an appointment in a different branch.');
            }

            if ((string) ($row['status'] ?? '') === 'offered') {
                $this->suppressStaleWaitlistOfferOutbound($id, $entryBranchId, 'linked');
            }
            $patch = [
                'matched_appointment_id' => $appointmentId,
                'status' => (string) ($row['status'] ?? 'waiting') === 'booked' ? 'booked' : 'matched',
                'offer_started_at' => null,
                'offer_expires_at' => null,
            ];
            $this->repo->update($id, $patch);

            $this->audit->log('appointment_waitlist_linked', 'appointment_waitlist', $id, $this->currentUserId(), $row['branch_id'] !== null ? (int) $row['branch_id'] : null, [
                'matched_appointment_id' => $appointmentId,
            ]);
        }, 'waitlist link appointment');
    }

    public function convertToAppointment(int $id, array $data): int
    {
        return $this->transactional(function () use ($id, $data): int {
            $row = $this->repo->find($id);
            if (!$row) {
                throw new \RuntimeException('Waitlist entry not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null);
            $status = (string) ($row['status'] ?? 'waiting');
            if (!in_array($status, ['waiting', 'offered', 'matched'], true)) {
                throw new \DomainException('Only waiting, offered, or matched waitlist entries can be converted.');
            }

            $clientId = isset($data['client_id']) && $data['client_id'] !== '' ? (int) $data['client_id'] : (int) ($row['client_id'] ?? 0);
            $serviceId = isset($data['service_id']) && $data['service_id'] !== '' ? (int) $data['service_id'] : (int) ($row['service_id'] ?? 0);
            $staffId = isset($data['staff_id']) && $data['staff_id'] !== '' ? (int) $data['staff_id'] : (int) ($row['preferred_staff_id'] ?? 0);
            $startTimeRaw = trim((string) ($data['start_time'] ?? ''));
            if ($startTimeRaw === '') {
                $prefDate = (string) ($row['preferred_date'] ?? '');
                $prefTime = (string) ($row['preferred_time_from'] ?? '');
                if ($prefDate !== '' && $prefTime !== '') {
                    $startTimeRaw = $prefDate . ' ' . substr($prefTime, 0, 5);
                }
            }
            if ($clientId <= 0 || $serviceId <= 0 || $staffId <= 0 || $startTimeRaw === '') {
                throw new \InvalidArgumentException('client, service, staff, and start_time are required for conversion.');
            }

            $rowBranchId = $this->normalizeBranchId($row['branch_id'] ?? null);
            if (array_key_exists('branch_id', $data)) {
                $rawInputBranch = $data['branch_id'];
                if ($rawInputBranch !== null && $rawInputBranch !== '') {
                    $inputBranchId = $this->normalizeBranchId($rawInputBranch);
                    if ($inputBranchId !== $rowBranchId) {
                        throw new \DomainException('branch_id does not match this waitlist entry.');
                    }
                }
            }
            $branchId = $rowBranchId;
            if (!$this->settings->getWaitlistSettings($branchId)['enabled']) {
                throw new \DomainException('Waitlist is disabled for this branch.');
            }
            $notesParts = [];
            if (!empty($row['notes'])) {
                $notesParts[] = '[waitlist] ' . trim((string) $row['notes']);
            }
            if (!empty($data['notes'])) {
                $notesParts[] = trim((string) $data['notes']);
            }
            $notes = $notesParts !== [] ? implode("\n", $notesParts) : null;

            $appointmentId = $this->appointments->createFromSlot([
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'staff_id' => $staffId,
                'start_time' => $startTimeRaw,
                'branch_id' => $branchId,
                'notes' => $notes,
            ]);

            if ($status === 'offered') {
                $this->suppressStaleWaitlistOfferOutbound($id, $branchId, 'converted');
            }

            $this->repo->update($id, [
                'status' => 'booked',
                'matched_appointment_id' => $appointmentId,
                'offer_started_at' => null,
                'offer_expires_at' => null,
            ]);

            $this->audit->log('appointment_waitlist_status_changed', 'appointment_waitlist', $id, $this->currentUserId(), $branchId, [
                'before_status' => $status,
                'after_status' => 'booked',
                'source' => 'conversion',
            ]);
            $this->audit->log('appointment_waitlist_converted', 'appointment_waitlist', $id, $this->currentUserId(), $branchId, [
                'appointment_id' => $appointmentId,
                'from_status' => $status,
                'to_status' => 'booked',
            ]);
            $this->audit->log('appointment_waitlist_linked', 'appointment_waitlist', $id, $this->currentUserId(), $branchId, [
                'matched_appointment_id' => $appointmentId,
                'source' => 'conversion',
            ]);

            try {
                $this->notifications->create([
                    'branch_id' => $branchId,
                    'user_id' => null,
                    'type' => 'waitlist_converted',
                    'title' => 'Waitlist converted to appointment',
                    'message' => 'Waitlist entry #' . $id . ' was converted to appointment #' . $appointmentId . '.',
                    'entity_type' => 'appointment',
                    'entity_id' => $appointmentId,
                ]);
            } catch (\Throwable $e) {
                slog('warning', 'notifications.waitlist_converted', $e->getMessage(), [
                    'waitlist_id' => $id,
                    'appointment_id' => $appointmentId,
                    'branch_id' => $branchId,
                ]);
            }

            return $appointmentId;
        }, 'waitlist convert to appointment', true);
    }

    private function normalizeBranchId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function transactional(callable $callback, string $action, bool $readCommittedNext = false): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                if ($readCommittedNext) {
                    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                }
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $callback();
            if ($started) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            slog('error', 'appointments.waitlist_transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \DomainException('Waitlist operation failed.');
        }
    }

    /**
     * Called after an appointment slot is vacated (cancel, soft-delete, reschedule, or scheduling update)
     * so the prior start date + service + staff window may be offered to the waitlist.
     * Respects waitlist.enabled, waitlist.auto_offer_enabled, and waitlist.default_expiry_minutes (branch-aware).
     */
    public function onAppointmentSlotFreed(array $appointmentRow, int $appointmentId): void
    {
        $branchId = null;
        try {
            $branchId = isset($appointmentRow['branch_id']) && $appointmentRow['branch_id'] !== '' && $appointmentRow['branch_id'] !== null
                ? (int) $appointmentRow['branch_id']
                : null;
            $this->expireDueOffers($branchId);

            $wlSettings = $this->settings->getWaitlistSettings($branchId);
            if (!$wlSettings['enabled']) {
                return;
            }
            if (!$wlSettings['auto_offer_enabled']) {
                $this->audit->log('auto_offer_skipped_disabled', 'appointment', $appointmentId, $this->currentUserId(), $branchId, [
                    'reason' => 'waitlist.auto_offer_enabled',
                ]);

                return;
            }
            $startAt = trim((string) ($appointmentRow['start_at'] ?? ''));
            if ($startAt === '') {
                return;
            }
            $dateYmd = substr($startAt, 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd) !== 1) {
                return;
            }
            $serviceId = (int) ($appointmentRow['service_id'] ?? 0);
            $staffId = (int) ($appointmentRow['staff_id'] ?? 0);
            if ($serviceId <= 0 || $staffId <= 0) {
                return;
            }
            $this->attemptAutoOfferForSlotContext($branchId, $dateYmd, $serviceId, $staffId, 0, $appointmentId, 'appointment_slot_freed');
        } catch (\Throwable $e) {
            slog('error', 'waitlist.on_appointment_slot_freed', $e->getMessage(), [
                'appointment_id' => $appointmentId,
                'branch_id' => $branchId,
            ]);
        }
    }

    /**
     * Revert expired offers to waiting. Idempotent; safe to call from UI or CLI.
     */
    public function expireDueOffers(?int $branchId): int
    {
        return $this->executeExpirySweep($branchId)['offers_expired'];
    }

    /**
     * Expire due offers and return structured stats (cron / ops). Same engine as {@see expireDueOffers}.
     *
     * @return array{
     *   candidates_examined: int,
     *   offers_expired: int,
     *   chained_reoffer_attempts: int,
     *   chained_reoffers_created: int,
     *   chained_not_reoffered: int,
     *   errors: list<string>,
     *   lock_held: bool
     * }
     */
    public function runWaitlistExpirySweep(?int $branchId): array
    {
        return $this->executeExpirySweep($branchId);
    }

    /**
     * @return array{
     *   candidates_examined: int,
     *   offers_expired: int,
     *   chained_reoffer_attempts: int,
     *   chained_reoffers_created: int,
     *   chained_not_reoffered: int,
     *   errors: list<string>,
     *   lock_held: bool
     * }
     */
    private function executeExpirySweep(?int $branchId): array
    {
        $skipped = [
            'candidates_examined' => 0,
            'offers_expired' => 0,
            'chained_reoffer_attempts' => 0,
            'chained_reoffers_created' => 0,
            'chained_not_reoffered' => 0,
            'errors' => [],
            'lock_held' => true,
        ];

        $lockRow = $this->db->fetchOne(
            'SELECT GET_LOCK(?, ?) AS acquired',
            [self::EXPIRY_SWEEP_MYSQL_LOCK, 0]
        );
        $acquired = isset($lockRow['acquired']) && (int) $lockRow['acquired'] === 1;
        if (!$acquired) {
            return $skipped;
        }

        try {
            return $this->doExecuteExpirySweepBody($branchId);
        } finally {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?) AS released', [self::EXPIRY_SWEEP_MYSQL_LOCK]);
        }
    }

    /**
     * @return array{
     *   candidates_examined: int,
     *   offers_expired: int,
     *   chained_reoffer_attempts: int,
     *   chained_reoffers_created: int,
     *   chained_not_reoffered: int,
     *   errors: list<string>,
     *   lock_held: bool
     * }
     */
    private function doExecuteExpirySweepBody(?int $branchId): array
    {
        // Time-based only (offer_expires_at / clock). Whether a customer email was ever delivered is irrelevant here.
        $rows = $this->repo->findExpiredOfferRows($branchId);
        $candidatesExamined = count($rows);
        $offersExpired = 0;
        $chainedAttempts = 0;
        $chainedCreated = 0;
        $errors = [];

        foreach ($rows as $r) {
            try {
                $id = (int) $r['id'];
                $bid = $r['branch_id'] !== null && $r['branch_id'] !== '' ? (int) $r['branch_id'] : null;
                $this->suppressStaleWaitlistOfferOutbound($id, $bid, 'expired');
                $before = $this->repo->find($id);
                $this->notifyWaitlistOfferExpiredIfEnabled($id, $bid, $before);
                $this->repo->update($id, [
                    'status' => 'waiting',
                    'offer_started_at' => null,
                    'offer_expires_at' => null,
                ]);
                $this->audit->log('waitlist_offer_expired', 'appointment_waitlist', $id, $this->currentUserId(), $bid, [
                    'source' => 'expiry',
                ]);
                $chain = $this->tryChainedAutoOfferAfterExpiredRow($id, $before);
                if ($chain['reached_attempt']) {
                    ++$chainedAttempts;
                }
                if ($chain['created']) {
                    ++$chainedCreated;
                }
                ++$offersExpired;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $errors[] = $msg;
                $wid = isset($r['id']) ? (int) $r['id'] : null;
                $bidRow = isset($r['branch_id']) && $r['branch_id'] !== '' && $r['branch_id'] !== null ? (int) $r['branch_id'] : null;
                slog('error', 'waitlist.expiry_sweep_row', $msg, ['waitlist_id' => $wid, 'branch_id' => $bidRow]);
            }
        }

        return [
            'candidates_examined' => $candidatesExamined,
            'offers_expired' => $offersExpired,
            'chained_reoffer_attempts' => $chainedAttempts,
            'chained_reoffers_created' => $chainedCreated,
            'chained_not_reoffered' => $offersExpired - $chainedCreated,
            'errors' => $errors,
            'lock_held' => false,
        ];
    }

    /**
     * After reverting an expired offer to waiting, try the next queue member for the same date/service/staff keys.
     * Skips the just-expired row, skips if another `offered` row already holds this slot context, and optionally
     * verifies staff/service window is still free when preferred_time_from is set (same AvailabilityService rules).
     *
     * @return array{reached_attempt: bool, created: bool}
     */
    private function tryChainedAutoOfferAfterExpiredRow(int $justExpiredWaitlistId, ?array $rowWhileOffered): array
    {
        $out = ['reached_attempt' => false, 'created' => false];
        try {
            if ($rowWhileOffered === null) {
                return $out;
            }
            $branchId = $rowWhileOffered['branch_id'] !== null && $rowWhileOffered['branch_id'] !== ''
                ? (int) $rowWhileOffered['branch_id']
                : null;
            $wlSettings = $this->settings->getWaitlistSettings($branchId);
            if (!$wlSettings['enabled'] || !$wlSettings['auto_offer_enabled']) {
                return $out;
            }
            $dateYmd = trim((string) ($rowWhileOffered['preferred_date'] ?? ''));
            if ($dateYmd === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd) !== 1) {
                return $out;
            }
            $serviceId = (int) ($rowWhileOffered['service_id'] ?? 0);
            $staffId = (int) ($rowWhileOffered['preferred_staff_id'] ?? 0);
            if ($serviceId <= 0 || $staffId <= 0) {
                return $out;
            }
            if (!$this->isSlotContextLikelyFreeForChainedOffer($rowWhileOffered, $serviceId, $staffId, $branchId)) {
                $this->audit->log('waitlist_expiry_reoffer_skipped', 'appointment_waitlist', $justExpiredWaitlistId, $this->currentUserId(), $branchId, [
                    'reason' => 'slot_unavailable_or_unverifiable',
                    'preferred_date' => $dateYmd,
                    'service_id' => $serviceId,
                    'staff_id' => $staffId,
                ]);

                return $out;
            }
            $out['reached_attempt'] = true;
            $out['created'] = $this->attemptAutoOfferForSlotContext(
                $branchId,
                $dateYmd,
                $serviceId,
                $staffId,
                $justExpiredWaitlistId,
                null,
                'waitlist_expiry_chain'
            );
        } catch (\Throwable $e) {
            slog('error', 'waitlist.chained_auto_offer_after_expired', $e->getMessage(), [
                'waitlist_id' => $justExpiredWaitlistId,
            ]);
        }

        return $out;
    }

    /**
     * When preferred_time_from is set, use AvailabilityService for that window; otherwise treat as free (same weak
     * guarantee as the original slot-freed auto-offer path, which matches by date only).
     *
     * @param array<string, mixed> $rowWhileOffered
     */
    private function isSlotContextLikelyFreeForChainedOffer(array $rowWhileOffered, int $serviceId, int $staffId, ?int $branchId): bool
    {
        $timeFrom = $rowWhileOffered['preferred_time_from'] ?? null;
        if ($timeFrom === null || trim((string) $timeFrom) === '') {
            return true;
        }
        $dateYmd = trim((string) ($rowWhileOffered['preferred_date'] ?? ''));
        $tf = trim((string) $timeFrom);
        if (strlen($tf) === 5) {
            $tf .= ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $tf) !== 1) {
            return true;
        }
        $startAt = $dateYmd . ' ' . $tf;
        $timing = $this->availability->getServiceTiming($serviceId);
        if ($timing === null) {
            return false;
        }
        $duration = max(1, (int) ($timing['duration_minutes'] ?? 0));
        $bufBefore = max(0, (int) ($timing['buffer_before_minutes'] ?? 0));
        $bufAfter = max(0, (int) ($timing['buffer_after_minutes'] ?? 0));
        $endTs = strtotime($startAt);
        if ($endTs === false) {
            return false;
        }
        $endAt = date('Y-m-d H:i:s', $endTs + $duration * 60);

        return $this->availability->isStaffWindowAvailable(
            $staffId,
            $startAt,
            $endAt,
            $branchId,
            null,
            $bufBefore,
            $bufAfter
        );
    }

    /**
     * Shared auto-offer: next waiting row for date/service/staff (optional exclude), no duplicate open offer for slot.
     * Serialized with {@see slotAutoOfferMysqlLockName()} and MySQL GET_LOCK so concurrent slot-freed handlers cannot
     * both pass {@see WaitlistRepository::existsOpenOfferForSlot()} before either promotes a row (TOCTOU).
     *
     * @param int $excludeWaitlistId Pass > 0 to skip the just-expired entry.
     * @param int|null $sourceAppointmentId Set when a real appointment freed the slot; null for expiry chain.
     * @param 'appointment_slot_freed'|'waitlist_expiry_chain' $auditSource
     * @return true when a waiting row was promoted to `offered`
     */
    private function attemptAutoOfferForSlotContext(
        ?int $branchId,
        string $dateYmd,
        int $serviceId,
        int $staffId,
        int $excludeWaitlistId,
        ?int $sourceAppointmentId,
        string $auditSource
    ): bool {
        $wlSettings = $this->settings->getWaitlistSettings($branchId);
        if (!$wlSettings['enabled'] || !$wlSettings['auto_offer_enabled']) {
            return false;
        }
        if ($serviceId <= 0 || $staffId <= 0) {
            return false;
        }

        $lockName = $this->slotAutoOfferMysqlLockName($branchId, $dateYmd, $serviceId, $staffId);
        $acquired = $this->db->fetchOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if (!isset($acquired['acquired']) || (int) $acquired['acquired'] !== 1) {
            $auditTarget = $sourceAppointmentId !== null && $sourceAppointmentId > 0 ? 'appointment' : 'appointment_waitlist';
            $auditId = $sourceAppointmentId !== null && $sourceAppointmentId > 0 ? $sourceAppointmentId : null;
            $this->audit->log('waitlist_slot_offer_duplicate_prevented', $auditTarget, $auditId, $this->currentUserId(), $branchId, [
                'reason' => 'slot_offer_mysql_lock_unavailable',
                'preferred_date' => $dateYmd,
                'service_id' => $serviceId,
                'preferred_staff_id' => $staffId,
                'audit_source' => $auditSource,
            ]);

            return false;
        }

        try {
            return $this->transactional(function () use (
                $branchId,
                $dateYmd,
                $serviceId,
                $staffId,
                $excludeWaitlistId,
                $sourceAppointmentId,
                $auditSource
            ): bool {
                if ($this->repo->existsOpenOfferForSlot($branchId, $dateYmd, $serviceId, $staffId)) {
                    return false;
                }
                $hit = $this->repo->findFirstWaitingForAutoOffer($branchId, $dateYmd, $serviceId, $staffId, $excludeWaitlistId);
                if ($hit === null) {
                    return false;
                }
                $waitlistId = (int) $hit['id'];
                $row = $this->repo->find($waitlistId);
                if ($row === null) {
                    return false;
                }
                $entryBranchId = $row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null;
                $ts = $this->offerTimestampsForBranch($entryBranchId);
                $this->repo->update($waitlistId, array_merge($ts, ['status' => 'offered']));
                $meta = [
                    'source' => $auditSource,
                    'default_expiry_minutes' => $this->settings->getWaitlistSettings($entryBranchId)['default_expiry_minutes'],
                    'slot_offer_mysql_lock' => true,
                ];
                if ($sourceAppointmentId !== null && $sourceAppointmentId > 0) {
                    $meta['appointment_id'] = $sourceAppointmentId;
                }
                if ($excludeWaitlistId > 0) {
                    $meta['excluded_waitlist_id'] = $excludeWaitlistId;
                }
                $this->audit->log('waitlist_offer_created', 'appointment_waitlist', $waitlistId, $this->currentUserId(), $entryBranchId, $meta);
                $this->notifyWaitlistOfferCreatedIfEnabled($waitlistId, $entryBranchId, $row, $ts, $sourceAppointmentId, $auditSource);

                return true;
            }, 'waitlist slot auto offer');
        } finally {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    /**
     * Deterministic MySQL user lock name (≤ 64 chars) for one auto-offer “slot context”.
     *
     * @see WaitlistRepository::existsOpenOfferForSlot()
     */
    private function slotAutoOfferMysqlLockName(?int $branchId, string $dateYmd, int $serviceId, int $staffId): string
    {
        $b = $branchId === null ? 'g' : (string) $branchId;

        return self::SLOT_AUTO_OFFER_LOCK_PREFIX . substr(hash('sha256', $b . '|' . $dateYmd . '|' . $serviceId . '|' . $staffId), 0, 40);
    }

    /**
     * @return array{offer_started_at: string, offer_expires_at: ?string}
     */
    private function offerTimestampsForBranch(?int $branchId): array
    {
        $wl = $this->settings->getWaitlistSettings($branchId);
        $minutes = max(0, (int) ($wl['default_expiry_minutes'] ?? 30));
        $started = date('Y-m-d H:i:s');
        if ($minutes <= 0) {
            return ['offer_started_at' => $started, 'offer_expires_at' => null];
        }
        $expires = date('Y-m-d H:i:s', strtotime('+' . $minutes . ' minutes'));

        return ['offer_started_at' => $started, 'offer_expires_at' => $expires];
    }

    /**
     * Records outbound enqueue outcome first, then optional in-app staff notice. Branch waitlist channel: {@see NotificationService::create}.
     * Copy does not claim the client was contacted; offer state is separate from dispatch/delivery truth.
     *
     * @param array<string, mixed> $waitlistRow from {@see WaitlistRepository::find}
     * @param array{offer_started_at: string, offer_expires_at: ?string} $ts
     */
    private function notifyWaitlistOfferCreatedIfEnabled(
        int $waitlistId,
        ?int $entryBranchId,
        array $waitlistRow,
        array $ts,
        ?int $sourceAppointmentId,
        string $auditSource
    ): void {
        $offerStarted = (string) ($ts['offer_started_at'] ?? '');
        if ($offerStarted === '') {
            return;
        }
        $expRaw = $ts['offer_expires_at'] ?? null;
        $expStr = $expRaw !== null && $expRaw !== '' ? (string) $expRaw : null;
        $outreach = $this->enqueueWaitlistOfferOutreach($waitlistId, $waitlistRow, $offerStarted, $expStr, $auditSource);

        $title = sprintf('Waitlist offer opened (#%d) · %s', $waitlistId, $offerStarted);
        if ($this->notifications->existsByTypeEntityAndTitle('waitlist_offer_created', 'appointment_waitlist', $waitlistId, $title)) {
            return;
        }
        try {
            $clientLabel = trim(((string) ($waitlistRow['client_first_name'] ?? '')) . ' ' . ((string) ($waitlistRow['client_last_name'] ?? '')));
            if ($clientLabel === '') {
                $clientLabel = 'Waitlist entry';
            }
            $expAt = $ts['offer_expires_at'] ?? null;
            $expNote = $expAt !== null && trim((string) $expAt) !== ''
                ? ' Offer window expires ' . trim((string) $expAt) . '.'
                : ' No scheduled expiry for this offer window.';
            $outreachNote = ' ' . $this->staffNoteForWaitlistOutreachOutcome($outreach);
            if ($sourceAppointmentId !== null && $sourceAppointmentId > 0) {
                $body = sprintf(
                    '%s (entry #%d): offer window opened after appointment #%d freed a matching slot.%s%s',
                    $clientLabel,
                    $waitlistId,
                    $sourceAppointmentId,
                    $expNote,
                    $outreachNote
                );
            } elseif ($auditSource === 'waitlist_expiry_chain') {
                $body = sprintf(
                    '%s (entry #%d): offer window opened after a prior offer on this slot expired.%s%s',
                    $clientLabel,
                    $waitlistId,
                    $expNote,
                    $outreachNote
                );
            } else {
                $body = sprintf(
                    '%s (entry #%d): offer window opened for a freed matching slot.%s%s',
                    $clientLabel,
                    $waitlistId,
                    $expNote,
                    $outreachNote
                );
            }
            $this->notifications->create([
                'branch_id' => $entryBranchId,
                'user_id' => null,
                'type' => 'waitlist_offer_created',
                'title' => $title,
                'message' => $body,
                'entity_type' => 'appointment_waitlist',
                'entity_id' => $waitlistId,
            ]);
        } catch (\Throwable $e) {
            slog('warning', 'notifications.waitlist_offer_created', $e->getMessage(), [
                'waitlist_id' => $waitlistId,
                'branch_id' => $entryBranchId,
            ]);
        }
    }

    /**
     * In-app notification when an offered row passes expiry (before state is cleared). Branch-effective waitlist channel in {@see NotificationService::create}.
     *
     * @param array<string, mixed>|null $rowBefore from {@see WaitlistRepository::find} while still status offered
     */
    private function notifyWaitlistOfferExpiredIfEnabled(int $waitlistId, ?int $entryBranchId, ?array $rowBefore): void
    {
        if ($rowBefore === null) {
            return;
        }
        $expAt = trim((string) ($rowBefore['offer_expires_at'] ?? ''));
        $title = sprintf('Waitlist offer #%d expired · %s', $waitlistId, $expAt !== '' ? $expAt : 'unknown');
        if ($this->notifications->existsByTypeEntityAndTitle('waitlist_offer_expired', 'appointment_waitlist', $waitlistId, $title)) {
            return;
        }
        try {
            $clientLabel = trim(((string) ($rowBefore['client_first_name'] ?? '')) . ' ' . ((string) ($rowBefore['client_last_name'] ?? '')));
            if ($clientLabel === '') {
                $clientLabel = 'Waitlist entry';
            }
            $this->notifications->create([
                'branch_id' => $entryBranchId,
                'user_id' => null,
                'type' => 'waitlist_offer_expired',
                'title' => $title,
                'message' => sprintf(
                    '%s — waitlist entry #%d reverted to waiting (offer expired).',
                    $clientLabel,
                    $waitlistId
                ),
                'entity_type' => 'appointment_waitlist',
                'entity_id' => $waitlistId,
            ]);
        } catch (\Throwable $e) {
            slog('warning', 'notifications.waitlist_offer_expired', $e->getMessage(), [
                'waitlist_id' => $waitlistId,
                'branch_id' => $entryBranchId,
            ]);
        }
    }

    /**
     * Pending `waitlist.offer` rows only; does not touch `processing` (avoids racing the dispatch worker).
     *
     * @param string $reasonCode short machine token for audit (expired, status_change, linked, converted)
     */
    private function suppressStaleWaitlistOfferOutbound(int $waitlistId, ?int $branchId, string $reasonCode): void
    {
        if ($waitlistId <= 0 || $reasonCode === '') {
            return;
        }
        $n = $this->outboundTransactional->suppressPendingWaitlistOfferEmails(
            $waitlistId,
            'waitlist_offer_no_longer_active:' . $reasonCode
        );
        if ($n > 0) {
            $this->audit->log('waitlist_offer_outbound_pending_suppressed', 'appointment_waitlist', $waitlistId, $this->currentUserId(), $branchId, [
                'pending_rows_skipped' => $n,
                'reason' => $reasonCode,
            ]);
        }
    }

    private function enqueueWaitlistOfferOutreach(
        int $waitlistId,
        array $waitlistRow,
        string $offerStarted,
        ?string $offerExpiresAt,
        string $source
    ): array {
        $branchId = $this->normalizeBranchId($waitlistRow['branch_id'] ?? null);
        try {
            $result = $this->outboundTransactional->enqueueWaitlistOffer($waitlistId, $waitlistRow, $offerStarted, $offerExpiresAt);
            $this->audit->log('waitlist_offer_outreach_recorded', 'appointment_waitlist', $waitlistId, $this->currentUserId(), $branchId, [
                'source' => $source,
                'outcome' => $result['outcome'] ?? 'unknown',
                'detail' => $result['detail'] ?? null,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->audit->log('waitlist_offer_outreach_failed', 'appointment_waitlist', $waitlistId, $this->currentUserId(), $branchId, [
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
            slog('error', 'waitlist.outbound_offer', $e->getMessage(), [
                'waitlist_id' => $waitlistId,
                'branch_id' => $branchId,
                'source' => $source,
            ]);

            return ['outcome' => 'enqueue_exception', 'detail' => $e->getMessage()];
        }
    }

    /**
     * @param array{outcome?: string, detail?: string|null} $outreach
     */
    private function staffNoteForWaitlistOutreachOutcome(array $outreach): string
    {
        $o = (string) ($outreach['outcome'] ?? 'unknown');

        return match ($o) {
            'pending_enqueued' => 'Customer email queued for outbound worker only (not proof of delivery; may be log capture or MTA handoff per transport).',
            'duplicate_ignored' => 'Outbound idempotency: no new queue row for this offer instant.',
            'outbound_event_gated' => 'Outbound waitlist.offer disabled by settings — no customer email queued.',
            'skipped_no_client_id' => 'No client on entry — customer email not queued.',
            'skipped_client_not_found' => 'Client record missing — customer email not queued.',
            'skipped_no_client_email' => 'No valid customer email — outbound skipped row recorded.',
            'skipped_template_error' => 'Template render failed — outbound skipped row recorded.',
            'enqueue_exception' => 'Outbound enqueue error: ' . trim((string) ($outreach['detail'] ?? '')),
            default => 'Outbound outreach outcome: ' . $o,
        };
    }
}
