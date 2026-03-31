<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Core\App\Application;
use Core\App\Database;
use Core\Audit\AuditService;
use Core\Kernel\RequestContextHolder;
use Modules\Appointments\Repositories\BlockedSlotRepository;

final class BlockedSlotService
{
    public function __construct(
        private BlockedSlotRepository $repo,
        private Database $db,
        private AuditService $audit,
        private RequestContextHolder $contextHolder,
        private AvailabilityService $availability
    ) {
    }

    public function create(array $data): int
    {
        $captureDate = null;
        $captureBranchId = null;
        $id = $this->transactional(function () use ($data, &$captureDate, &$captureBranchId): int {
            $ctx = $this->contextHolder->requireContext();
            ['branch_id' => $branchId] = $ctx->requireResolvedTenant();
            $data['branch_id'] = $branchId;
            $date = trim((string) ($data['block_date'] ?? ''));
            if ($date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new \InvalidArgumentException('block_date must be YYYY-MM-DD.');
            }
            $start = trim((string) ($data['start_time'] ?? ''));
            $end = trim((string) ($data['end_time'] ?? ''));
            if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) !== 1) {
                throw new \InvalidArgumentException('start_time must be HH:MM.');
            }
            if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end) !== 1) {
                throw new \InvalidArgumentException('end_time must be HH:MM.');
            }
            $start = strlen($start) === 5 ? $start . ':00' : $start;
            $end = strlen($end) === 5 ? $end . ':00' : $end;
            if (strcmp($end, $start) <= 0) {
                throw new \InvalidArgumentException('end_time must be after start_time.');
            }
            $staffId = isset($data['staff_id']) && $data['staff_id'] !== '' ? (int) $data['staff_id'] : null;
            if ($staffId === null || $staffId <= 0) {
                throw new \InvalidArgumentException('staff_id is required.');
            }
            $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
                ? (int) $data['branch_id']
                : null;
            $staff = $this->availability->getActiveStaffForScope($staffId, $branchId);
            if (!$staff) {
                throw new \DomainException('Selected staff is not active.');
            }

            $payload = [
                'branch_id' => $data['branch_id'] ?? null,
                'staff_id' => $staffId,
                'title' => trim((string) ($data['title'] ?? '')) ?: 'Blocked',
                'block_date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'created_by' => $this->currentUserId(),
            ];
            $newId = $this->repo->create($payload);
            $this->audit->log('appointment_blocked_slot_created', 'appointment_blocked_slot', $newId, $this->currentUserId(), $payload['branch_id'], [
                'blocked_slot' => $payload,
            ]);
            $captureDate = $date;
            $captureBranchId = $branchId;
            return $newId;
        }, 'blocked slot create');
        // WAVE-06: invalidate calendar display cache after blocked slot creation.
        if ($captureDate !== null) {
            try {
                $this->availability->invalidateDayCalendarCache($captureDate, $captureBranchId);
            } catch (\Throwable) {}
        }
        return $id;
    }

    public function delete(int $id): void
    {
        $captureRow = null;
        $this->transactional(function () use ($id, &$captureRow): void {
            $ctx = $this->contextHolder->requireContext();
            $ctx->requireResolvedTenant();
            $row = $this->repo->loadOwned($ctx, $id);
            if (!$row) {
                throw new \RuntimeException('Blocked slot not found.');
            }
            $captureRow = $row;
            $this->repo->softDelete($id);
            $this->audit->log('appointment_blocked_slot_deleted', 'appointment_blocked_slot', $id, $this->currentUserId(), $row['branch_id'] !== null ? (int) $row['branch_id'] : null, [
                'blocked_slot' => $row,
            ]);
        }, 'blocked slot delete');
        // WAVE-06: invalidate calendar display cache after blocked slot deletion.
        if ($captureRow !== null) {
            try {
                $blkDate = (string) ($captureRow['block_date'] ?? '');
                $blkBranchId = isset($captureRow['branch_id']) && $captureRow['branch_id'] !== null ? (int) $captureRow['branch_id'] : null;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $blkDate) === 1) {
                    $this->availability->invalidateDayCalendarCache($blkDate, $blkBranchId);
                }
            } catch (\Throwable) {}
        }
    }

    private function currentUserId(): ?int
    {
        return Application::container()->get(\Core\Auth\SessionAuth::class)->id();
    }

    private function transactional(callable $callback, string $action): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
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
            slog('error', 'appointments.blocked_slot_transactional', $e->getMessage(), ['action' => $action]);
            if ($e instanceof \DomainException || $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) {
                throw $e;
            }
            throw new \DomainException('Blocked slot operation failed.');
        }
    }
}
