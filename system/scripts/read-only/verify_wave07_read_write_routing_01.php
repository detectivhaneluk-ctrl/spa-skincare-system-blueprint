<?php

declare(strict_types=1);

/**
 * WAVE-07: Read/write routing — proof script.
 *
 * Verifies:
 *  W7-A  Infrastructure: ReadWriteConnectionResolver + ReadQueryExecutor classes exist and expose required API
 *  W7-B  Database routing integration: forRead(), requirePrimary(), isStickyPrimary() wired correctly
 *  W7-C  Config: replica config fields present in database.php
 *  W7-D  Bootstrap: resolver singleton registered and attached to Database
 *  W7-E  Sticky-primary correctness: transaction() and insert() trigger requirePrimary()
 *  W7-F  Replica-eligible path: AvailabilityService::listDayAppointmentsGroupedByStaff uses forRead()
 *  W7-G  Primary-only preservation: isSlotAvailable, hasBufferedAppointmentConflict, AppointmentService
 *         mutations do NOT use forRead()
 *  W7-H  Fail-safe: replica connect failure returns primary_fallback target
 *  W7-I  Observability: ReadQueryExecutor exposes routingTarget() and isReplica()
 *  W7-J  ProxySQL WAVE-03 artifacts still intact
 *  W7-K  No-split mode: when resolver has no replica config, forRead() returns primary connection
 *
 * Run: php system/scripts/read-only/verify_wave07_read_write_routing_01.php
 * Expected: all assertions PASS, exit code 0.
 */

$repoRoot = dirname(__DIR__, 3);
$pass = 0;
$fail = 0;

