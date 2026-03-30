<?php

declare(strict_types=1);

namespace Core\App;

use Core\Router\Router;

final class Application
{
    private static ?Container $container = null;

    /** Set per-request after {@see \Core\Router\Dispatcher} matches a route; read by {@see \Core\Middleware\CsrfMiddleware}. */
    private static bool $matchedRouteCsrfExempt = false;

    /** Set per-request when matched route opts into {@see \Core\Middleware\SessionEarlyReleaseMiddleware}. */
    private static bool $matchedRouteSessionEarlyRelease = false;

    public static function resetMatchedRoutePolicy(): void
    {
        self::$matchedRouteCsrfExempt = false;
        self::$matchedRouteSessionEarlyRelease = false;
    }

    public static function setMatchedRouteCsrfExempt(bool $exempt): void
    {
        self::$matchedRouteCsrfExempt = $exempt;
    }

    public static function isMatchedRouteCsrfExempt(): bool
    {
        return self::$matchedRouteCsrfExempt;
    }

    public static function setMatchedRouteSessionEarlyRelease(bool $enabled): void
    {
        self::$matchedRouteSessionEarlyRelease = $enabled;
    }

    public static function isMatchedRouteSessionEarlyRelease(): bool
    {
        return self::$matchedRouteSessionEarlyRelease;
    }

    public static function setContainer(Container $container): void
    {
        self::$container = $container;
    }

    public static function container(): Container
    {
        if (!self::$container) {
            throw new \RuntimeException('Container not initialized');
        }
        return self::$container;
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $config = self::container()->get(Config::class);
        return $config->get($key, $default);
    }

    public function __construct(private string $basePath)
    {
        if (!defined('SYSTEM_PATH')) {
            define('SYSTEM_PATH', $this->basePath);
        }
    }

    public function run(): void
    {
        ApplicationTimezone::applyForHttpRequest();
        if (PHP_SAPI !== 'cli' && (bool) self::config('migration_baseline_enforce', false)) {
            MigrationBaseline::respond503IfNotAligned($this->basePath, self::container()->get(Database::class)->connection());
        }
        $router = $this->buildRouter();
        $dispatcher = new \Core\Router\Dispatcher($router, self::container());
        $dispatcher->dispatch();
    }

    private function buildRouter(): Router
    {
        $router = new Router();
        $this->registerRoutes($router);
        return $router;
    }

    private function registerRoutes(Router $router): void
    {
        require $this->basePath . '/routes/web.php';
    }
}
