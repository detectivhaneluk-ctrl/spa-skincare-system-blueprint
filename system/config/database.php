<?php

declare(strict_types=1);

$databaseName = trim((string) env('DB_DATABASE', ''));
if ($databaseName === '') {
    throw new \RuntimeException('DB_DATABASE is required. Copy system/.env.example to system/.env.local (preferred) or system/.env, then set DB_DATABASE.');
}

return [
    'driver'   => 'mysql',
    'host'     => env('DB_HOST', '127.0.0.1'),
    'port'     => (int) env('DB_PORT', 3306),
    'database' => $databaseName,
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset'  => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',

    // WAVE-07: Read replica routing.
    //
    // Set DB_REPLICA_HOST to a replica hostname/IP (or to the ProxySQL read-only
    // port if using ProxySQL transparent routing) to enable application-level
    // read/write routing. Leave blank (default) to disable the split and route
    // all queries to the primary — safe for local dev and single-node setups.
    //
    // DB_READ_WRITE_ROUTING=true is required in addition to DB_REPLICA_HOST;
    // this double-gate prevents accidental activation during deploys.
    //
    // See deploy/proxysql/README.md for ProxySQL deployment guidance (WAVE-03).
    'replica_host'              => (string) env('DB_REPLICA_HOST', ''),
    'replica_port'              => (int) env('DB_REPLICA_PORT', 3306),
    'read_write_routing_enabled' => filter_var(env('DB_READ_WRITE_ROUTING', 'false'), FILTER_VALIDATE_BOOLEAN),
];
