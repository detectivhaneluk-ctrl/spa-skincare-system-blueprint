<?php

declare(strict_types=1);

namespace Modules\Appointments\Services;

use Core\App\Database;
use Core\App\SettingsService;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Appointments\Repositories\BlockedSlotRepository;
use Modules\Settings\Services\BranchOperatingHoursService;
use Modules\Staff\Repositories\StaffAvailabilityExceptionRepository;
use Modules\Staff\Repositories\StaffBreakRepository;
use Modules\ServicesResources\Services\ServiceStaffGroupEligibilityService;
use Modules\Staff\Repositories\StaffScheduleRepository;
use Modules\Staff\Services\StaffGroupService;

final class AvailabilityService
{
    private const SLOT_MINUTES = 30;
    private const BLOCKING_STATUSES = ['scheduled', 'confirmed', 'in_progress', 'completed'];

    public function __construct(
        private Database $db,
        private BlockedSlotRepository $blockedSlots,
        private StaffScheduleRepository $staffSchedules,
        private StaffBreakRepository $staffBreaks,
        private StaffAvailabilityExceptionRepository $staffAvailabilityExceptions,
        private StaffGroupService $staffGroupService,
        private ServiceStaffGroupEligibilityService $serviceStaffGroupEligibility,
        private BranchOperatingHoursService $branchOperatingHours,
        private SettingsService $settings,
        private AppointmentRepository $appointments,
        private OrganizationRepositoryScope $orgScope,
    ) {
    }

    /**
     * Availability search: union of bookable start times in HH:ii.
     *
     * - {@code $slotQueryAudience}: {@code public} = anonymous/public channel (strict staff off-days). {@code internal} = staff-operated UI;
     *   when {@code appointments.allow_staff_booking_on_off_days} is true, internal search can surface slots on staff off-days (no weekly
     *   intervals / empty schedule) except full-day {@code closed} exceptions — public audience never does.
     * - {@code appointments.check_staff_availability_in_search}: when false, branch envelope only for iteration; search {@see isSlotAvailable} flag applies.
     * - {@code appointments.allow_staff_concurrency}: when true, internal search/write skips buffered staff appointment overlap only; public audience unchanged.
     * - Final booking uses {@see isSlotAvailable} with explicit public vs internal channel — public always strict off-days and always enforces staff overlap.
     *
     * @param 'public'|'internal' $slotQueryAudience
     * @param ?int $roomIdForOccupancy When {@code $slotQueryAudience} is {@code internal} and this is a positive room id,
     *        candidate slots are filtered by {@see AppointmentRepository::hasRoomConflict} unless {@code appointments.allow_room_overbooking}
     *        is true for the branch ({@see SettingsService::shouldEnforceAppointmentRoomExclusivity}).
     *        Ignored for {@code public} audience. Omit or null to preserve legacy slot lists (no room occupancy filter).
     * @return array<int, string> Time slots in HH:ii format.
     */
    public function getAvailableSlots(
        int $serviceId,
        string $date,
        ?int $staffId = null,
        ?int $branchId = null,
        string $slotQueryAudience = 'public',
        ?int $roomIdForOccupancy = null,
    ): array {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return [];
        }
        $service = $this->getActiveService($serviceId);
        if (!$service) {
            return [];
        }
        $duration = max(1, (int) ($service['duration_minutes'] ?? 0));
        if ($duration <= 0) {
            return [];
        }

        $scopeBranchId = $branchId ?? ($service['branch_id'] !== null ? (int) $service['branch_id'] : null);
        $branchHoursMeta = $this->getBranchOperatingHoursMeta($scopeBranchId, $date);
        if (($branchHoursMeta['is_open_for_slots'] ?? false) !== true) {
            return [];
        }
        $branchOpen = (string) ($branchHoursMeta['open_time'] ?? '');
        $branchClose = (string) ($branchHoursMeta['close_time'] ?? '');
        $staffRows = $this->getEligibleStaff($serviceId, $scopeBranchId, $staffId);
        if (empty($staffRows)) {
            return [];
        }

        $apt = $this->settings->getAppointmentSettings($scopeBranchId);
        $checkStaffScheduleInSearch = !empty($apt['check_staff_availability_in_search']);
        $allowOffDayInternalSearch = $slotQueryAudience === 'internal' && !empty($apt['allow_staff_booking_on_off_days']);
        $forPublicChannel = $slotQueryAudience === 'public';
        $roomForInternalSlotSearch = ($slotQueryAudience === 'internal' && $roomIdForOccupancy !== null && $roomIdForOccupancy > 0)
            ? $roomIdForOccupancy
            : null;

