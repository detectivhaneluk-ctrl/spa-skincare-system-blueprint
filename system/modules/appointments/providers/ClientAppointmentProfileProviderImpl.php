<?php

declare(strict_types=1);

namespace Modules\Appointments\Providers;

use Core\App\Database;
use Core\App\SettingsService;
use Core\Branch\BranchContext;
use Core\Contracts\ClientAppointmentProfileProvider;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Clients\Services\ClientProfileAccessService;

final class ClientAppointmentProfileProviderImpl implements ClientAppointmentProfileProvider
{
    /** @var list<string> */
    private const PROFILE_LIST_STATUSES = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'];

    /** @var array<int, array<string, mixed>> */
    private array $getSummaryRequestCache = [];

    public function __construct(
        private Database $db,
        private ClientProfileAccessService $profileAccess,
        private OrganizationRepositoryScope $orgScope,
        private SettingsService $settings,
        private BranchContext $branchContext,
    ) {
    }

    private function appointmentSettingsReadBranchId(): ?int
    {
        $bid = $this->branchContext->getCurrentBranchId();

        return $bid !== null && $bid > 0 ? $bid : null;
    }

    /**
     * Canonical operational payload for no-show threshold warnings (read surfaces / JSON).
     *
     * @return array{
     *   active: bool,
     *   code: string,
     *   severity: string,
     *   settings_enabled: bool,
     *   recorded_no_show_count: int,
     *   threshold: int,
     *   message: string
     * }
     */
    private function buildNoShowAlertPayload(bool $settingsEnabled, int $threshold, int $recordedNoShowCount): array
    {
        $threshold = max(1, min(99, $threshold));
        $active = $settingsEnabled && $recordedNoShowCount >= $threshold;
        $message = '';
        if ($active) {
            $message = 'Client has ' . $recordedNoShowCount . ' recorded no-show(s) (threshold ' . $threshold . ').';
        }

        return [
            'active' => $active,
            'code' => 'client_no_show_threshold',
            'severity' => 'warning',
            'settings_enabled' => $settingsEnabled,
            'recorded_no_show_count' => $recordedNoShowCount,
            'threshold' => $threshold,
            'message' => $message,
        ];
    }

    public function getSummary(int $clientId): array
    {
        if (isset($this->getSummaryRequestCache[$clientId])) {
            return $this->getSummaryRequestCache[$clientId];
        }
        if ($this->profileAccess->resolveForProviderRead($clientId) === null) {
            $denied = [
                'total' => 0,
                'scheduled' => 0,
                'confirmed' => 0,
                'in_progress' => 0,
                'completed' => 0,
                'cancelled' => 0,
                'no_show' => 0,
                'no_show_alert_enabled' => false,
                'no_show_alert_threshold' => 1,
                'no_show_alert_triggered' => false,
                'no_show_alert' => $this->buildNoShowAlertPayload(false, 1, 0),
                'last_start_at' => null,
                'first_start_at' => null,
            ];
            $this->getSummaryRequestCache[$clientId] = $denied;

            return $denied;
        }

        $aFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $params = array_merge([$clientId], $aFrag['params']);
        $row = $this->db->fetchOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN a.status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled,
                SUM(CASE WHEN a.status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
                SUM(CASE WHEN a.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN a.status = 'no_show' THEN 1 ELSE 0 END) AS no_show,
                MAX(a.start_at) AS last_start_at,
                MIN(a.start_at) AS first_start_at
             FROM appointments a
             WHERE a.deleted_at IS NULL AND a.client_id = ?"
                . $aFrag['sql'],
            $params
        ) ?? [];

        $apt = $this->settings->getAppointmentSettings($this->appointmentSettingsReadBranchId());
        $noShow = (int) ($row['no_show'] ?? 0);
        $alertEnabled = (bool) ($apt['no_show_alert_enabled'] ?? false);
        $threshold = max(1, min(99, (int) ($apt['no_show_alert_threshold'] ?? 1)));
        $alertPayload = $this->buildNoShowAlertPayload($alertEnabled, $threshold, $noShow);

        $lastStart = $row['last_start_at'] ?? null;
        $firstStart = $row['first_start_at'] ?? null;

        $out = [
            'total' => (int) ($row['total'] ?? 0),
            'scheduled' => (int) ($row['scheduled'] ?? 0),
            'confirmed' => (int) ($row['confirmed'] ?? 0),
            'in_progress' => (int) ($row['in_progress'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
            'no_show' => $noShow,
            'no_show_alert_enabled' => $alertEnabled,
            'no_show_alert_threshold' => $threshold,
            'no_show_alert_triggered' => $alertPayload['active'],
            'no_show_alert' => $alertPayload,
            'last_start_at' => $lastStart !== null && (string) $lastStart !== '' ? (string) $lastStart : null,
            'first_start_at' => $firstStart !== null && (string) $firstStart !== '' ? (string) $firstStart : null,
        ];
        $this->getSummaryRequestCache[$clientId] = $out;

        return $out;
    }

    public function listRecent(int $clientId, int $limit = 10): array
    {
        if ($this->profileAccess->resolveForProviderRead($clientId) === null) {
            return [];
        }
        $limit = max(1, (int) $limit);
        $aFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $params = array_merge([$clientId], $aFrag['params'], [$limit]);
        $rows = $this->db->fetchAll(
            "SELECT a.id,
                    a.start_at,
                    a.end_at,
                    a.status,
                    s.name AS service_name,
                    CONCAT(COALESCE(st.first_name, ''), ' ', COALESCE(st.last_name, '')) AS staff_name,
                    rm.name AS room_name
             FROM appointments a
             LEFT JOIN services s ON s.id = a.service_id
             LEFT JOIN staff st ON st.id = a.staff_id
             LEFT JOIN rooms rm ON rm.id = a.room_id AND rm.deleted_at IS NULL
             WHERE a.deleted_at IS NULL
               AND a.client_id = ?"
                . $aFrag['sql'] . '
             ORDER BY a.start_at DESC
             LIMIT ?',
            $params
        );

        $apt = $this->settings->getAppointmentSettings($this->appointmentSettingsReadBranchId());
        $showStaff = (bool) ($apt['client_itinerary_show_staff'] ?? true);
        $showSpace = (bool) ($apt['client_itinerary_show_space'] ?? false);

        return array_map(function (array $r) use ($showStaff, $showSpace): array {
            $staffRaw = trim((string) ($r['staff_name'] ?? '')) ?: null;
            $roomRaw = isset($r['room_name']) && $r['room_name'] !== null && $r['room_name'] !== ''
                ? (string) $r['room_name']
                : null;

            return [
                'id' => (int) $r['id'],
                'start_at' => (string) $r['start_at'],
                'end_at' => (string) $r['end_at'],
                'status' => (string) ($r['status'] ?? 'scheduled'),
                'service_name' => $r['service_name'] ?? null,
                'staff_name' => $showStaff ? $staffRaw : null,
                'room_name' => $showSpace ? $roomRaw : null,
            ];
        }, $rows);
    }

    public function listForClientProfile(int $clientId, array $query): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($query['per_page'] ?? 15)));

