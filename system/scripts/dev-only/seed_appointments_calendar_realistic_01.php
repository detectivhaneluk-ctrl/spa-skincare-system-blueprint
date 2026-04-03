<?php

declare(strict_types=1);

/**
 * Dev-only: realistic appointment/calendar dataset for smoke & UI truth (APPOINTMENTS-CALENDAR-SMOKE-01).
 *
 * Prerequisites:
 *   - php scripts/migrate.php, php scripts/seed.php
 *   - php scripts/dev-only/seed_branch_smoke_data.php
 *
 * Default branch SMOKE_A (tenant-admin-a@example.test is pinned there; booking requires allowedBranchIds).
 *
 * Usage (from system/):
 *   php scripts/dev-only/seed_appointments_calendar_realistic_01.php
 *   php scripts/dev-only/seed_appointments_calendar_realistic_01.php --branch-code=SMOKE_A
 *   php scripts/dev-only/seed_appointments_calendar_realistic_01.php --day-offset=35
 *
 * Idempotent: re-run clears prior CAL_SEED appointments/blocked rows for the branch, then recreates them.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Branch\TenantBranchAccessService;
use Core\Kernel\AssuranceLevel;
use Core\Kernel\ExecutionSurface;
use Core\Kernel\RequestContextHolder;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationContext;
use Modules\Appointments\Services\AppointmentService;
use Modules\Appointments\Services\AvailabilityService;
use Modules\Appointments\Services\BlockedSlotService;
use Modules\Clients\Services\ClientService;

$db = app(Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$contextHolder = app(RequestContextHolder::class);
$tenantBranchAccess = app(TenantBranchAccessService::class);
$appointmentService = app(AppointmentService::class);
$blockedSlotService = app(BlockedSlotService::class);
$availability = app(AvailabilityService::class);
$clientService = app(ClientService::class);

$branchCode = 'SMOKE_A';
$dayOffset = 28;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--branch-code=')) {
        $branchCode = trim(substr($arg, strlen('--branch-code=')));
    }
    if (str_starts_with($arg, '--day-offset=')) {
        $dayOffset = max(7, (int) substr($arg, strlen('--day-offset=')));
    }
}

$adminRow = $db->fetchOne('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1', ['tenant-admin-a@example.test']);
if ($adminRow === null) {
    fwrite(STDERR, "Missing tenant-admin-a@example.test — run seed_branch_smoke_data.php first.\n");
    exit(1);
}
$adminUserId = (int) $adminRow['id'];

$scopeRow = $db->fetchOne(
    'SELECT b.id AS branch_id, b.organization_id AS organization_id
     FROM branches b
     INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
     WHERE b.code = ? AND b.deleted_at IS NULL LIMIT 1',
    [$branchCode]
);
if ($scopeRow === null) {
    fwrite(STDERR, "Unknown branch code: {$branchCode}\n");
    exit(1);
}
$branchId = (int) $scopeRow['branch_id'];
$orgId = (int) $scopeRow['organization_id'];

$allowed = $tenantBranchAccess->allowedBranchIdsForUser($adminUserId);
if (!in_array($branchId, $allowed, true)) {
    fwrite(STDERR, "User tenant-admin-a cannot book on branch_id={$branchId}. Pin/membership must include this branch (default: SMOKE_A).\n");
    exit(1);
}

$groupProbe = $db->fetchOne(
    'SELECT COUNT(*) AS c FROM staff_groups WHERE branch_id = ? AND deleted_at IS NULL AND is_active = 1',
    [$branchId]
);
if ((int) ($groupProbe['c'] ?? 0) > 0) {
    fwrite(STDERR, "WARNING: branch has active staff_groups; ensure calendar seed staff are in a group or they may be hidden from scheduling.\n");
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

$d1 = (new DateTimeImmutable('today'))->modify("+{$dayOffset} days")->format('Y-m-d');
$d2 = (new DateTimeImmutable('today'))->modify('+' . ($dayOffset + 1) . ' days')->format('Y-m-d');

// ── Cleanup prior seed payloads ─────────────────────────────────────────────
$db->query(
    'UPDATE appointments SET deleted_at = NOW() WHERE branch_id = ? AND deleted_at IS NULL AND notes LIKE ?',
    [$branchId, '[CAL_SEED_V1]%']
);
$db->query(
    "UPDATE appointment_blocked_slots SET deleted_at = NOW()
     WHERE branch_id = ? AND deleted_at IS NULL AND title LIKE 'CAL_SEED%'",
    [$branchId]
);

$staffSpecs = [
    ['fn' => 'Zara', 'ln' => 'CalSeed', 'em' => 'cal-seed-staff-1@example.invalid', 'ph' => '+1-555-0101'],
    ['fn' => 'Mia', 'ln' => 'CalSeed', 'em' => 'cal-seed-staff-2@example.invalid', 'ph' => '+1-555-0102'],
    ['fn' => 'Elena', 'ln' => 'CalSeed', 'em' => 'cal-seed-staff-3@example.invalid', 'ph' => '+1-555-0103'],
    ['fn' => 'Sofia', 'ln' => 'CalSeed', 'em' => 'cal-seed-staff-4@example.invalid', 'ph' => '+1-555-0104'],
    ['fn' => 'Nina', 'ln' => 'CalSeed', 'em' => 'cal-seed-staff-5@example.invalid', 'ph' => '+1-555-0105'],
    ['fn' => 'Dana', 'ln' => 'CalSeed', 'em' => 'cal-seed-staff-6@example.invalid', 'ph' => '+1-555-0106'],
];

$staffIds = [];
foreach ($staffSpecs as $sp) {
    $ex = $db->fetchOne('SELECT id FROM staff WHERE email = ? AND deleted_at IS NULL LIMIT 1', [$sp['em']]);
    if ($ex) {
        $staffIds[] = (int) $ex['id'];
        $db->query(
            'UPDATE staff SET first_name = ?, last_name = ?, phone = ?, is_active = 1, branch_id = ?, onboarding_step = NULL WHERE id = ?',
            [$sp['fn'], $sp['ln'], $sp['ph'], $branchId, (int) $ex['id']]
        );
        continue;
    }
    $staffIds[] = $db->insert('staff', [
        'user_id' => null,
        'first_name' => $sp['fn'],
        'last_name' => $sp['ln'],
        'email' => $sp['em'],
        'phone' => $sp['ph'],
        'job_title' => 'Therapist',
        'is_active' => 1,
        'branch_id' => $branchId,
        'onboarding_step' => null,
        'created_by' => $adminUserId,
        'updated_by' => $adminUserId,
    ]);
}

foreach ($staffIds as $sid) {
    $db->query('DELETE FROM staff_schedules WHERE staff_id = ?', [$sid]);
    for ($dow = 0; $dow <= 6; $dow++) {
        $db->insert('staff_schedules', [
            'staff_id' => $sid,
            'day_of_week' => $dow,
            'start_time' => '08:00:00',
            'end_time' => '20:00:00',
        ]);
    }
}

$serviceDefs = [
    ['name' => 'CALSVC Express 30', 'dur' => 30],
    ['name' => 'CALSVC Glow 45', 'dur' => 45],
    ['name' => 'CALSVC Classic 60', 'dur' => 60],
    ['name' => 'CALSVC Deep 90', 'dur' => 90],
    ['name' => 'CALSVC Retreat 120', 'dur' => 120],
    ['name' => 'CALSVC Mini 30', 'dur' => 30],
    ['name' => 'CALSVC Polish 45', 'dur' => 45],
    ['name' => 'CALSVC Refresh 60', 'dur' => 60],
    ['name' => 'CALSVC Sculpt 90', 'dur' => 90],
    ['name' => 'CALSVC Deluxe 60', 'dur' => 60],
];

$serviceIdsByName = [];
foreach ($serviceDefs as $def) {
    $row = $db->fetchOne(
        'SELECT id FROM services WHERE name = ? AND branch_id = ? AND deleted_at IS NULL LIMIT 1',
        [$def['name'], $branchId]
    );
    if ($row) {
        $sid = (int) $row['id'];
        $db->query(
            'UPDATE services SET duration_minutes = ?, buffer_before_minutes = 0, buffer_after_minutes = 0, is_active = 1 WHERE id = ?',
            [$def['dur'], $sid]
        );
    } else {
        $sid = $db->insert('services', [
            'category_id' => null,
            'name' => $def['name'],
            'duration_minutes' => $def['dur'],
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'price' => 0,
            'vat_rate_id' => null,
            'is_active' => 1,
            'branch_id' => $branchId,
            'created_by' => $adminUserId,
            'updated_by' => $adminUserId,
        ]);
    }
    $serviceIdsByName[$def['name']] = $sid;
}

$s = static fn (string $n): int => $serviceIdsByName[$n];

$calSvcIds = $db->fetchAll(
    'SELECT id FROM services WHERE branch_id = ? AND name LIKE ? AND deleted_at IS NULL',
    [$branchId, 'CALSVC %']
);
foreach ($calSvcIds as $row) {
    $db->query('DELETE FROM service_staff WHERE service_id = ?', [(int) $row['id']]);
}
$pairs = [
    [$s('CALSVC Express 30'), [0, 1, 4]],
    [$s('CALSVC Glow 45'), [0, 1, 2, 3]],
    [$s('CALSVC Classic 60'), [0, 2, 3, 4]],
    [$s('CALSVC Deep 90'), [1, 2, 3, 4]],
    [$s('CALSVC Retreat 120'), [0, 3, 4]],
    [$s('CALSVC Mini 30'), [1, 2, 5]],
    [$s('CALSVC Polish 45'), [0, 4]],
    [$s('CALSVC Refresh 60'), [1, 3, 5]],
    [$s('CALSVC Sculpt 90'), [2, 4]],
    [$s('CALSVC Deluxe 60'), [4]],
];
foreach ($pairs as [$svcId, $idxs]) {
    foreach ($idxs as $i) {
        $db->query(
            'INSERT IGNORE INTO service_staff (service_id, staff_id) VALUES (?, ?)',
            [$svcId, $staffIds[$i]]
        );
    }
}

$roomRow = $db->fetchOne(
    'SELECT id FROM rooms WHERE branch_id = ? AND code = ? AND deleted_at IS NULL LIMIT 1',
    [$branchId, 'CAL_ROOM_SEED']
);
if ($roomRow) {
    $roomId = (int) $roomRow['id'];
} else {
    $roomId = $db->insert('rooms', [
        'name' => 'Cal Seed Suite',
        'code' => 'CAL_ROOM_SEED',
        'is_active' => 1,
        'maintenance_mode' => 0,
        'branch_id' => $branchId,
    ]);
}

$retreatId = $s('CALSVC Retreat 120');
$db->query('DELETE FROM service_rooms WHERE service_id = ?', [$retreatId]);
$db->insert('service_rooms', ['service_id' => $retreatId, 'room_id' => $roomId]);

for ($dow = 0; $dow <= 6; $dow++) {
    $db->query(
        'INSERT INTO branch_operating_hours (branch_id, day_of_week, start_time, end_time)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time)',
        [$branchId, $dow, '08:00:00', '21:00:00']
    );
}

$clientIds = [];
for ($i = 1; $i <= 15; $i++) {
    $email = sprintf('cal-seed-client-%02d@example.invalid', $i);
    $ex = $db->fetchOne(
        'SELECT id FROM clients WHERE branch_id = ? AND email_lc = LOWER(?) AND deleted_at IS NULL LIMIT 1',
        [$branchId, $email]
    );
    if ($ex) {
        $clientIds[] = (int) $ex['id'];
        continue;
    }
    $clientIds[] = $clientService->create([
        'branch_id' => $branchId,
        'first_name' => 'Guest',
        'last_name' => sprintf('CalSeed %02d', $i),
        'email' => $email,
        'phone' => sprintf('+1-555-%04d', 2000 + $i),
    ]);
}

// Blocked slots (staff 5 = near full column; staff 0 afternoon; staff 1 lunch)
$blockedSlotService->create([
    'staff_id' => $staffIds[5],
    'block_date' => $d1,
    'start_time' => '08:00',
    'end_time' => '20:00',
    'title' => 'CAL_SEED_OFF',
    'notes' => 'Cal seed: day off / blocked column',
]);
$blockedSlotService->create([
    'staff_id' => $staffIds[0],
    'block_date' => $d1,
    'start_time' => '13:00',
    'end_time' => '16:00',
    'title' => 'CAL_SEED_PM',
    'notes' => 'Cal seed: afternoon block',
]);
$blockedSlotService->create([
    'staff_id' => $staffIds[1],
    'block_date' => $d1,
    'start_time' => '12:00',
    'end_time' => '13:00',
    'title' => 'CAL_SEED_LUNCH',
    'notes' => 'Cal seed: lunch',
]);

/**
 * @param array{svc:string,time:string,room?:bool} $rows
 */
