<?php

declare(strict_types=1);

/**
 * Read-only: array route handlers `[Class::class, 'method']` and string middleware `Foo::class`
 * (not `PermissionMiddleware::for(...)` objects) must be registered via `$container->singleton|bind(\Fqcn::class, ...)`
 * in system/bootstrap.php, system/modules/bootstrap.php, or system/modules/bootstrap/register_*.php.
 * Includes `private array $globalMiddleware` entries from {@see \Core\Router\Dispatcher}.
 *
 * Run from project root:
 *   php system/scripts/read-only/verify_routed_string_classes_container_bindings_a002_audit_01.php
 */
$repoRoot = dirname(__DIR__, 3);
$systemRoot = $repoRoot . '/system';

function readCorpus(string $systemRoot): string
{
    $parts = [
        (string) file_get_contents($systemRoot . '/bootstrap.php'),
        (string) file_get_contents($systemRoot . '/modules/bootstrap.php'),
    ];
    foreach (glob($systemRoot . '/modules/bootstrap/register_*.php') ?: [] as $path) {
        $parts[] = (string) file_get_contents($path);
    }

    return implode("\n", $parts);
}

function fqcnBound(string $corpus, string $fqcn): bool
{
    $q = preg_quote('\\' . $fqcn . '::class', '/');

    return (bool) preg_match('/\$[a-zA-Z_][a-zA-Z0-9_]*->(?:singleton|bind)\s*\(\s*' . $q . '/s', $corpus);
}

/** @return array<string, string> alias => fqcn */
function parseUseMap(string $php): array
{
    $map = [];
    if (!preg_match_all(
        '/^use\s+((?:\\\\?[A-Za-z0-9_]+)(?:\\\\[A-Za-z0-9_]+)*)\s*(?:as\s+([A-Za-z0-9_]+))?\s*;/m',
        $php,
        $m,
        PREG_SET_ORDER
    )) {
        return $map;
    }
    foreach ($m as $row) {
        $fqcn = ltrim($row[1], '\\');
        $alias = $row[2] ?? null;
        if ($alias === null) {
            $parts = explode('\\', $fqcn);
            $alias = end($parts) ?: $fqcn;
        }
        $map[$alias] = $fqcn;
    }

    return $map;
}

/** @return array<string, string> varName => "[ ... ]" body including brackets */
function parseMiddlewareVarBodies(string $php): array
{
    $out = [];
    if (!preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*\[/', $php, $m, PREG_OFFSET_CAPTURE)) {
        return $out;
    }
    $n = count($m[0]);
    for ($i = 0; $i < $n; $i++) {
        $name = $m[1][$i][0];
        $matchStart = $m[0][$i][1];
        $bodyStart = strpos($php, '[', $matchStart);
        if ($bodyStart === false) {
            continue;
        }
        $depth = 0;
        $len = strlen($php);
        for ($p = $bodyStart; $p < $len; $p++) {
            $ch = $php[$p];
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    $out[$name] = substr($php, $bodyStart, $p - $bodyStart + 1);
                    break;
                }
            }
        }
    }

    return $out;
}

/**
 * @return array{0:string,1:string} middleware fragment, remainder (route options)
 */
function splitMiddlewareArg(string $afterHandler): array
{
    $afterHandler = trim($afterHandler);
    if ($afterHandler === '') {
        return ['', ''];
    }
    if ($afterHandler[0] === '[') {
        $depth = 0;
        $len = strlen($afterHandler);
        for ($i = 0; $i < $len; $i++) {
            if ($afterHandler[$i] === '[') {
                $depth++;
            } elseif ($afterHandler[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    $mw = substr($afterHandler, 0, $i + 1);
                    $rest = trim(substr($afterHandler, $i + 1));
                    $rest = ltrim($rest, ',');

                    return [$mw, trim($rest)];
                }
            }
        }

        return [$afterHandler, ''];
    }
    if ($afterHandler[0] === '$' && preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*/', $afterHandler, $vm)) {
        $var = $vm[0];
        $rest = trim(substr($afterHandler, strlen($var)));
        $rest = ltrim($rest, ',');

        return [$var, trim($rest)];
    }

    return [$afterHandler, ''];
}

function resolveClassRef(string $ref, array $useMap): string
{
    if (str_starts_with($ref, '\\')) {
        return ltrim($ref, '\\');
    }

    return $useMap[$ref] ?? $ref;
}

/**
 * @param array<string, string> $useMap
 * @param array<string, string> $mwVarBodies
 * @return list<array{file:string,line:int,kind:string,class:string}>
 */
