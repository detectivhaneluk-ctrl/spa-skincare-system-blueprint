<?php

declare(strict_types=1);

namespace Modules\Organizations\Repositories;

use Core\App\Database;

/**
 * Cross-tenant user listing for founder access management (read-only SQL).
 */
final class PlatformTenantAccessReadRepository
{
    private const EMAIL_FILTER_MAX_LEN = 120;

    public function __construct(private Database $db)
    {
    }

    public function userMembershipPivotExists(): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['user_organization_memberships']
        ) !== null;
    }

    /**
     * @return list<array{id:int,email:string,name:string,branch_id:?int,deleted_at:?string,role_codes:string}>
     */
    public function listUsersForAccessMatrix(int $limit, ?string $emailQuery, ?int $organizationIdFilter): array
    {
        $limit = max(1, min(500, $limit));
        $params = [];
        $where = '1=1';
        if ($emailQuery !== null && $emailQuery !== '') {
            $needle = strtolower(substr(trim($emailQuery), 0, self::EMAIL_FILTER_MAX_LEN));
            if ($needle !== '') {
                $where .= ' AND u.email LIKE ?';
                $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $needle) . '%';
            }
        }
        if ($organizationIdFilter !== null && $organizationIdFilter > 0) {
            $m = $this->db->fetchOne(
                'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
                ['user_organization_memberships']
            );
            if ($m !== null) {
                $where .= ' AND EXISTS (SELECT 1 FROM user_organization_memberships m WHERE m.user_id = u.id AND m.organization_id = ?)';
                $params[] = $organizationIdFilter;
            }
        }
        $sql = "SELECT u.id AS id, u.email AS email, u.name AS name, u.branch_id AS branch_id, u.deleted_at AS deleted_at,
                GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ',') AS role_codes
                FROM users u
                LEFT JOIN user_roles ur ON ur.user_id = u.id
                LEFT JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
                WHERE {$where}
                GROUP BY u.id, u.email, u.name, u.branch_id, u.deleted_at
                ORDER BY u.id ASC
                LIMIT {$limit}";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public function listOrganizationsBrief(): array
    {
        return $this->db->fetchAll(
            'SELECT id, name FROM organizations WHERE deleted_at IS NULL ORDER BY name ASC, id ASC LIMIT 500'
        );
    }

    /**
     * @return list<array{id:int,name:string,code:string,organization_id:int}>
     */
    public function listBranchesBrief(): array
    {
        return $this->db->fetchAll(
            'SELECT b.id AS id, b.name AS name, b.code AS code, b.organization_id AS organization_id
             FROM branches b
             WHERE b.deleted_at IS NULL
             ORDER BY b.organization_id ASC, b.id ASC
             LIMIT 2000'
        );
    }

    /**
     * @return array{id:int,email:string,name:string,branch_id:?int,deleted_at:?string,role_codes:string}|null
     */
    public function fetchUserForAccessMatrixRow(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $row = $this->db->fetchOne(
            "SELECT u.id AS id, u.email AS email, u.name AS name, u.branch_id AS branch_id, u.deleted_at AS deleted_at,
                    GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ',') AS role_codes
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id AND r.deleted_at IS NULL
             WHERE u.id = ?
             GROUP BY u.id, u.email, u.name, u.branch_id, u.deleted_at
             LIMIT 1",
            [$userId]
        );

        return $row === null ? null : $row;
    }

    /**
     * @return array{id:int,name:string,organization_id:int,organization_name:string}|null
     */
    public function fetchBranchGuardrailRow(int $branchId): ?array
    {
        if ($branchId <= 0) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT b.id AS id, b.name AS name, b.organization_id AS organization_id, o.name AS organization_name
             FROM branches b
             INNER JOIN organizations o ON o.id = b.organization_id
             WHERE b.id = ? AND b.deleted_at IS NULL
             LIMIT 1',
            [$branchId]
        );
    }
}