$makeAppts = static function (
    string $day,
    array $rows,
    array $staffIds,
    array $serviceIdsByName,
    array $clientIds,
    int $branchId,
    int $roomId,
    AppointmentService $appointmentService,
    AvailabilityService $availability,
    int &$clientCursor,
): int {
    $n = 0;
    foreach ($rows as $r) {
        $staffId = $staffIds[$r['staffIdx']];
        $svcName = $r['svc'];
        $serviceId = $serviceIdsByName[$svcName];
        $startAt = $day . ' ' . $r['time'] . ':00';
        $dur = $availability->getServiceDurationMinutes($serviceId, $branchId);
        $endAt = date('Y-m-d H:i:s', strtotime($startAt) + $dur * 60);
        $cid = $clientIds[$clientCursor % count($clientIds)];
        $clientCursor++;
        $payload = [
            'client_id' => $cid,
            'service_id' => $serviceId,
            'staff_id' => $staffId,
            'branch_id' => $branchId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status' => 'scheduled',
            'notes' => '[CAL_SEED_V1] ' . ($r['label'] ?? $svcName),
        ];
        if (!empty($r['room'])) {
            $payload['room_id'] = $roomId;
        }
        $appointmentService->create($payload);
        $n++;
    }

    return $n;
};

$clientCursor = 0;
$day1Rows = [
    ['staffIdx' => 0, 'svc' => 'CALSVC Retreat 120', 'time' => '09:00', 'room' => true, 'label' => 'Morning spa suite'],
    ['staffIdx' => 0, 'svc' => 'CALSVC Express 30', 'time' => '16:00', 'label' => 'Late express'],
    ['staffIdx' => 1, 'svc' => 'CALSVC Glow 45', 'time' => '10:00', 'label' => 'Morning glow'],
    ['staffIdx' => 1, 'svc' => 'CALSVC Glow 45', 'time' => '11:15', 'label' => 'Parallel lane B 11:15'],
    ['staffIdx' => 1, 'svc' => 'CALSVC Deep 90', 'time' => '14:00', 'label' => 'Afternoon deep'],
    ['staffIdx' => 2, 'svc' => 'CALSVC Mini 30', 'time' => '09:00', 'label' => 'Early mini'],
    ['staffIdx' => 2, 'svc' => 'CALSVC Classic 60', 'time' => '11:15', 'label' => 'Parallel lane A 11:15'],
    ['staffIdx' => 2, 'svc' => 'CALSVC Sculpt 90', 'time' => '13:00', 'label' => 'Afternoon sculpt'],
    ['staffIdx' => 3, 'svc' => 'CALSVC Glow 45', 'time' => '11:15', 'label' => 'Parallel lane C 11:15'],
    ['staffIdx' => 3, 'svc' => 'CALSVC Retreat 120', 'time' => '14:00', 'room' => true, 'label' => 'Afternoon suite'],
    ['staffIdx' => 4, 'svc' => 'CALSVC Express 30', 'time' => '09:30', 'label' => 'Quick slot'],
    ['staffIdx' => 4, 'svc' => 'CALSVC Classic 60', 'time' => '10:30', 'label' => 'Mid classic'],
    ['staffIdx' => 4, 'svc' => 'CALSVC Deluxe 60', 'time' => '16:30', 'label' => 'Senior-only deluxe'],
];

