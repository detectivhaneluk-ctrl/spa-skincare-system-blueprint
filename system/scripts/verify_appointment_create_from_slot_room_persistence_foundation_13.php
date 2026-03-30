<?php

declare(strict_types=1);

/**
 * Foundation 13: optional room_id on internal POST /appointments/create → {@see AppointmentService::createFromSlot}
 * persists room with {@see AppointmentRepository::hasRoomConflict} on write.
 *
 * From system/:
 *   php scripts/verify_appointment_create_from_slot_room_persistence_foundation_13.php --branch-code=SMOKE_A
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Appointments\Services\AppointmentService;
use Modules\Appointments\Services\AvailabilityService;

$passed = 0;
$failed = 0;
$createdIds = [];

function v13Pass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function v13Fail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

function v13Cleanup(AppointmentRepository $repo): void
{
    global $createdIds;
    foreach ($createdIds as $id) {
        try {
            $repo->softDelete((int) $id);
        } catch (Throwable) {
            // best-effort
        }
    }
    $createdIds = [];
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function v13ResolveScopeByBranchCode(Database $db, string $code): array
{
    $code = trim($code);
    if ($code === '') {
        throw new InvalidArgumentException('Branch code is empty.');
    }
    $row = $db->fetchOne(
        'SELECT b.id AS branch_id, b.organization_id AS organization_id
         FROM branches b
         INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
         WHERE b.code = ? AND b.deleted_at IS NULL
         LIMIT 1',
        [$code]
    );
    if ($row === null) {
        throw new RuntimeException('No active branch found for code ' . $code);
    }

    return [
        'branch_id' => (int) $row['branch_id'],
        'organization_id' => (int) $row['organization_id'],
    ];
}

$branchCode = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--branch-code=')) {
        $branchCode = trim(substr($arg, strlen('--branch-code=')));
    }
}
if ($branchCode === '') {
    $branchCode = trim((string) (getenv('APPOINTMENT_SETTINGS_VERIFY_BRANCH_CODE') ?: ''));
}
if ($branchCode === '') {
    fwrite(STDERR, "FAIL  scope: Pass --branch-code=<branches.code> (or APPOINTMENT_SETTINGS_VERIFY_BRANCH_CODE).\n");
    exit(1);
}

$db = app(Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$service = app(AppointmentService::class);
$repo = app(AppointmentRepository::class);
$availability = app(AvailabilityService::class);

try {
    $scope = v13ResolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    v13Fail('scope', $e->getMessage());
    exit(1);
}

$branchId = $scope['branch_id'];
$orgId = $scope['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

$roomRow = $db->fetchOne(
    'SELECT id FROM rooms WHERE deleted_at IS NULL AND branch_id = ? LIMIT 1',
    [$branchId]
);
if ($roomRow === null) {
    echo "SKIP  room_row: No room for branch_id {$branchId}.\n";
    exit(0);
}
$roomId = (int) $roomRow['id'];

$pair = $db->fetchOne(
    'SELECT c.id AS client_id, s.id AS service_id, st.id AS staff_id
     FROM clients c
     CROSS JOIN services s
     INNER JOIN service_staff ss ON ss.service_id = s.id AND ss.staff_id IS NOT NULL
     INNER JOIN staff st ON st.id = ss.staff_id AND st.deleted_at IS NULL AND st.is_active = 1
     WHERE c.deleted_at IS NULL AND (c.branch_id = ? OR c.branch_id IS NULL)
       AND s.deleted_at IS NULL AND s.is_active = 1 AND (s.branch_id = ? OR s.branch_id IS NULL)
     LIMIT 1',
    [$branchId, $branchId]
);
if ($pair === null) {
    echo "SKIP  booking_fixture: No client + service + staff triple for branch.\n";
    exit(0);
}

$clientId = (int) $pair['client_id'];
$serviceId = (int) $pair['service_id'];
$staffId = (int) $pair['staff_id'];

$altStaff = $db->fetchOne(
    'SELECT st.id AS staff_id
     FROM service_staff ss
     INNER JOIN staff st ON st.id = ss.staff_id AND st.deleted_at IS NULL AND st.is_active = 1
     WHERE ss.service_id = ? AND st.id != ?
     LIMIT 1',
    [$serviceId, $staffId]
);

$date = date('Y-m-d', strtotime('+10 days'));
$slotsWithRoom = $availability->getAvailableSlots($serviceId, $date, $staffId, $branchId, 'internal', $roomId);
if ($slotsWithRoom === []) {
    echo "SKIP  free_slot: No internal slot with room filter for fixture (try another branch/date).\n";
    exit(0);
}
$slotHm = $slotsWithRoom[0];
$startTime = $date . ' ' . (strlen($slotHm) === 5 ? $slotHm . ':00' : $slotHm);

$basePayload = [
    'client_id' => $clientId,
    'service_id' => $serviceId,
    'staff_id' => $staffId,
    'start_time' => $startTime,
    'branch_id' => $branchId,
];

try {
    $idNoRoom = $service->createFromSlot($basePayload);
    $createdIds[] = $idNoRoom;
    $rowNo = $db->fetchOne('SELECT room_id FROM appointments WHERE id = ?', [$idNoRoom]);
    if ($rowNo === null || $rowNo['room_id'] !== null) {
        v13Fail('null_room', 'Expected room_id NULL when omitted.');
        throw new RuntimeException('abort');
    }
    v13Pass('create_from_slot_without_room_persists_null_room');

    $slotsNoRoom = $availability->getAvailableSlots($serviceId, $date, $staffId, $branchId, 'internal', null);
    if ($slotsNoRoom !== $availability->getAvailableSlots($serviceId, $date, $staffId, $branchId, 'internal')) {
        v13Fail('wave12_unchanged', 'Internal slots without explicit null room_id should match explicit null.');
        throw new RuntimeException('abort');
    }
    v13Pass('wave12_internal_slot_shape_stable');

    v13Cleanup($repo);

    $idWithRoom = $service->createFromSlot(array_merge($basePayload, ['room_id' => $roomId]));
    $createdIds[] = $idWithRoom;
    $rowWith = $db->fetchOne('SELECT room_id FROM appointments WHERE id = ?', [$idWithRoom]);
    if ($rowWith === null || (int) $rowWith['room_id'] !== $roomId) {
        v13Fail('persist_room', 'Expected room_id persisted.');
        throw new RuntimeException('abort');
    }
    v13Pass('create_from_slot_with_room_persists_room_id');

    v13Cleanup($repo);

    $badRoom = $db->fetchOne(
        'SELECT r.id FROM rooms r
         INNER JOIN branches b ON b.id = r.branch_id AND b.deleted_at IS NULL
         WHERE r.deleted_at IS NULL AND b.organization_id = ? AND r.branch_id IS NOT NULL AND r.branch_id != ?
         LIMIT 1',
        [$orgId, $branchId]
    );
    if ($badRoom !== null) {
        $badRoomId = (int) $badRoom['id'];
        try {
            $service->createFromSlot(array_merge($basePayload, ['room_id' => $badRoomId]));
            v13Fail('reject_foreign_room', 'Expected DomainException for room outside branch.');
            throw new RuntimeException('abort');
        } catch (\DomainException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'room')) {
                v13Fail('reject_foreign_room_msg', 'Expected room scope message, got: ' . $e->getMessage());
                throw new RuntimeException('abort');
            }
            v13Pass('invalid_room_rejected');
        }
    } else {
        echo "SKIP  foreign_branch_room: No second branch room in same org for negative test.\n";
    }

    if ($altStaff !== null) {
        $staff2 = (int) $altStaff['staff_id'];
        $idFirst = $service->createFromSlot(array_merge($basePayload, [
            'staff_id' => $staffId,
            'room_id' => $roomId,
            'start_time' => $startTime,
        ]));
        $createdIds[] = $idFirst;
        try {
            $service->createFromSlot([
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'staff_id' => $staff2,
                'start_time' => $startTime,
                'branch_id' => $branchId,
                'room_id' => $roomId,
            ]);
            v13Fail('room_double_book', 'Second create with same room/time should fail.');
            throw new RuntimeException('abort');
        } catch (\DomainException $e) {
            if (stripos($e->getMessage(), 'room') === false) {
                v13Fail('room_double_book_msg', $e->getMessage());
                throw new RuntimeException('abort');
            }
            v13Pass('authoritative_room_conflict_blocks_second_booking');
        }
    } else {
        echo "SKIP  room_race_second_staff: No alternate staff on service for double-book test.\n";
    }
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'abort') {
        throw $e;
    }
} finally {
    v13Cleanup($repo);
}

if ($failed > 0) {
    fwrite(STDERR, "\nFailed {$failed} check(s).\n");
    exit(1);
}

echo "\nAll {$passed} check(s) passed.\n";