        $union = [];
        foreach ($staffRows as $staff) {
            $sid = (int) $staff['id'];
            if ($checkStaffScheduleInSearch) {
                $intervals = $this->getWorkingIntervals($sid, $date, $scopeBranchId);
                $intervals = $this->intersectIntervalsWithEnvelope($intervals, $branchOpen, $branchClose);
                if (
                    $intervals === []
                    && $allowOffDayInternalSearch
                    && !$this->staffHasClosedExceptionForDate($sid, $date, $scopeBranchId)
                ) {
                    $openSeg = strlen($branchOpen) === 5 ? $branchOpen . ':00' : substr($branchOpen, 0, 8);
                    $closeSeg = strlen($branchClose) === 5 ? $branchClose . ':00' : substr($branchClose, 0, 8);
                    $intervals = $this->intersectIntervalsWithEnvelope(
                        [['start' => $openSeg, 'end' => $closeSeg]],
                        $branchOpen,
                        $branchClose
                    );
                }
            } else {
                $openSeg = strlen($branchOpen) === 5 ? $branchOpen . ':00' : substr($branchOpen, 0, 8);
                $closeSeg = strlen($branchClose) === 5 ? $branchClose . ':00' : substr($branchClose, 0, 8);
                $intervals = $this->intersectIntervalsWithEnvelope(
                    [['start' => $openSeg, 'end' => $closeSeg]],
                    $branchOpen,
                    $branchClose
                );
            }
            if (empty($intervals)) {
                continue;
            }
            foreach ($intervals as $interval) {
                $cursor = strtotime($date . ' ' . $interval['start']);
                $intervalEnd = strtotime($date . ' ' . $interval['end']);
                while ($cursor !== false && $intervalEnd !== false && ($cursor + $duration * 60) <= $intervalEnd) {
                    $candidateStartAt = date('Y-m-d H:i:s', $cursor);
                    if ($this->isSlotAvailable($serviceId, $sid, $candidateStartAt, null, $scopeBranchId, true, $forPublicChannel, $roomForInternalSlotSearch)) {
                        $union[date('H:i', $cursor)] = true;
                    }
                    $cursor = strtotime('+' . self::SLOT_MINUTES . ' minutes', $cursor);
                }
            }
        }

