<?php

declare(strict_types=1);

namespace Core\Router;

final class Router
{
    private array $routes = [];
    private array $named = [];

    public function get(string $path, callable|array $handler, array $middleware = [], array $options = []): self
    {
        return $this->add('GET', $path, $handler, $middleware, null, $options);
    }

    public function post(string $path, callable|array $handler, array $middleware = [], array $options = []): self
    {
        return $this->add('POST', $path, $handler, $middleware, null, $options);
    }

    /**
     * @param array<string, mixed> $options Supported:
     *   - {@code csrf_exempt} => true for intentional anonymous/public POST without session CSRF.
     *   - {@code session_early_release} => true with {@see \Core\Middleware\SessionEarlyReleaseMiddleware} on the route (GET-safe only).
     */
    public function add(string $method, string $path, callable|array $handler, array $middleware = [], ?string $name = null, array $options = []): self
    {
        $meta = $this->analyzePath($path);

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'pattern' => $this->pathToRegex($path),
            'handler' => $handler,
            'middleware' => $middleware,
            'options' => $options,
            'meta' => $meta,
            'order' => count($this->routes),
        ];
        if ($name) {
            $this->named[$name] = count($this->routes) - 1;
        }
        return $this;
    }

    public function match(string $method, string $uri): ?array
    {
        $uri = parse_url($uri, PHP_URL_PATH) ?: '/';
        $uri = $uri ?: '/';
        $candidates = [];

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['pattern'], $uri, $m)) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                $candidates[] = [
                    'route' => $route,
                    'params' => $params,
                ];
            }
        }
        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $am = $a['route']['meta'];
            $bm = $b['route']['meta'];
            // More static segments first, then longer paths, then fewer dynamic segments.
            $scoreA = [$am['static_count'], $am['segment_count'], -$am['dynamic_count']];
            $scoreB = [$bm['static_count'], $bm['segment_count'], -$bm['dynamic_count']];
            if ($scoreA === $scoreB) {
                return $a['route']['order'] <=> $b['route']['order'];
            }
            return $scoreB <=> $scoreA;
        });

        $best = $candidates[0];
        $route = $best['route'];

        return [
            'handler' => $route['handler'],
            'params' => $best['params'],
            'middleware' => $route['middleware'],
            'csrf_exempt' => !empty($route['options']['csrf_exempt']),
            'session_early_release' => !empty($route['options']['session_early_release']),
        ];
    }

    public function url(string $name, array $params = []): string
    {
        $idx = $this->named[$name] ?? null;
        if ($idx === null) {
            return '#';
        }
        $route = $this->routes[$idx];
        $path = $route['path'];
        foreach ($params as $k => $v) {
            $path = str_replace('{' . $k . '}', (string) $v, $path);
        }
        return $path;
    }

    private function pathToRegex(string $path): string
    {
        $segments = $this->splitPath($path);
        if (empty($segments)) {
            return '#^/$#';
        }

        $parts = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(:([^}]+))?\}$/', $segment, $m) === 1) {
                $name = $m[1];
                $constraint = $m[3] ?? '[^/]+';
                $parts[] = '(?P<' . $name . '>' . $constraint . ')';
                continue;
            }
            $parts[] = preg_quote($segment, '#');
        }

        return '#^/' . implode('/', $parts) . '$#';
    }

    private function analyzePath(string $path): array
    {
        $segmentsRaw = $this->splitPath($path);
        $segments = [];
        $staticCount = 0;
        $dynamicCount = 0;
        foreach ($segmentsRaw as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)(:([^}]+))?\}$/', $segment, $m) === 1) {
                $segments[] = [
                    'type' => 'dynamic',
                    'name' => $m[1],
                    'constraint' => $m[3] ?? null,
                ];
                $dynamicCount++;
            } else {
                $segments[] = ['type' => 'static', 'value' => $segment];
                $staticCount++;
            }
        }
        return [
            'segments' => $segments,
            'segment_count' => count($segments),
            'static_count' => $staticCount,
            'dynamic_count' => $dynamicCount,
        ];
    }

    private function splitPath(string $path): array
    {
        $trimmed = trim($path);
        $trimmed = trim($trimmed, '/');
        if ($trimmed === '') {
            return [];
        }
        return explode('/', $trimmed);
    }
}