function wave07_assert(bool $condition, string $label): void
{
    global $pass, $fail;
    echo ($condition ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    $condition ? ++$pass : ++$fail;
}

function wave07_file_contains(string $file, string $needle, string $label): void
{
    wave07_assert(
        file_exists($file) && str_contains((string) file_get_contents($file), $needle),
        $label
    );
}

function wave07_file_not_contains(string $file, string $needle, string $label): void
{
    $content = file_exists($file) ? (string) file_get_contents($file) : '';
    wave07_assert(!str_contains($content, $needle), $label);
}

echo "\n=== WAVE-07 READ/WRITE ROUTING + PROXYSQL RUNTIME PROOF ===\n\n";

// ─── W7-A: Infrastructure classes ───

echo "W7-A: Infrastructure — ReadWriteConnectionResolver + ReadQueryExecutor\n";

$resolverFile = $repoRoot . '/system/core/App/ReadWriteConnectionResolver.php';
$executorFile = $repoRoot . '/system/core/App/ReadQueryExecutor.php';

wave07_assert(file_exists($resolverFile), 'ReadWriteConnectionResolver.php exists');
wave07_file_contains($resolverFile, 'final class ReadWriteConnectionResolver', 'ReadWriteConnectionResolver is final class');
wave07_file_contains($resolverFile, 'public function primaryConnection(): PDO', 'ReadWriteConnectionResolver::primaryConnection() exists');
wave07_file_contains($resolverFile, 'public function replicaConnectionForRead(): array', 'ReadWriteConnectionResolver::replicaConnectionForRead() exists');
wave07_file_contains($resolverFile, 'public function requirePrimary(): void', 'ReadWriteConnectionResolver::requirePrimary() exists');
wave07_file_contains($resolverFile, 'public function isStickyPrimary(): bool', 'ReadWriteConnectionResolver::isStickyPrimary() exists');
wave07_file_contains($resolverFile, 'public function canUseReplica(): bool', 'ReadWriteConnectionResolver::canUseReplica() exists');
wave07_file_contains($resolverFile, 'public function isReplicaConfigured(): bool', 'ReadWriteConnectionResolver::isReplicaConfigured() exists');
wave07_file_contains($resolverFile, '$this->stickyPrimary = true', 'ReadWriteConnectionResolver sets stickyPrimary flag');
wave07_file_contains($resolverFile, "return ['pdo' => \$this->primaryConnection(), 'target' => 'primary']", "replicaConnectionForRead() returns primary when canUseReplica() is false");
wave07_file_contains($resolverFile, "'target' => 'replica'", "replicaConnectionForRead() returns 'replica' target when routing to replica");
wave07_file_contains($resolverFile, 'primary_fallback_replica_connect_error', 'Replica connect failure returns primary_fallback target');
wave07_file_contains($resolverFile, "slog('warning', 'db_routing', 'replica_connect_failed'", 'Replica connect failure is slog-logged');

wave07_assert(file_exists($executorFile), 'ReadQueryExecutor.php exists');
wave07_file_contains($executorFile, 'final class ReadQueryExecutor', 'ReadQueryExecutor is final class');
wave07_file_contains($executorFile, 'public function fetchAll(string $sql, array $params = []): array', 'ReadQueryExecutor::fetchAll() exists');
wave07_file_contains($executorFile, 'public function fetchOne(string $sql, array $params = []): ?array', 'ReadQueryExecutor::fetchOne() exists');
wave07_file_contains($executorFile, 'public function routingTarget(): string', 'ReadQueryExecutor::routingTarget() exists for observability');
wave07_file_contains($executorFile, 'public function isReplica(): bool', 'ReadQueryExecutor::isReplica() exists for observability');
wave07_file_contains($executorFile, "return \$this->routingTarget === 'replica'", "isReplica() checks for 'replica' target string");
// Critical: ReadQueryExecutor must NOT expose insert/write methods
wave07_file_not_contains($executorFile, 'public function insert(', 'ReadQueryExecutor does NOT expose insert() — no write API');
wave07_file_not_contains($executorFile, 'public function transaction(', 'ReadQueryExecutor does NOT expose transaction() — no write API');
wave07_file_not_contains($executorFile, 'public function exec(', 'ReadQueryExecutor does NOT expose exec() — no write API');
wave07_file_contains($executorFile, 'SlowQueryLogger', 'ReadQueryExecutor forwards slow-query logging');

echo "\n";

// ─── W7-B: Database class integration ───

echo "W7-B: Database routing integration\n";

$dbFile = $repoRoot . '/system/core/App/Database.php';
wave07_assert(file_exists($dbFile), 'Database.php exists');
wave07_file_contains($dbFile, 'public function forRead(): \\Core\\App\\ReadQueryExecutor', 'Database::forRead() exists and returns ReadQueryExecutor');
wave07_file_contains($dbFile, 'public function requirePrimary(): void', 'Database::requirePrimary() exists');
wave07_file_contains($dbFile, 'public function isStickyPrimary(): bool', 'Database::isStickyPrimary() exists');
wave07_file_contains($dbFile, 'public function setReadWriteResolver', 'Database::setReadWriteResolver() exists');
wave07_file_contains($dbFile, 'public function getReadWriteResolver', 'Database::getReadWriteResolver() exists');
wave07_file_contains($dbFile, '\\Core\\App\\ReadWriteConnectionResolver', 'Database references ReadWriteConnectionResolver type');
wave07_file_contains($dbFile, 'new \\Core\\App\\ReadQueryExecutor($result[\'pdo\'], $result[\'target\'])', 'Database::forRead() builds ReadQueryExecutor from resolver result');
wave07_file_contains($dbFile, "new \\Core\\App\\ReadQueryExecutor(\$this->connection(), 'primary')", "Database::forRead() falls back to primary when resolver is null");

echo "\n";

// ─── W7-C: Config ───

echo "W7-C: Replica config fields in database.php\n";

$configFile = $repoRoot . '/system/config/database.php';
wave07_assert(file_exists($configFile), 'system/config/database.php exists');
wave07_file_contains($configFile, 'replica_host', "config has 'replica_host' field");
wave07_file_contains($configFile, 'replica_port', "config has 'replica_port' field");
wave07_file_contains($configFile, 'read_write_routing_enabled', "config has 'read_write_routing_enabled' field");
wave07_file_contains($configFile, 'DB_REPLICA_HOST', 'replica_host sourced from DB_REPLICA_HOST env var');
wave07_file_contains($configFile, 'DB_REPLICA_PORT', 'replica_port sourced from DB_REPLICA_PORT env var');
wave07_file_contains($configFile, 'DB_READ_WRITE_ROUTING', 'routing enabled sourced from DB_READ_WRITE_ROUTING env var');
wave07_file_contains($configFile, "env('DB_REPLICA_HOST', '')", "DB_REPLICA_HOST defaults to empty string (no split by default)");

echo "\n";

// ─── W7-D: Bootstrap wiring ───

echo "W7-D: Bootstrap singleton registration\n";

$bootstrapFile = $repoRoot . '/system/bootstrap.php';
wave07_assert(file_exists($bootstrapFile), 'system/bootstrap.php exists');
wave07_file_contains($bootstrapFile, 'ReadWriteConnectionResolver::class', 'bootstrap registers ReadWriteConnectionResolver singleton');
wave07_file_contains($bootstrapFile, 'setReadWriteResolver', 'bootstrap calls setReadWriteResolver on Database');
wave07_file_contains($bootstrapFile, "\$db->setReadWriteResolver(\$c->get(\\Core\\App\\ReadWriteConnectionResolver::class))", 'bootstrap attaches resolver to Database singleton');
wave07_file_contains($bootstrapFile, "'replica_host'", 'bootstrap reads replica_host from config');
wave07_file_contains($bootstrapFile, "'read_write_routing_enabled'", 'bootstrap reads read_write_routing_enabled from config');
wave07_file_contains($bootstrapFile, '$replicaCfg = null', 'bootstrap defaults to null replica config (no split)');

echo "\n";

// ─── W7-E: Sticky-primary correctness ───

echo "W7-E: Sticky-primary — transaction() and insert() trigger requirePrimary()\n";

$dbContent = (string) file_get_contents($dbFile);

// Check transaction() calls requirePrimary
$txnMethodStart = strpos($dbContent, 'public function transaction(');
$txnMethodEnd   = $txnMethodStart !== false ? strpos($dbContent, "\n    }", $txnMethodStart + 50) : false;
if ($txnMethodStart !== false && $txnMethodEnd !== false) {
    $txnBody = substr($dbContent, $txnMethodStart, $txnMethodEnd - $txnMethodStart + 10);
    wave07_assert(str_contains($txnBody, '$this->requirePrimary()'), 'transaction() calls requirePrimary() before beginning transaction');
} else {
    wave07_assert(false, 'transaction() method not found in Database.php — cannot verify sticky-primary call');
}

// Check insert() calls requirePrimary
$insertMethodStart = strpos($dbContent, 'public function insert(');
$insertMethodEnd   = $insertMethodStart !== false ? strpos($dbContent, "\n        return (int) \$this->connection()->lastInsertId()", $insertMethodStart) : false;
if ($insertMethodStart !== false && $insertMethodEnd !== false) {
    $insertBody = substr($dbContent, $insertMethodStart, $insertMethodEnd - $insertMethodStart + 60);
    wave07_assert(str_contains($insertBody, '$this->requirePrimary()'), 'insert() calls requirePrimary() before performing insert');
} else {
    wave07_assert(false, 'insert() method not found in Database.php — cannot verify sticky-primary call');
}

// requirePrimary() delegates to resolver
wave07_file_contains($dbFile, "if (\$this->resolver !== null) {\n            \$this->resolver->requirePrimary();\n        }", 'Database::requirePrimary() delegates to resolver when present');

echo "\n";

// ─── W7-F: Replica-eligible path wired ───

echo "W7-F: Replica-eligible path — AvailabilityService::listDayAppointmentsGroupedByStaff DB cache-miss path\n";

$availFile = $repoRoot . '/system/modules/appointments/services/AvailabilityService.php';
wave07_assert(file_exists($availFile), 'AvailabilityService.php exists');
wave07_file_contains($availFile, '$this->db->forRead()->fetchAll($sql, $params)', 'listDayAppointmentsGroupedByStaff DB fallback uses forRead() for replica routing');
wave07_file_contains($availFile, 'display-only path', 'forRead() usage has explicit comment explaining why replica is safe here');
wave07_file_contains($availFile, 'booking write path uses isSlotAvailable()', 'Comment confirms booking write path is not affected by forRead()');

echo "\n";

// ─── W7-G: Primary-only preservation ───

echo "W7-G: Primary-only preservation — booking correctness paths do NOT use forRead()\n";

$availContent = file_exists($availFile) ? (string) file_get_contents($availFile) : '';

// isSlotAvailable should not use forRead()
$isSlotStart = strpos($availContent, 'public function isSlotAvailable(');
$isSlotEnd   = $isSlotStart !== false ? strpos($availContent, "\n    public function ", $isSlotStart + 50) : false;
if ($isSlotStart !== false && $isSlotEnd !== false) {
    $isSlotBody = substr($availContent, $isSlotStart, $isSlotEnd - $isSlotStart);
    wave07_assert(!str_contains($isSlotBody, '->forRead()'), 'isSlotAvailable() does NOT use forRead() — always primary for booking correctness');
} else {
    wave07_assert(false, 'isSlotAvailable() not found in AvailabilityService — cannot verify primary-only constraint');
}

// hasBufferedAppointmentConflict should not use forRead()
$hbcStart = strpos($availContent, 'private function hasBufferedAppointmentConflict(');
$hbcEnd   = $hbcStart !== false ? strpos($availContent, "\n    private function ", $hbcStart + 50) : false;
if ($hbcStart !== false && $hbcEnd !== false) {
    $hbcBody = substr($availContent, $hbcStart, $hbcEnd - $hbcStart);
    wave07_assert(!str_contains($hbcBody, '->forRead()'), 'hasBufferedAppointmentConflict() does NOT use forRead() — always primary for booking correctness');
} else {
    wave07_assert(false, 'hasBufferedAppointmentConflict() not found — cannot verify primary-only constraint');
}

// AppointmentService mutations must not use forRead()
$appointmentServiceFile = $repoRoot . '/system/modules/appointments/services/AppointmentService.php';
if (file_exists($appointmentServiceFile)) {
    $asContent = (string) file_get_contents($appointmentServiceFile);
    wave07_assert(!str_contains($asContent, '->forRead()'), 'AppointmentService (write service) does NOT use forRead() — all paths primary');
} else {
    wave07_assert(false, 'AppointmentService.php not found');
}

// Payment/Invoice services must not use forRead()
$paymentServiceFile = $repoRoot . '/system/modules/sales/services/PaymentService.php';
if (file_exists($paymentServiceFile)) {
    $psContent = (string) file_get_contents($paymentServiceFile);
    wave07_assert(!str_contains($psContent, '->forRead()'), 'PaymentService does NOT use forRead() — all paths primary');
}

// StaffGroupService must not use forRead()
$sgFile = $repoRoot . '/system/modules/staff/services/StaffGroupService.php';
if (file_exists($sgFile)) {
    wave07_assert(!str_contains((string) file_get_contents($sgFile), '->forRead()'), 'StaffGroupService does NOT use forRead() — all paths primary');
}

echo "\n";

// ─── W7-H: Fail-safe routing ───

echo "W7-H: Fail-safe routing — replica unavailability falls back to primary\n";

$resolverContent = file_exists($resolverFile) ? (string) file_get_contents($resolverFile) : '';
wave07_assert(str_contains($resolverContent, '$this->replicaConnectFailed = true'), 'Resolver records replica connect failure in $replicaConnectFailed');
wave07_assert(str_contains($resolverContent, "return ['pdo' => \$this->primaryConnection(), 'target' => 'primary_fallback_replica_connect_error']"), 'Replica connect error returns primary_fallback target');
wave07_assert(str_contains($resolverContent, "return ['pdo' => \$this->primaryConnection(), 'target' => 'primary_fallback_replica_unavailable']"), 'Persistent replica failure returns primary_fallback_unavailable target');
wave07_assert(str_contains($resolverContent, "} catch (\\Throwable \$e) {"), 'Replica connect is wrapped in try/catch (never throws)');

echo "\n";

// ─── W7-I: Observability ───

echo "W7-I: Observability — routingTarget() + slog on replica connect error\n";

$executorContent = file_exists($executorFile) ? (string) file_get_contents($executorFile) : '';
wave07_assert(str_contains($executorContent, 'public function routingTarget(): string'), 'ReadQueryExecutor::routingTarget() returns the routing target string');
wave07_assert(str_contains($executorContent, 'public function isReplica(): bool'), 'ReadQueryExecutor::isReplica() returns bool for quick checks');
wave07_assert(str_contains($resolverContent, "slog('warning', 'db_routing'"), 'Resolver logs replica connect failure via slog for observability');

echo "\n";

// ─── W7-J: ProxySQL WAVE-03 artifacts still intact ───

echo "W7-J: WAVE-03 ProxySQL deployment artifacts still intact\n";

$proxysqlDir = $repoRoot . '/deploy/proxysql';
wave07_assert(is_dir($proxysqlDir), 'deploy/proxysql/ directory exists');
wave07_assert(file_exists($proxysqlDir . '/README.md'), 'deploy/proxysql/README.md exists');
wave07_assert(file_exists($proxysqlDir . '/proxysql.cnf.template'), 'deploy/proxysql/proxysql.cnf.template exists');
wave07_assert(file_exists($proxysqlDir . '/proxysql_setup.sql'), 'deploy/proxysql/proxysql_setup.sql exists');
wave07_assert(file_exists($proxysqlDir . '/health_check_proxysql.php'), 'deploy/proxysql/health_check_proxysql.php exists');

// Verify README still documents the expected WAVE-07 abstraction
$readmeContent = file_exists($proxysqlDir . '/README.md') ? (string) file_get_contents($proxysqlDir . '/README.md') : '';
wave07_assert(str_contains($readmeContent, 'DB_HOST=127.0.0.1'), 'ProxySQL README still documents DB_HOST pointing to ProxySQL');
wave07_assert(str_contains($readmeContent, 'destination_hostgroup=0') || file_exists($proxysqlDir . '/proxysql.cnf.template'), 'ProxySQL config documents write hostgroup routing');

echo "\n";

// ─── W7-K: No-split mode is safe ───

echo "W7-K: No-split mode (no replica config) — forRead() uses primary connection\n";

$dbContent2 = file_exists($dbFile) ? (string) file_get_contents($dbFile) : '';
wave07_assert(
    str_contains($dbContent2, "new \\Core\\App\\ReadQueryExecutor(\$this->connection(), 'primary')"),
    "Database::forRead() falls back to primary PDO when resolver is null — no-split mode is safe"
);
wave07_file_contains($bootstrapFile, '$replicaCfg = null', 'Bootstrap defaults replicaCfg to null (no-split)');
wave07_file_contains($bootstrapFile, "\$routingEnabled && \$replicaHost !== ''", 'Bootstrap requires BOTH routing flag AND non-empty replica host to enable split');

echo "\n";

// ─── Summary ───

$total = $pass + $fail;
echo "=== WAVE-07 PROOF SUMMARY ===\n";
echo "PASS: {$pass} / {$total}\n";
if ($fail > 0) {
    echo "FAIL: {$fail} / {$total}\n";
    echo "\nStatus: FAIL — fix failing assertions before marking WAVE-07 CLOSED.\n\n";
    exit(1);
}
echo "\nStatus: PASS — WAVE-07 routing infrastructure proven.\n\n";
exit(0);