        if ($this->profileAccess->resolveForProviderRead($clientId) === null) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $statusRaw = $query['status'] ?? null;
        $status = is_string($statusRaw) && $statusRaw !== '' && in_array($statusRaw, self::PROFILE_LIST_STATUSES, true)
            ? $statusRaw
            : null;

        $dateMode = (($query['date_mode'] ?? '') === 'created') ? 'created' : 'appointment';
        $dateFrom = $this->normalizeProfileDateInput($query['date_from'] ?? null, false);
        $dateTo = $this->normalizeProfileDateInput($query['date_to'] ?? null, true);

        $aFrag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $params = array_merge([$clientId], $aFrag['params']);
        $sqlWhere = 'a.deleted_at IS NULL AND a.client_id = ?' . $aFrag['sql'];

        if ($status !== null) {
            $sqlWhere .= ' AND a.status = ?';
            $params[] = $status;
        }

        if ($dateFrom !== null) {
            if ($dateMode === 'created') {
                $sqlWhere .= ' AND a.created_at >= ?';
            } else {
                $sqlWhere .= ' AND a.start_at >= ?';
            }
            $params[] = $dateFrom;
        }
        if ($dateTo !== null) {
            if ($dateMode === 'created') {
                $sqlWhere .= ' AND a.created_at <= ?';
            } else {
                $sqlWhere .= ' AND a.start_at <= ?';
            }
            $params[] = $dateTo;
        }

        $countRow = $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM appointments a WHERE {$sqlWhere}",
            $params
        );
        $total = (int) ($countRow['c'] ?? 0);

        if ($dateMode === 'created') {
            $orderBySql = 'a.created_at DESC, a.id DESC';
        } else {
            $orderBySql = 'a.start_at DESC, a.id DESC';
        }
        $offset = ($page - 1) * $perPage;
        $listParams = array_merge($params, [$perPage, $offset]);
        $rows = $this->db->fetchAll(
            "SELECT a.id,
                    a.start_at,
                    a.end_at,
                    a.created_at,
                    a.status,
                    s.name AS service_name,
                    CONCAT(COALESCE(st.first_name, ''), ' ', COALESCE(st.last_name, '')) AS staff_name,
                    rm.name AS room_name
             FROM appointments a
             LEFT JOIN services s ON s.id = a.service_id
             LEFT JOIN staff st ON st.id = a.staff_id
             LEFT JOIN rooms rm ON rm.id = a.room_id AND rm.deleted_at IS NULL
             WHERE {$sqlWhere}
             ORDER BY " . $orderBySql . '
             LIMIT ? OFFSET ?',
            $listParams
        );

        $apt = $this->settings->getAppointmentSettings($this->appointmentSettingsReadBranchId());
        $showStaff = (bool) ($apt['client_itinerary_show_staff'] ?? true);
        $showSpace = (bool) ($apt['client_itinerary_show_space'] ?? false);

        $items = array_map(function (array $r) use ($showStaff, $showSpace): array {
            $staffRaw = trim((string) ($r['staff_name'] ?? '')) ?: null;
            $roomRaw = isset($r['room_name']) && $r['room_name'] !== null && $r['room_name'] !== ''
                ? (string) $r['room_name']
                : null;

            return [
                'id' => (int) $r['id'],
                'start_at' => (string) $r['start_at'],
                'end_at' => (string) $r['end_at'],
                'created_at' => (string) ($r['created_at'] ?? ''),
                'status' => (string) ($r['status'] ?? 'scheduled'),
                'service_name' => $r['service_name'] ?? null,
                'staff_name' => $showStaff ? $staffRaw : null,
                'room_name' => $showSpace ? $roomRaw : null,
            ];
        }, $rows);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function normalizeProfileDateInput(mixed $raw, bool $endOfDay): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $s = trim($raw);
        if ($s === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) !== 1) {
            return null;
        }

        return $endOfDay ? $s . ' 23:59:59' : $s . ' 00:00:00';
    }
}
