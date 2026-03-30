<?php

declare(strict_types=1);

namespace Core\Auth;

/**
 * Single post-login / authenticated-home redirect path ({@see AuthenticatedHomePathResolver}).
 * SUPER-ADMIN-LOGIN-CONTROL-PLANE-CANONICALIZATION-01.
 */
final class PostLoginHomePathResolver
{
    public const PATH_PLATFORM = '/platform-admin';

    public const PATH_TENANT_ENTRY = '/tenant-entry';

    public const PATH_TENANT_DASHBOARD = '/dashboard';

    public function __construct(
        private UserAccessShapeService $accessShape,
    ) {
    }

    public function homePathForUserId(int $userId): string
    {
        return $this->accessShape->expectedHomePathForUserId($userId);
    }
}
