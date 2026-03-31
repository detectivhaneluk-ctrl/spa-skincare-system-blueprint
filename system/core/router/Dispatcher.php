<?php

declare(strict_types=1);

namespace Core\Router;

use Core\App\Application;
use Core\App\RequestCorrelation;
use Core\Middleware\ErrorHandlerMiddleware;
use Core\Middleware\MiddlewareInterface;
use Core\Storage\Contracts\StorageProviderInterface;
use Core\Storage\StorageKey;

/**
 * HTTP pipeline order (first runs first): {@see \Core\Middleware\CsrfMiddleware} (validates state-changing requests; POST is exempt
 * only when the matched route was registered with `csrf_exempt` => true), {@see ErrorHandlerMiddleware}, {@see \Core\Middleware\BranchContextMiddleware}
 * (resolves branch, then {@see \Core\App\ApplicationTimezone::syncAfterBranchContextResolved()} and `ApplicationContentLanguage`),
 * {@see \Core\Middleware\OrganizationContextMiddleware} (resolves organization from branch or single-org fallback; FOUNDATION-09),
 * then per-route middleware (typically {@see \Core\Middleware\AuthMiddleware},
 * {@see \Core\Middleware\TenantProtectedRouteMiddleware} on tenant-internal modules, then {@see \Core\Middleware\PermissionMiddleware};
 * optional {@see \Core\Middleware\SessionEarlyReleaseMiddleware} last among session writers when route opts in).
 * Authenticated enforcement: session user id + DB user row; permissions are not implied by Auth alone.
 *
 * String middleware entries and array-handler controllers are resolved **only** via the DI container (A-002).
 * Pre-instantiated middleware (e.g. {@see \Core\Middleware\PermissionMiddleware::for}) is passed through unchanged.
 */
final class Dispatcher
{
    private array $globalMiddleware = [
        \Core\Middleware\CsrfMiddleware::class,
        \Core\Middleware\ErrorHandlerMiddleware::class,
        \Core\Middleware\BranchContextMiddleware::class,
        \Core\Middleware\OrganizationContextMiddleware::class,
        // FOUNDATION-A1: materializes immutable TenantContext after branch+org are resolved.
        \Core\Middleware\TenantContextMiddleware::class,
    ];

    public function __construct(
        private Router $router,
        private \Core\App\Container $container
    ) {
    }

    public function dispatch(): void
    {
        RequestCorrelation::reset();
        Application::resetMatchedRoutePolicy();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        if ($method === 'GET' && $this->tryServePublicProcessedMedia($uri)) {
            return;
        }

        $match = $this->router->match($method, $uri);
        if (!$match) {
            $this->container->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }

        Application::setMatchedRouteCsrfExempt((bool) ($match['csrf_exempt'] ?? false));
        Application::setMatchedRouteSessionEarlyRelease((bool) ($match['session_early_release'] ?? false));

        $pipeline = array_merge(
            $this->globalMiddleware,
            $match['middleware'],
            [fn () => $this->invokeHandler($match['handler'], $match['params'])]
        );

        $this->runPipeline($pipeline);
    }

    private function runPipeline(array $pipeline): void
    {
        $run = function (int $i) use ($pipeline, &$run): void {
            if ($i >= count($pipeline)) {
                return;
            }
            $m = $pipeline[$i];
            if (is_string($m)) {
                $m = $this->resolveMiddlewareFromContainer($m);
            }
            if ($i === count($pipeline) - 1) {
                $m();
                return;
            }
            $next = fn () => $run($i + 1);
            $m->handle($next);
        };
        $run(0);
    }

    /**
     * Serves files written by the image pipeline under public/media/processed/ (no DB lookup; allowlist + {@see StorageKey}).
     */
    private function tryServePublicProcessedMedia(string $requestUri): bool
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/media/processed/')) {
            return false;
        }
        $rel = ltrim($path, '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return false;
        }
        if (!$this->container->has(StorageProviderInterface::class)) {
            return false;
        }
        /** @var StorageProviderInterface $storage */
        $storage = $this->container->get(StorageProviderInterface::class);
        try {
            $key = StorageKey::publicMedia($rel);
        } catch (\InvalidArgumentException) {
            return false;
        }
        if (!$storage->isReadableFile($key)) {
            return false;
        }
        try {
            $len = $storage->fileSizeOrFail($key);
        } catch (\RuntimeException) {
            return false;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'avif' => 'image/avif',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) $len);
        try {
            $storage->readStreamToOutput($key);
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    private function invokeHandler(callable|array $handler, array $params): void
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            if (!is_string($class)) {
                throw new \RuntimeException('Routed controller class name must be a string (A-002).');
            }
            if (!$this->container->has($class)) {
                throw new \RuntimeException(
                    'Routed controller `' . $class . '` is not registered in the container. '
                    . 'Add a `singleton` in the appropriate `system/modules/bootstrap/register_*.php` (or `system/bootstrap.php` for core). A-002.'
                );
            }
            $controller = $this->container->get($class);
            call_user_func_array([$controller, $method], array_values($params));
        } else {
            $handler(...array_values($params));
        }
    }

    /**
     * @param class-string $class
     */
    private function resolveMiddlewareFromContainer(string $class): MiddlewareInterface
    {
        if (!$this->container->has($class)) {
            throw new \RuntimeException(
                'Pipeline middleware `' . $class . '` is not registered in the container. '
                . 'Register it in `system/bootstrap.php` next to other global/route middleware bindings. A-002.'
            );
        }
        $m = $this->container->get($class);
        if (!$m instanceof MiddlewareInterface) {
            throw new \RuntimeException('Resolved middleware `' . $class . '` must implement ' . MiddlewareInterface::class . '.');
        }

        return $m;
    }
}
