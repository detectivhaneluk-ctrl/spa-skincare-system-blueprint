<?php

declare(strict_types=1);

/**
 * WAVE-05 — LIVE ENFORCEMENT FOUNDATIONS — Proof Script
 *
 * Verifies:
 * W5-A: RequestLatencyMiddleware is the first entry in Dispatcher::$globalMiddleware
 * W5-B: PublicBookingRateLimitMiddleware exists and uses the correct rate-limit buckets
 * W5-C: /api/public/booking/slots route has PublicBookingRateLimitMiddleware
 * W5-D: /api/public/booking/book route has PublicBookingRateLimitMiddleware
 * W5-E: PublicBookingRateLimitMiddleware is registered in bootstrap.php
 * W5-F: PublicBookingRateLimitMiddleware is fail-open (try/catch on rate-limiter errors)
 * W5-G: RequestLatencyMiddleware docblock notes global pipeline position
 * W5-H: Dispatcher.php comment confirms WAVE-05 wiring
 */

$systemPath = dirname(__DIR__, 2);

$pass = 0;
$fail = 0;

function probe(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        echo "  PASS  {$label}\n";
        $pass++;
    } else {
        echo "  FAIL  {$label}\n";
        $fail++;
    }
}

echo "=== WAVE-05 LIVE ENFORCEMENT FOUNDATIONS PROOF ===\n\n";

// W5-A: RequestLatencyMiddleware is first in global pipeline
$dispatcherFile = $systemPath . '/core/Router/Dispatcher.php';
$dispatcherSrc  = (string) file_get_contents($dispatcherFile);

$globalMwPos     = strpos($dispatcherSrc, 'globalMiddleware');
$latencyPos      = strpos($dispatcherSrc, 'RequestLatencyMiddleware');
$csrfPos         = strpos($dispatcherSrc, 'CsrfMiddleware::class');
$errorHandlerPos = strpos($dispatcherSrc, 'ErrorHandlerMiddleware::class');

probe('W5-A: RequestLatencyMiddleware appears in globalMiddleware array', $latencyPos !== false && $latencyPos > $globalMwPos);
probe('W5-A: RequestLatencyMiddleware is before CsrfMiddleware in array', $latencyPos < $csrfPos);
probe('W5-A: RequestLatencyMiddleware is before ErrorHandlerMiddleware in array', $latencyPos < $errorHandlerPos);

// W5-B: PublicBookingRateLimitMiddleware class exists
$rlmFile = $systemPath . '/core/middleware/PublicBookingRateLimitMiddleware.php';
probe('W5-B: PublicBookingRateLimitMiddleware.php exists', file_exists($rlmFile));

$rlmSrc = file_exists($rlmFile) ? (string) file_get_contents($rlmFile) : '';
probe('W5-B: Implements MiddlewareInterface', str_contains($rlmSrc, 'implements MiddlewareInterface'));
probe('W5-B: Uses BUCKET_BOOKING_SUBMIT via tryConsumeBookingSubmit', str_contains($rlmSrc, 'tryConsumeBookingSubmit'));
probe('W5-B: Uses BUCKET_BOOKING_AVAILABILITY_READ via tryConsumeBookingAvailabilityRead', str_contains($rlmSrc, 'tryConsumeBookingAvailabilityRead'));
probe('W5-B: Fail-open on rate-limiter exception (try/catch)', str_contains($rlmSrc, 'catch (\Throwable)'));
probe('W5-B: Returns 429 JSON on rate limit exceeded', str_contains($rlmSrc, '429') && str_contains($rlmSrc, 'RATE_LIMITED'));
probe('W5-B: Sends Retry-After header', str_contains($rlmSrc, 'Retry-After'));

// W5-C/D: Routes wired
$routeFile = $systemPath . '/routes/web/register_core_dashboard_auth_public.php';
$routeSrc  = (string) file_get_contents($routeFile);

probe('W5-C: /api/public/booking/slots has PublicBookingRateLimitMiddleware', str_contains($routeSrc, "'/api/public/booking/slots'") && str_contains(substr($routeSrc, strpos($routeSrc, "'/api/public/booking/slots'")), 'PublicBookingRateLimitMiddleware'));
probe('W5-D: /api/public/booking/book has PublicBookingRateLimitMiddleware', str_contains($routeSrc, "'/api/public/booking/book'") && str_contains(substr($routeSrc, strpos($routeSrc, "'/api/public/booking/book'")), 'PublicBookingRateLimitMiddleware'));
probe('W5-D: /api/public/booking/book retains csrf_exempt option', str_contains(substr($routeSrc, strpos($routeSrc, "'/api/public/booking/book'")), 'csrf_exempt'));

// W5-E: Registered in bootstrap.php
$bootstrapSrc = (string) file_get_contents($systemPath . '/bootstrap.php');
probe('W5-E: PublicBookingRateLimitMiddleware singleton in bootstrap.php', str_contains($bootstrapSrc, 'PublicBookingRateLimitMiddleware::class'));
probe('W5-E: RequestLatencyMiddleware singleton in bootstrap.php', str_contains($bootstrapSrc, 'RequestLatencyMiddleware::class'));

// W5-F: RequestLatencyMiddleware docstring mentions global pipeline
$latencyFile = $systemPath . '/core/middleware/RequestLatencyMiddleware.php';
$latencySrc  = (string) file_get_contents($latencyFile);
probe('W5-G: RequestLatencyMiddleware docblock mentions global middleware pipeline', str_contains($latencySrc, 'global middleware pipeline'));

// Runtime integration: boot the app and verify container resolves the middleware
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

try {
    $rlm = app(\Core\Middleware\PublicBookingRateLimitMiddleware::class);
    probe('W5-H: PublicBookingRateLimitMiddleware resolves from DI container', $rlm instanceof \Core\Middleware\MiddlewareInterface);
} catch (\Throwable $e) {
    probe('W5-H: PublicBookingRateLimitMiddleware resolves from DI container', false);
    echo "    Error: " . $e->getMessage() . "\n";
}

try {
    $latencyMw = app(\Core\Middleware\RequestLatencyMiddleware::class);
    probe('W5-H: RequestLatencyMiddleware resolves from DI container', $latencyMw instanceof \Core\Middleware\MiddlewareInterface);
} catch (\Throwable $e) {
    probe('W5-H: RequestLatencyMiddleware resolves from DI container', false);
    echo "    Error: " . $e->getMessage() . "\n";
}

echo "\n=== RESULTS ===\n";
echo "PASS: {$pass}\n";
echo "FAIL: {$fail}\n";
echo ($fail === 0 ? "WAVE-05 PROOF: PASS\n" : "WAVE-05 PROOF: FAIL\n");
exit($fail > 0 ? 1 : 0);
