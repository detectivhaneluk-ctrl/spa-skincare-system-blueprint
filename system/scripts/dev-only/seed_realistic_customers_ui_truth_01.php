<?php

declare(strict_types=1);

/**
 * Dev-only: realistic named clients + clean UI-truth test day + optional blocked window for conflict demos.
 * Prereq: seed_branch_smoke_data.php + seed_appointments_calendar_realistic_01.php (staff/services/room).
 *
 * Writes fixture JSON for Playwright: system/scripts/dev-only/ui-truth-fixture.generated.json
 *
 * From system/:
 *   php scripts/dev-only/seed_realistic_customers_ui_truth_01.php
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

use Core\Branch\BranchContext;
use Core\Branch\TenantBranchAccessService;
use Core\Kernel\AssuranceLevel;
use Core\Kernel\ExecutionSurface;
use Core\Kernel\RequestContextHolder;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationContext;
use Modules\Appointments\Services\BlockedSlotService;
use Modules\Clients\Services\ClientService;

$db = app(\Core\App\Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$contextHolder = app(RequestContextHolder::class);
$tenantBranchAccess = app(TenantBranchAccessService::class);
$clientService = app(ClientService::class);
$blockedSlotService = app(BlockedSlotService::class);

$branchCode = 'SMOKE_A';
$testDate = '2026-05-12';

$adminRow = $db->fetchOne('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1', ['tenant-admin-a@example.test']);
if ($adminRow === null) {
    fwrite(STDERR, "Missing tenant-admin-a@example.test\n");
    exit(1);
}
$adminUserId = (int) $adminRow['id'];

$scopeRow = $db->fetchOne(
    'SELECT b.id AS branch_id, b.organization_id FROM branches b WHERE b.code = ? AND b.deleted_at IS NULL LIMIT 1',
    [$branchCode]
);
if ($scopeRow === null) {
    fwrite(STDERR, "Unknown branch {$branchCode}\n");
    exit(1);
}
$branchId = (int) $scopeRow['branch_id'];
$orgId = (int) $scopeRow['organization_id'];

if (!in_array($branchId, $tenantBranchAccess->allowedBranchIdsForUser($adminUserId), true)) {
    fwrite(STDERR, "Admin user cannot access branch_id={$branchId}\n");
    exit(1);
}

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
$contextHolder->set(TenantContext::resolvedTenant(
    actorId: $adminUserId,
    organizationId: $orgId,
    branchId: $branchId,
    isSupportEntry: false,
    supportActorId: null,
    assuranceLevel: AssuranceLevel::SESSION,
    executionSurface: ExecutionSurface::CLI,
    organizationResolutionMode: OrganizationContext::MODE_BRANCH_DERIVED,
));

$db->query(
    'UPDATE appointments SET deleted_at = NOW() WHERE branch_id = ? AND deleted_at IS NULL AND notes LIKE ? AND DATE(start_at) = ?',
    [$branchId, '[UI_TRUTH_V1]%', $testDate]
);
$db->query(
    "UPDATE appointment_blocked_slots SET deleted_at = NOW() WHERE branch_id = ? AND deleted_at IS NULL AND block_date = ? AND title LIKE 'UI_TRUTH%'",
    [$branchId, $testDate]
);

$clientsSpec = [
    ['fn' => 'Anahit', 'ln' => 'Karapetyan', 'em' => 'anahit.karapetyan@ui-truth.test', 'ph' => '+374-77-111-001'],
    ['fn' => 'Davit', 'ln' => 'Sargsyan', 'em' => null, 'ph' => '+374-98-222-002'],
    ['fn' => 'Mariam', 'ln' => 'Hakobyan', 'em' => 'mariam.h@ui-truth.test', 'ph' => '+374-55-333-003'],
    ['fn' => 'Armen', 'ln' => 'Petrosyan', 'em' => 'armen.p@ui-truth.test', 'ph' => null],
    ['fn' => 'Lilit', 'ln' => 'Grigoryan', 'em' => 'lilit.grigoryan@ui-truth.test', 'ph' => '+374-91-444-004'],
    ['fn' => 'Gor', 'ln' => 'Gevorgyan', 'em' => 'gor.gevorgyan.vip@ui-truth.test', 'ph' => '+374-99-555-005'],
    ['fn' => 'Sona', 'ln' => 'Ter-Mkrtchyan', 'em' => null, 'ph' => '+374-43-666-006'],
    ['fn' => 'Nare', 'ln' => 'Azatyan', 'em' => 'nare.azatyan@ui-truth.test', 'ph' => '+374-77-777-007'],
    ['fn' => 'Tigran', 'ln' => 'Vardanyan', 'em' => 'tigran.v@ui-truth.test', 'ph' => '+374-88-888-008'],
    ['fn' => 'Hasmik', 'ln' => 'Manukyan', 'em' => 'hasmik.m@ui-truth.test', 'ph' => '+374-33-999-009'],
    ['fn' => 'Elen', 'ln' => 'Beglaryan', 'em' => 'elen.beglaryan@ui-truth.test', 'ph' => '+374-77-000-010'],
    ['fn' => 'Karen', 'ln' => 'Mkrtchyan', 'em' => 'karen.mkrtchyan@ui-truth.test', 'ph' => '+374-55-000-011'],
    ['fn' => 'Lucy', 'ln' => 'Martirosyan', 'em' => 'lucy.m@ui-truth.test', 'ph' => '+374-44-121-012'],
];

$clientIds = [];
foreach ($clientsSpec as $c) {
    $email = $c['em'];
    $row = null;
    if ($email !== null && $email !== '') {
        $row = $db->fetchOne(
            'SELECT id FROM clients WHERE branch_id = ? AND email_lc = LOWER(?) AND deleted_at IS NULL LIMIT 1',
            [$branchId, $email]
        );
    }
    if ($row === null && $c['ph'] !== null) {
        $row = $db->fetchOne(
            'SELECT id FROM clients WHERE branch_id = ? AND phone = ? AND deleted_at IS NULL LIMIT 1',
            [$branchId, $c['ph']]
        );
    }
    if ($row) {
        $clientIds[$c['fn'] . '_' . $c['ln']] = (int) $row['id'];
        continue;
    }
    $payload = [
        'branch_id' => $branchId,
        'first_name' => $c['fn'],
        'last_name' => $c['ln'],
        'phone' => $c['ph'],
        'email' => $email,
    ];
    $clientIds[$c['fn'] . '_' . $c['ln']] = $clientService->create(array_filter($payload, static fn ($v) => $v !== null));
}

$staffByEmail = static function (string $email) use ($db, $branchId): int {
    $r = $db->fetchOne('SELECT id FROM staff WHERE branch_id = ? AND email = ? AND deleted_at IS NULL LIMIT 1', [$branchId, $email]);
    if (!$r) {
        throw new RuntimeException('Staff not found: ' . $email);
    }

    return (int) $r['id'];
};

$svcByName = static function (string $name) use ($db, $branchId): int {
    $r = $db->fetchOne('SELECT id FROM services WHERE branch_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1', [$branchId, $name]);
    if (!$r) {
        throw new RuntimeException('Service not found: ' . $name);
    }

    return (int) $r['id'];
};

$zara = $staffByEmail('cal-seed-staff-1@example.invalid');
$mia = $staffByEmail('cal-seed-staff-2@example.invalid');
$elena = $staffByEmail('cal-seed-staff-3@example.invalid');
$sofia = $staffByEmail('cal-seed-staff-4@example.invalid');
$nina = $staffByEmail('cal-seed-staff-5@example.invalid');

$blockedSlotService->create([
    'staff_id' => $zara,
    'block_date' => $testDate,
    'start_time' => '14:00',
    'end_time' => '15:00',
    'title' => 'UI_TRUTH_BLOCK',
    'notes' => 'Playwright conflict demo — afternoon hold',
]);

$roomRow = $db->fetchOne('SELECT id FROM rooms WHERE branch_id = ? AND code = ? AND deleted_at IS NULL LIMIT 1', [$branchId, 'CAL_ROOM_SEED']);
$roomId = $roomRow ? (int) $roomRow['id'] : null;

$fixture = [
    'branch_id' => $branchId,
    'branch_code' => $branchCode,
    'test_date' => $testDate,
    'room_id' => $roomId,
    'staff' => [
        'zara' => $zara,
        'mia' => $mia,
        'elena' => $elena,
        'sofia' => $sofia,
        'nina' => $nina,
    ],
    'services' => [
        'express30' => $svcByName('CALSVC Express 30'),
        'glow45' => $svcByName('CALSVC Glow 45'),
        'classic60' => $svcByName('CALSVC Classic 60'),
        'deep90' => $svcByName('CALSVC Deep 90'),
        'retreat120' => $svcByName('CALSVC Retreat 120'),
        'polish45' => $svcByName('CALSVC Polish 45'),
    ],
    'clients' => [
        'Anahit_Karapetyan' => $clientIds['Anahit_Karapetyan'],
        'Davit_Sargsyan' => $clientIds['Davit_Sargsyan'],
        'Mariam_Hakobyan' => $clientIds['Mariam_Hakobyan'],
        'Armen_Petrosyan' => $clientIds['Armen_Petrosyan'],
        'Lilit_Grigoryan' => $clientIds['Lilit_Grigoryan'],
        'Gor_Gevorgyan' => $clientIds['Gor_Gevorgyan'],
        'Sona_Ter-Mkrtchyan' => $clientIds['Sona_Ter-Mkrtchyan'],
        'Nare_Azatyan' => $clientIds['Nare_Azatyan'],
        'Tigran_Vardanyan' => $clientIds['Tigran_Vardanyan'],
        'Hasmik_Manukyan' => $clientIds['Hasmik_Manukyan'],
        'Elen_Beglaryan' => $clientIds['Elen_Beglaryan'],
        'Karen_Mkrtchyan' => $clientIds['Karen_Mkrtchyan'],
        'Lucy_Martirosyan' => $clientIds['Lucy_Martirosyan'],
    ],
];

$outPath = dirname(__DIR__) . '/dev-only/ui-truth-fixture.generated.json';
file_put_contents($outPath, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo json_encode([
    'ok' => true,
    'fixture_path' => $outPath,
    'clients_upserted' => count($clientIds),
    'test_date' => $testDate,
    'ui_truth_appointments_cleared_on_date' => true,
    'blocked_ui_truth' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
