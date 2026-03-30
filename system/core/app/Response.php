<?php

declare(strict_types=1);

namespace Core\App;

/**
 * Standard response helpers. Single structure for JSON and error handling.
 *
 * **Canonical public/API JSON error envelope (API-ERROR-CONTRACT-AND-STATUS-CODE-HARDENING-01):**
 * `{ "success": false, "error": { "code": "SNAKE_UPPER", "message": "…", "details": {…}? } }`
 * HTTP status is set explicitly per endpoint (do not infer from client bugs). Use {@see self::jsonPublicApiError}
 * for anonymous JSON routes; {@see self::jsonError} remains for global HttpErrorHandler (maps `code` → status).
 */
final class Response
{
    /**
     * Public/anonymous JSON API errors with explicit HTTP status and optional RFC-style Retry-After (seconds).
     */
    public static function jsonPublicApiError(
        int $httpStatus,
        string $code,
        string $message,
        ?array $details = null,
        ?int $retryAfterSeconds = null
    ): void {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            header('Retry-After: ' . (string) $retryAfterSeconds);
        }
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ], static fn ($v) => $v !== null),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function jsonSuccess(mixed $data = null, ?array $meta = null): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_filter([
            'success' => true,
            'data' => $data,
            'meta' => $meta,
        ], fn ($v) => $v !== null));
    }

    public static function jsonError(string $code, string $message, ?array $details = null): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(self::codeToHttp($code));
        echo json_encode([
            'success' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ], fn ($v) => $v !== null),
        ]);
    }

    public static function codeToHttp(string $code): int
    {
        return match ($code) {
            'BAD_REQUEST' => 400,
            'UNAUTHORIZED' => 401,
            'FORBIDDEN' => 403,
            'NOT_FOUND' => 404,
            'METHOD_NOT_ALLOWED' => 405,
            'CONFLICT' => 409,
            'PAGE_EXPIRED' => 419,
            'VALIDATION_FAILED' => 422,
            'TOO_MANY_ATTEMPTS' => 429,
            default => 500,
        };
    }
}
