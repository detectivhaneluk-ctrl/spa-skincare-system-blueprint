<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Core\App\ApplicationTimezone;
use Modules\Appointments\Repositories\BlockedSlotRepository;
use Modules\Settings\Services\BranchClosureDateService;
use Modules\Settings\Services\BranchOperatingHoursService;

/**
 * Backend-driven month navigator payload for the appointments day calendar control plane.
 * Reuses the same appointment overlap semantics as {@see AvailabilityService::listDayAppointmentsGroupedByStaff()}.
 */
final class CalendarMonthSummaryService
{
    public function __construct(
        private AvailabilityService $availability,
        private BlockedSlotRepository $blockedSlotRepo,
        private BranchOperatingHoursService $branchOperatingHours,
        private BranchClosureDateService $branchClosureDates,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function contractEnvelope(): array
    {
        return [
            'month_summary_contract' => [
                'name' => 'spa.calendar_month_summary',
                'version' => 1,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(int $branchId, int $year, int $month, string $selectedDate, string $todayDate): array
    {
        if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Invalid calendar month.');
        }

        $first = sprintf('%04d-%02d-01', $year, $month);
        try {
            $last = (new \DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid calendar month.');
        }

        $dates = [];
        try {
            $cur = new \DateTimeImmutable($first . ' 00:00:00');
            $end = new \DateTimeImmutable($last . ' 00:00:00');
            while ($cur <= $end) {
                $dates[] = $cur->format('Y-m-d');
                $cur = $cur->modify('+1 day');
            }
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Invalid calendar month.');
        }

        $closureSet = [];
        if ($this->branchClosureDates->isStorageReady()) {
            foreach ($this->branchClosureDates->listForBranch($branchId) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cd = (string) ($row['closure_date'] ?? '');
                if ($cd >= $first && $cd <= $last) {
                    $closureSet[$cd] = true;
                }
            }
        }

        $hoursBatch = $this->branchOperatingHours->getDayHoursMetaBatch($branchId, $dates);
        $apptByDay = $this->availability->countOverlappingAppointmentsPerDayInRange($branchId, $first, $last);
        $blockedByDay = $this->blockedSlotRepo->countByBlockDateInRange($first, $last, $branchId);

        $days = [];
        foreach ($dates as $d) {
            $h = $hoursBatch[$d] ?? [
                'branch_hours_available' => false,
                'is_closed_day' => false,
                'is_configured_day' => false,
                'open_time' => null,
                'close_time' => null,
                'weekday' => 0,
            ];
            $closureActive = isset($closureSet[$d]);
            $hoursClosed = !empty($h['branch_hours_available']) && !empty($h['is_closed_day']);
            $branchClosed = $closureActive || $hoursClosed;

            $apptCount = (int) ($apptByDay[$d] ?? 0);
            $blockedCount = (int) ($blockedByDay[$d] ?? 0);

            $busyLevel = 'quiet';
            if ($apptCount >= 8) {
                $busyLevel = 'heavy';
            } elseif ($apptCount >= 3) {
                $busyLevel = 'steady';
            }

            $days[] = [
                'date' => $d,
                'in_visible_month' => true,
                'is_today' => $d === $todayDate,
                'is_past' => $d < $todayDate,
                'is_future' => $d > $todayDate,
                'branch_hours_available' => (bool) ($h['branch_hours_available'] ?? false),
                'branch_closed' => $branchClosed,
                'closure_active' => $closureActive,
                'appointment_count' => $apptCount,
                'blocked_slot_count' => $blockedCount,
                'has_blocked' => $blockedCount > 0,
                'busy_level' => $busyLevel,
            ];
        }

        $selectedMeta = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) === 1) {
            foreach ($days as $entry) {
                if (($entry['date'] ?? '') === $selectedDate) {
                    $selectedMeta = $entry;
                    break;
                }
            }
        }

        return array_merge($this->contractEnvelope(), [
            'branch_id' => $branchId,
            'branch_timezone' => ApplicationTimezone::getAppliedIdentifier() ?? 'UTC',
            'today_date' => $todayDate,
            'selected_date' => $selectedDate,
            'month' => [
                'year' => $year,
                'month' => $month,
                'first_date' => $first,
                'last_date' => $last,
                'day_count' => count($dates),
            ],
            'days' => $days,
            'selected_day' => $selectedMeta,
        ]);
    }
}
