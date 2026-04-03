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

    /**
     * Replaces the full weekly schedule for an employee from the onboarding Step 4 form.
     *
     * $rawDays is keyed by day_of_week (0=Sun..6=Sat). Only days with is_working set are
     * persisted as rows; off-days produce no row (no row = employee not working that day).
     *
     * @param array<int,array{is_working?:mixed,start_time?:string,end_time?:string,lunch_start_time?:string,lunch_end_time?:string}> $rawDays
     * @throws \InvalidArgumentException on invalid time values
     */
    public function saveDefaultWeek(int $staffId, array $rawDays): void
    {
        $this->assertStaffAccess($staffId);
        $days = [];
        foreach ($rawDays as $dowRaw => $day) {
            $dow = (int) $dowRaw;
            if ($dow < 0 || $dow > 6) {
                continue;
            }
            if (empty($day['is_working'])) {
                continue; // off day — no row
            }
            $start = $this->normalizeTime($day['start_time'] ?? null);
            $end   = $this->normalizeTime($day['end_time'] ?? null);
            if ($start === null) {
                throw new \InvalidArgumentException(self::dayName($dow) . ': Start time is required for working days.');
            }
            if ($end === null) {
                throw new \InvalidArgumentException(self::dayName($dow) . ': End time is required for working days.');
            }
            if (strcmp($end, $start) <= 0) {
                throw new \InvalidArgumentException(self::dayName($dow) . ': End time must be after start time.');
            }
            $entry = ['day_of_week' => $dow, 'start_time' => $start, 'end_time' => $end];

            $lunchStart = $this->normalizeTime($day['lunch_start_time'] ?? null);
            $lunchEnd   = $this->normalizeTime($day['lunch_end_time'] ?? null);
            if ($lunchStart !== null || $lunchEnd !== null) {
                if ($lunchStart === null) {
                    throw new \InvalidArgumentException(self::dayName($dow) . ': Lunch start is required when lunch end is set.');
                }
                if ($lunchEnd === null) {
                    throw new \InvalidArgumentException(self::dayName($dow) . ': Lunch end is required when lunch start is set.');
                }
                if (strcmp($lunchEnd, $lunchStart) <= 0) {
                    throw new \InvalidArgumentException(self::dayName($dow) . ': Lunch end must be after lunch start.');
                }
                if (strcmp($lunchStart, $start) < 0 || strcmp($lunchEnd, $end) > 0) {
                    throw new \InvalidArgumentException(self::dayName($dow) . ': Lunch must fall within work hours.');
                }
                $entry['lunch_start_time'] = $lunchStart;
                $entry['lunch_end_time']   = $lunchEnd;
            }
            $days[] = $entry;
        }
        $this->repo->replaceWeekForStaff($staffId, $days);
    }

    private function normalizeTime(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $t = trim($raw);
        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $t) !== 1) {
            return null;
        }
        if (strlen($t) === 5) {
            $t .= ':00';
        }
        return $t;
    }

    private static function dayName(int $dow): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$dow] ?? "Day {$dow}";
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
