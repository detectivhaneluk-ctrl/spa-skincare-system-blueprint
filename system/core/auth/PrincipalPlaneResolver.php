<?php

declare(strict_types=1);

namespace Core\Auth;

/**
 * Canonical authenticated principal-plane classifier.
 */
final class PrincipalPlaneResolver
{
    public const CONTROL_PLANE = 'CONTROL_PLANE';
    public const TENANT_PLANE = 'TENANT_PLANE';
    public const BLOCKED_AUTHENTICATED = 'BLOCKED_AUTHENTICATED';

    public function __construct(
        private UserAccessShapeService $accessShape,
    ) {
    }

    public function resolveForUserId(int $userId): string
    {
        return $this->accessShape->principalPlaneForUserId($userId);
    }

    public function isControlPlane(int $userId): bool
    {
        return $this->resolveForUserId($userId) === self::CONTROL_PLANE;
    }

    public function isTenantPlane(int $userId): bool
    {
        return $this->resolveForUserId($userId) === self::TENANT_PLANE;
    }
}
