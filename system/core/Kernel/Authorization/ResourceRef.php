<?php

declare(strict_types=1);

namespace Core\Kernel\Authorization;

/**
 * Reference to the resource being acted upon.
 *
 * resourceType: the domain resource class (e.g. 'appointment', 'client', 'profile-image').
 *              Should match the left side of the ResourceAction naming convention.
 * resourceId:  the specific entity id, or null for collection-level or creation actions.
 *
 * Future ReBAC compatibility: resourceType + resourceId form the "object" in a
 * subject-action-object triple. The TenantContext provides the subject and the
 * org/branch scope for the relationship graph lookup.
 */
final class ResourceRef
{
    public function __construct(
        public readonly string $resourceType,
        public readonly ?int $resourceId = null,
    ) {
    }

    /**
     * Reference to a collection or a creation action (no specific entity yet).
     */
    public static function collection(string $resourceType): self
    {
        return new self($resourceType, null);
    }

    /**
     * Reference to a specific entity instance.
     */
    public static function instance(string $resourceType, int $resourceId): self
    {
        return new self($resourceType, $resourceId);
    }

    public function isCollection(): bool
    {
        return $this->resourceId === null;
    }
}
