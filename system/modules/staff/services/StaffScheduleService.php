<?php

declare(strict_types=1);

namespace Modules\Staff\Services;

use Core\App\Application;
use Core\Branch\BranchContext;
use Modules\Staff\Repositories\StaffRepository;
use Modules\Staff\Repositories\StaffScheduleRepository;

final class StaffScheduleService
{
    public function __construct(
        private StaffScheduleRepository $repo,
        private StaffRepository $staffRepo,
        private BranchContext $branchContext
    ) {
    }

    public function create(int $staffId, array $data): int
    {
        $this->assertStaffAccess($staffId);
        $payload = $this->validateAndNormalize($data);
        $payload['staff_id'] = $staffId;
        return $this->repo->create($payload);
    }

    public function update(int $id, array $data): void
    {
        $row = $this->repo->find($id);
        if (!$row) {
            throw new \RuntimeException('Schedule entry not found.');
        }
        $this->assertStaffAccess((int) $row['staff_id']);
        $payload = $this->validateAndNormalize($data);
        $this->repo->update($id, $payload);
    }

    public function delete(int $id): void
    {
        $row = $this->repo->find($id);
        if (!$row) {
            throw new \RuntimeException('Schedule entry not found.');
        }
        $this->assertStaffAccess((int) $row['staff_id']);
        $this->repo->delete($id);
    }

    private function assertStaffAccess(int $staffId): void
    {
        $staff = $this->staffRepo->find($staffId);
        if (!$staff) {
            throw new \RuntimeException('Staff not found.');
        }
        $branchId = isset($staff['branch_id']) && $staff['branch_id'] !== null && $staff['branch_id'] !== ''
            ? (int) $staff['branch_id'] : null;
        $this->branchContext->assertBranchMatchOrGlobalEntity($branchId);
    }

    /**
     * @return array{day_of_week: int, start_time: string, end_time: string}
     */
    private function validateAndNormalize(array $data): array
    {
        $dayOfWeek = isset($data['day_of_week']) ? (int) $data['day_of_week'] : 0;
        if ($dayOfWeek < 0 || $dayOfWeek > 6) {
            throw new \InvalidArgumentException('day_of_week must be 0–6 (Sun–Sat).');
        }
        $start = trim((string) ($data['start_time'] ?? ''));
        $end = trim((string) ($data['end_time'] ?? ''));
        if ($start === '' || preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $start) !== 1) {
            throw new \InvalidArgumentException('start_time must be HH:MM or HH:MM:SS.');
        }
        if ($end === '' || preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $end) !== 1) {
            throw new \InvalidArgumentException('end_time must be HH:MM or HH:MM:SS.');
        }
        if (strlen($start) === 5) {
            $start .= ':00';
        }
        if (strlen($end) === 5) {
            $end .= ':00';
        }
        if (strcmp($end, $start) <= 0) {
            throw new \InvalidArgumentException('end_time must be after start_time.');
        }
        return [
            'day_of_week' => $dayOfWeek,
            'start_time' => $start,
            'end_time' => $end,
        ];
    }
}