        $result = array_keys($union);
        sort($result);
        return $result;
    }

    /**
     * @return array{
     *   branch_hours_available: bool,
     *   is_closed_day: bool,
     *   is_configured_day: bool,
     *   is_open_for_slots: bool,
     *   open_time: ?string,
     *   close_time: ?string,
     *   message: ?string
     * }
     */
    public function getBranchOperatingHoursMeta(?int $branchId, string $date): array
    {
        $meta = $this->branchOperatingHours->getDayHoursMeta($branchId, $date);
        $available = (bool) ($meta['branch_hours_available'] ?? false);
        $configured = (bool) ($meta['is_configured_day'] ?? false);
        $closed = (bool) ($meta['is_closed_day'] ?? false);
        $open = isset($meta['open_time']) ? trim((string) $meta['open_time']) : '';
        $close = isset($meta['close_time']) ? trim((string) $meta['close_time']) : '';

        if (!$available || !$configured) {
            return [
                'branch_hours_available' => $available,
                'is_closed_day' => false,
                'is_configured_day' => false,
                'is_open_for_slots' => false,
                'open_time' => null,
                'close_time' => null,
                'message' => 'Opening hours are not configured for this branch on the selected day.',
            ];
        }
        if ($closed || $open === '' || $close === '') {
            return [
                'branch_hours_available' => true,
                'is_closed_day' => true,
                'is_configured_day' => true,
                'is_open_for_slots' => false,
                'open_time' => null,
                'close_time' => null,
                'message' => 'This branch is closed on the selected day.',
            ];
        }

        return [
            'branch_hours_available' => true,
            'is_closed_day' => false,
            'is_configured_day' => true,
            'is_open_for_slots' => true,
            'open_time' => $open,
            'close_time' => $close,
            'message' => null,
        ];
    }

    /**
     * @param bool $forAvailabilitySearch When true and {@code appointments.check_staff_availability_in_search} is false for the branch,
     *        staff schedule / breaks / blocked-slot checks are skipped; overlapping appointments (buffers) still apply unless internal
     *        {@code appointments.allow_staff_concurrency} is true. Booking paths must pass false.
     * @param bool $forPublicBookingChannel When true (public online booking / public slot search), staff off-day bypass is never applied;
     *        staff appointment overlap (buffers) is always enforced regardless of {@code appointments.allow_staff_concurrency}.
     * @param ?int $roomIdForOccupancyInSearch When non-null with {@code $forAvailabilitySearch} true and {@code $forPublicBookingChannel} false,
     *        rejects the candidate if {@see AppointmentRepository::hasRoomConflict} is true (canonical SQL; statuses
     *        {@see AppointmentRepository::EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES} excluded; raw interval overlap; no room buffers).
     */
    public function isSlotAvailable(
        int $serviceId,
        int $staffId,
        string $startAt,
        ?int $excludeAppointmentId = null,
        ?int $branchId = null,
        bool $forAvailabilitySearch = false,
        bool $forPublicBookingChannel = false,
        ?int $roomIdForOccupancyInSearch = null,
    ): bool {
        $timing = $this->getServiceTiming($serviceId);
        if ($timing === null) {
            return false;
        }
        $scopeBranchId = $branchId ?? $timing['branch_id'];
        if (!$this->serviceStaffGroupEligibility->isStaffAllowedForService($serviceId, $staffId, $scopeBranchId)) {
            return false;
        }
        $duration = max(1, $timing['duration_minutes']);
        $startTs = strtotime($startAt);
        if ($startTs === false) {
            return false;
        }
        $endAt = date('Y-m-d H:i:s', $startTs + $duration * 60);
        $enforceStaffSchedule = true;
        if ($forAvailabilitySearch) {
            $enforceStaffSchedule = !empty(
                $this->settings->getAppointmentSettings($scopeBranchId)['check_staff_availability_in_search']
            );
        }

        if (!$this->isStaffWindowAvailable(
            $staffId,
            $startAt,
            $endAt,
            $scopeBranchId,
            $excludeAppointmentId,
            $timing['buffer_before_minutes'],
            $timing['buffer_after_minutes'],
            $enforceStaffSchedule,
            $forPublicBookingChannel
        )) {
            return false;
        }

        if (
            $forAvailabilitySearch
            && !$forPublicBookingChannel
            && $roomIdForOccupancyInSearch !== null
            && $roomIdForOccupancyInSearch > 0
            && $this->settings->shouldEnforceAppointmentRoomExclusivity($scopeBranchId)
        ) {
            $exId = $excludeAppointmentId ?? 0;
            if ($this->appointments->hasRoomConflict(
                $roomIdForOccupancyInSearch,
                $startAt,
                $endAt,
                $scopeBranchId,
                $exId
            )) {
                return false;
            }
        }

        return true;
    }

    public function getServiceDurationMinutes(int $serviceId): int
    {
        $timing = $this->getServiceTiming($serviceId);
        return $timing ? max(1, $timing['duration_minutes']) : 0;
    }

    /**
     * @return array{duration_minutes:int,buffer_before_minutes:int,buffer_after_minutes:int,branch_id:int|null}|null
     */
    public function getServiceTiming(int $serviceId): ?array
    {
        $service = $this->db->fetchOne(
            'SELECT id, duration_minutes, buffer_before_minutes, buffer_after_minutes, branch_id
             FROM services
             WHERE id = ? AND deleted_at IS NULL AND is_active = 1',
            [$serviceId]
        );
        if (!$service) {
            return null;
        }
        return [
            'duration_minutes' => max(1, (int) ($service['duration_minutes'] ?? 0)),
            'buffer_before_minutes' => max(0, (int) ($service['buffer_before_minutes'] ?? 0)),
            'buffer_after_minutes' => max(0, (int) ($service['buffer_after_minutes'] ?? 0)),
            'branch_id' => $service['branch_id'] !== null ? (int) $service['branch_id'] : null,
        ];
    }

    /**
     * @param bool $enforceStaffScheduleConstraints When false, skips working-hours schedule, breaks, and staff blocked-slot intervals;
     *        still enforces staff active/branch match, same-calendar-day buffered window, and overlapping appointment conflicts.
     * @param bool $forPublicBookingChannel When true, never apply {@code appointments.allow_staff_booking_on_off_days} synthetic branch-day intervals.
     *        When true, {@code appointments.allow_staff_concurrency} is ignored — buffered staff appointment overlap is always enforced for public channel.
     */
    public function isStaffWindowAvailable(
        int $staffId,
        string $startAt,
        string $endAt,
        ?int $branchId = null,
        ?int $excludeAppointmentId = null,
        int $bufferBeforeMinutes = 0,
        int $bufferAfterMinutes = 0,
        bool $enforceStaffScheduleConstraints = true,
        bool $forPublicBookingChannel = false
    ): bool {
        $startTs = strtotime($startAt);
        $endTs = strtotime($endAt);
        if ($startTs === false || $endTs === false || $endTs <= $startTs) {
            return false;
        }

        $staff = $this->getActiveStaff($staffId);
        if (!$staff) {
            return false;
        }
        if ($branchId !== null && $staff['branch_id'] !== null && (int) $staff['branch_id'] !== $branchId) {
            return false;
        }

        $windowStartTs = $startTs - (max(0, $bufferBeforeMinutes) * 60);
        $windowEndTs = $endTs + (max(0, $bufferAfterMinutes) * 60);
        $windowStart = date('Y-m-d H:i:s', $windowStartTs);
        $windowEnd = date('Y-m-d H:i:s', $windowEndTs);
        $date = date('Y-m-d', $startTs);
        if (date('Y-m-d', $windowStartTs) !== $date || date('Y-m-d', $windowEndTs) !== $date) {
            return false;
        }

        $windowStartTime = date('H:i:s', $windowStartTs);
        $windowEndTime = date('H:i:s', $windowEndTs);
        if ($enforceStaffScheduleConstraints) {
            $intervals = $this->getWorkingIntervals($staffId, $date, $branchId);
            if (
                $intervals === []
                && !$forPublicBookingChannel
                && $this->internalStaffOffDayBypassEnabledForBranch($branchId)
                && !$this->staffHasClosedExceptionForDate($staffId, $date, $branchId)
            ) {
                $intervals = $this->branchOpenCloseIntervalsForDate($branchId, $date);
            }
            if (!$this->isWithinAnyInterval($windowStartTime, $windowEndTime, $intervals)) {
                return false;
            }

            $breaks = $this->getBreakIntervals($staffId, $date);
            if ($this->overlapsAny($windowStartTime, $windowEndTime, $breaks)) {
                return false;
            }
            $blocked = $this->getBlockedIntervals($staffId, $date, $branchId);
            if ($this->overlapsAny($windowStartTime, $windowEndTime, $blocked)) {
                return false;
            }
        }

        if (
            $this->settings->shouldEnforceBufferedStaffAppointmentOverlap($branchId, $forPublicBookingChannel)
            && $this->hasBufferedAppointmentConflict($staffId, $windowStart, $windowEnd, $excludeAppointmentId)
        ) {
            return false;
        }

        return true;
    }

    public function getActiveServiceForScope(int $serviceId, ?int $branchId = null): ?array
    {
        $service = $this->getActiveService($serviceId);
        if (!$service) {
            return null;
        }
        $serviceBranch = $service['branch_id'] !== null ? (int) $service['branch_id'] : null;
        if ($branchId !== null && $serviceBranch !== null && $serviceBranch !== $branchId) {
            return null;
        }
        return [
            'id' => (int) $service['id'],
            'duration_minutes' => (int) $service['duration_minutes'],
            'branch_id' => $serviceBranch,
        ];
    }

    public function getActiveStaffForScope(int $staffId, ?int $branchId = null, ?int $serviceId = null): ?array
    {
        $staff = $this->getActiveStaff($staffId);
        if (!$staff) {
            return null;
        }
        if (!$this->staffGroupService->isStaffInScopeForBranch($staffId, $branchId)) {
            return null;
        }
        $staffBranch = $staff['branch_id'] !== null ? (int) $staff['branch_id'] : null;
        if ($branchId !== null && $staffBranch !== null && $staffBranch !== $branchId) {
            return null;
        }
        if ($serviceId !== null && $serviceId > 0 && !$this->serviceStaffGroupEligibility->isStaffAllowedForService($serviceId, $staffId, $branchId)) {
            return null;
        }
        return [
            'id' => (int) $staff['id'],
            'branch_id' => $staffBranch,
        ];
    }

    /**
     * Staff rows eligible for booking this service at branch (service_staff mapping, branch staff_groups scope, service_staff_groups).
     *
     * @return list<array{id:int,first_name:string,last_name:string,branch_id:int|null}>
     */
    public function listStaffSelectableForService(int $serviceId, ?int $branchId): array
    {
        if ($serviceId <= 0) {
            return [];
        }

        return $this->getEligibleStaff($serviceId, $branchId, null);
    }

    public function getDayGrid(string $date, ?int $branchId = null): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = date('Y-m-d');
        }
        $staffRows = $this->getEligibleStaff(0, $branchId, null, false);
        $dayMin = '09:00';
        $dayMax = '18:00';
        $found = false;
        foreach ($staffRows as $staff) {
            $intervals = $this->getWorkingIntervals((int) $staff['id'], $date, $branchId);
            foreach ($intervals as $r) {
                if (empty($r['start']) || empty($r['end'])) {
                    continue;
                }
                $start = substr((string) $r['start'], 0, 5);
                $end = substr((string) $r['end'], 0, 5);
                if (!$found || strcmp($start, $dayMin) < 0) {
                    $dayMin = $start;
                }
                if (!$found || strcmp($end, $dayMax) > 0) {
                    $dayMax = $end;
                }
                $found = true;
            }
        }
        return [
            'date' => $date,
            'slot_minutes' => self::SLOT_MINUTES,
            'day_start' => $dayMin,
            'day_end' => $dayMax,
        ];
    }

    /**
     * @return array<int, array{id:int,first_name:string,last_name:string,branch_id:int|null}>
     */
    public function listActiveStaff(?int $branchId = null): array
    {
        $rows = $this->getEligibleStaff(0, $branchId, null, false);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'first_name' => (string) ($r['first_name'] ?? ''),
            'last_name' => (string) ($r['last_name'] ?? ''),
            'branch_id' => $r['branch_id'] !== null ? (int) $r['branch_id'] : null,
        ], $rows);
    }

    /**
     * @return array<int, array{
     *   staff_id:int,
     *   id:int,
     *   client_id:int|null,
     *   client_name:string|null,
     *   service_id:int|null,
     *   service_name:string|null,
     *   series_id:int|null,
     *   start_at:string,
     *   end_at:string,
     *   status:string,
     *   created_at:string|null
     * }>
     */
    public function listDayAppointmentsGroupedByStaff(string $date, ?int $branchId = null): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return [];
        }
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';
        $sql = "SELECT a.id, a.client_id, a.service_id, a.staff_id, a.series_id, a.start_at, a.end_at, a.status, a.created_at,
                       c.first_name AS client_first_name, c.last_name AS client_last_name,
                       s.name AS service_name
                FROM appointments a
                LEFT JOIN clients c ON c.id = a.client_id
                LEFT JOIN services s ON s.id = a.service_id
                WHERE a.deleted_at IS NULL
                  AND a.start_at <= ?
                  AND a.end_at >= ?";
        $params = [$end, $start];
        if ($branchId !== null) {
            $sql .= ' AND a.branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY a.staff_id, a.start_at';
        $rows = $this->db->fetchAll($sql, $params);
        $grouped = [];
        foreach ($rows as $row) {
            $sid = (int) ($row['staff_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            if (!isset($grouped[$sid])) {
                $grouped[$sid] = [];
            }
            $createdRaw = $row['created_at'] ?? null;
            $seriesRaw = $row['series_id'] ?? null;
            $seriesId = ($seriesRaw !== null && $seriesRaw !== '' && (int) $seriesRaw > 0) ? (int) $seriesRaw : null;
            $grouped[$sid][] = [
                'id' => (int) $row['id'],
                'staff_id' => $sid,
                'client_id' => $row['client_id'] !== null ? (int) $row['client_id'] : null,
                'client_name' => trim((string) ($row['client_first_name'] ?? '') . ' ' . (string) ($row['client_last_name'] ?? '')) ?: null,
                'service_id' => $row['service_id'] !== null ? (int) $row['service_id'] : null,
                'service_name' => $row['service_name'] !== null ? (string) $row['service_name'] : null,
                'series_id' => $seriesId,
                'start_at' => (string) $row['start_at'],
                'end_at' => (string) $row['end_at'],
                'status' => (string) ($row['status'] ?? 'scheduled'),
                'created_at' => $createdRaw !== null && (string) $createdRaw !== '' ? (string) $createdRaw : null,
            ];
        }
        return $grouped;
    }

    public function listDayBlockedSlotsGroupedByStaff(string $date, ?int $branchId = null): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return [];
        }
        return $this->blockedSlots->listGroupedByStaffForDate($date, $branchId);
    }

    /**
     * Reusable availability shape for one staff on one date (branch-aware).
     * Use for operational scheduling and future public booking. Off-days = empty working_intervals.
     * Breaks and blocked reduce available window; appointment_slots are already-booked times.
     *
     * @return array{date: string, staff_id: int, branch_id: int|null, working_intervals: list<array{start:string,end:string}>, break_intervals: list<array{start:string,end:string}>, blocked_intervals: list<array{start:string,end:string}>, appointment_slots: list<array{start_at:string,end_at:string,status:string}>}|null
     *         Null if staff not found, inactive, or branch mismatch.
     */
    public function getStaffAvailabilityForDate(int $staffId, string $date, ?int $branchId = null): ?array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }
        $staff = $this->getActiveStaff($staffId);
        if (!$staff) {
            return null;
        }
        $staffBranchId = $staff['branch_id'] !== null ? (int) $staff['branch_id'] : null;
        if ($branchId !== null && $staffBranchId !== null && $staffBranchId !== $branchId) {
            return null;
        }
        $scopeBranchId = $branchId ?? $staffBranchId;
        if (!$this->staffGroupService->isStaffInScopeForBranch($staffId, $scopeBranchId)) {
            return null;
        }
        $working = $this->getWorkingIntervals($staffId, $date, $branchId);
        $breaks = $this->getBreakIntervals($staffId, $date);
        $blocked = $this->getBlockedIntervals($staffId, $date, $branchId);
        $appointments = $this->getStaffAppointmentSlotsForDate($staffId, $date, $branchId);
        return [
            'date' => $date,
            'staff_id' => $staffId,
            'branch_id' => $staffBranchId,
            'working_intervals' => $working,
            'break_intervals' => $breaks,
            'blocked_intervals' => $blocked,
            'appointment_slots' => $appointments,
        ];
    }

    /**
     * Availability for a date range: one entry per date. Skips dates outside range.
     *
     * @return list<array{date: string, staff_id: int, branch_id: int|null, working_intervals: list<array{start:string,end:string}>, break_intervals: list<array{start:string,end:string}>, blocked_intervals: list<array{start:string,end:string}>, appointment_slots: list<array{start_at:string,end_at:string,status:string}>}>
     */
    public function getStaffAvailabilityForDateRange(int $staffId, string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) !== 1) {
            return [];
        }
        $from = strtotime($dateFrom);
        $to = strtotime($dateTo);
        if ($from === false || $to === false || $to < $from) {
            return [];
        }
        $result = [];
        $current = $from;
        while ($current <= $to) {
            $d = date('Y-m-d', $current);
            $day = $this->getStaffAvailabilityForDate($staffId, $d, $branchId);
            if ($day !== null) {
                $result[] = $day;
            }
            $current = strtotime('+1 day', $current);
        }
        return $result;
    }

    /**
     * Blocking appointments for this staff on this date (branch-aware). Used to build availability shape.
     *
     * @return list<array{start_at:string,end_at:string,status:string}>
     */
    private function getStaffAppointmentSlotsForDate(int $staffId, string $date, ?int $branchId): array
    {
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';
        $sql = "SELECT a.id, a.start_at, a.end_at, a.status
                FROM appointments a
                WHERE a.deleted_at IS NULL
                  AND a.staff_id = ?
                  AND a.status IN (?, ?, ?, ?)
                  AND a.start_at <= ?
                  AND a.end_at >= ?";
        $params = [$staffId, self::BLOCKING_STATUSES[0], self::BLOCKING_STATUSES[1], self::BLOCKING_STATUSES[2], self::BLOCKING_STATUSES[3], $end, $start];
        if ($branchId !== null) {
            $sql .= ' AND a.branch_id = ?';
            $params[] = $branchId;
        }
        $sql .= ' ORDER BY a.start_at ASC';
        $rows = $this->db->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'start_at' => (string) $r['start_at'],
                'end_at' => (string) $r['end_at'],
                'status' => (string) ($r['status'] ?? 'scheduled'),
            ];
        }
        return $out;
    }

    private function getActiveService(int $serviceId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, duration_minutes, branch_id FROM services WHERE id = ? AND deleted_at IS NULL AND is_active = 1',
            [$serviceId]
        );
    }

    private function getActiveStaff(int $staffId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, branch_id FROM staff WHERE id = ? AND deleted_at IS NULL AND is_active = 1',
            [$staffId]
        );
    }

    /**
     * @return array<int, array{id:int,first_name:string,last_name:string,branch_id:int|null}>
     */
    private function getEligibleStaff(int $serviceId, ?int $branchId, ?int $specificStaffId, bool $applyServiceMapping = true): array
    {
        $hasMapping = false;
        if ($applyServiceMapping && $serviceId > 0) {
            $m = $this->db->fetchOne('SELECT 1 FROM service_staff WHERE service_id = ? LIMIT 1', [$serviceId]);
            $hasMapping = $m !== null;
        }

        if ($specificStaffId !== null) {
            if ($hasMapping) {
                $sql = 'SELECT st.id, st.first_name, st.last_name, st.branch_id
                        FROM staff st
                        INNER JOIN service_staff ss ON ss.staff_id = st.id
                        WHERE st.id = ? AND ss.service_id = ? AND st.deleted_at IS NULL AND st.is_active = 1';
                $params = [$specificStaffId, $serviceId];
                [$sgSql, $sgParams] = $this->serviceStaffGroupExistsSql('st', $serviceId, $branchId);
                $sql .= $sgSql;
                $params = array_merge($params, $sgParams);
            } else {
                $sql = 'SELECT id, first_name, last_name, branch_id
                        FROM staff
                        WHERE id = ? AND deleted_at IS NULL AND is_active = 1';
                $params = [$specificStaffId];
                [$sgSql, $sgParams] = $this->serviceStaffGroupExistsSql('staff', $serviceId, $branchId);
                $sql .= $sgSql;
                $params = array_merge($params, $sgParams);
            }
            if ($branchId !== null && $branchId > 0) {
                $alias = $hasMapping ? 'st' : 'staff';
                $stSel = $this->orgScope->staffSelectableAtOperationBranchTenantClause($alias, $branchId);
                $sql .= ' AND (' . $stSel['sql'] . ')';
                $params = array_merge($params, $stSel['params']);
            }
            $row = $this->db->fetchOne($sql, $params);
            if (!$row) {
                return [];
            }
            if (!$this->staffGroupService->isStaffInScopeForBranch((int) $row['id'], $branchId)) {
                return [];
            }

            return [$row];
        }

        if ($hasMapping) {
            $sql = 'SELECT st.id, st.first_name, st.last_name, st.branch_id
                    FROM staff st
                    INNER JOIN service_staff ss ON ss.staff_id = st.id
                    WHERE ss.service_id = ? AND st.deleted_at IS NULL AND st.is_active = 1';
            $params = [$serviceId];
            [$sgSql, $sgParams] = $this->serviceStaffGroupExistsSql('st', $serviceId, $branchId);
            $sql .= $sgSql;
            $params = array_merge($params, $sgParams);
        } else {
            $sql = 'SELECT id, first_name, last_name, branch_id
                    FROM staff
                    WHERE deleted_at IS NULL AND is_active = 1';
            $params = [];
            [$sgSql, $sgParams] = $this->serviceStaffGroupExistsSql('staff', $serviceId, $branchId);
            $sql .= $sgSql;
            $params = array_merge($params, $sgParams);
        }
        if ($branchId !== null && $branchId > 0) {
            $alias = $hasMapping ? 'st' : 'staff';
            $stSel = $this->orgScope->staffSelectableAtOperationBranchTenantClause($alias, $branchId);
            $sql .= ' AND (' . $stSel['sql'] . ')';
            $params = array_merge($params, $stSel['params']);
        }
        $sql .= ' ORDER BY last_name, first_name';
        $rows = $this->db->fetchAll($sql, $params);

        return array_values(array_filter($rows, fn (array $r): bool => $this->staffGroupService->isStaffInScopeForBranch((int) $r['id'], $branchId)));
    }

    /**
     * When the service has enforceable staff-group links, require membership in an applicable linked group.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function serviceStaffGroupExistsSql(string $staffTableQualifier, int $serviceId, ?int $branchId): array
    {
        if ($serviceId <= 0 || !$this->serviceStaffGroupEligibility->serviceHasStaffGroupRestrictions($serviceId)) {
            return ['', []];
        }
        $sql = ' AND EXISTS (
            SELECT 1 FROM service_staff_groups ssg
            INNER JOIN staff_groups sg ON sg.id = ssg.staff_group_id AND sg.deleted_at IS NULL AND sg.is_active = 1
            INNER JOIN staff_group_members sgm ON sgm.staff_group_id = sg.id AND sgm.staff_id = ' . $staffTableQualifier . '.id
            WHERE ssg.service_id = ?';
        $params = [$serviceId];
        if ($branchId === null) {
            $sql .= ' AND sg.branch_id IS NULL';
        } else {
            $sql .= ' AND (sg.branch_id IS NULL OR sg.branch_id = ?)';
            $params[] = $branchId;
        }
        $sql .= ')';

        return [$sql, $params];
    }

    /**
     * Effective working intervals for one staff on a calendar date (BKM-006).
     *
     * Precedence: any `closed` exception → none; else if any `open` segments → merged `open` only;
     * else weekly `staff_schedules`; then subtract each `unavailable` window. Recurring breaks/blocks
     * are applied later in `isStaffWindowAvailable`.
     *
     * @return array<int, array{start:string,end:string}>
     */
    private function getWorkingIntervals(int $staffId, string $date, ?int $branchId = null): array
    {
        $rows = $this->staffAvailabilityExceptions->listForStaffAndDate($staffId, $date, $branchId);

        $hasClosed = false;
        $openSegments = [];
        $unavailableWindows = [];

        foreach ($rows as $row) {
            $kind = $row['kind'];
            if ($kind === 'closed') {
                $hasClosed = true;
                break;
            }
            if ($kind === 'open') {
                $seg = $this->normalizeExceptionTimeSegment($row['start_time'] ?? null, $row['end_time'] ?? null);
                if ($seg !== null) {
                    $openSegments[] = $seg;
                }
                continue;
            }
            if ($kind === 'unavailable') {
                $seg = $this->normalizeExceptionTimeSegment($row['start_time'] ?? null, $row['end_time'] ?? null);
                if ($seg !== null) {
                    $unavailableWindows[] = $seg;
                }
            }
        }

        if ($hasClosed) {
            return [];
        }

        if ($openSegments !== []) {
            $intervals = $this->mergeWorkingIntervalSegments($openSegments);
        } else {
            $dow = (int) date('w', strtotime($date));
            $intervals = $this->staffSchedules->listByStaffAndDay($staffId, $dow);
        }

        foreach ($unavailableWindows as $u) {
            $intervals = $this->subtractUnavailableWindow($intervals, $u['start'], $u['end']);
        }

        return $intervals;
    }

    /**
     * @return array{start:string,end:string}|null
     */
    private function normalizeExceptionTimeSegment(?string $start, ?string $end): ?array
    {
        if ($start === null || $end === null || $start === '' || $end === '') {
            return null;
        }
        $start = strlen($start) === 5 ? $start . ':00' : substr($start, 0, 8);
        $end = strlen($end) === 5 ? $end . ':00' : substr($end, 0, 8);
        if ($end <= $start) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @param list<array{start:string,end:string}> $segments
     * @return list<array{start:string,end:string}>
     */
    private function mergeWorkingIntervalSegments(array $segments): array
    {
        if ($segments === []) {
            return [];
        }
        usort($segments, static fn (array $a, array $b): int => strcmp($a['start'], $b['start']));
        $merged = [];
        $cur = $segments[0];
        $n = count($segments);
        for ($i = 1; $i < $n; $i++) {
            $next = $segments[$i];
            if ($next['start'] <= $cur['end']) {
                if (strcmp($next['end'], $cur['end']) > 0) {
                    $cur['end'] = $next['end'];
                }
            } else {
                $merged[] = $cur;
                $cur = $next;
            }
        }
        $merged[] = $cur;

        return $merged;
    }

    /**
     * @param list<array{start:string,end:string}> $intervals
     * @return list<array{start:string,end:string}>
     */
    private function subtractUnavailableWindow(array $intervals, string $uStart, string $uEnd): array
    {
        if ($uEnd <= $uStart) {
            return $intervals;
        }
        $out = [];
        foreach ($intervals as $iv) {
            if ($uEnd <= $iv['start'] || $uStart >= $iv['end']) {
                $out[] = $iv;
                continue;
            }
            if ($uStart > $iv['start'] && strcmp($uStart, $iv['end']) < 0) {
                $out[] = ['start' => $iv['start'], 'end' => $uStart];
            }
            if ($uEnd < $iv['end'] && strcmp($uEnd, $iv['start']) > 0) {
                $out[] = ['start' => $uEnd, 'end' => $iv['end']];
            }
        }

        return $out;
    }

    /**
     * @return array<int, array{start:string,end:string}>
     */
    private function getBreakIntervals(int $staffId, string $date): array
    {
        $dow = (int) date('w', strtotime($date));
        return $this->staffBreaks->listByStaffAndDay($staffId, $dow);
    }

    /**
     * @return array<int, array{start:string,end:string}>
     */
    private function getBlockedIntervals(int $staffId, string $date, ?int $branchId): array
    {
        $rows = $this->blockedSlots->listForStaffAndDate($staffId, $date, $branchId);
        $out = [];
        foreach ($rows as $row) {
            if (empty($row['start_time']) || empty($row['end_time'])) {
                continue;
            }
            $start = substr((string) $row['start_time'], 0, 8);
            $end = substr((string) $row['end_time'], 0, 8);
            if ($end <= $start) {
                continue;
            }
            $out[] = ['start' => $start, 'end' => $end];
        }
        return $out;
    }

    private function hasBufferedAppointmentConflict(
        int $staffId,
        string $windowStartAt,
        string $windowEndAt,
        ?int $excludeAppointmentId
    ): bool {
        // Buffered overlap vs candidate [windowStartAt, windowEndAt]:
        // existing blocks [start_at - bb, end_at + ba] (bb/ba from joined service, 0 if no row).
        // Legacy shape wrapped columns: DATE_SUB(start_at, bb) < We AND DATE_ADD(end_at, ba) > Ws.
        // Algebraically equivalent & more index-friendly: start_at < We+bb AND end_at > Ws-ba (bounds only touch parameters).
        $sql = "SELECT a.id
                FROM appointments a
                LEFT JOIN services s ON s.id = a.service_id
                WHERE a.deleted_at IS NULL
                  AND a.status IN (?, ?, ?, ?)
                  AND a.staff_id = ?
                  AND a.start_at < DATE_ADD(?, INTERVAL COALESCE(s.buffer_before_minutes, 0) MINUTE)
                  AND a.end_at > DATE_SUB(?, INTERVAL COALESCE(s.buffer_after_minutes, 0) MINUTE)";
        $params = [
            self::BLOCKING_STATUSES[0],
            self::BLOCKING_STATUSES[1],
            self::BLOCKING_STATUSES[2],
            self::BLOCKING_STATUSES[3],
            $staffId,
            $windowEndAt,
            $windowStartAt,
        ];
        if ($excludeAppointmentId !== null) {
            $sql .= ' AND a.id != ?';
            $params[] = $excludeAppointmentId;
        }
        $row = $this->db->fetchOne($sql . ' LIMIT 1', $params);
        return $row !== null;
    }

    /**
     * @param array<int, array{start:string,end:string}> $intervals
     */
    private function isWithinAnyInterval(string $startTime, string $endTime, array $intervals): bool
    {
        foreach ($intervals as $i) {
            if ($startTime >= $i['start'] && $endTime <= $i['end']) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array{start:string,end:string}> $intervals
     */
    private function overlapsAny(string $startTime, string $endTime, array $intervals): bool
    {
        foreach ($intervals as $i) {
            if ($startTime < $i['end'] && $endTime > $i['start']) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array{start:string,end:string}> $intervals
     * @return array<int, array{start:string,end:string}>
     */
    private function intersectIntervalsWithEnvelope(array $intervals, string $open, string $close): array
    {
        $out = [];
        if ($open === '' || $close === '') {
            return $out;
        }
        $openTs = strtotime('1970-01-01 ' . $open);
        $closeTs = strtotime('1970-01-01 ' . $close);
        if ($openTs === false || $closeTs === false || $closeTs <= $openTs) {
            return $out;
        }

        foreach ($intervals as $iv) {
            $start = substr((string) ($iv['start'] ?? ''), 0, 8);
            $end = substr((string) ($iv['end'] ?? ''), 0, 8);
            if ($start === '' || $end === '') {
                continue;
            }
            $startTs = strtotime('1970-01-01 ' . $start);
            $endTs = strtotime('1970-01-01 ' . $end);
            if ($startTs === false || $endTs === false || $endTs <= $startTs) {
                continue;
            }
            $clampedStartTs = max($startTs, $openTs);
            $clampedEndTs = min($endTs, $closeTs);
            if ($clampedEndTs <= $clampedStartTs) {
                continue;
            }
            $out[] = [
                'start' => date('H:i:s', $clampedStartTs),
                'end' => date('H:i:s', $clampedEndTs),
            ];
        }

        return $out;
    }

    private function internalStaffOffDayBypassEnabledForBranch(?int $branchId): bool
    {
        return !empty($this->settings->getAppointmentSettings($branchId)['allow_staff_booking_on_off_days']);
    }

    private function staffHasClosedExceptionForDate(int $staffId, string $date, ?int $branchId): bool
    {
        foreach ($this->staffAvailabilityExceptions->listForStaffAndDate($staffId, $date, $branchId) as $row) {
            if (($row['kind'] ?? '') === 'closed') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{start:string,end:string}>
     */
    private function branchOpenCloseIntervalsForDate(?int $branchId, string $date): array
    {
        $meta = $this->getBranchOperatingHoursMeta($branchId, $date);
        if (($meta['is_open_for_slots'] ?? false) !== true) {
            return [];
        }
        $open = trim((string) ($meta['open_time'] ?? ''));
        $close = trim((string) ($meta['close_time'] ?? ''));
        if ($open === '' || $close === '') {
            return [];
        }
        $openSeg = strlen($open) === 5 ? $open . ':00' : substr($open, 0, 8);
        $closeSeg = strlen($close) === 5 ? $close . ':00' : substr($close, 0, 8);

        return [['start' => $openSeg, 'end' => $closeSeg]];
    }
}
