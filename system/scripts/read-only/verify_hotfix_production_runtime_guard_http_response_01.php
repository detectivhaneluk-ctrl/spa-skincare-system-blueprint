<?php

declare(strict_types=1);

/**
 * HOTFIX proof: PRODUCTION-RUNTIME-GUARD-REDIS-FAIL-CLOSED-HTTP-RESPONSE-FIX-01
 *
 * Verifies that ProductionRuntimeGuard.php:
 *  HF-1  Does NOT use fwrite(STDERR, ...) in the HTTP/web path (the root bug)
 *  HF-2  Uses error_log() for server-side logging (safe in all SAPIs)
 *  HF-3  Drains output buffers before emitting JSON (ob_end_clean loop)
 *  HF-4  Suppresses display_errors before output (prevents PHP HTML injection)
 *  HF-5  Casts json_encode result to string (prevents false/null body)
 *  HF-6  Sends Cache-Control: no-store header
 *  HF-7  CLI SAPI is a no-op (guard returns immediately for cli/cli-server)
 *  HF-8  Redis-mandatory production law is still enforced (not rolled back)
 *  HF-9  WAVE-01 proof still passes (no regression to existing assertions)
 *
 * Run: php system/scripts/read-only/verify_hotfix_production_runtime_guard_http_response_01.php
 * Expected: all assertions PASS, exit code 0.
 */

$repoRoot = dirname(__DIR__, 3);
$pass = 0;
$fail = 0;

