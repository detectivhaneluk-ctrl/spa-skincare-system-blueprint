<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;
use Core\Auth\SessionAuth;
use Core\Kernel\RequestContextHolder;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Appointments\Repositories\AppointmentSeriesRepository;
use Modules\ServicesResources\Services\ServiceStaffGroupEligibilityService;
use Modules\Staff\Services\StaffGroupService;
use PDOException;

final class AppointmentSeriesService
{
    public function __construct(
        private AppointmentSeriesRepository $seriesRepo,
        private AppointmentService $appointmentService,
        private RequestContextHolder $contextHolder,
        private Database $db,
        private AuditService $audit,
        private StaffGroupService $staffGroupService,
        private ServiceStaffGroupEligibilityService $serviceStaffGroupEligibility,
        private AvailabilityService $availability,
        private OrganizationRepositoryScope $orgScope
    ) {
    }

    /**
     * Authoritative lifecycle for a series row + materialized appointments.
     *
     * - cancelled: DB status is cancelled (no generation; series treated as dead).
     * - completed: status is active but every future slot in the recurrence plan is already materialized (nothing left to add).
     * - active: status is active and at least one planned future occurrence is not yet materialized.
     *
     * @param array<string, mixed> $seriesRow appointment_series row
     * @param list<string> $existingStartAtsNormalized Y-m-d H:i:s from listExistingStartAts()
     * @return array{lifecycle: 'active'|'completed'|'cancelled', allows_future_generation: bool}
     */
    public function resolveSeriesLifecycleState(array $seriesRow, array $existingStartAtsNormalized): array
    {
        $status = strtolower(trim((string) ($seriesRow['status'] ?? '')));
        if ($status === 'cancelled') {
            return ['lifecycle' => 'cancelled', 'allows_future_generation' => false];
        }

        $existingSet = array_flip($existingStartAtsNormalized);
        $candidates = $this->generateOccurrences($this->recurrencePayloadFromSeriesRow($seriesRow));
        $now = time();
        foreach ($candidates as $c) {
            $st = $this->normalizeStartAtForKey((string) $c['start_at']);
            if (strtotime($st) <= $now) {
                continue;
            }
            if (!isset($existingSet[$st])) {
                return ['lifecycle' => 'active', 'allows_future_generation' => true];
            }
        }

        return ['lifecycle' => 'completed', 'allows_future_generation' => false];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSeriesWithOccurrences(array $payload): array
    {
        $normalized = $this->normalizePayload($payload);
        $ctx = $this->contextHolder->requireContext();
        ['branch_id' => $resolvedBranch] = $ctx->requireResolvedTenant();
        if ((int) $normalized['branch_id'] !== $resolvedBranch) {
            throw new \Core\Errors\AccessDeniedException('Series branch is outside tenant scope.');
        }
        $this->assertSeriesEntityState($normalized);

        $occurrences = $this->generateOccurrences($normalized);
        if ($occurrences === []) {
            throw new \InvalidArgumentException('No occurrences generated from recurrence rule.');
        }

        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
                $started = true;
            }

            $seriesId = $this->seriesRepo->create([
                'branch_id' => $normalized['branch_id'],
                'client_id' => $normalized['client_id'],
                'service_id' => $normalized['service_id'],
                'staff_id' => $normalized['staff_id'],
                'recurrence_type' => $normalized['recurrence_type'],
                'interval_weeks' => $normalized['interval_weeks'],
                'weekday' => $normalized['weekday'],
                'start_date' => $normalized['start_date'],
                'end_date' => $normalized['end_date'],
                'occurrences_count' => $normalized['occurrences_count'],
                'start_time' => $normalized['start_time'],
                'end_time' => $normalized['end_time'],
                'status' => 'active',
            ]);

            $requestedCount = count($occurrences);
            $createdCount = 0;
            $skippedConflictCount = 0;
            $firstConflictDate = null;
            $branchId = (int) $normalized['branch_id'];
            $actorId = $this->currentUserId();

            foreach ($occurrences as $occurrence) {
                try {
                    $startKey = $this->normalizeStartAtForKey((string) $occurrence['start_at']);
                    $appointmentId = $this->appointmentService->createFromSeriesOccurrence(
                        $seriesId,
                        $branchId,
                        $normalized['client_id'],
                        $normalized['service_id'],
                        $normalized['staff_id'],
                        $startKey,
                        $normalized['notes']
                    );
                    $createdCount++;
                    $this->audit->log('series_occurrence_generated', 'appointment', $appointmentId, $actorId, $branchId, [
                        'series_id' => $seriesId,
                        'start_at' => $startKey,
                    ]);
                } catch (PDOException $e) {
                    if ($this->isMysqlDuplicateKey($e)) {
                        // Idempotent skip: same series + start already exists
                        continue;
                    }
                    throw $e;
                } catch (\DomainException $e) {
                    if (!$this->isConflictDomainFailure($e)) {
                        throw $e;
                    }
                    if ($createdCount === 0) {
                        throw new \DomainException('First series occurrence conflicts with existing schedule.');
                    }
                    $skippedConflictCount = 1;
                    $firstConflictDate = substr((string) $occurrence['start_at'], 0, 10);
                    break;
                }
            }

            if ($createdCount <= 0) {
                throw new \DomainException('Series creation did not create any appointments.');
            }

            if ($started) {
                $pdo->commit();
            }

            return [
                'series_id' => $seriesId,
                'requested_count' => $requestedCount,
                'created_count' => $createdCount,
                'skipped_conflict_count' => $skippedConflictCount,
                'first_conflict_date' => $firstConflictDate,
            ];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \RuntimeException('Failed to create appointment series.');
        }
    }

    /**
     * Materialize up to $maxBatch future occurrences missing from the DB, using the locked appointment pipeline.
     *
     * @return array<string, mixed>
     */
    public function materializeFutureOccurrences(int $seriesId, int $maxBatch = 26): array
    {
        if ($seriesId <= 0) {
            throw new \InvalidArgumentException('series_id is required.');
        }
        if ($maxBatch < 1 || $maxBatch > 260) {
            throw new \InvalidArgumentException('max_batch must be between 1 and 260.');
        }

        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
                $started = true;
            }

            $row = $this->seriesRepo->findForUpdate($seriesId);
            if (!$row) {
                throw new \DomainException('Series not found.');
            }
            $branchId = (int) ($row['branch_id'] ?? 0);
            $ctx = $this->contextHolder->requireContext();
            ['branch_id' => $resolvedBranch] = $ctx->requireResolvedTenant();
            if ($branchId <= 0 || $branchId !== $resolvedBranch) {
                throw new \Core\Errors\AccessDeniedException('Series is outside branch scope.');
            }

            $existing = $this->seriesRepo->listExistingStartAts($seriesId);
            $lifecycle = $this->resolveSeriesLifecycleState($row, $existing);
            if ($lifecycle['lifecycle'] === 'cancelled') {
                throw new \DomainException('Series is cancelled; cannot generate occurrences.');
            }
            if (!$lifecycle['allows_future_generation']) {
                throw new \DomainException('Series has no remaining future occurrences to materialize.');
            }

            $existingSet = array_flip($existing);
            $candidates = $this->generateOccurrences($this->recurrencePayloadFromSeriesRow($row));
            $now = time();
            $toCreate = [];
            foreach ($candidates as $c) {
                $st = $this->normalizeStartAtForKey((string) $c['start_at']);
                if (strtotime($st) <= $now) {
                    continue;
                }
                if (isset($existingSet[$st])) {
                    continue;
                }
                $toCreate[] = $st;
                if (count($toCreate) >= $maxBatch) {
                    break;
                }
            }

            $created = 0;
            $skippedConflict = 0;
            $skippedDuplicate = 0;
            $actorId = $this->currentUserId();
            $clientId = (int) ($row['client_id'] ?? 0);
            $serviceId = (int) ($row['service_id'] ?? 0);
            $staffId = (int) ($row['staff_id'] ?? 0);

            foreach ($toCreate as $startKey) {
                try {
                    $appointmentId = $this->appointmentService->createFromSeriesOccurrence(
                        $seriesId,
                        $branchId,
                        $clientId,
                        $serviceId,
                        $staffId,
                        $startKey,
                        null
                    );
                    $created++;
                    $existingSet[$startKey] = true;
                    $this->audit->log('series_occurrence_generated', 'appointment', $appointmentId, $actorId, $branchId, [
                        'series_id' => $seriesId,
                        'start_at' => $startKey,
                    ]);
                } catch (PDOException $e) {
                    if ($this->isMysqlDuplicateKey($e)) {
                        $skippedDuplicate++;
                        $existingSet[$startKey] = true;
                        continue;
                    }
                    throw $e;
                } catch (\DomainException $e) {
                    if ($this->isConflictDomainFailure($e)) {
                        $skippedConflict++;
                        continue;
                    }
                    throw $e;
                }
            }

            if ($started) {
                $pdo->commit();
            }

            return [
                'series_id' => $seriesId,
                'created_count' => $created,
                'skipped_conflict_count' => $skippedConflict,
                'skipped_duplicate_count' => $skippedDuplicate,
                'candidates_considered' => count($toCreate),
            ];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \RuntimeException('Failed to materialize series occurrences.');
        }
    }

    /**
     * Cancel all remaining cancellable appointments and mark the series cancelled. Does not alter completed/no_show rows.
     *
     * @return array<string, mixed>
     */
    public function cancelEntireSeries(int $seriesId, ?string $notes = null): array
    {
        return $this->runSeriesBulkCancel($seriesId, 'whole', null, $notes);
    }

    /**
     * Truncate the series plan to end before from_date (Y-m-d) and cancel appointments on or after that date (local day start).
     *
     * @return array<string, mixed>
     */
    public function cancelSeriesForwardFrom(int $seriesId, string $fromDateYmd, ?string $notes = null): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDateYmd) !== 1) {
            throw new \InvalidArgumentException('from_date must be YYYY-MM-DD.');
        }

        return $this->runSeriesBulkCancel($seriesId, 'forward', $fromDateYmd, $notes);
    }

    /**
     * Cancel a single occurrence; enforces normal cancellation settings (not internal bypass).
     */
    public function cancelSeriesOccurrence(int $appointmentId, ?string $notes = null): void
    {
        if ($appointmentId <= 0) {
            throw new \InvalidArgumentException('appointment_id is required.');
        }
        $appointmentFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $app = $this->db->fetchOne(
            'SELECT a.id, a.series_id, a.branch_id, a.status, a.start_at
             FROM appointments a
             WHERE a.id = ? AND a.deleted_at IS NULL' . $appointmentFrag['sql'],
            array_merge([$appointmentId], $appointmentFrag['params'])
        );
        if (!$app) {
            throw new \DomainException('Appointment not found.');
        }
        $sid = $app['series_id'] ?? null;
        if ($sid === null || (int) $sid <= 0) {
            throw new \DomainException('Appointment is not part of a series.');
        }
        $branchId = $app['branch_id'] !== null && $app['branch_id'] !== '' ? (int) $app['branch_id'] : null;
        $ctx = $this->contextHolder->requireContext();
        ['branch_id' => $resolvedBranch] = $ctx->requireResolvedTenant();
        if ($branchId === null || $branchId !== $resolvedBranch) {
            throw new \Core\Errors\AccessDeniedException('Appointment is outside branch scope.');
        }

        $st = strtolower(trim((string) ($app['status'] ?? '')));
        if (!in_array($st, ['scheduled', 'confirmed', 'in_progress'], true)) {
            throw new \DomainException('This occurrence cannot be cancelled in its current status.');
        }

        $this->appointmentService->cancel($appointmentId, $notes);
        $this->audit->log('series_occurrence_cancelled', 'appointment', $appointmentId, $this->currentUserId(), $branchId, [
            'series_id' => (int) $sid,
        ]);
    }

    /**
     * @param 'whole'|'forward' $scope
     * @return array<string, mixed>
     */
    private function runSeriesBulkCancel(int $seriesId, string $scope, ?string $fromDateYmd, ?string $notes): array
    {
        if ($seriesId <= 0) {
            throw new \InvalidArgumentException('series_id is required.');
        }

        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
                $started = true;
            }

            $row = $this->seriesRepo->findForUpdate($seriesId);
            if (!$row) {
                throw new \DomainException('Series not found.');
            }
            $branchId = (int) ($row['branch_id'] ?? 0);
            $ctxCancelBulk = $this->contextHolder->requireContext();
            ['branch_id' => $resolvedBranchCancelBulk] = $ctxCancelBulk->requireResolvedTenant();
            if ($branchId <= 0 || $branchId !== $resolvedBranchCancelBulk) {
                throw new \Core\Errors\AccessDeniedException('Series is outside branch scope.');
            }

            if ($scope === 'forward' && strtolower(trim((string) ($row['status'] ?? ''))) === 'cancelled') {
                throw new \DomainException('Series is cancelled; cannot apply forward cancellation.');
            }

            $fromInclusive = null;
            if ($scope === 'forward') {
                $fromInclusive = $fromDateYmd . ' 00:00:00';
                $patches = $this->computeForwardEndDatePatches($row, $fromDateYmd);
                if ($patches !== []) {
                    $this->seriesRepo->update($seriesId, $patches);
                }
            } else {
                $this->seriesRepo->update($seriesId, ['status' => 'cancelled']);
            }

            $ids = $this->seriesRepo->listCancellableAppointmentIds(
                $seriesId,
                $scope === 'forward' ? $fromInclusive : null
            );

            $cancelOpts = ['internal_series_bypass_cancellation_policy' => true];
            foreach ($ids as $aid) {
                $this->appointmentService->cancel($aid, $notes, null, $cancelOpts);
            }

            $actorId = $this->currentUserId();
            $this->audit->log('series_cancelled', 'appointment_series', $seriesId, $actorId, $branchId, [
                'scope' => $scope,
                'from_date' => $fromDateYmd,
                'cancelled_appointment_ids' => array_slice($ids, 0, 100),
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ]);

            if ($started) {
                $pdo->commit();
            }

            return [
                'series_id' => $seriesId,
                'scope' => $scope,
                'cancelled_appointment_count' => count($ids),
            ];
        } catch (\Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \RuntimeException('Failed to cancel series.');
        }
    }

    /**
     * @param array<string, mixed> $seriesRow
     * @return array<string, mixed> patches for appointment_series.update()
     */
    private function computeForwardEndDatePatches(array $seriesRow, string $fromDateYmd): array
    {
        $startDate = (string) ($seriesRow['start_date'] ?? '');
        $newEndCap = (new \DateTimeImmutable($fromDateYmd . ' 00:00:00'))->modify('-1 day')->format('Y-m-d');
        $currentEnd = isset($seriesRow['end_date']) && $seriesRow['end_date'] !== null && (string) $seriesRow['end_date'] !== ''
            ? (string) $seriesRow['end_date']
            : null;

        if ($currentEnd !== null && $currentEnd < $newEndCap) {
            $effectiveEnd = $currentEnd;
        } else {
            $effectiveEnd = $newEndCap;
        }

        $patches = ['end_date' => $effectiveEnd];
        if ($effectiveEnd < $startDate) {
            $patches['status'] = 'cancelled';
        }

        return $patches;
    }

    /**
     * @param array<string, mixed> $seriesRow
     * @return array<string, mixed>
     */
    private function recurrencePayloadFromSeriesRow(array $seriesRow): array
    {
        return [
            'interval_weeks' => (int) ($seriesRow['interval_weeks'] ?? 0),
            'start_date' => (string) ($seriesRow['start_date'] ?? ''),
            'end_date' => isset($seriesRow['end_date']) && $seriesRow['end_date'] !== null && (string) $seriesRow['end_date'] !== ''
                ? (string) $seriesRow['end_date']
                : null,
            'occurrences_count' => isset($seriesRow['occurrences_count']) && $seriesRow['occurrences_count'] !== null && (string) $seriesRow['occurrences_count'] !== ''
                ? (int) $seriesRow['occurrences_count']
                : null,
            'start_time' => $this->normalizeTimeFromDbForGenerator($seriesRow['start_time'] ?? ''),
            'end_time' => $this->normalizeTimeFromDbForGenerator($seriesRow['end_time'] ?? ''),
            'weekday' => (int) ($seriesRow['weekday'] ?? -1),
        ];
    }

    private function normalizeTimeFromDbForGenerator(mixed $raw): string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return '00:00:00';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m) === 1) {
            $h = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $min = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $sec = isset($m[3]) && $m[3] !== '' ? str_pad($m[3], 2, '0', STR_PAD_LEFT) : '00';

            return $h . ':' . $min . ':' . $sec;
        }

        return $s;
    }

    private function normalizeStartAtForKey(string $raw): string
    {
        $ts = strtotime($raw);
        if ($ts === false) {
            return trim($raw);
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function isMysqlDuplicateKey(PDOException $e): bool
    {
        $info = $e->errorInfo ?? null;
        if (is_array($info) && isset($info[1]) && (int) $info[1] === 1062) {
            return true;
        }
        $code = (string) $e->getCode();

        return $code === '23000';
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(SessionAuth::class)->id();
    }

    /**
     * Plan recurrence dates/times. Returned `end_at` is not written to appointments: materialization uses
     * {@see AppointmentService::createFromSeriesOccurrence} which sets `end_at` from service duration only.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{start_at: string, end_at: string}>
     */
    public function generateOccurrences(array $data): array
    {
        $intervalWeeks = (int) ($data['interval_weeks'] ?? 0);
        $startDate = (string) ($data['start_date'] ?? '');
        $endDate = isset($data['end_date']) && $data['end_date'] !== '' ? (string) $data['end_date'] : null;
        $occurrencesCount = isset($data['occurrences_count']) && $data['occurrences_count'] !== null
            ? (int) $data['occurrences_count']
            : null;
        $startTime = (string) ($data['start_time'] ?? '');
        $endTime = (string) ($data['end_time'] ?? '');
        $weekday = (int) ($data['weekday'] ?? -1);

        $this->assertDate($startDate, 'start_date');
        if ($endDate !== null) {
            $this->assertDate($endDate, 'end_date');
            if ($endDate < $startDate) {
                throw new \InvalidArgumentException('end_date cannot be before start_date.');
            }
        }
        if ($occurrencesCount !== null && $occurrencesCount <= 0) {
            throw new \InvalidArgumentException('occurrences_count must be positive when provided.');
        }
        if ($occurrencesCount === null && $endDate === null) {
            throw new \InvalidArgumentException('Provide end_date or occurrences_count.');
        }
        if ($occurrencesCount !== null && $occurrencesCount > 260) {
            throw new \InvalidArgumentException('occurrences_count exceeds max allowed (260).');
        }
        if ($intervalWeeks <= 0) {
            throw new \InvalidArgumentException('interval_weeks must be greater than 0.');
        }
        if ($weekday < 0 || $weekday > 6) {
            throw new \InvalidArgumentException('weekday must be between 0 (Sunday) and 6 (Saturday).');
        }
        $this->assertTime($startTime, 'start_time');
        $this->assertTime($endTime, 'end_time');
        if ($endTime <= $startTime) {
            throw new \InvalidArgumentException('end_time must be after start_time.');
        }

        $occurrences = [];
        $cursor = new \DateTimeImmutable($startDate . ' 00:00:00');
        while ((int) $cursor->format('w') !== $weekday) {
            $cursor = $cursor->modify('+1 day');
        }

        while (true) {
            $date = $cursor->format('Y-m-d');
            if ($endDate !== null && $date > $endDate) {
                break;
            }
            if ($date >= $startDate) {
                $occurrences[] = [
                    'start_at' => $date . ' ' . $startTime,
                    'end_at' => $date . ' ' . $endTime,
                ];
                if ($occurrencesCount !== null && count($occurrences) >= $occurrencesCount) {
                    break;
                }
            }
            $cursor = $cursor->modify('+' . $intervalWeeks . ' weeks');
        }

        return $occurrences;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $recurrenceType = strtolower(trim((string) ($payload['recurrence_type'] ?? '')));
        if (!in_array($recurrenceType, ['weekly', 'biweekly'], true)) {
            throw new \InvalidArgumentException('recurrence_type must be weekly or biweekly.');
        }

        $intervalWeeks = $recurrenceType === 'biweekly' ? 2 : 1;

        return [
            'branch_id' => (int) ($payload['branch_id'] ?? 0),
            'client_id' => (int) ($payload['client_id'] ?? 0),
            'service_id' => (int) ($payload['service_id'] ?? 0),
            'staff_id' => (int) ($payload['staff_id'] ?? 0),
            'recurrence_type' => $recurrenceType,
            'interval_weeks' => $intervalWeeks,
            'weekday' => (int) ($payload['weekday'] ?? -1),
            'start_date' => trim((string) ($payload['start_date'] ?? '')),
            'end_date' => trim((string) ($payload['end_date'] ?? '')) ?: null,
            'occurrences_count' => isset($payload['occurrences_count']) && trim((string) $payload['occurrences_count']) !== ''
                ? (int) $payload['occurrences_count']
                : null,
            'start_time' => $this->normalizeTime((string) ($payload['start_time'] ?? '')),
            'end_time' => $this->normalizeTime((string) ($payload['end_time'] ?? '')),
            'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
        ];
    }

    private function assertDate(string $value, string $field): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be YYYY-MM-DD.');
        }
    }

    private function assertTime(string $value, string $field): void
    {
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) !== 1) {
            throw new \InvalidArgumentException($field . ' must be HH:MM[:SS].');
        }
    }

    private function normalizeTime(string $value): string
    {
        $trimmed = trim($value);
        if (preg_match('/^\d{2}:\d{2}$/', $trimmed) === 1) {
            return $trimmed . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $trimmed) === 1) {
            return $trimmed;
        }
        throw new \InvalidArgumentException('Time must be HH:MM or HH:MM:SS.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertSeriesEntityState(array $data): void
    {
        if ((int) $data['branch_id'] <= 0 || (int) $data['client_id'] <= 0 || (int) $data['service_id'] <= 0 || (int) $data['staff_id'] <= 0) {
            throw new \InvalidArgumentException('branch_id, client_id, service_id, and staff_id must be positive integers.');
        }

        $branchAnchor = $this->orgScope->branchIdBelongsToResolvedOrganizationExistsClause((int) $data['branch_id']);
        $branch = $this->db->fetchOne(
            'SELECT 1 AS ok WHERE 1 = 1' . $branchAnchor['sql'],
            $branchAnchor['params']
        );
        if (!$branch) {
            throw new \DomainException('Branch not found or inactive.');
        }

        $clientFrag = $this->orgScope->clientProfileOrgMembershipExistsClause('c');
        $client = $this->db->fetchOne(
            'SELECT c.id FROM clients c WHERE c.id = ? AND c.deleted_at IS NULL' . $clientFrag['sql'],
            array_merge([(int) $data['client_id']], $clientFrag['params'])
        );
        if (!$client) {
            throw new \DomainException('Client not found or inactive.');
        }

        $serviceFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('s');
        $service = $this->db->fetchOne(
            'SELECT s.id, s.branch_id, s.is_active, s.deleted_at
             FROM services s
             WHERE s.id = ?' . $serviceFrag['sql'],
            array_merge([(int) $data['service_id']], $serviceFrag['params'])
        );
        if (!$service || !empty($service['deleted_at']) || (int) ($service['is_active'] ?? 0) !== 1) {
            throw new \DomainException('Selected service is not active.');
        }
        if ($service['branch_id'] !== null && (int) $service['branch_id'] !== (int) $data['branch_id']) {
            throw new \DomainException('Service is not available for the selected branch.');
        }

        $staffFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('st');
        $staff = $this->db->fetchOne(
            'SELECT st.id, st.branch_id, st.is_active, st.deleted_at
             FROM staff st
             WHERE st.id = ?' . $staffFrag['sql'],
            array_merge([(int) $data['staff_id']], $staffFrag['params'])
        );
        if (!$staff || !empty($staff['deleted_at']) || (int) ($staff['is_active'] ?? 0) !== 1) {
            throw new \DomainException('Selected staff is not active.');
        }
        if ($staff['branch_id'] !== null && (int) $staff['branch_id'] !== (int) $data['branch_id']) {
            throw new \DomainException('Staff is not available for the selected branch.');
        }

        $b = (int) $data['branch_id'];
        if (!$this->staffGroupService->isStaffInScopeForBranch((int) $data['staff_id'], $b)) {
            throw new \DomainException('Selected staff is not in scope for this branch.');
        }
        $this->serviceStaffGroupEligibility->assertStaffAllowedForService((int) $data['staff_id'], (int) $data['service_id'], $b);
        $this->assertSeriesTimeWindowMatchesServiceDuration((int) $data['service_id'], (int) $data['branch_id'], (string) $data['start_time'], (string) $data['end_time']);
    }

    /**
     * Stored series `end_time` does not drive appointment rows; occurrences use service duration from Availability.
     * Reject payloads where the declared window length does not match that duration so UI and DB are not misleading.
     */
    private function assertSeriesTimeWindowMatchesServiceDuration(int $serviceId, int $branchId, string $startTime, string $endTime): void
    {
        $duration = $this->availability->getServiceDurationMinutes($serviceId, $branchId > 0 ? $branchId : null);
        if ($duration <= 0) {
            throw new \DomainException('Service is not active or has invalid duration.');
        }
        $base = '2000-01-01';
        $startTs = strtotime($base . ' ' . $startTime);
        $endTs = strtotime($base . ' ' . $endTime);
        if ($startTs === false || $endTs === false) {
            throw new \DomainException('Invalid series time window.');
        }
        $windowMinutes = (int) round(($endTs - $startTs) / 60);
        if ($windowMinutes !== $duration) {
            throw new \DomainException(
                'Series time window must equal the selected service duration (' . $duration . ' minutes). '
                . 'Each occurrence is booked using that duration, not the series end_time alone.'
            );
        }
    }

    private function isConflictDomainFailure(\DomainException $e): bool
    {
        $message = strtolower(trim($e->getMessage()));

        return str_contains($message, 'selected slot is no longer available')
            || str_contains($message, 'staff time is unavailable')
            || str_contains($message, 'room is booked for another appointment at this time');
    }
}
