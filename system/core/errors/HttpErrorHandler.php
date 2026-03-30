<?php

declare(strict_types=1);

namespace Core\Errors;

use Core\App\Response;
use Core\App\StructuredLogger;
use Throwable;

/**
 * Global error handling. HTML or JSON per Accept header.
 * Conventions: CONVENTIONS.md §3
 *
 * Expected tenant branch/organization scope denials use {@see AccessDeniedException} → HTTP 403 (non-debug), not fragile message matching.
 */
final class HttpErrorHandler
{
    public function __construct(private StructuredLogger $logger)
    {
    }

    private const HTTP_TO_CODE = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHORIZED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        409 => 'CONFLICT',
        419 => 'PAGE_EXPIRED',
        422 => 'VALIDATION_FAILED',
        429 => 'TOO_MANY_ATTEMPTS',
        500 => 'SERVER_ERROR',
    ];

    private const CODE_MESSAGES = [
        'BAD_REQUEST' => 'Invalid request',
        'UNAUTHORIZED' => 'Authentication required',
        'FORBIDDEN' => 'Access denied',
        'NOT_FOUND' => 'Not found',
        'CONFLICT' => 'Conflict',
        'PAGE_EXPIRED' => 'Page expired. Please refresh.',
        'VALIDATION_FAILED' => 'Validation failed',
        'TOO_MANY_ATTEMPTS' => 'Too many attempts. Try again later.',
        'SERVER_ERROR' => 'An error occurred.',
    ];

    public function handle(int $statusCode): void
    {
        http_response_code($statusCode);
        if ($this->wantsJson()) {
            $this->sendJson($statusCode);
            return;
        }
        $this->renderPage($statusCode);
    }

    public function handleException(Throwable $e): void
    {
        if (config('app.debug')) {
            throw $e;
        }
        if ($e instanceof AccessDeniedException) {
            $this->logger->log('warning', 'security.access_denied', $e->getMessage(), []);
            $this->respondForbidden($e->getMessage());

            return;
        }
        $code = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
        $category = $code >= 500 ? 'http.server_error' : 'http.application_error';
        $this->logger->logThrowable($e, $category, ['http_status' => $code]);
        $this->handle($code);
    }

    private function respondForbidden(string $message): void
    {
        http_response_code(403);
        if ($this->wantsJson()) {
            Response::jsonError('FORBIDDEN', $message);

            return;
        }
        $this->renderPage(403);
    }

    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    private function sendJson(int $code): void
    {
        $errCode = self::HTTP_TO_CODE[$code] ?? 'SERVER_ERROR';
        $message = self::CODE_MESSAGES[$errCode] ?? 'An error occurred.';
        Response::jsonError($errCode, $message);
    }

    private function renderPage(int $code): void
    {
        $path = shared_path("layout/errors/{$code}.php");
        if (is_file($path)) {
            require $path;
            return;
        }
        $path = shared_path('layout/errors/500.php');
        if (is_file($path)) {
            require $path;
            return;
        }
        echo "<h1>{$code}</h1><p>An error occurred.</p>";
    }
}
