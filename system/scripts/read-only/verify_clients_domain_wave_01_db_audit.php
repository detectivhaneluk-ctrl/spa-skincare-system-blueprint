<?php

declare(strict_types=1);

/**
 * Wave-01 — optional live DB read-only counts (uses system/.env.local if present).
 *
 *   php system/scripts/read-only/verify_clients_domain_wave_01_db_audit.php
 *
 * Exit 0 when DB reachable or when .env.local missing (skip). Exit 2 on SQL error.
 */

$systemDir = dirname(__DIR__, 2);
$envFile = $systemDir . '/.env.local';

function parseEnvLocal(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v);
    }

    return $out;
}

$env = parseEnvLocal($envFile);
if ($env === []) {
    fwrite(STDOUT, "SKIP_DB: no .env.local at {$envFile}\n");
    exit(0);
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = (int) ($env['DB_PORT'] ?? 3306);
$db = $env['DB_DATABASE'] ?? '';
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';

if ($db === '') {
    fwrite(STDOUT, "SKIP_DB: DB_DATABASE empty\n");
    exit(0);
}

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'DB_CONNECT_FAIL: ' . $e->getMessage() . "\n");
    exit(2);
}

$run = static function (PDO $pdo, string $sql, array $params = []): int {
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int) $st->fetchColumn();
};

fwrite(STDOUT, "DB_OK connected={$db} host={$host}\n");

$nNullDef = $run($pdo, 'SELECT COUNT(*) FROM client_field_definitions WHERE deleted_at IS NULL AND branch_id IS NULL');
fwrite(STDOUT, "DATA client_field_definitions active branch_id IS NULL count={$nNullDef}\n");

$nOrphanReg = $run(
    $pdo,
    'SELECT COUNT(*) FROM client_registration_requests WHERE branch_id IS NULL AND (linked_client_id IS NULL OR linked_client_id = 0)'
);
fwrite(STDOUT, "DATA client_registration_requests branch_id NULL AND linked_client_id NULL count={$nOrphanReg}\n");

// Read-only destructive dedupe rehearsal: transaction rolled back, impossible client ids.
$pdo->beginTransaction();
try {
    $st1 = $pdo->prepare(
        'DELETE s FROM client_consents s
         INNER JOIN client_consents p ON p.document_definition_id = s.document_definition_id AND p.client_id = ?
         WHERE s.client_id = ?'
    );
    $st1->execute([999999001, 999999002]);
    $dc = $st1->rowCount();
    $st2 = $pdo->prepare(
        'DELETE m2 FROM marketing_contact_list_members m2
         INNER JOIN marketing_contact_list_members m1 ON m1.list_id = m2.list_id AND m1.client_id = ?
         WHERE m2.client_id = ?'
    );
    $st2->execute([999999001, 999999002]);
    $dm = $st2->rowCount();
    fwrite(STDOUT, "DEDUPE_REHEARSAL_ROLLBACK consents_rowCount={$dc} marketing_list_rowCount={$dm}\n");
} finally {
    $pdo->rollBack();
    fwrite(STDOUT, "DEDUPE_REHEARSAL transaction rolled back\n");
}

fwrite(STDOUT, "OK: verify_clients_domain_wave_01_db_audit\n");
exit(0);
