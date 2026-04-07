<?php

declare(strict_types=1);

/**
 * CLIENT-PROFILE-READ-MODEL-CONSOLIDATION-AND-QUERY-BUDGET-HARDENING-01 — read-only structural proof (no DB).
 *
 * From repo root:
 *   php system/scripts/read-only/verify_client_profile_read_model_contract_01.php
 */

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(2);
}

$system = dirname(__DIR__, 2);
$serviceFile = $system . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'ClientProfileReadService.php';
$controllerFile = $system . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'clients' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'ClientController.php';
$registerFile = $system . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'register_clients.php';

foreach ([$serviceFile, $controllerFile, $registerFile] as $p) {
    if (!is_file($p)) {
        fail("missing file: {$p}");
    }
}

$serviceSrc = (string) file_get_contents($serviceFile);
$controllerSrc = (string) file_get_contents($controllerFile);
$registerSrc = (string) file_get_contents($registerFile);

if (!str_contains($serviceSrc, 'function buildMainProfileReadModel(')) {
    fail('ClientProfileReadService missing buildMainProfileReadModel');
}

$expectedTopLevel = [
    "'client'",
    "'shell'",
    "'appointments'",
    "'duplicates'",
    "'duplicate_search'",
    "'custom_fields'",
    "'flags'",
    "'commerce'",
    "'packages'",
    "'gift_cards'",
    "'memberships'",
    "'audit'",
    "'layout'",
    "'permissions'",
    "'_read_steps'",
];
foreach ($expectedTopLevel as $key) {
    if (!str_contains($serviceSrc, $key)) {
        fail("ClientProfileReadService return payload missing key fragment {$key}");
    }
}

if (!str_contains($controllerSrc, '$this->profileRead->buildMainProfileReadModel(')) {
    fail('ClientController::show must delegate to profileRead->buildMainProfileReadModel');
}

if (!preg_match('/private\s+ClientProfileReadService\s+\$profileRead/', $controllerSrc)) {
    fail('ClientController must inject ClientProfileReadService $profileRead');
}

if (!str_contains($registerSrc, '\\Modules\\Clients\\Services\\ClientProfileReadService::class')) {
    fail('register_clients must register ClientProfileReadService');
}

if (!str_contains($registerSrc, '$c->get(\Modules\Clients\Services\ClientProfileReadService::class)')) {
    fail('ClientController container binding must resolve ClientProfileReadService');
}

echo "PASS: client_profile_read_model_contract_01\n";
echo "  service: {$serviceFile}\n";
echo "  top-level keys checked: " . implode(', ', $expectedTopLevel) . "\n";
exit(0);
