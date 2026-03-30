<?php

declare(strict_types=1);

require __DIR__ . '/autoload.php';

function env(string $key, mixed $default = null): mixed
{
    return \Core\App\Env::get($key, $default);
}

function config(string $key, mixed $default = null): mixed
{
    return \Core\App\Application::config($key, $default);
}

function app(?string $id = null): mixed
{
    $container = \Core\App\Application::container();
    if ($id === null || $id === '') {
        return $container;
    }

    return $container->get($id);
}

function base_path(string $path = ''): string
{
    if (!defined('SYSTEM_PATH') || SYSTEM_PATH === '') {
        throw new \RuntimeException(
            'SYSTEM_PATH must be defined (load system/bootstrap.php) before base_path(); fallback to system/core was removed (M-007).'
        );
    }

    return rtrim(SYSTEM_PATH, '/') . ($path ? '/' . ltrim($path, '/') : '');
}

function view_path(string $path = ''): string
{
    return base_path('modules/' . $path);
}

function shared_path(string $path = ''): string
{
    return base_path('shared/' . $path);
}

function flash(?string $key = null, mixed $value = null): mixed
{
    if (session_status() === PHP_SESSION_NONE) {
        \Core\App\Application::container()->get(\Core\Auth\SessionAuth::class)->startSession();
    }
    if ($key === null) {
        $v = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return $v;
    }
    if ($value === null) {
        $v = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        if (empty($_SESSION['_flash'])) {
            unset($_SESSION['_flash']);
        }

        return $v;
    }
    if (!isset($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        $_SESSION['_flash'] = [];
    }
    $_SESSION['_flash'][$key] = $value;

    return null;
}

/**
 * Structured application log line (JSON via {@see \Core\App\StructuredLogger}); falls back to one-line JSON via {@see error_log()}
 * ({@code spa_structured_slog_fallback_v1}) if the container/logger is unavailable — TRANSPORT-RESIDUAL-01.
 *
 * @param array<string, mixed> $context
 */
function slog(string $level, string $category, string $message, array $context = []): void
{
    try {
        \Core\App\Application::container()->get(\Core\App\StructuredLogger::class)->log($level, $category, $message, $context);
    } catch (\Throwable) {
        $ts = gmdate('c');
        $line = json_encode([
            'ts' => $ts,
            '@timestamp' => $ts,
            'log_schema' => 'spa_structured_slog_fallback_v1',
            'severity' => $level,
            'level' => $level,
            'category' => $category,
            'event_code' => $category,
            'message' => $message,
            'spa_application' => 'spa-backend',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        error_log($line !== false ? $line : '[' . $category . '] ' . $message);
    }
}
