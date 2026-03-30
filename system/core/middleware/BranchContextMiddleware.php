<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;
use Core\App\ApplicationContentLanguage;
use Core\App\ApplicationTimezone;
use Core\Auth\PrincipalAccessService;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Core\Branch\TenantBranchAccessService;

/**
 * Resolves current branch from authenticated user/session and sets BranchContext.
 * TENANT-BOUNDARY-HARDENING-01: request query/body must not implicitly mutate branch context.
 * Runs after session is available; does not require AuthMiddleware (reads session directly).
 * Only **active** branches ({@see BranchDirectory::isActiveBranchId}) are used; soft-deleted rows never become current context.
 */
final class BranchContextMiddleware implements MiddlewareInterface
{
    private const SESSION_KEY = 'branch_id';

    public function handle(callable $next): void
    {
        $auth = Application::container()->get(\Core\Auth\SessionAuth::class);
        $context = Application::container()->get(BranchContext::class);
        $dir = Application::container()->get(BranchDirectory::class);
        $principalAccess = Application::container()->get(PrincipalAccessService::class);
        $tenantBranchAccess = Application::container()->get(TenantBranchAccessService::class);

        $user = $auth->user();
        if (!$user) {
            $context->setCurrentBranchId(null);
            ApplicationTimezone::syncAfterBranchContextResolved();
            ApplicationContentLanguage::applyAfterBranchContextResolved();
            $next();

            return;
        }

        $userId = isset($user['id']) ? (int) $user['id'] : 0;

        $fromSession = isset($_SESSION[self::SESSION_KEY]) && $_SESSION[self::SESSION_KEY] !== ''
            ? (int) $_SESSION[self::SESSION_KEY]
            : null;
        if ($fromSession !== null && ($fromSession <= 0 || !$dir->isActiveBranchId($fromSession))) {
            unset($_SESSION[self::SESSION_KEY]);
            $fromSession = null;
        }

        $resolved = null;
        if ($principalAccess->isPlatformPrincipal($userId)) {
            // Platform routes are not branch-scoped by this middleware.
            $resolved = null;
            unset($_SESSION[self::SESSION_KEY]);
        } else {
            $allowedBranchIds = $tenantBranchAccess->allowedBranchIdsForUser($userId);
            $defaultBranchId = $tenantBranchAccess->defaultAllowedBranchIdForUser($userId);
            if ($fromSession !== null && in_array($fromSession, $allowedBranchIds, true)) {
                $resolved = $fromSession;
            } elseif ($defaultBranchId !== null && in_array($defaultBranchId, $allowedBranchIds, true)) {
                $resolved = $defaultBranchId;
            }
            if ($resolved === null) {
                unset($_SESSION[self::SESSION_KEY]);
            }
        }

        if ($resolved !== null && ($resolved <= 0 || !$dir->isActiveBranchId($resolved))) {
            $resolved = null;
        }

        $context->setCurrentBranchId($resolved);
        if ($resolved !== null) {
            $_SESSION[self::SESSION_KEY] = $resolved;
        }

        ApplicationTimezone::syncAfterBranchContextResolved();
        ApplicationContentLanguage::applyAfterBranchContextResolved();
        $next();
    }
}
