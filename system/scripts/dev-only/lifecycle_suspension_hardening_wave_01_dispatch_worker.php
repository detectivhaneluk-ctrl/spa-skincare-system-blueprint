<?php

declare(strict_types=1);

/**
 * One-shot Dispatcher smoke worker (JSON Accept for deterministic error codes).
 *
 * argv: state.json, result.json, METHOD, URI
 */

if ($argc < 5) {
    fwrite(STDERR, "Usage: php lifecycle_suspension_hardening_wave_01_dispatch_worker.php <state.json> <result.json> <METHOD> <URI>\n");
    exit(2);
}

$statePath = $argv[1];
$resultPath = $argv[2];
$method = strtoupper($argv[3]);
$requestUri = $argv[4];

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Core\App\Application;
use Core\App\ApplicationTimezone;
use Core\Router\Dispatcher;
use Core\Router\Router;

if (!is_readable($statePath)) {
    file_put_contents($resultPath, json_encode(['fatal' => 'state not readable'], JSON_THROW_ON_ERROR));

    exit(1);
}

/** @var array<string, mixed> $state */
$state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
$userId = (int) ($state['user_id'] ?? 0);
$branchId = (int) ($state['branch_id'] ?? 0);
if ($userId <= 0 || $branchId <= 0) {
    file_put_contents($resultPath, json_encode(['fatal' => 'bad user/branch'], JSON_THROW_ON_ERROR));

    exit(1);
}

$fatal = null;
$normalEnd = false;

register_shutdown_function(function () use ($resultPath, &$fatal, &$normalEnd): void {
    $body = '';
    while (ob_get_level() > 0) {
        $body .= ob_get_clean();
    }
    $code = http_response_code();
    if ($code === false) {
        $code = 200;
    }
    $hdrs = headers_list();
    foreach ($hdrs as $h) {
        if (is_string($h) && stripos($h, 'Location:') === 0 && (int) $code === 200) {
            $code = 302;
            break;
        }
    }
    file_put_contents($resultPath, json_encode([
        'http_status' => (int) $code,
        'body' => $body,
        'fatal' => $fatal,
        'normal_end' => $normalEnd,
    ], JSON_THROW_ON_ERROR));
});

ob_start();

try {
    $session = Application::container()->get(\Core\Auth\SessionAuth::class);
    $session->login($userId);
    $_SESSION['branch_id'] = $branchId;

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $requestUri;
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['HTTPS'] = '';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

    $parts = parse_url($requestUri);
    $_GET = [];
    if (is_array($parts) && !empty($parts['query'])) {
        parse_str((string) $parts['query'], $_GET);
    }
    $_POST = [];

    ApplicationTimezone::applyForHttpRequest();

    $router = new Router();
    require SYSTEM_PATH . '/routes/web.php';
    $dispatcher = new Dispatcher($router, Application::container());
    $dispatcher->dispatch();
    $normalEnd = true;
} catch (\Throwable $e) {
    $fatal = $e->getMessage();
}
