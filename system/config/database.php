<?php

declare(strict_types=1);

$databaseName = trim((string) env('DB_DATABASE', ''));
if ($databaseName === '') {
    throw new \RuntimeException('DB_DATABASE is required. Copy system/.env.example to system/.env.local (preferred) or system/.env, then set DB_DATABASE.');
}

return [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => $databaseName,
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];
