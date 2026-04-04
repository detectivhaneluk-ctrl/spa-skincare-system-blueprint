<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Core\Branch\BranchDirectory;

/**
 * Read-only day snapshot for printable HTML (no JSON contract).
 */
final class DayCalendarPrintService
{
    public function __construct(
        private AvailabilityService $availability,
        private BranchDirectory $branches,
        private AppointmentService $appointmentService,
    ) {
    }

    /**
     * @return array{
     *   date:string,
     *   branch_id:int,
     *   branch_name:string,
     *   staff:list<array<string,mixed>>,
     *   appointments_by_staff:array<string,list<array<string,mixed>>>,
     *   blocked_by_staff:array<string,list<array<string,mixed>>>
     * }
     */
    public function buildDaySnapshot(string $date, int $branchId): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = date('Y-m-d');
        }
        $staff = $this->availability->listActiveStaff($branchId);
        $appointmentsByStaff = $this->availability->listDayAppointmentsGroupedByStaff($date, $branchId);
        $blockedByStaff = $this->availability->listDayBlockedSlotsGroupedByStaff($date, $branchId);

        $branchName = 'Branch #' . $branchId;
        try {
            $b = $this->branches->getBranchByIdForAdmin($branchId);
            if ($b !== null && empty($b['deleted_at'])) {
                $branchName = (string) ($b['name'] ?? $branchName);
            }
        } catch (\Throwable) {
        }

        return [
            'date' => $date,
            'branch_id' => $branchId,
            'branch_name' => $branchName,
            'staff' => $staff,
            'appointments_by_staff' => $appointmentsByStaff,
            'blocked_by_staff' => $blockedByStaff,
        ];
    }

    /**
     * Flat rows sorted by start_at for "appointments list" print.
     *
     * @param array<string, list<array<string, mixed>>> $grouped
     * @return list<array<string, mixed>>
     */
    public function flattenAppointmentsSorted(array $grouped): array
    {
        $rows = [];
        foreach ($grouped as $staffId => $list) {
            foreach ($list as $a) {
                $a['_print_staff_id'] = (int) $staffId;
                $rows[] = $a;
            }
        }
        usort($rows, static function (array $x, array $y): int {
            return strcmp((string) ($x['start_at'] ?? ''), (string) ($y['start_at'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $flat
     * @return array<string, list<array<string, mixed>>>
     */
    public function groupFlatByClient(array $flat): array
    {
        $out = [];
        foreach ($flat as $a) {
            $cid = isset($a['client_id']) ? (int) $a['client_id'] : 0;
            $key = $cid > 0 ? (string) $cid : 'guest';
            if (!isset($out[$key])) {
                $out[$key] = [];
            }
            $out[$key][] = $a;
        }

        return $out;
    }

    public function formatAppointmentLine(array $a): string
    {
        $t0 = isset($a['start_at']) ? substr((string) $a['start_at'], 11, 5) : '';
        $t1 = isset($a['end_at']) ? substr((string) $a['end_at'], 11, 5) : '';
        $client = trim((string) ($a['client_name'] ?? ''));
        $svc = (string) ($a['service_name'] ?? '');
        $st = (string) ($a['status'] ?? '');
        $stLabel = $this->appointmentService->formatStatusLabel($st);

        return $t0 . '–' . $t1 . ' · ' . ($client !== '' ? $client : '—') . ' · ' . ($svc !== '' ? $svc : '—') . ' · ' . $stLabel;
    }
}
