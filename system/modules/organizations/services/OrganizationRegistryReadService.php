<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Modules\Organizations\Repositories\OrganizationRegistryReadRepository;

/**
 * Minimal read facade for the organization registry (F-37 / F-40).
 * No writes; no HTTP; global listing — not branch- or {@code OrganizationContext}-scoped.
 */
final class OrganizationRegistryReadService
{
    public function __construct(private OrganizationRegistryReadRepository $repository)
    {
    }

    /**
     * @return list<array{id: int|string, name: string, code: string|null, created_at: string, updated_at: string, suspended_at: string|null, deleted_at: string|null}>
     */
    public function listOrganizations(): array
    {
        return $this->repository->listAllOrderedById();
    }

    /**
     * @return array{id: int|string, name: string, code: string|null, created_at: string, updated_at: string, suspended_at: string|null, deleted_at: string|null}|null
     */
    public function getOrganizationById(int $organizationId): ?array
    {
        return $this->repository->findById($organizationId);
    }
}
