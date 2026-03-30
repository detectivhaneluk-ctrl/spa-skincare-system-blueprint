<?php

declare(strict_types=1);

/**
 * Subprocess worker: one real {@see \Core\Router\Dispatcher::dispatch()} through global middleware + route stack.
 * Parent captures results via shutdown handler (controllers use exit on redirect).
 *
 * argv: state.json path, result.json path, HTTP method, request URI, post-body.json path or "-"
 */

if ($argc < 6) {
    fwrite(STDERR, "Usage: php clients_wave_proof_close_02_dispatch_worker.php <state.json> <result.json> <METHOD> <URI> <post.json|->\n");
    exit(2);
}

$statePath = $argv[1];
$resultPath = $argv[2];
$method = strtoupper($argv[3]);
$requestUri = $argv[4];
$postPath = $argv[5];

$systemPath = dirname(__DIR__, 2);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

use Core\App\Application;
use Core\App\ApplicationTimezone;
use Core\Router\Dispatcher;
use Core\Router\Router;

if (!is_readable($statePath)) {
    file_put_contents($resultPath, json_encode(['fatal' => 'state file not readable'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    exit(1);
}

/** @var array<string, mixed> $state */
$state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
$userId = (int) ($state['user_id'] ?? 0);
$branchId = (int) ($state['branch_id'] ?? 0);
if ($userId <= 0 || $branchId <= 0) {
    file_put_contents($resultPath, json_encode(['fatal' => 'invalid state user_id/branch_id'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    exit(1);
}

$fatal = null;
$normalEnd = false;

register_shutdown_function(function () use ($resultPath, &$fatal, &$normalEnd): void {
    $body = '';
    while (ob_get_level() > 0) {
        $body .= ob_get_clean();
    }
    $flashError = null;
    $flashSuccess = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $flash = $_SESSION['_flash'] ?? null;
        if (is_array($flash)) {
            $flashError = $flash['error'] ?? null;
            $flashSuccess = $flash['success'] ?? null;
        }
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
    $payload = [
        'http_status' => (int) $code,
        'headers' => $hdrs,
        'body' => $body,
        'flash_error' => $flashError,
        'flash_success' => $flashSuccess,
        'fatal' => $fatal,
        'normal_end' => $normalEnd,
    ];
    file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
});

ob_start();

try {
    $session = Application::container()->get(\Core\Auth\SessionAuth::class);
    $session->login($userId);
    $_SESSION['branch_id'] = $branchId;

    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $requestUri;
    $_SERVER['HTTP_ACCEPT'] = 'text/html, */*;q=0.8';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['HTTPS'] = '';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

    $parts = parse_url($requestUri);
    $_GET = [];
    if (is_array($parts) && !empty($parts['query'])) {
        parse_str((string) $parts['query'], $_GET);
    }
    $_POST = [];
    if ($postPath !== '-' && is_readable($postPath)) {
        /** @var array<string, mixed> $post */
        $post = json_decode((string) file_get_contents($postPath), true, 512, JSON_THROW_ON_ERROR);
        $_POST = $post;
        $csrfName = (string) config('app.csrf_token_name', 'csrf_token');
        $_POST[$csrfName] = $session->csrfToken();
    }

    ApplicationTimezone::applyForHttpRequest();

    $router = new Router();
    require SYSTEM_PATH . '/routes/web.php';
    $dispatcher = new Dispatcher($router, Application::container());
    $dispatcher->dispatch();
    $normalEnd = true;
} catch (\Throwable $e) {
    $fatal = $e->getMessage();
}
