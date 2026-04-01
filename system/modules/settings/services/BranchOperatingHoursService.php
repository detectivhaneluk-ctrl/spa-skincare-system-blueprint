<?php

declare(strict_types=1);

namespace Modules\Settings\Services;

use Core\App\Database;
use Core\Kernel\Authorization\AuthorizerInterface;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;
use Core\Kernel\RequestContextHolder;
use Modules\Settings\Repositories\BranchOperatingHoursRepository;

final class BranchOperatingHoursService
{
    private const DAY_LABELS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    public function __construct(
        private Database $db,
        private BranchOperatingHoursRepository $repo,
        private RequestContextHolder $contextHolder,
        private AuthorizerInterface $authorizer,
    ) {
    }

    public function isStorageReady(): bool
    {
        return $this->repo->isTableAvailable();
    }

    /**
     * @return array<int,array{start_time:string,end_time:string}>
     */
    public function getWeeklyMapForBranch(int $branchId): array
    {
        $map = [];
        for ($d = 0; $d <= 6; $d++) {
            $map[$d] = ['start_time' => '', 'end_time' => ''];
        }
        foreach ($this->repo->listByBranch($branchId) as $row) {
            $d = (int) $row['day_of_week'];
            if ($d < 0 || $d > 6) {
                continue;
            }
            $map[$d] = [
                'start_time' => $this->displayTime($row['start_time']),
                'end_time' => $this->displayTime($row['end_time']),
            ];
        }

        return $map;
    }

    /**
     * @param array<mixed> $submitted
     * @return array<int,array{start_time:string,end_time:string}>
     */
    public function mergeSubmittedMap(array $baseMap, array $submitted): array
    {
        $merged = $baseMap;
        for ($d = 0; $d <= 6; $d++) {
            $row = is_array($submitted[(string) $d] ?? null)
                ? $submitted[(string) $d]
                : (is_array($submitted[$d] ?? null) ? $submitted[$d] : []);
            if ($row === []) {
                continue;
            }
            $merged[$d] = [
                'start_time' => trim((string) ($row['start_time'] ?? '')),
                'end_time' => trim((string) ($row['end_time'] ?? '')),
            ];
        }

        return $merged;
    }

    /**
     * @param array<mixed> $rawInput
     * @return array<int,array{start_time:?string,end_time:?string}>
     */
    public function saveWeeklyMapForBranch(int $branchId, array $rawInput): array
    {
        if (!$this->isStorageReady()) {
            throw new \RuntimeException('Opening Hours is not available yet because the required database migration has not been applied.');
        }
        $ctx = $this->contextHolder->requireContext();
        $ctx->requireResolvedTenant();
        $this->authorizer->requireAuthorized($ctx, ResourceAction::BRANCH_SETTINGS_MANAGE, ResourceRef::collection('branch-settings'));
        $normalized = $this->validateAndNormalize($rawInput);
        $this->db->transaction(function () use ($branchId, $normalized): void {
            $this->repo->replaceWeeklyMap($branchId, $normalized);
        });

        return $normalized;
    }

    /**
     * @param array<int,array{start_time:string,end_time:string}> $map
     */
    public function formatSummary(array $map): string
    {
        $segments = [];
        foreach (self::DAY_LABELS as $day => $label) {
            $row = $map[$day] ?? ['start_time' => '', 'end_time' => ''];
            $start = trim((string) ($row['start_time'] ?? ''));
            $end = trim((string) ($row['end_time'] ?? ''));
            $segments[] = $label . ': ' . (($start === '' && $end === '') ? 'Closed' : ($start . '-' . $end));
        }

        return implode(' | ', $segments);
    }

    /**
     * @return array<int,string>
     */
    public function dayLabels(): array
    {
        return self::DAY_LABELS;
    }

    /**
     * @return array{
     *   branch_hours_available: bool,
     *   is_closed_day: bool,
     *   is_configured_day: bool,
     *   open_time: ?string,
     *   close_time: ?string,
     *   weekday: int
     * }
     */
    public function getDayHoursMeta(?int $branchId, string $date): array
    {
        $dateTs = strtotime($date);
        $weekday = (int) date('w', $dateTs !== false ? $dateTs : time());
        if (!$this->isStorageReady() || $branchId === null || $branchId <= 0) {
            return [
                'branch_hours_available' => false,
                'is_closed_day' => false,
                'is_configured_day' => false,
                'open_time' => null,
                'close_time' => null,
                'weekday' => $weekday,
            ];
        }

        $row = $this->repo->findByBranchAndDay($branchId, $weekday);
        if ($row === null) {
            return [
                'branch_hours_available' => true,
                'is_closed_day' => false,
                'is_configured_day' => false,
                'open_time' => null,
                'close_time' => null,
                'weekday' => $weekday,
            ];
        }

        $start = $this->displayTime($row['start_time']);
        $end = $this->displayTime($row['end_time']);
        $isClosedDay = ($start === '' && $end === '');

        return [
            'branch_hours_available' => true,
            'is_closed_day' => $isClosedDay,
            'is_configured_day' => true,
            'open_time' => $isClosedDay ? null : $start,
            'close_time' => $isClosedDay ? null : $end,
            'weekday' => $weekday,
        ];
    }

    /**
     * @param array<mixed> $input
     * @return array<int,array{start_time:?string,end_time:?string}>
     */
    private function validateAndNormalize(array $input): array
    {
        $out = [];
        foreach (self::DAY_LABELS as $day => $label) {
            $row = is_array($input[(string) $day] ?? null)
                ? $input[(string) $day]
                : (is_array($input[$day] ?? null) ? $input[$day] : []);
            $startRaw = trim((string) ($row['start_time'] ?? ''));
            $endRaw = trim((string) ($row['end_time'] ?? ''));
            if ($startRaw === '' && $endRaw === '') {
                $out[$day] = ['start_time' => null, 'end_time' => null];
                continue;
            }
            if ($startRaw === '' || $endRaw === '') {
                throw new \InvalidArgumentException($label . ': opening and closing time are both required, or leave both blank.');
            }
            $start = $this->normalizeTime($startRaw, $label, 'opening');
            $end = $this->normalizeTime($endRaw, $label, 'closing');
            if (strcmp($end, $start) <= 0) {
                throw new \InvalidArgumentException($label . ': closing time must be after opening time.');
            }
            $out[$day] = ['start_time' => $start, 'end_time' => $end];
        }

        return $out;
    }

    private function normalizeTime(string $raw, string $dayLabel, string $kind): string
    {
        if (preg_match('/^\d{2}:\d{2}$/', $raw) !== 1 && preg_match('/^\d{2}:\d{2}:\d{2}$/', $raw) !== 1) {
            throw new \InvalidArgumentException($dayLabel . ': invalid ' . $kind . ' time format.');
        }
        if (strlen($raw) === 5) {
            return $raw . ':00';
        }

        return substr($raw, 0, 8);
    }

    private function displayTime(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return substr($value, 0, 5);
    }
}
