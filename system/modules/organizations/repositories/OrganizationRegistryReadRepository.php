<?php

declare(strict_types=1);

namespace Modules\Organizations\Repositories;

use Core\App\Database;

/**
 * Platform / control-plane read access to the {@code organizations} table.
 *
 * **Scope:** Global registry reads — **not** filtered by {@see \Core\Organization\OrganizationContext}.
 * Callers enforcing {@code platform.organizations.*} or tenancy rules belong in future HTTP/guard waves.
 */
final class OrganizationRegistryReadRepository
{
    public function __construct(private Database $db)
    {
    }

    /**
     * All organization rows for registry/admin listing. Deterministic order: {@code id ASC}.
     *
     * @return list<array{id: int|string, name: string, code: string|null, created_at: string, updated_at: string, suspended_at: string|null, deleted_at: string|null}>
     */
    public function listAllOrderedById(): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, code, created_at, updated_at, suspended_at, deleted_at
             FROM organizations
             ORDER BY id ASC'
        );
    }

    /**
     * Single row by primary key, or null if missing.
     *
     * @return array{id: int|string, name: string, code: string|null, created_at: string, updated_at: string, suspended_at: string|null, deleted_at: string|null}|null
     */
    public function findById(int $organizationId): ?array
    {
        if ($organizationId <= 0) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT id, name, code, created_at, updated_at, suspended_at, deleted_at
             FROM organizations
             WHERE id = ?',
            [$organizationId]
        );

        return $row === null ? null : $row;
    }
}