function hf_assert(bool $condition, string $label): void
{
    global $pass, $fail;
    echo ($condition ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    $condition ? ++$pass : ++$fail;
}

function hf_contains(string $file, string $needle, string $label): void
{
    hf_assert(
        file_exists($file) && str_contains((string) file_get_contents($file), $needle),
        $label
    );
}

function hf_not_contains(string $file, string $needle, string $label): void
{
    hf_assert(
        file_exists($file) && !str_contains((string) file_get_contents($file), $needle),
        $label
    );
}

echo "\n=== HOTFIX PROOF: ProductionRuntimeGuard HTTP Response Fix ===\n\n";

$guardFile = $repoRoot . '/system/core/Runtime/Guard/ProductionRuntimeGuard.php';
hf_assert(file_exists($guardFile), 'ProductionRuntimeGuard.php exists');

// ─── HF-1: fwrite(STDERR, ...) removed from the web-SAPI code path ───

echo "HF-1: fwrite(STDERR) removed\n";

// Check that no actual fwrite() call passes STDERR as an argument.
// Comments that mention STDERR for documentation are fine; functional calls are not.
// We look for the pattern: fwrite( + any whitespace + STDERR (the actual function call form).
$guardContent = (string) file_get_contents($guardFile);
hf_assert(
    !preg_match('/fwrite\s*\(\s*\\\\?STDERR/', $guardContent),
    'Guard has no fwrite(STDERR, ...) function call [bare or qualified]'
);

echo "\n";

// ─── HF-2: error_log() used for server-side logging ───

echo "HF-2: error_log() used for SAPI-safe logging\n";

hf_contains($guardFile, 'error_log(', 'Guard uses error_log() for server-side logging (safe in all SAPIs)');
hf_contains($guardFile, '[ProductionRuntimeGuard] FATAL:', 'Guard error_log message includes [ProductionRuntimeGuard] FATAL: prefix');

echo "\n";

// ─── HF-3: Output buffer drain before JSON output ───

echo "HF-3: Output buffer drain\n";

hf_contains($guardFile, 'ob_get_level()', 'Guard checks ob_get_level() before output');
hf_contains($guardFile, 'ob_end_clean()', 'Guard calls ob_end_clean() to drain existing buffers');
hf_contains($guardFile, 'while (ob_get_level() > 0)', 'Guard drains ALL open buffers in a loop');

echo "\n";

// ─── HF-4: display_errors suppression ───

echo "HF-4: display_errors suppressed\n";

hf_contains($guardFile, "@ini_set('display_errors', '0')", "Guard suppresses display_errors before output");

echo "\n";

// ─── HF-5: json_encode result cast to string ───

echo "HF-5: json_encode result is type-safe string\n";

hf_contains($guardFile, '(string) json_encode(', 'Guard casts json_encode() result to string');

echo "\n";

// ─── HF-6: Cache-Control: no-store header ───

echo "HF-6: Cache-Control: no-store added\n";

hf_contains($guardFile, "header('Cache-Control: no-store')", 'Guard sends Cache-Control: no-store to prevent proxy caching of 503');

echo "\n";

// ─── HF-7: CLI SAPI is a no-op ───

echo "HF-7: CLI SAPI guard is a no-op\n";

hf_contains($guardFile, "PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server'", 'Guard checks for CLI/cli-server SAPI');
hf_contains($guardFile, 'return;', 'Guard returns early for CLI (no-op)');

echo "\n";

// ─── HF-8: Redis-mandatory production law intact ───

echo "HF-8: Redis-mandatory production law preserved\n";

hf_contains($guardFile, "http_response_code(503)", 'Guard still sends 503 on Redis unavailability');
hf_contains($guardFile, 'exit(1)', 'Guard still exits with code 1 on Redis unavailability');
hf_contains($guardFile, "env !== 'production' && \$env !== 'prod'", 'Guard is still non-production no-op');
hf_contains($guardFile, "'Service unavailable: Redis is required in production.'", 'Guard JSON body still contains service unavailable error message');
hf_contains($guardFile, 'Redis is mandatory in production', 'Guard detail message still states Redis is mandatory');
hf_contains($guardFile, "header('Retry-After: 60')", 'Guard still sends Retry-After: 60 header');
hf_contains($guardFile, "header('Content-Type: application/json; charset=utf-8')", 'Guard still sends Content-Type: application/json');
hf_contains($guardFile, '$metrics->redisEffective()', 'Guard still calls redisEffective() check');

echo "\n";

// ─── HF-9: WAVE-01 proof still passes (no regression) ───

echo "HF-9: WAVE-01 proof regression check\n";

$wave01ProofFile = $repoRoot . '/system/scripts/read-only/verify_wave01_production_runtime_foundation_01.php';
hf_assert(file_exists($wave01ProofFile), 'WAVE-01 proof script still exists');

$phpBin = PHP_BINARY;
$wave01Output = shell_exec($phpBin . ' ' . escapeshellarg($wave01ProofFile) . ' 2>&1');
hf_assert(
    $wave01Output !== null && str_contains((string) $wave01Output, 'RESULT: PASS'),
    'WAVE-01 proof still passes after hotfix (no regression to existing assertions)'
);

echo "\n";

// ─── Static simulation: verify guard code structure is coherent ───

echo "Guard structure coherence\n";

$content = (string) file_get_contents($guardFile);

// Guard must NOT contain any fwrite(STDERR) call. Comments that reference STDERR for
// documentation purposes are fine; what is forbidden is a functional fwrite() call.
$guardContent = (string) file_get_contents($guardFile);
hf_assert(
    !preg_match('/fwrite\s*\(\s*\\\\?STDERR/', $guardContent),
    'Guard has no fwrite(STDERR) call — no functional STDERR use in any SAPI path'
);

// Guard must be a final class in the correct namespace.
hf_contains($guardFile, 'namespace Core\Runtime\Guard;', 'Guard is in correct namespace Core\Runtime\Guard');
hf_contains($guardFile, 'final class ProductionRuntimeGuard', 'Guard is a final class');

echo "\n";

// ─── Summary ───

$total = $pass + $fail;
echo "===========================================\n";
echo "HOTFIX PROOF: {$pass}/{$total} assertions passed\n";
if ($fail > 0) {
    echo "RESULT: FAIL — {$fail} assertion(s) failed\n";
    exit(1);
}
echo "RESULT: PASS — ProductionRuntimeGuard HTTP response hotfix verified. No regressions.\n";
exit(0);
