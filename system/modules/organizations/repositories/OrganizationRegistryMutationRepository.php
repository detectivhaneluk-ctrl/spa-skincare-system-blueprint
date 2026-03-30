<?php

declare(strict_types=1);

namespace Modules\Organizations\Repositories;

use Core\App\Database;

/**
 * Control-plane writes to {@code organizations} only (F-37 S4 minimal slice).
 *
 * **Scope:** Global — no {@see \Core\Organization\OrganizationContext} filtering.
 */
final class OrganizationRegistryMutationRepository
{
    public function __construct(private Database $db)
    {
    }

    public function insertOrganization(string $name, ?string $code): int
    {
        return $this->db->insert('organizations', [
            'name' => $name,
            'code' => $code,
        ]);
    }

    /**
     * Resolve another row’s id by non-null {@code code}, or null if unused.
     */
    public function findOrganizationIdByCode(string $code): ?int
    {
        if ($code === '') {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT id FROM organizations WHERE code = ? LIMIT 1',
            [$code]
        );

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * @param array<string, mixed> $patch Columns to set (allowed: name, code — caller-validated)
     */
    public function updateProfile(int $organizationId, array $patch): void
    {
        if ($patch === []) {
            return;
        }

        $cols = array_map(static fn (string $k): string => "{$k} = ?", array_keys($patch));
        $vals = array_values($patch);
        $vals[] = $organizationId;
        $this->db->query(
            'UPDATE organizations SET ' . implode(', ', $cols) . ' WHERE id = ?',
            $vals
        );
    }

    public function setSuspendedAtToNow(int $organizationId): void
    {
        $this->db->query(
            'UPDATE organizations SET suspended_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$organizationId]
        );
    }

    public function setSuspendedAtToNull(int $organizationId): void
    {
        $this->db->query(
            'UPDATE organizations SET suspended_at = NULL WHERE id = ?',
            [$organizationId]
        );
    }

    /** Soft archive: sets {@code deleted_at} (founder end-of-life). Idempotent if already archived. */
    public function setDeletedAtToNow(int $organizationId): void
    {
        $this->db->query(
            'UPDATE organizations SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL',
            [$organizationId]
        );
    }
}
