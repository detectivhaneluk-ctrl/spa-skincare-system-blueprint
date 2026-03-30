<?php

declare(strict_types=1);

/**
 * A-002 read-only: Dispatcher must not fall back to `new $class()` / `new $m()` for string
 * pipeline middleware or array-style routed controllers; resolution is container-only.
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_dispatcher_container_only_resolution_a002_01.php
 */
$systemRoot = dirname(__DIR__, 2);
$dispatcher = (string) file_get_contents($systemRoot . '/core/router/Dispatcher.php');

$checks = [
    'Dispatcher: no dynamic `new $variable()` (routed resolution bypass)' => !preg_match('/new\s+\$/', $dispatcher),
    'Dispatcher: no legacy ternary new middleware/controller fallback' => !str_contains($dispatcher, '? $this->container->get($m) : new $m()')
        && !str_contains($dispatcher, '? $this->container->get($class) : new $class()'),
    'Dispatcher: resolves string middleware via resolveMiddlewareFromContainer' => str_contains($dispatcher, 'resolveMiddlewareFromContainer'),
    'Dispatcher: controller path uses container has+get only' => str_contains($dispatcher, 'if (!$this->container->has($class))')
        && str_contains($dispatcher, '$controller = $this->container->get($class);'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo $label . '=' . ($ok ? 'ok' : 'FAIL') . "\n";
}

exit($failed === [] ? 0 : 1);
