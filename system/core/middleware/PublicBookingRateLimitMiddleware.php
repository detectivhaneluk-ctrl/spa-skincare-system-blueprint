<?php

declare(strict_types=1);

namespace Core\Middleware;

use Core\App\Application;
use Core\App\ClientIp;
use Core\Runtime\RateLimit\RuntimeProtectedPathRateLimiter;

/**
 * Outer IP-level rate gate for public booking hot paths — WAVE-05 live enforcement.
 *
 * Acts as a first-line IP gate on top of the finer-grained per-fingerprint/per-contact/per-slot
 * limits already enforced inside {@see \Modules\OnlineBooking\Controllers\PublicBookingController}.
 *
 * Buckets:
 * - GET  → {@see RuntimeProtectedPathRateLimiter::tryConsumeBookingAvailabilityRead} (120 req / 60 s)
 * - POST → {@see RuntimeProtectedPathRateLimiter::tryConsumeBookingSubmit} (20 req / 600 s)
 *
 * Fail-open on rate-limiter errors: if the backing store (Redis or DB) is unavailable the request
 * is allowed through; the controller's own rate limits remain as the second line of defence.
 */
final class PublicBookingRateLimitMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $ip = ClientIp::forRequest();

        try {
            $lim = Application::container()->get(RuntimeProtectedPathRateLimiter::class);
            $result = $method === 'POST'
                ? $lim->tryConsumeBookingSubmit($ip)
                : $lim->tryConsumeBookingAvailabilityRead($ip);
        } catch (\Throwable) {
            // Fail-open: rate-limiter store unavailable — let the controller's own limits gate.
            $next();
            return;
        }

        if ($result['ok']) {
            $next();
            return;
        }

        $retryAfter = (int) $result['retry_after'];
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $retryAfter);
        echo (string) json_encode([
            'success' => false,
            'error' => [
                'code' => 'RATE_LIMITED',
                'message' => 'Too many requests. Retry after ' . $retryAfter . ' seconds.',
                'retry_after' => $retryAfter,
            ],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
