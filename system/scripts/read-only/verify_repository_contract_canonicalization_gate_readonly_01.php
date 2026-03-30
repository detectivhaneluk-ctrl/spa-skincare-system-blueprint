<?php

declare(strict_types=1);

/**
 * PLT-TNT-01 / PLT-TNT-02 — Repository contract canonicalization gate.
 *
 * Self-defending enforcement:
 * - protected repository families must expose explicit runtime/non-runtime method families from central policy
 * - banned generic verbs and explicitly listed mixed-semantics compatibility methods in protected families
 *   must be locked through a central guard
 * - runtime-sensitive callers must not invoke explicit non-runtime repository methods
 * - runtime-sensitive callers must not invoke locked generic compatibility methods from protected families
 */

$root = dirname(__DIR__, 3);
$modules = $root . '/system/modules';
$policy = require __DIR__ . '/lib/repository_contract_policy.php';

if (!is_dir($modules)) {
    fwrite(STDERR, "FAIL: expected system/modules at {$modules}\n");
    exit(1);
}

/**
 * @return string|null
 */
function extractMethodBody(string $source, string $methodName): ?string
{
    $pattern = '/function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*(?::\s*[^{\s]+)?\s*\{([\s\S]*?)\n    \}/';
    if (preg_match($pattern, $source, $m) !== 1) {
        return null;
    }

    return $m[1];
}

/**
 * @param list<string> $needles
 */
function containsAll(string $source, array $needles): bool
{
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            return false;
        }
    }

    return true;
}

function methodLocked(?string $body, string $lockGuardSymbol): bool
{
    return $body !== null && str_contains($body, $lockGuardSymbol);
}

function isRuntimeSensitivePath(string $path, array $policy): bool
{
    foreach ($policy['runtime_sensitive_path_segments'] as $segment) {
        if (str_contains($path, $segment)) {
            return true;
        }
    }

    return false;
}

function nonRuntimeMethodRegex(array $policy): string
{
    $parts = array_map(static fn (string $m): string => preg_quote($m, '/'), $policy['non_runtime_method_markers']);

    return '/->\s*[A-Za-z_][A-Za-z0-9_]*(' . implode('|', $parts) . ')[A-Za-z0-9_]*\s*\(/';
}

/**
 * @param list<string> $lockedMethods
 * @return array{ok: bool, referenced: int, violations: list<string>}
 */
function verifyLockedMethodUsage(string $modules, string $className, array $lockedMethods): array
{
    $violations = [];
    $referenced = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
            continue;
        }
        $path = str_replace('\\', '/', $fileInfo->getPathname());
        if (str_contains($path, '/repositories/')) {
            continue;
        }
        $text = (string) file_get_contents($fileInfo->getPathname());
        if ($text === '' || !str_contains($text, $className)) {
            continue;
        }
        $referenced++;

        preg_match_all('/' . preg_quote($className, '/') . '\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $text, $typedVars);
        $vars = array_values(array_unique($typedVars[1] ?? []));

        foreach ($vars as $var) {
            foreach ($lockedMethods as $method) {
                $patterns = [
                    '/\$this->' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($method, '/') . '\s*\(/',
                    '/\$' . preg_quote($var, '/') . '\s*->\s*' . preg_quote($method, '/') . '\s*\(/',
                ];
                foreach ($patterns as $rx) {
                    if (preg_match($rx, $text) === 1) {
                        $violations[] = "{$path} calls locked {$className}::{$method}()";
                    }
                }
            }
        }

        foreach ($lockedMethods as $method) {
            $patterns = [
                '/app\(\s*' . preg_quote($className, '/') . '::class\s*\)\s*->\s*' . preg_quote($method, '/') . '\s*\(/',
                '/get\(\s*' . preg_quote($className, '/') . '::class\s*\)\s*->\s*' . preg_quote($method, '/') . '\s*\(/',
                '/\b' . preg_quote($className, '/') . '::' . preg_quote($method, '/') . '\s*\(/',
            ];
            foreach ($patterns as $rx) {
                if (preg_match($rx, $text) === 1) {
                    $violations[] = "{$path} calls locked {$className}::{$method}()";
                }
            }
        }
    }

    return [
        'ok' => $violations === [],
        'referenced' => $referenced,
        'violations' => $violations,
    ];
}

$checks = [];
$violations = [];
$referencedStats = [];

foreach ($policy['protected_repositories'] as $rule) {
    $path = $modules . '/' . $rule['path'];
    if (!is_file($path)) {
        $checks[$rule['class'] . ' file exists'] = false;
        $violations[] = "Missing protected repository file: {$path}";
        continue;
    }

    $src = (string) file_get_contents($path);
    $requiredMethods = array_merge($rule['required_runtime_methods'], $rule['required_non_runtime_methods']);
    $checks[$rule['class'] . ' explicit protected contract methods exist'] = containsAll($src, array_map(
        static fn (string $m): string => 'function ' . $m . '(',
        $requiredMethods
    ));
    $checks[$rule['class'] . ' keeps required scope signals'] = containsAll($src, $rule['required_signals']);

    $lockedMethods = array_merge($rule['locked_generic_methods'], $rule['locked_mixed_methods'] ?? []);
    $allLocked = true;
    foreach ($lockedMethods as $method) {
        $allLocked = $allLocked && methodLocked(extractMethodBody($src, $method), $policy['lock_guard_symbol']);
    }
    $checks[$rule['class'] . ' locked compatibility methods are guarded centrally'] = $allLocked;

    $usage = verifyLockedMethodUsage($modules, $rule['class'], $lockedMethods);
    $checks[$rule['class'] . ' locked compatibility methods are unused outside repositories'] = $usage['ok'];
    $referencedStats[$rule['class']] = $usage['referenced'];
    foreach ($usage['violations'] as $violation) {
        $violations[] = $violation;
    }
}

$runtimeSensitiveFiles = 0;
$runtimeRepairViolations = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($modules, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $fileInfo->getPathname());
    if (str_contains($path, '/repositories/')) {
        continue;
    }
    if (!isRuntimeSensitivePath($path, $policy)) {
        continue;
    }
    $runtimeSensitiveFiles++;
    $text = (string) file_get_contents($fileInfo->getPathname());
    if ($text === '') {
        continue;
    }
    if (preg_match(nonRuntimeMethodRegex($policy), $text, $m) === 1) {
        $runtimeRepairViolations[] = "{$path} runtime-sensitive surface calls explicit non-runtime method {$m[0]}";
    }
}

$checks['Broad runtime scan found no explicit non-runtime method usage in runtime-sensitive surfaces'] = ($runtimeRepairViolations === []);
$checks['Broad runtime scan covers real runtime-sensitive files'] = $runtimeSensitiveFiles > 0;

foreach ($checks as $label => $ok) {
    echo $label . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL;
}
foreach ($referencedStats as $class => $count) {
    echo $class . ' references outside repositories: ' . $count . PHP_EOL;
}
echo 'Runtime-sensitive files scanned: ' . $runtimeSensitiveFiles . PHP_EOL;

foreach ($runtimeRepairViolations as $violation) {
    $violations[] = $violation;
}

if ($violations !== []) {
    foreach ($violations as $violation) {
        fwrite(STDERR, 'VIOLATION: ' . $violation . PHP_EOL);
    }
    exit(1);
}

echo PHP_EOL . "verify_repository_contract_canonicalization_gate_readonly_01: OK\n";
exit(0);
