<?php

declare(strict_types=1);

namespace Modules\Organizations\Repositories;

use Core\App\Database;

/**
 * Read-only access to {@code user_organization_memberships} for active rows linked to live organizations (F-37 / F-46).
 *
 * **Mutations:** Out of scope — no INSERT/UPDATE/DELETE here.
 */
final class UserOrganizationMembershipReadRepository
{
    /** Cached result of {@see membershipTableAvailable()} (pre-migration DBs may lack F-087 table). */
    private ?bool $membershipTableAvailable = null;

    public function __construct(private Database $db)
    {
    }

    /**
     * When {@code user_organization_memberships} is not present (migration 087 not applied), membership reads
     * return empty answers so {@see \Core\Organization\OrganizationContextResolver} can use legacy fallback.
     */
    /**
     * True when migration 087 applied and {@code user_organization_memberships} exists (F-46 / F-48 gate).
     */
    public function isMembershipTablePresent(): bool
    {
        return $this->membershipTableAvailable();
    }

    private function membershipTableAvailable(): bool
    {
        if ($this->membershipTableAvailable !== null) {
            return $this->membershipTableAvailable;
        }

        $row = $this->db->fetchOne(
            'SELECT 1 AS ok FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            ['user_organization_memberships']
        );
        $this->membershipTableAvailable = $row !== null;

        return $this->membershipTableAvailable;
    }

    /**
     * Count rows where {@code status = 'active'} and organization {@code deleted_at IS NULL}.
     */
    public function countActiveMembershipsForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        if (!$this->membershipTableAvailable()) {
            return 0;
        }

        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS c
             FROM user_organization_memberships m
             INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
             WHERE m.user_id = ? AND m.status = ?',
            [$userId, 'active']
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Distinct active organization ids for the user, {@code organization_id ASC}.
     *
     * @return list<int>
     */
    public function listActiveOrganizationIdsForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if (!$this->membershipTableAvailable()) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT m.organization_id AS organization_id
             FROM user_organization_memberships m
             INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
             WHERE m.user_id = ? AND m.status = ?
             ORDER BY m.organization_id ASC',
            [$userId, 'active']
        );

        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['organization_id'];
        }

        return $ids;
    }

    /**
     * When exactly one qualifying membership exists, returns that {@code organization_id}; otherwise null.
     */
    public function getSingleActiveOrganizationIdForUser(int $userId): ?int
    {
        $ids = $this->listActiveOrganizationIdsForUser($userId);
        if (count($ids) !== 1) {
            return null;
        }

        return $ids[0] > 0 ? $ids[0] : null;
    }
}
