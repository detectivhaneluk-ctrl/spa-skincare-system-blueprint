<?php

declare(strict_types=1);

/**
 * Core bootstrap: environment, config, container, and **base** core singletons.
 *
 * **Not a full application container:** bindings that depend on module-registered
 * services (for example {@see \Core\Organization\OrganizationContextResolver}, which
 * needs organization membership services) are registered in `modules/bootstrap.php`.
 * HTTP and any path that runs {@see \Core\Middleware\AuthMiddleware} must load
 * `bootstrap.php` **and** `modules/bootstrap.php` (see `public/index.php`).
 *
 * Scripts that `require` only this file get a **core-only** container: types registered
 * in `modules/bootstrap.php` are absent until that file is loaded.
 */

const SYSTEM_PATH = __DIR__;

require SYSTEM_PATH . '/core/app/helpers.php';

\Core\App\Env::load(SYSTEM_PATH);

$container = new \Core\App\Container();
\Core\App\Application::setContainer($container);

$container->singleton(\Core\App\Config::class, fn () => new \Core\App\Config(SYSTEM_PATH . '/config'));
$container->singleton(\Core\App\Database::class, fn ($c) => new \Core\App\Database($c->get(\Core\App\Config::class)));
$container->singleton(\Core\Auth\UserSessionEpochRepository::class, fn ($c) => new \Core\Auth\UserSessionEpochRepository($c->get(\Core\App\Database::class)));
$container->singleton(\Core\Auth\SessionAuth::class, fn ($c) => new \Core\Auth\SessionAuth(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Auth\UserSessionEpochRepository::class)
));
$container->singleton(\Core\Auth\SessionEpochCoordinator::class, fn ($c) => new \Core\Auth\SessionEpochCoordinator(
    $c->get(\Core\Auth\UserSessionEpochRepository::class),
    $c->get(\Core\Auth\SessionAuth::class)
));
$container->singleton(\Core\Auth\LoginThrottleService::class, fn ($c) => new \Core\Auth\LoginThrottleService($c->get(\Core\App\Database::class)));
$container->singleton(\Core\Auth\UserPasswordResetTokenRepository::class, fn ($c) => new \Core\Auth\UserPasswordResetTokenRepository($c->get(\Core\App\Database::class)));
$container->singleton(\Core\Auth\PasswordResetRequestLogRepository::class, fn ($c) => new \Core\Auth\PasswordResetRequestLogRepository($c->get(\Core\App\Database::class)));
$container->singleton(\Core\Auth\AuthService::class, fn ($c) => new \Core\Auth\AuthService(
    $c->get(\Core\Auth\SessionAuth::class),
    $c->get(\Core\Auth\LoginThrottleService::class),
    $c->get(\Core\Auth\SessionEpochCoordinator::class),
    $c->get(\Core\Auth\UserSessionEpochRepository::class)
));
$container->singleton(\Core\Auth\PrincipalAccessService::class, fn ($c) => new \Core\Auth\PrincipalAccessService($c->get(\Core\App\Database::class)));
$container->singleton(\Core\Branch\BranchContext::class, fn () => new \Core\Branch\BranchContext());
$container->singleton(\Core\Organization\OrganizationContext::class, fn () => new \Core\Organization\OrganizationContext());
$container->singleton(\Core\Organization\OrganizationLifecycleGate::class, fn ($c) => new \Core\Organization\OrganizationLifecycleGate(
    $c->get(\Core\App\Database::class)
));
// OrganizationContextResolver + StaffMultiOrgOrganizationResolutionGate: registered in modules/bootstrap.php
// after module singletons (resolver depends on UserOrganizationMembershipReadService).
$container->singleton(\Core\Organization\OrganizationScopedBranchAssert::class, fn ($c) => new \Core\Organization\OrganizationScopedBranchAssert(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Organization\OrganizationContext::class)
));
$container->singleton(\Core\Organization\OrganizationRepositoryScope::class, fn ($c) => new \Core\Organization\OrganizationRepositoryScope(
    $c->get(\Core\Organization\OrganizationContext::class),
    $c->get(\Core\App\Database::class)
));
$container->singleton(\Core\Branch\BranchDirectory::class, fn ($c) => new \Core\Branch\BranchDirectory(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Organization\OrganizationContext::class),
    $c->get(\Core\Organization\OrganizationScopedBranchAssert::class)
));
$container->singleton(\Core\Branch\TenantBranchAccessService::class, fn ($c) => new \Core\Branch\TenantBranchAccessService(
    $c->get(\Core\App\Database::class)
));
$container->singleton(\Core\Permissions\StaffGroupPermissionRepository::class, fn ($c) => new \Core\Permissions\StaffGroupPermissionRepository($c->get(\Core\App\Database::class)));
$container->singleton(\Modules\Auth\Services\TenantEntryResolverService::class, fn ($c) => new \Modules\Auth\Services\TenantEntryResolverService(
    $c->get(\Core\Branch\TenantBranchAccessService::class)
));
$container->singleton(\Core\Auth\UserAccessShapeService::class, fn ($c) => new \Core\Auth\UserAccessShapeService(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Auth\PrincipalAccessService::class),
    $c->get(\Core\Branch\TenantBranchAccessService::class),
    $c->get(\Modules\Auth\Services\TenantEntryResolverService::class),
    $c->get(\Core\Organization\OrganizationLifecycleGate::class)
));
$container->singleton(\Core\Auth\PostLoginHomePathResolver::class, fn ($c) => new \Core\Auth\PostLoginHomePathResolver(
    $c->get(\Core\Auth\UserAccessShapeService::class)
));
$container->singleton(\Core\Auth\PrincipalPlaneResolver::class, fn ($c) => new \Core\Auth\PrincipalPlaneResolver(
    $c->get(\Core\Auth\UserAccessShapeService::class)
));
$container->singleton(\Core\Permissions\PermissionService::class, fn ($c) => new \Core\Permissions\PermissionService(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Branch\BranchContext::class),
    $c->get(\Core\Permissions\StaffGroupPermissionRepository::class)
));
$container->singleton(\Core\Auth\AuthenticatedHomePathResolver::class, fn ($c) => new \Core\Auth\AuthenticatedHomePathResolver(
    $c->get(\Core\Auth\PostLoginHomePathResolver::class)
));
$container->singleton(\Core\Audit\AuditService::class, fn ($c) => new \Core\Audit\AuditService($c->get(\Core\App\Database::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Core\Runtime\Jobs\RuntimeExecutionRegistry::class, fn ($c) => new \Core\Runtime\Jobs\RuntimeExecutionRegistry(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\App\Config::class)
));
$container->singleton(\Core\Runtime\Queue\RuntimeAsyncJobRepository::class, fn ($c) => new \Core\Runtime\Queue\RuntimeAsyncJobRepository(
    $c->get(\Core\App\Database::class)
));
$container->singleton(\Core\Runtime\Cache\SharedCacheMetrics::class, fn () => new \Core\Runtime\Cache\SharedCacheMetrics());
$container->singleton(\Core\Contracts\SharedCacheInterface::class, function ($c) {
    /** @var \Core\Runtime\Cache\SharedCacheMetrics $metrics */
    $metrics = $c->get(\Core\Runtime\Cache\SharedCacheMetrics::class);
    $config = $c->get(\Core\App\Config::class);
    $url = trim((string) $config->get('app.redis_url', ''));
    $prefix = (string) $config->get('app.redis_key_prefix', 'spa');
    $inner = null;
    if ($url !== '' && extension_loaded('redis')) {
        try {
            $redis = \Core\Runtime\Redis\RedisFactory::connect($url);
            $inner = new \Core\Runtime\Cache\RedisSharedCache($redis, $prefix);
            $metrics->setBackend('redis');
        } catch (\Throwable) {
            $metrics->setBackend('redis_connect_failed');
            $inner = new \Core\Runtime\Cache\NoopSharedCache();
        }
    } else {
        if ($url !== '' && !extension_loaded('redis')) {
            $metrics->setBackend('redis_extension_missing');
        } else {
            $metrics->setBackend('noop');
        }
        $inner = new \Core\Runtime\Cache\NoopSharedCache();
    }

    return new \Core\Runtime\Cache\InstrumentedSharedCache($inner, $metrics);
});
$container->singleton(\Core\App\SettingsService::class, fn ($c) => new \Core\App\SettingsService(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Organization\OrganizationContext::class),
    $c->get(\Core\Contracts\SharedCacheInterface::class)
));
$container->singleton(\Core\App\StructuredLogger::class, fn () => new \Core\App\StructuredLogger());
$container->singleton(\Core\Errors\HttpErrorHandler::class, fn ($c) => new \Core\Errors\HttpErrorHandler($c->get(\Core\App\StructuredLogger::class)));
$container->singleton(\Core\Tenant\TenantRuntimeContextEnforcer::class, fn ($c) => new \Core\Tenant\TenantRuntimeContextEnforcer(
    $c->get(\Core\Auth\PrincipalPlaneResolver::class),
    $c->get(\Core\Branch\BranchContext::class),
    $c->get(\Core\Organization\OrganizationContext::class),
    $c->get(\Core\Organization\OrganizationLifecycleGate::class)
));
$container->singleton(\Core\Tenant\TenantOwnedDataScopeGuard::class, fn ($c) => new \Core\Tenant\TenantOwnedDataScopeGuard(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Branch\BranchContext::class),
    $c->get(\Core\Organization\OrganizationContext::class)
));

