<?php

declare(strict_types=1);

namespace Modules\Appointments\Repositories;

use Core\App\Database;
use Modules\Appointments\Services\CalendarToolbarUiService;

final class CalendarUserPreferencesRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{
     *   column_width_px:int,
     *   time_zoom_percent:int,
     *   show_in_progress:bool,
     *   hidden_staff_ids:list<string>,
     *   staff_order_scheduled_ids:list<string>,
     *   staff_order_freelancer_ids:list<string>,
     *   staff_columns_per_view:int|null
     * }|null
     */
    public function find(int $organizationId, int $userId, int $branchId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT column_width_px, time_zoom_percent, show_in_progress, hidden_staff_ids, staff_order_scheduled_ids, staff_order_freelancer_ids, staff_columns_per_view
             FROM calendar_user_preferences
             WHERE organization_id = ? AND user_id = ? AND branch_id = ?',
            [$organizationId, $userId, $branchId]
        );
        if (!$row) {
            return null;
        }

        return $this->normalizeRow($row);
    }

    public function exists(int $organizationId, int $userId, int $branchId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM calendar_user_preferences
             WHERE organization_id = ? AND user_id = ? AND branch_id = ?
             LIMIT 1',
            [$organizationId, $userId, $branchId]
        );

        return $row !== null;
    }

    /**
     * @param list<string> $hiddenStaffIds
     * @param list<string> $staffOrderScheduledIds
     * @param list<string> $staffOrderFreelancerIds
     */
    public function upsert(
        int $organizationId,
        int $userId,
        int $branchId,
        int $columnWidthPx,
        int $timeZoomPercent,
        bool $showInProgress,
        array $hiddenStaffIds,
        array $staffOrderScheduledIds,
        array $staffOrderFreelancerIds,
        ?int $staffColumnsPerView = null
    ): void {
        $columnWidthPx = max(
            CalendarToolbarUiService::MIN_COLUMN_WIDTH,
            min(CalendarToolbarUiService::MAX_COLUMN_WIDTH, $columnWidthPx)
        );
        $timeZoomPercent = max(
            CalendarToolbarUiService::MIN_TIME_ZOOM_PERCENT,
            min(CalendarToolbarUiService::MAX_TIME_ZOOM_PERCENT, $timeZoomPercent)
        );
        $staffColumnsPerView = $staffColumnsPerView !== null
            ? max(CalendarToolbarUiService::MIN_STAFF_COLUMNS_PER_VIEW, min(CalendarToolbarUiService::MAX_STAFF_COLUMNS_PER_VIEW, $staffColumnsPerView))
            : null;
        $json = json_encode(array_values($hiddenStaffIds), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $schedJson = json_encode(array_values($staffOrderScheduledIds), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $frJson = json_encode(array_values($staffOrderFreelancerIds), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->query(
            'INSERT INTO calendar_user_preferences
                (organization_id, user_id, branch_id, column_width_px, time_zoom_percent, show_in_progress, hidden_staff_ids, staff_order_scheduled_ids, staff_order_freelancer_ids, staff_columns_per_view)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                column_width_px = VALUES(column_width_px),
                time_zoom_percent = VALUES(time_zoom_percent),
                show_in_progress = VALUES(show_in_progress),
                hidden_staff_ids = VALUES(hidden_staff_ids),
                staff_order_scheduled_ids = VALUES(staff_order_scheduled_ids),
                staff_order_freelancer_ids = VALUES(staff_order_freelancer_ids),
                staff_columns_per_view = VALUES(staff_columns_per_view),
                updated_at = CURRENT_TIMESTAMP',
            [
                $organizationId,
                $userId,
                $branchId,
                $columnWidthPx,
                $timeZoomPercent,
                $showInProgress ? 1 : 0,
                $json,
                $schedJson,
                $frJson,
                $staffColumnsPerView,
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   column_width_px:int,
     *   time_zoom_percent:int,
     *   show_in_progress:bool,
     *   hidden_staff_ids:list<string>,
     *   staff_order_scheduled_ids:list<string>,
     *   staff_order_freelancer_ids:list<string>,
     *   staff_columns_per_view:int|null
     * }
     */
    private function normalizeRow(array $row): array
    {
        $raw = $row['hidden_staff_ids'] ?? null;
        $ids = [];
        if (is_string($raw) && $raw !== '') {
            $dec = json_decode($raw, true);
            if (is_array($dec)) {
                foreach ($dec as $v) {
                    $ids[] = (string) $v;
                }
            }
        }

        $schedRaw = $row['staff_order_scheduled_ids'] ?? null;
        $sched = [];
        if (is_string($schedRaw) && $schedRaw !== '') {
            $dec = json_decode($schedRaw, true);
            if (is_array($dec)) {
                foreach ($dec as $v) {
                    $sched[] = (string) $v;
                }
            }
        }
        $frRaw = $row['staff_order_freelancer_ids'] ?? null;
        $fr = [];
        if (is_string($frRaw) && $frRaw !== '') {
            $dec = json_decode($frRaw, true);
            if (is_array($dec)) {
                foreach ($dec as $v) {
                    $fr[] = (string) $v;
                }
            }
        }

        $col = (int) ($row['column_width_px'] ?? 160);
        $col = max(CalendarToolbarUiService::MIN_COLUMN_WIDTH, min(CalendarToolbarUiService::MAX_COLUMN_WIDTH, $col));
        $zoom = (int) ($row['time_zoom_percent'] ?? 100);
        // Clamp so legacy sub-min values upgrade on read (canonical min matches slider + PATCH validation).
        $zoom = max(CalendarToolbarUiService::MIN_TIME_ZOOM_PERCENT, min(CalendarToolbarUiService::MAX_TIME_ZOOM_PERCENT, $zoom));

        $scpv = isset($row['staff_columns_per_view']) && $row['staff_columns_per_view'] !== null
            ? max(CalendarToolbarUiService::MIN_STAFF_COLUMNS_PER_VIEW, min(CalendarToolbarUiService::MAX_STAFF_COLUMNS_PER_VIEW, (int) $row['staff_columns_per_view']))
            : null;

        return [
            'column_width_px' => $col,
            'time_zoom_percent' => $zoom,
            'show_in_progress' => !empty($row['show_in_progress']),
            'hidden_staff_ids' => $ids,
            'staff_order_scheduled_ids' => $sched,
            'staff_order_freelancer_ids' => $fr,
            'staff_columns_per_view' => $scpv,
        ];
    }
}
