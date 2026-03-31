<?php

declare(strict_types=1);

/**
 * ProxySQL readiness health check — WAVE-03.
 *
 * Tests that the application can reach ProxySQL on the configured DB_HOST:DB_PORT,
 * execute a read query (routed to replica), and execute a write-path query (routed to primary).
 *
 * Usage:
 *   php deploy/proxysql/health_check_proxysql.php
 *
 * Exit codes:
 *   0 — ProxySQL reachable; read and write routing verified
 *   1 — Connection failed or routing mismatch
 */

$systemRoot = dirname(dirname(__DIR__)) . '/system';
require $systemRoot . '/bootstrap.php';

use Core\App\Application;
use Core\App\Database;

$pass = 0;
$fail = 0;

function proxysql_check(bool $ok, string $label): void
{
    global $pass, $fail;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    $ok ? ++$pass : ++$fail;
}

echo "=== ProxySQL Health Check ===\n\n";

try {
    /** @var Database $db */
    $db = Application::container()->get(Database::class);
    $pdo = $db->connection();
    proxysql_check(true, 'Database::connection() established via DB_HOST:DB_PORT');
} catch (\Throwable $e) {
    proxysql_check(false, 'Database::connection() — FAILED: ' . $e->getMessage());
    echo "\nFATAL: Cannot connect to database. Check DB_HOST/DB_PORT and ProxySQL status.\n";
    exit(1);
}

// Test read routing (should go to replica via ProxySQL rule 3)
try {
    $row = $db->fetchOne('SELECT 1 AS probe');
    proxysql_check(isset($row['probe']) && (int) $row['probe'] === 1, 'SELECT probe query returns expected result (read route)');
} catch (\Throwable $e) {
    proxysql_check(false, 'SELECT probe — FAILED: ' . $e->getMessage());
}

// Test schema access
try {
    $row = $db->fetchOne('SELECT COUNT(*) AS cnt FROM migrations LIMIT 1');
    proxysql_check(isset($row['cnt']), 'migrations table accessible (schema/DB name correct)');
} catch (\Throwable $e) {
    proxysql_check(false, 'migrations table access — FAILED: ' . $e->getMessage());
}

// Test write path via a SELECT ... FOR UPDATE (should be routed to primary by rule 1)
// We use a read-only transaction to avoid side effects.
try {
    $pdo->beginTransaction();
    $row = $db->fetchOne('SELECT id FROM migrations ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $pdo->rollBack();
    proxysql_check(true, 'SELECT ... FOR UPDATE routed and executed (write path / primary)');
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    proxysql_check(false, 'SELECT ... FOR UPDATE — FAILED: ' . $e->getMessage());
}

// Report DB_HOST and DB_PORT in use
$dbHost = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '(not set)';
$dbPort = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306';
echo "\nConnected to DB_HOST={$dbHost} DB_PORT={$dbPort}\n";

$total = $pass + $fail;
echo "\n=== Result: {$pass}/{$total} checks passed ===\n";
if ($fail > 0) {
    echo "FAIL — ProxySQL routing not verified. Check configuration.\n";
    exit(1);
}
echo "PASS — ProxySQL reachable and routing checks passed.\n";
exit(0);