function collectFromRouteFile(string $path, array $useMap, array $mwVarBodies): array
{
    $php = (string) file_get_contents($path);
    $lines = preg_split('/\R/', $php) ?: [];
    $found = [];

    foreach ($lines as $idx => $line) {
        $lineNum = $idx + 1;
        if (!str_contains($line, '$router->')) {
            continue;
        }
        if (preg_match(
            '/\$router->(?:get|post|put|patch|delete|any)\s*\(\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,\s*static\s+function/',
            $line
        ) || preg_match(
            '/\$router->(?:get|post|put|patch|delete|any)\s*\(\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,\s*function\s*\(/',
            $line
        )) {
            continue;
        }
        if (!preg_match(
            '/\$router->(?:get|post|put|patch|delete|any)\s*\(\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,\s*\[\s*(\\\\?[A-Za-z][A-Za-z0-9_\\\\]*)::class\s*,\s*\'[^\']+\'\s*\]/',
            $line,
            $hm
        )) {
            continue;
        }
        $ctrlRef = $hm[1];
        $found[] = [
            'file' => $path,
            'line' => $lineNum,
            'kind' => 'controller',
            'class' => resolveClassRef($ctrlRef, $useMap),
        ];

        if (!preg_match(
            '/\$router->(?:get|post|put|patch|delete|any)\s*\(\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,\s*\[\s*\\\\?[A-Za-z][A-Za-z0-9_\\\\]*::class\s*,\s*\'[^\']+\'\s*\]\s*,\s*(.+)\)\s*;\s*$/',
            $line,
            $tail
        )) {
            continue;
        }
        [$mwFragment] = splitMiddlewareArg($tail[1]);
        if ($mwFragment === '') {
            continue;
        }
        if (str_starts_with($mwFragment, '[')) {
            if (preg_match_all('/([\\\\A-Za-z][A-Za-z0-9_\\\\]*)::class/', $mwFragment, $cm)) {
                foreach ($cm[1] as $ref) {
                    $found[] = [
                        'file' => $path,
                        'line' => $lineNum,
                        'kind' => 'middleware',
                        'class' => resolveClassRef($ref, $useMap),
                    ];
                }
            }
        } elseif (preg_match('/^\$([a-zA-Z_][a-zA-Z0-9_]*)$/', $mwFragment, $vm)) {
            $body = $mwVarBodies[$vm[1]] ?? '';
            if ($body !== '' && preg_match_all('/([\\\\A-Za-z][A-Za-z0-9_\\\\]*)::class/', $body, $cm)) {
                foreach ($cm[1] as $ref) {
                    $found[] = [
                        'file' => $path,
                        'line' => $lineNum,
                        'kind' => 'middleware',
                        'class' => resolveClassRef($ref, $useMap),
                    ];
                }
            }
        }
    }

    return $found;
}

/** @return list<string> */
function dispatcherGlobalMiddleware(string $systemRoot): array
{
    $src = (string) file_get_contents($systemRoot . '/core/router/Dispatcher.php');
    if (!preg_match('/\$globalMiddleware\s*=\s*\[(.*?)\];/s', $src, $m)) {
        return [];
    }
    preg_match_all('/\\\\([A-Za-z][A-Za-z0-9_\\\\]*)::class/', $m[1], $cm);

    return $cm[1];
}

$corpus = readCorpus($systemRoot);
$dispatcherMw = dispatcherGlobalMiddleware($systemRoot);

$routeFiles = array_merge(
    glob($systemRoot . '/routes/web/*.php') ?: [],
    glob($systemRoot . '/modules/*/routes/web.php') ?: []
);
sort($routeFiles);

$entries = [];
foreach ($routeFiles as $file) {
    $php = (string) file_get_contents($file);
    $useMap = parseUseMap($php);
    $mwVarBodies = parseMiddlewareVarBodies($php);
    foreach (collectFromRouteFile($file, $useMap, $mwVarBodies) as $e) {
        $entries[] = $e;
    }
}

foreach ($dispatcherMw as $c) {
    $entries[] = [
        'file' => $systemRoot . '/core/router/Dispatcher.php',
        'line' => 0,
        'kind' => 'global_middleware',
        'class' => $c,
    ];
}

$byClass = [];
foreach ($entries as $e) {
    $byClass[$e['class']][] = $e;
}

$missing = [];
foreach (array_keys($byClass) as $class) {
    if (!fqcnBound($corpus, $class)) {
        $missing[] = $class;
    }
}
sort($missing);

echo "Routed string classes checked: " . count($byClass) . "\n";
if ($missing === []) {
    echo "Missing container bindings: none\n";
    exit(0);
}

echo "Missing container bindings:\n";
foreach ($missing as $c) {
    echo "  - {$c}\n";
    foreach ($byClass[$c] as $ref) {
        $ln = $ref['line'] > 0 ? (string) $ref['line'] : 'global';
        echo "      {$ref['kind']} {$ref['file']}:{$ln}\n";
    }
}

exit(1);