$day2Rows = [
    ['staffIdx' => 0, 'svc' => 'CALSVC Classic 60', 'time' => '10:00', 'label' => 'D2 classic'],
    ['staffIdx' => 1, 'svc' => 'CALSVC Refresh 60', 'time' => '11:00', 'label' => 'D2 refresh'],
    ['staffIdx' => 2, 'svc' => 'CALSVC Deep 90', 'time' => '09:30', 'label' => 'D2 deep'],
    ['staffIdx' => 5, 'svc' => 'CALSVC Mini 30', 'time' => '15:00', 'label' => 'D2 mini'],
    ['staffIdx' => 4, 'svc' => 'CALSVC Sculpt 90', 'time' => '14:00', 'label' => 'D2 sculpt'],
    ['staffIdx' => 0, 'svc' => 'CALSVC Glow 45', 'time' => '14:30', 'label' => 'D2 glow'],
    ['staffIdx' => 4, 'svc' => 'CALSVC Express 30', 'time' => '16:00', 'label' => 'D2 express'],
];

$total = 0;
$total += $makeAppts($d1, $day1Rows, $staffIds, $serviceIdsByName, $clientIds, $branchId, $roomId, $appointmentService, $availability, $clientCursor);
$total += $makeAppts($d2, $day2Rows, $staffIds, $serviceIdsByName, $clientIds, $branchId, $roomId, $appointmentService, $availability, $clientCursor);

echo json_encode([
    'branch_code' => $branchCode,
    'branch_id' => $branchId,
    'organization_id' => $orgId,
    'day1' => $d1,
    'day2' => $d2,
    'staff' => count($staffIds),
    'services' => count($serviceIdsByName),
    'clients_seeded' => count($clientIds),
    'blocked_slots' => 3,
    'appointments_created' => $total,
    'room_id' => $roomId,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
