<?php

declare(strict_types=1);

namespace Modules\Appointments\Repositories;

use Core\App\Database;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationRepositoryScope;

final class AppointmentRepository
{
    /**
     * Appointments in these statuses do not participate in staff or room interval-overlap conflict checks
     * ({@see hasStaffConflict}, {@see hasRoomConflict}).
     */
    public const EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES = ['cancelled', 'no_show'];

    public function __construct(private Database $db, private OrganizationRepositoryScope $orgScope)
    {
    }

    public function find(int $id, bool $withTrashed = false): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $sql = 'SELECT a.*, c.first_name as client_first_name, c.last_name as client_last_name,
                s.name as service_name, st.first_name as staff_first_name, st.last_name as staff_last_name,
                r.name as room_name
                FROM appointments a
                LEFT JOIN clients c ON a.client_id = c.id
                LEFT JOIN services s ON a.service_id = s.id
                LEFT JOIN staff st ON a.staff_id = st.id
                LEFT JOIN rooms r ON a.room_id = r.id
                WHERE a.id = ?';
        if (!$withTrashed) {
            $sql .= ' AND a.deleted_at IS NULL';
        }
        $sql .= $frag['sql'];

        return $this->db->fetchOne($sql, array_merge([$id], $frag['params']));
    }

    /**
     * Canonical scoped retrieval — requires resolved TenantContext (fail-closed).
     * Scopes to both branch_id AND org via OrganizationRepositoryScope.
     * Use at all new protected read entry points (FOUNDATION-A4 / BIG-04).
     *
     * @throws \Core\Kernel\UnresolvedTenantContextException when context not resolved
     */
    public function loadVisible(TenantContext $ctx, int $id): ?array
    {
        ['branch_id' => $branchId] = $ctx->requireResolvedTenant();
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $sql = 'SELECT a.*, c.first_name as client_first_name, c.last_name as client_last_name,
                s.name as service_name, st.first_name as staff_first_name, st.last_name as staff_last_name,
                r.name as room_name
                FROM appointments a
                LEFT JOIN clients c ON a.client_id = c.id
                LEFT JOIN services s ON a.service_id = s.id
                LEFT JOIN staff st ON a.staff_id = st.id
                LEFT JOIN rooms r ON a.room_id = r.id
                WHERE a.id = ? AND a.branch_id = ? AND a.deleted_at IS NULL' . $frag['sql'];

        return $this->db->fetchOne($sql, array_merge([$id, $branchId], $frag['params']));
    }

    /**
     * Canonical scoped locking retrieval — requires resolved TenantContext (fail-closed).
     * Scopes to branch_id AND org. Returns null when row does not exist OR is out of scope.
     * Eliminates the old "load by id then assertBranchMatch" anti-pattern (BIG-04 migration).
     *
     * @throws \Core\Kernel\UnresolvedTenantContextException when context not resolved
     */
    public function loadForUpdate(TenantContext $ctx, int $id): ?array
    {
        ['branch_id' => $branchId] = $ctx->requireResolvedTenant();
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');

        return $this->db->fetchOne(
            'SELECT a.* FROM appointments a WHERE a.id = ? AND a.branch_id = ? AND a.deleted_at IS NULL' . $frag['sql'] . ' FOR UPDATE',
            array_merge([$id, $branchId], $frag['params'])
        );
    }

    public function findForUpdate(int $id): ?array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');

        return $this->db->fetchOne(
            'SELECT a.* FROM appointments a WHERE a.id = ? AND a.deleted_at IS NULL' . $frag['sql'] . ' FOR UPDATE',
            array_merge([$id], $frag['params'])
        );
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $limit = (int) $limit;
        $offset = (int) $offset;
        $sql = 'SELECT a.*, c.first_name as client_first_name, c.last_name as client_last_name,
                s.name as service_name, st.first_name as staff_first_name, st.last_name as staff_last_name,
                r.name as room_name
                FROM appointments a
                LEFT JOIN clients c ON a.client_id = c.id
                LEFT JOIN services s ON a.service_id = s.id
                LEFT JOIN staff st ON a.staff_id = st.id
                LEFT JOIN rooms r ON a.room_id = r.id
                WHERE a.deleted_at IS NULL';
        $params = [];

        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND a.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        if (!empty($filters['from_date'])) {
            $sql .= ' AND a.start_at >= ?';
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $sql .= ' AND a.start_at <= ?';
            $params[] = $filters['to_date'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND a.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['client_id'])) {
            $sql .= ' AND a.client_id = ?';
            $params[] = $filters['client_id'];
        }
        if (!empty($filters['staff_id'])) {
            $sql .= ' AND a.staff_id = ?';
            $params[] = $filters['staff_id'];
        }
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);

        $sql .= ' ORDER BY a.start_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        // WAVE-07C: display-only paginated appointment list — replica-eligible.
        // Only called from AppointmentController::index() (pure GET); all write actions
        // (create/cancel/reschedule/updateStatus/delete) redirect before this runs.
        // Booking conflict checks (hasStaffConflict, hasRoomConflict, loadForUpdate) stay primary.
        return $this->db->forRead()->fetchAll($sql, $params);
    }

    public function count(array $filters = []): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $sql = 'SELECT COUNT(*) AS c FROM appointments a WHERE a.deleted_at IS NULL';
        $params = [];

        if (isset($filters['branch_id']) && $filters['branch_id'] !== null && $filters['branch_id'] !== '') {
            $sql .= ' AND a.branch_id = ?';
            $params[] = $filters['branch_id'];
        }
        if (!empty($filters['from_date'])) {
            $sql .= ' AND a.start_at >= ?';
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $sql .= ' AND a.start_at <= ?';
            $params[] = $filters['to_date'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND a.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['client_id'])) {
            $sql .= ' AND a.client_id = ?';
            $params[] = $filters['client_id'];
        }
        if (!empty($filters['staff_id'])) {
            $sql .= ' AND a.staff_id = ?';
            $params[] = $filters['staff_id'];
        }
        $sql .= $frag['sql'];
        $params = array_merge($params, $frag['params']);

        // WAVE-07C: display count companion — replica-eligible for same reason as list().
        $row = $this->db->forRead()->fetchOne($sql, $params);
        return (int) ($row['c'] ?? 0);
    }

    public function create(array $data): int
    {
        $this->db->insert('appointments', $this->normalize($data));
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $norm = $this->normalize($data);
        if (empty($norm)) return;
        $cols = array_map(fn ($k) => "{$k} = ?", array_keys($norm));
        $vals = array_values($norm);
        $vals[] = $id;
        $vals = array_merge($vals, $frag['params']);
        $this->db->query('UPDATE appointments a SET ' . implode(', ', $cols) . ' WHERE a.id = ?' . $frag['sql'], $vals);
    }

    public function softDelete(int $id): void
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $params = array_merge([$id], $frag['params']);
        $this->db->query('UPDATE appointments a SET a.deleted_at = NOW() WHERE a.id = ?' . $frag['sql'], $params);
    }

    /**
     * Sets check-in columns only (not exposed via {@see normalize()} / generic update mass-assign).
     *
     * @return int Rows updated (0 if id out of scope or missing)
     */
    public function markCheckedIn(int $id, string $checkedInAt, ?int $checkedInByUserId, ?int $updatedByUserId): int
    {
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $params = array_merge(
            [$checkedInAt, $checkedInByUserId, $updatedByUserId, $id],
            $frag['params']
        );
        $stmt = $this->db->query(
            'UPDATE appointments a SET a.checked_in_at = ?, a.checked_in_by = ?, a.updated_by = ? WHERE a.id = ? AND a.deleted_at IS NULL' . $frag['sql'],
            $params
        );

        return $stmt->rowCount();
    }

    /**
     * Check for staff time overlap. Returns true if conflict exists.
     * Excludes cancelled/no_show and the given exclude appointment id (for edit).
     *
     * **Tenant data-plane:** counts only **`appointments`** rows whose **`branch_id`** is a live branch in the **resolved**
     * organization ({@see OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause()} — same basis as
     * {@see find()} / {@see list()}). Overlap is **org-wide per {@code staff_id}** (any branch in the tenant), not cross-tenant global.
     * {@code $branchId} is unused in SQL (signature parity with {@see hasRoomConflict}); fail-closed when branch-derived org is missing.
     * Rows with {@code branch_id IS NULL} are excluded by the org fragment (**ROOT-02**).
     */
    public function hasStaffConflict(?int $staffId, string $startAt, string $endAt, ?int $branchId, int $excludeId = 0): bool
    {
        if ($staffId === null) {
            return false;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
        $sql = 'SELECT 1 FROM appointments a
                WHERE a.deleted_at IS NULL AND a.status NOT IN (?, ?)
                AND a.staff_id = ? AND a.id != ?'
            . $frag['sql'] . '
                AND a.start_at < ? AND a.end_at > ?';
        $params = array_merge(
            [
                self::EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES[0],
                self::EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES[1],
                $staffId,
                $excludeId,
            ],
            $frag['params'],
            [$endAt, $startAt]
        );
        $row = $this->db->fetchOne($sql, $params);

        return $row !== null;
    }

    /**
     * Serialize same-room overlap checks. Call inside an open transaction before {@see hasRoomConflict}.
     * **Tenant data-plane:** row lock only when the room’s {@code branch_id} is a live branch in the **resolved** organization
     * ({@see OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause()} — same basis as {@see find()} /
     * {@see update()} on {@code appointments}). No cross-tenant lock by bare room primary key.
     * No-op when $roomId is null or not positive.
     */
    public function lockRoomRowForConflictCheck(?int $roomId): void
    {
        if ($roomId === null || $roomId <= 0) {
            return;
        }
        $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('r');
        $params = array_merge([$roomId], $frag['params']);
        $this->db->fetchOne(
            'SELECT r.id FROM rooms r WHERE r.id = ? AND r.deleted_at IS NULL' . $frag['sql'] . ' FOR UPDATE',
            $params
        );
    }

    /**
     * Canonical room occupancy conflict (single SQL source of truth for all room-aware internal paths).
     *
     * True when there exists another non-deleted appointment with:
     * - same positive {@code room_id},
     * - {@code id != excludeAppointmentId},
     * - {@code status} not in {@see EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES},
     * - raw half-open style overlap: {@code start_at < $endAt AND end_at > $startAt} (same as staff overlap in this repo),
     * - branch predicate: if {@code $branchId} is non-null, {@code a.branch_id = $branchId} plus
     *   {@see OrganizationRepositoryScope::branchColumnOwnedByResolvedOrganizationExistsClause()} on alias {@code a}
     *   (same basis as {@see find()} / {@see list()} / {@see update()} — fail-closed when tenant org context is missing).
     *   if {@code $branchId} is null, {@code a.branch_id IS NULL} only (isolated legacy/repair slice; **no** org EXISTS).
     *
     * No service buffers; equipment/resources are out of scope. Returns false when {@code $roomId} is null or not positive.
     */
    public function hasRoomConflict(?int $roomId, string $startAt, string $endAt, ?int $branchId, int $excludeAppointmentId = 0): bool
    {
        if ($roomId === null || $roomId <= 0) {
            return false;
        }
        if ($branchId !== null) {
            $frag = $this->orgScope->branchColumnOwnedByResolvedOrganizationExistsClause('a');
            $sql = 'SELECT 1 FROM appointments a
                WHERE a.deleted_at IS NULL AND a.status NOT IN (?, ?)
                AND a.room_id = ? AND a.id != ?
                AND a.branch_id = ?'
                . $frag['sql'] . '
                AND a.start_at < ? AND a.end_at > ?';
            $params = array_merge(
                [
                    self::EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES[0],
                    self::EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES[1],
                    $roomId,
                    $excludeAppointmentId,
                    $branchId,
                ],
                $frag['params'],
                [$endAt, $startAt]
            );
        } else {
            $sql = 'SELECT 1 FROM appointments a
                WHERE a.deleted_at IS NULL AND a.status NOT IN (?, ?)
                AND a.room_id = ? AND a.id != ?
                AND a.branch_id IS NULL
                AND a.start_at < ? AND a.end_at > ?';
            $params = [
                self::EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES[0],
                self::EXCLUDED_FROM_INTERVAL_CONFLICT_STATUSES[1],
                $roomId,
                $excludeAppointmentId,
                $endAt,
                $startAt,
            ];
        }
        $row = $this->db->fetchOne($sql, $params);

        return $row !== null;
    }

    private function normalize(array $data): array
    {
        $allowed = [
            'client_id', 'service_id', 'staff_id', 'room_id', 'branch_id',
            'series_id', 'client_membership_id', 'start_at', 'end_at', 'status', 'notes',
            'cancellation_reason_id', 'no_show_reason_id',
            'created_by', 'updated_by',
        ];
        return array_intersect_key($data, array_flip($allowed));
    }
}
