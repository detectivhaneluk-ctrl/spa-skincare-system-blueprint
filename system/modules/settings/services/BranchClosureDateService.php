<?php

declare(strict_types=1);

namespace Modules\Settings\Services;

use Core\App\Database;
use Core\Audit\AuditService;
use Core\Auth\SessionAuth;
use Modules\Settings\Repositories\BranchClosureDateRepository;

final class BranchClosureDateService
{
    public function __construct(
        private Database $db,
        private BranchClosureDateRepository $repo,
        private AuditService $audit,
        private SessionAuth $auth
    ) {
    }

    public function isStorageReady(): bool
    {
        return $this->repo->isTableAvailable();
    }

    /**
     * @return list<array{id:int,branch_id:int,closure_date:string,title:string,notes:?string,created_by:?int,created_at:?string,updated_at:?string}>
     */
    public function listForBranch(int $branchId): array
    {
        if ($branchId <= 0 || !$this->isStorageReady()) {
            return [];
        }

        return $this->repo->listByBranch($branchId);
    }

    /**
     * @param array<string,mixed> $input
     */
    public function createForBranch(int $branchId, array $input): int
    {
        return $this->db->transaction(function () use ($branchId, $input): int {
            $payload = $this->validateAndNormalizePayload($branchId, $input, null);
            $id = $this->repo->create($payload);
            $this->audit->log('branch_closure_date_created', 'branch_closure_date', $id, $this->currentUserId(), $branchId, [
                'closure_date' => $payload['closure_date'],
                'title' => $payload['title'],
            ]);

            return $id;
        });
    }

    /**
     * @param array<string,mixed> $input
     */
    public function updateForBranch(int $branchId, int $id, array $input): void
    {
        $this->db->transaction(function () use ($branchId, $id, $input): void {
            if ($id <= 0) {
                throw new \InvalidArgumentException('Invalid closure date record.');
            }
            $existing = $this->repo->findLiveById($id);
            if ($existing === null || (int) $existing['branch_id'] !== $branchId) {
                throw new \RuntimeException('Closure date record not found for active branch context.');
            }

            $payload = $this->validateAndNormalizePayload($branchId, $input, $id);
            $this->repo->updateLive($id, [
                'closure_date' => $payload['closure_date'],
                'title' => $payload['title'],
                'notes' => $payload['notes'],
            ]);
            $this->audit->log('branch_closure_date_updated', 'branch_closure_date', $id, $this->currentUserId(), $branchId, [
                'closure_date' => $payload['closure_date'],
                'title' => $payload['title'],
            ]);
        });
    }

    public function deleteForBranch(int $branchId, int $id): void
    {
        $this->db->transaction(function () use ($branchId, $id): void {
            if ($id <= 0) {
                throw new \InvalidArgumentException('Invalid closure date record.');
            }
            $existing = $this->repo->findLiveById($id);
            if ($existing === null || (int) $existing['branch_id'] !== $branchId) {
                throw new \RuntimeException('Closure date record not found for active branch context.');
            }

            $this->repo->softDeleteLive($id);
            $this->audit->log('branch_closure_date_deleted', 'branch_closure_date', $id, $this->currentUserId(), $branchId, [
                'closure_date' => (string) ($existing['closure_date'] ?? ''),
                'title' => (string) ($existing['title'] ?? ''),
            ]);
        });
    }

    /**
     * @param array<string,mixed> $input
     * @return array{branch_id:int,closure_date:string,title:string,notes:?string,created_by:?int}
     */
    private function validateAndNormalizePayload(int $branchId, array $input, ?int $excludeId): array
    {
        if (!$this->isStorageReady()) {
            throw new \RuntimeException('Closure Dates is not available yet because the required database migration has not been applied.');
        }
        if ($branchId <= 0) {
            throw new \RuntimeException('Closure Dates cannot be saved because no active branch context is available.');
        }

        $closureDate = trim((string) ($input['closure_date'] ?? ''));
        if ($closureDate === '') {
            throw new \InvalidArgumentException('Closure date is required.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $closureDate) !== 1) {
            throw new \InvalidArgumentException('Closure date must be in YYYY-MM-DD format.');
        }
        $parts = explode('-', $closureDate);
        if (count($parts) !== 3 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            throw new \InvalidArgumentException('Closure date must be a valid calendar date.');
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Title is required.');
        }
        if (mb_strlen($title) > 150) {
            throw new \InvalidArgumentException('Title must be 150 characters or fewer.');
        }

        $notesRaw = trim((string) ($input['notes'] ?? ''));
        $notes = $notesRaw !== '' ? $notesRaw : null;

        if ($this->repo->existsLiveDateForBranch($branchId, $closureDate, $excludeId)) {
            throw new \InvalidArgumentException('A closure date for this branch and date already exists.');
        }

        return [
            'branch_id' => $branchId,
            'closure_date' => $closureDate,
            'title' => $title,
            'notes' => $notes,
            'created_by' => $this->currentUserId(),
        ];
    }

    private function currentUserId(): ?int
    {
        $id = $this->auth->id();

        return $id !== null ? (int) $id : null;
    }
}
