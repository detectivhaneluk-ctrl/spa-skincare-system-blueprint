<?php

declare(strict_types=1);

/**
 * A-004 read-only: CSRF exemption is route-declared (`csrf_exempt`), not a middleware path allowlist.
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_csrf_policy_route_registration_a004_01.php
 */
$systemRoot = dirname(__DIR__, 2);

require $systemRoot . '/core/router/Router.php';

function src(string $relativeFromSystem): string
{
    global $systemRoot;

    return (string) file_get_contents($systemRoot . '/' . $relativeFromSystem);
}

$csrf = src('core/middleware/CsrfMiddleware.php');
$app = src('core/app/Application.php');
$disp = src('core/router/Dispatcher.php');
$routes = src('routes/web/register_core_dashboard_auth_public.php');
$intake = src('modules/intake/routes/web.php');

$router = new \Core\Router\Router();
$router->post('/__a004_probe', [stdClass::class, '__invoke'], [], ['csrf_exempt' => true]);
$match = $router->match('POST', '/__a004_probe');

$checks = [
    'CsrfMiddleware: no hard-coded /api/public/booking/book path list' => !str_contains($csrf, "'/api/public/booking/book'")
        && !str_contains($csrf, 'isPublicBookingNoAuthPost'),
    'CsrfMiddleware: uses Application::isMatchedRouteCsrfExempt' => str_contains($csrf, 'isMatchedRouteCsrfExempt'),
    'Application: matched-route CSRF flags' => str_contains($app, 'isMatchedRouteCsrfExempt')
        && str_contains($app, 'resetMatchedRoutePolicy'),
    'Dispatcher: sets CSRF flag from match' => str_contains($disp, 'setMatchedRouteCsrfExempt')
        && str_contains($disp, 'resetMatchedRoutePolicy'),
    'register_core: public POST routes declare csrf_exempt' => substr_count($routes, "'csrf_exempt' => true") >= 5,
    'intake public submit declares csrf_exempt' => str_contains($intake, "/public/intake/submit'")
        && str_contains($intake, "'csrf_exempt' => true"),
    'Router::match exposes csrf_exempt for exempt POST' => ($match['csrf_exempt'] ?? null) === true,
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
