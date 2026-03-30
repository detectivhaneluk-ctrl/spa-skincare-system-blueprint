<?php

declare(strict_types=1);

/**
 * Simulate authenticated GET through full Application stack (for local diagnosis).
 */
$systemPath = dirname(__DIR__, 2);

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $systemPath . '/public/index.php';
$_SERVER['REQUEST_METHOD'] = $argv[1] ?? 'GET';
$_SERVER['REQUEST_URI'] = $argv[2] ?? '/memberships';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTPS'] = '';

$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
$_GET = [];
if (is_string($query) && $query !== '') {
    parse_str($query, $_GET);
}

require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

\Core\App\ApplicationTimezone::applyForHttpRequest();

$sessionAuth = \Core\App\Application::container()->get(\Core\Auth\SessionAuth::class);
$sessionAuth->login((int) ($argv[3] ?? 1));

$app = new \Core\App\Application($systemPath);
try {
    ob_start();
    $app->run();
    $out = ob_get_clean();
    $code = http_response_code();
    $fromHeaders = null;
    foreach (headers_list() as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $fromHeaders = (int) $m[1];
            break;
        }
    }
    $dump = $systemPath . '/storage/logs/_last_http_sim.html';
    @file_put_contents($dump, $out);
    $effective = $fromHeaders ?? $code ?: 200;
    fwrite(STDOUT, "HTTP status: {$effective}\n");
    fwrite(STDOUT, "Body length: " . strlen($out) . "\n");
    fwrite(STDOUT, "Dump: {$dump}\n");
    if ($code >= 400) {
        fwrite(STDOUT, substr($out, 0, 2000) . "\n");
    }
} catch (Throwable $e) {
    ob_end_clean();
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    exit(1);
}
