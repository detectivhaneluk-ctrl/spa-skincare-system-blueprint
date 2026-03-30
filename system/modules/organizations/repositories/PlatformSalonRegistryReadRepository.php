<?php

declare(strict_types=1);

namespace Modules\Organizations\Repositories;

use Core\App\Database;

/**
 * Salon-centric (organization registry) reads for the founder control plane.
 * Aggregates registry + branch + primary admin resolution without tenant workspace scope.
 */
final class PlatformSalonRegistryReadRepository
{
    public function __construct(private Database $db)
    {
    }

    public function membershipPivotExists(): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['user_organization_memberships']
        ) !== null;
    }

    /**
     * @return list<array{id: int|string, name: string, code: string|null, created_at: string, updated_at: string, suspended_at: string|null, deleted_at: string|null}>
     */
    public function listOrganizationsFiltered(?string $q, string $lifecycle): array
    {
        $where = [];
        $params = [];
        $lifecycle = strtolower($lifecycle);
        if (!in_array($lifecycle, ['all', 'active', 'suspended', 'archived'], true)) {
            $lifecycle = 'all';
        }
        if ($lifecycle === 'active') {
            $where[] = 'o.deleted_at IS NULL AND o.suspended_at IS NULL';
        } elseif ($lifecycle === 'suspended') {
            $where[] = 'o.deleted_at IS NULL AND o.suspended_at IS NOT NULL';
        } elseif ($lifecycle === 'archived') {
            $where[] = 'o.deleted_at IS NOT NULL';
        }

        if ($q !== null && trim($q) !== '') {
            $raw = trim($q);
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], strtolower($raw)) . '%';
            $where[] = '(LOWER(o.name) LIKE ? OR LOWER(COALESCE(o.code, \'\')) LIKE ? OR CAST(o.id AS CHAR) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $raw) . '%';
        }

        $sql = 'SELECT o.id, o.name, o.code, o.created_at, o.updated_at, o.suspended_at, o.deleted_at
                FROM organizations o';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY o.updated_at DESC, o.id DESC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param list<int> $organizationIds
     * @return array<int, int> organization_id => branch count
     */
    public function countBranchesByOrganizationIds(array $organizationIds): array
    {
        $organizationIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $organizationIds),
            static fn (int $id) => $id > 0
        )));
        if ($organizationIds === []) {
            return [];
        }
        $ph = implode(', ', array_fill(0, count($organizationIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT organization_id, COUNT(*) AS c FROM branches WHERE deleted_at IS NULL AND organization_id IN ({$ph}) GROUP BY organization_id",
            $organizationIds
        );
        $out = [];
        foreach ($rows as $r) {
            $oid = (int) ($r['organization_id'] ?? 0);
            if ($oid > 0) {
                $out[$oid] = (int) ($r['c'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * @param list<int> $organizationIds
     * @return array<int, array{id:int, email:string, name:string, deleted_at:?string, password_changed_at:?string, role_code:string}>
     */
    public function batchPrimaryAdminForOrganizations(array $organizationIds): array
    {
        $organizationIds = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $organizationIds),
            static fn (int $id) => $id > 0
        )));
        if ($organizationIds === []) {
            return [];
        }

        $best = [];
        $ph = implode(', ', array_fill(0, count($organizationIds), '?'));

        if ($this->membershipPivotExists()) {
            $rows = $this->db->fetchAll(
                "SELECT m.organization_id AS organization_id, u.id AS id, u.email AS email, u.name AS name,
                        u.deleted_at AS deleted_at, u.password_changed_at AS password_changed_at, r.code AS role_code
                 FROM user_organization_memberships m
                 INNER JOIN users u ON u.id = m.user_id
                 INNER JOIN user_roles ur ON ur.user_id = u.id
                 INNER JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
                 WHERE m.organization_id IN ({$ph}) AND m.status = 'active' AND r.code IN ('owner', 'admin')",
                $organizationIds
            );
            foreach ($rows as $r) {
                $this->mergePrimaryAdminCandidate($best, $r);
            }
        }

        $missing = [];
        foreach ($organizationIds as $oid) {
            if (!isset($best[$oid])) {
                $missing[] = $oid;
            }
        }
        if ($missing !== []) {
            $ph2 = implode(', ', array_fill(0, count($missing), '?'));
            $rows = $this->db->fetchAll(
                "SELECT b.organization_id AS organization_id, u.id AS id, u.email AS email, u.name AS name,
                        u.deleted_at AS deleted_at, u.password_changed_at AS password_changed_at, r.code AS role_code
                 FROM users u
                 INNER JOIN branches b ON b.id = u.branch_id AND b.deleted_at IS NULL
                 INNER JOIN user_roles ur ON ur.user_id = u.id
                 INNER JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
                 WHERE b.organization_id IN ({$ph2}) AND r.code IN ('owner', 'admin')",
                $missing
            );
            foreach ($rows as $r) {
                $this->mergePrimaryAdminCandidate($best, $r);
            }
        }

        foreach ($best as $k => $v) {
            unset($best[$k]['_pri']);
        }

        return $best;
    }

    /**
     * @param array<int, array<string, mixed>> $best
     * @param array<string, mixed> $r
     */
    private function mergePrimaryAdminCandidate(array &$best, array $r): void
    {
        $oid = (int) ($r['organization_id'] ?? 0);
        if ($oid <= 0) {
            return;
        }
        $role = (string) ($r['role_code'] ?? '');
        $pri = $role === 'owner' ? 0 : 1;
        $uid = (int) ($r['id'] ?? 0);
        if ($uid <= 0) {
            return;
        }
        if (!isset($best[$oid])) {
            $best[$oid] = [
                'id' => $uid,
                'email' => (string) ($r['email'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'deleted_at' => isset($r['deleted_at']) && $r['deleted_at'] !== '' && $r['deleted_at'] !== null
                    ? (string) $r['deleted_at'] : null,
                'password_changed_at' => isset($r['password_changed_at']) && $r['password_changed_at'] !== '' && $r['password_changed_at'] !== null
                    ? (string) $r['password_changed_at'] : null,
                'role_code' => $role,
                '_pri' => $pri,
            ];

            return;
        }
        $cur = $best[$oid];
        $curPri = (int) ($cur['_pri'] ?? 1);
        $curId = (int) ($cur['id'] ?? 0);
        if ($pri < $curPri || ($pri === $curPri && $uid < $curId)) {
            $best[$oid] = [
                'id' => $uid,
                'email' => (string) ($r['email'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'deleted_at' => isset($r['deleted_at']) && $r['deleted_at'] !== '' && $r['deleted_at'] !== null
                    ? (string) $r['deleted_at'] : null,
                'password_changed_at' => isset($r['password_changed_at']) && $r['password_changed_at'] !== '' && $r['password_changed_at'] !== null
                    ? (string) $r['password_changed_at'] : null,
                'role_code' => $role,
                '_pri' => $pri,
            ];
        }
    }

    /**
     * Users linked to this salon: active organization membership and/or branch pin under the org.
     *
     * @return list<array{id:int, email:string, name:string, branch_id:?int, deleted_at:?string, role_codes:string}>
     */
    public function listSalonLinkedPeople(int $organizationId): array
    {
        if ($organizationId <= 0) {
            return [];
        }

        if ($this->membershipPivotExists()) {
            return $this->db->fetchAll(
                "SELECT u.id AS id, u.email AS email, u.name AS name, u.branch_id AS branch_id, u.deleted_at AS deleted_at,
                        GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ',') AS role_codes
                 FROM users u
                 LEFT JOIN user_roles ur ON ur.user_id = u.id
                 LEFT JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
                 WHERE (
                     EXISTS (
                         SELECT 1 FROM user_organization_memberships m
                         WHERE m.user_id = u.id AND m.organization_id = ? AND m.status = 'active'
                     )
                     OR EXISTS (
                         SELECT 1 FROM branches b
                         WHERE b.id = u.branch_id AND b.organization_id = ? AND b.deleted_at IS NULL
                     )
                 )
                 GROUP BY u.id, u.email, u.name, u.branch_id, u.deleted_at
                 ORDER BY u.name ASC, u.id ASC",
                [$organizationId, $organizationId]
            );
        }

        return $this->db->fetchAll(
            "SELECT u.id AS id, u.email AS email, u.name AS name, u.branch_id AS branch_id, u.deleted_at AS deleted_at,
                    GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ',') AS role_codes
             FROM users u
             INNER JOIN branches b ON b.id = u.branch_id AND b.organization_id = ? AND b.deleted_at IS NULL
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
             GROUP BY u.id, u.email, u.name, u.branch_id, u.deleted_at
             ORDER BY u.name ASC, u.id ASC",
            [$organizationId]
        );
    }

    /**
     * @return list<array{id:int, name:string, code:?string, created_at:string, updated_at:string}>
     */
    public function listBranchesForOrganization(int $organizationId): array
    {
        if ($organizationId <= 0) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT id, name, code, created_at, updated_at FROM branches
             WHERE organization_id = ? AND deleted_at IS NULL
             ORDER BY id ASC',
            [$organizationId]
        );
    }
}
