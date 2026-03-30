<?php

declare(strict_types=1);

namespace Core\Auth;

/**
 * Single decision path for “where should this authenticated user land?” ({@code GET /}, post-login).
 * Delegates to {@see PostLoginHomePathResolver} / {@see UserAccessShapeService}.
 */
final class AuthenticatedHomePathResolver
{
    public const PATH_PLATFORM = PostLoginHomePathResolver::PATH_PLATFORM;

    /** @deprecated Use {@see PATH_TENANT_ENTRY}; kept as alias for legacy smoke/docs referencing “tenant pipeline”. */
    public const PATH_TENANT = PostLoginHomePathResolver::PATH_TENANT_ENTRY;

    public const PATH_TENANT_ENTRY = PostLoginHomePathResolver::PATH_TENANT_ENTRY;

    public const PATH_DASHBOARD = PostLoginHomePathResolver::PATH_TENANT_DASHBOARD;

    public function __construct(
        private PostLoginHomePathResolver $postLogin
    ) {
    }

    public function homePathForUserId(int $userId): string
    {
        return $this->postLogin->homePathForUserId($userId);
    }
}
