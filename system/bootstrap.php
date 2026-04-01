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
// WAVE-07: ReadWriteConnectionResolver — manages primary + replica PDO connections.
// Attached to Database after construction. Resolver is a no-op (no-split) when
// DB_REPLICA_HOST is empty or DB_READ_WRITE_ROUTING is not true.
$container->singleton(\Core\App\ReadWriteConnectionResolver::class, function ($c) {
    $config = $c->get(\Core\App\Config::class);
    $db = $config->get('database');
    $primaryCfg = [
        'host'     => $db['host'],
        'port'     => (int) $db['port'],
        'database' => $db['database'],
        'username' => $db['username'],
        'password' => $db['password'],
        'charset'  => $db['charset'],
    ];
    $replicaHost = trim((string) ($db['replica_host'] ?? ''));
    $routingEnabled = (bool) ($db['read_write_routing_enabled'] ?? false);
    $replicaCfg = null;
    if ($routingEnabled && $replicaHost !== '') {
        $replicaCfg = [
            'host'     => $replicaHost,
            'port'     => (int) ($db['replica_port'] ?? 3306),
            'database' => $db['database'],
            'username' => $db['username'],
            'password' => $db['password'],
            'charset'  => $db['charset'],
        ];
    }
    return new \Core\App\ReadWriteConnectionResolver($primaryCfg, $replicaCfg);
});
$container->singleton(\Core\App\Database::class, function ($c) {
    $db = new \Core\App\Database($c->get(\Core\App\Config::class));
    $db->setReadWriteResolver($c->get(\Core\App\ReadWriteConnectionResolver::class));
    return $db;
});
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
    $c->get(\Core\Permissions\StaffGroupPermissionRepository::class),
    $c->get(\Core\Contracts\SharedCacheInterface::class),
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
// WAVE-01: Centralised Redis connection — shared by cache, session handler, and distributed lock.
$container->singleton(\Core\Runtime\Redis\RedisConnectionProvider::class, function ($c) {
    $config = $c->get(\Core\App\Config::class);
    $url = trim((string) $config->get('app.redis_url', ''));
    if ($url === '') {
        return new \Core\Runtime\Redis\RedisConnectionProvider(null, 'noop');
    }
    if (!extension_loaded('redis')) {
        return new \Core\Runtime\Redis\RedisConnectionProvider(null, 'redis_extension_missing');
    }
    try {
        $redis = \Core\Runtime\Redis\RedisFactory::connect($url);
        return new \Core\Runtime\Redis\RedisConnectionProvider($redis, 'redis');
    } catch (\Throwable) {
        return new \Core\Runtime\Redis\RedisConnectionProvider(null, 'redis_connect_failed');
    }
});
$container->singleton(\Core\Contracts\SharedCacheInterface::class, function ($c) {
    /** @var \Core\Runtime\Cache\SharedCacheMetrics $metrics */
    $metrics = $c->get(\Core\Runtime\Cache\SharedCacheMetrics::class);
    /** @var \Core\Runtime\Redis\RedisConnectionProvider $provider */
    $provider = $c->get(\Core\Runtime\Redis\RedisConnectionProvider::class);
    $config = $c->get(\Core\App\Config::class);
    $prefix = (string) $config->get('app.redis_key_prefix', 'spa');
    $metrics->setBackend($provider->backend());
    if ($provider->isConnected()) {
        $inner = new \Core\Runtime\Cache\RedisSharedCache($provider->redis(), $prefix);
    } else {
        $inner = new \Core\Runtime\Cache\NoopSharedCache();
    }
    return new \Core\Runtime\Cache\InstrumentedSharedCache($inner, $metrics);
});
// WAVE-01: Distributed lock — Redis primary (production), MySQL advisory lock fallback (non-production dev).
$container->singleton(\Core\Contracts\DistributedLockInterface::class, function ($c) {
    /** @var \Core\Runtime\Redis\RedisConnectionProvider $provider */
    $provider = $c->get(\Core\Runtime\Redis\RedisConnectionProvider::class);
    $config = $c->get(\Core\App\Config::class);
    $prefix = (string) $config->get('app.redis_key_prefix', 'spa');
    if ($provider->isConnected()) {
        return new \Core\Runtime\Redis\RedisDistributedLock($provider->redis(), $prefix);
    }
    return new \Core\Runtime\Redis\MysqlDistributedLock($c->get(\Core\App\Database::class));
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
// FOUNDATION-A2: Authorization kernel — real PolicyAuthorizer now installed (BIG-04).
// DenyAllAuthorizer replaced with PolicyAuthorizer that integrates with PermissionService.
// Founder full allow, support-actor read-only, tenant permission-based, all else deny-by-default.
$container->singleton(\Core\Kernel\Authorization\AuthorizerInterface::class, fn ($c) => new \Core\Kernel\Authorization\PolicyAuthorizer(
    $c->get(\Core\Permissions\PermissionService::class)
));

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
// PLT-AUTH-02: AuthorizationMiddleware is per-route, not a singleton (instantiated via ::forAction() factory).
// The class is autoloaded; no container singleton registration needed for the middleware class itself.
$container->singleton(\Core\Router\RootController::class, fn () => new \Core\Router\RootController());

// WAVE-01: Eager-resolve Redis provider + cache to trigger backend detection before any request work.
// This ensures ProductionRuntimeGuard can evaluate the real backend state at bootstrap time.
$redisProvider = $container->get(\Core\Runtime\Redis\RedisConnectionProvider::class);
$container->get(\Core\Contracts\SharedCacheInterface::class); // populates SharedCacheMetrics::backend()

// WAVE-01: Production runtime guard — fail-closed: 503 + exit if Redis unavailable in production.
\Core\Runtime\Guard\ProductionRuntimeGuard::assertRedisOrDie(
    $container->get(\Core\App\Config::class),
    $container->get(\Core\Runtime\Cache\SharedCacheMetrics::class)
);

// WAVE-01: Redis session handler — register before any session_start().
// Replaces file-based sessions with Redis-backed sessions for multi-server readiness.
// In non-production without Redis, falls back to PHP default file sessions silently.
{
    $config = $container->get(\Core\App\Config::class);
    $prefix = (string) $config->get('app.redis_key_prefix', 'spa');
    $lifetime = max(60, (int) $config->get('session.lifetime', 120)) * 60;
    \Core\Runtime\Redis\RedisSessionHandler::registerIfAvailable($redisProvider, $prefix, $lifetime);
}

// WAVE-03: Slow query logger — tenant-aware query latency observability.
// Threshold configurable via SLOW_QUERY_THRESHOLD_MS (default 500 ms).
// RequestContextHolder is injected so each slow query entry includes org_id/branch_id/actor_id.
$container->singleton(\Core\Observability\SlowQueryLogger::class, fn ($c) => new \Core\Observability\SlowQueryLogger(
    (float) ($c->get(\Core\App\Config::class)->get('observability.slow_query_threshold_ms', 500)),
    $c->get(\Core\Kernel\RequestContextHolder::class)
));
// Wire slow query logger to Database singleton.
$container->get(\Core\App\Database::class)->setSlowQueryLogger(
    $container->get(\Core\Observability\SlowQueryLogger::class)
);

// WAVE-03: Request latency middleware — logs slow HTTP requests with tenant context.
// Threshold configurable via SLOW_REQUEST_THRESHOLD_MS (default 1000 ms).
$container->singleton(\Core\Middleware\RequestLatencyMiddleware::class, fn ($c) => new \Core\Middleware\RequestLatencyMiddleware(
    $c->get(\Core\Kernel\RequestContextHolder::class),
    (float) ($c->get(\Core\App\Config::class)->get('observability.slow_request_threshold_ms', 1000))
));

// WAVE-05: Public booking outer IP-level rate gate — fail-open, supplemental to controller's own limits.
$container->singleton(\Core\Middleware\PublicBookingRateLimitMiddleware::class, fn () => new \Core\Middleware\PublicBookingRateLimitMiddleware());

return $container;