// FOUNDATION-A1: TenantContext kernel — resolved-once immutable context per request.
$container->singleton(\Core\Kernel\RequestContextHolder::class, fn () => new \Core\Kernel\RequestContextHolder());
$container->singleton(\Core\Kernel\TenantContextResolver::class, fn ($c) => new \Core\Kernel\TenantContextResolver(
    $c->get(\Core\Auth\SessionAuth::class),
    $c->get(\Core\Branch\BranchContext::class),
    $c->get(\Core\Organization\OrganizationContext::class),
    $c->get(\Core\Auth\PrincipalAccessService::class),
));
// FOUNDATION-A2: Authorization kernel — deny-by-default until full policy layer is installed.
$container->singleton(\Core\Kernel\Authorization\AuthorizerInterface::class, fn () => new \Core\Kernel\Authorization\DenyAllAuthorizer());

// Global + common route middleware and root controller: {@see \Core\Router\Dispatcher} resolves string middleware/controllers
// only via the container (A-002). PermissionMiddleware::for() remains per-route object instances.
$container->singleton(\Core\Middleware\CsrfMiddleware::class, fn () => new \Core\Middleware\CsrfMiddleware());
$container->singleton(\Core\Middleware\SessionEarlyReleaseMiddleware::class, fn () => new \Core\Middleware\SessionEarlyReleaseMiddleware());
$container->singleton(\Core\Middleware\ErrorHandlerMiddleware::class, fn () => new \Core\Middleware\ErrorHandlerMiddleware());
$container->singleton(\Core\Middleware\BranchContextMiddleware::class, fn () => new \Core\Middleware\BranchContextMiddleware());
$container->singleton(\Core\Middleware\OrganizationContextMiddleware::class, fn () => new \Core\Middleware\OrganizationContextMiddleware());
$container->singleton(\Core\Middleware\TenantContextMiddleware::class, fn () => new \Core\Middleware\TenantContextMiddleware());
$container->singleton(\Core\Middleware\AuthMiddleware::class, fn () => new \Core\Middleware\AuthMiddleware());
$container->singleton(\Core\Middleware\GuestMiddleware::class, fn () => new \Core\Middleware\GuestMiddleware());
$container->singleton(\Core\Middleware\TenantProtectedRouteMiddleware::class, fn () => new \Core\Middleware\TenantProtectedRouteMiddleware());
$container->singleton(\Core\Middleware\TenantPrincipalMiddleware::class, fn () => new \Core\Middleware\TenantPrincipalMiddleware());
$container->singleton(\Core\Middleware\PlatformPrincipalMiddleware::class, fn () => new \Core\Middleware\PlatformPrincipalMiddleware());
$container->singleton(\Core\Middleware\PlatformManagePostRateLimitMiddleware::class, fn () => new \Core\Middleware\PlatformManagePostRateLimitMiddleware());
$container->singleton(\Core\Router\RootController::class, fn () => new \Core\Router\RootController());

return $container;
