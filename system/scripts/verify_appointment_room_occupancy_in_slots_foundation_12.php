<?php

declare(strict_types=1);

/**
 * Foundation 12: internal slot search honors room occupancy when room_id is supplied
 * ({@see \Modules\Appointments\Services\AvailabilityService::getAvailableSlots} + {@see isSlotAvailable}).
 *
 * From system/:
 *   php scripts/verify_appointment_room_occupancy_in_slots_foundation_12.php --branch-code=SMOKE_A
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Appointments\Services\AvailabilityService;

$passed = 0;
$failed = 0;

function v12Pass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function v12Fail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function v12ResolveScopeByBranchCode(Database $db, string $code): array
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

$availability = app(AvailabilityService::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$db = app(Database::class);

try {
    $scope = v12ResolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    v12Fail('scope', $e->getMessage());
    exit(1);
}

$branchId = $scope['branch_id'];
$orgId = $scope['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

$block = $db->fetchOne(
    'SELECT id, room_id, branch_id, service_id, staff_id, start_at, end_at
     FROM appointments
     WHERE deleted_at IS NULL
       AND room_id IS NOT NULL
       AND status NOT IN (\'cancelled\', \'no_show\')
       AND branch_id = ?
     ORDER BY id DESC
     LIMIT 1',
    [$branchId]
);

if ($block === null) {
    echo "SKIP  room_slot_fixture: No non-cancelled appointment with room_id on this branch (cannot prove room occupancy filter).\n";
    exit(0);
}

$serviceId = (int) $block['service_id'];
$roomId = (int) $block['room_id'];
$staffBusy = (int) $block['staff_id'];
$startAt = (string) $block['start_at'];
$date = date('Y-m-d', strtotime($startAt));

$altStaffRow = $db->fetchOne(
    'SELECT st.id AS id
     FROM service_staff ss
     INNER JOIN staff st ON st.id = ss.staff_id AND st.deleted_at IS NULL AND st.is_active = 1
     WHERE ss.service_id = ? AND st.id != ?
     LIMIT 1',
    [$serviceId, $staffBusy]
);

if ($altStaffRow === null) {
    echo "SKIP  room_slot_second_staff: No alternate staff linked to service_id {$serviceId} (cannot isolate room vs staff conflict).\n";
    exit(0);
}

$staffAlt = (int) $altStaffRow['id'];

$slotsNoRoom = $availability->getAvailableSlots($serviceId, $date, $staffAlt, $branchId, 'internal', null);
$slotsWithRoom = $availability->getAvailableSlots($serviceId, $date, $staffAlt, $branchId, 'internal', $roomId);

$slotHm = date('H:i', strtotime($startAt));
if (!in_array($slotHm, $slotsNoRoom, true)) {
    echo "SKIP  room_slot_baseline: Alternate staff has no slot at {$slotHm} without room filter (staff schedule or other constraint); cannot compare lists.\n";
    exit(0);
}

if (in_array($slotHm, $slotsWithRoom, true)) {
    v12Fail('room_filters_slot', "Expected room {$roomId} occupancy to remove {$slotHm} for staff {$staffAlt}; still present with room filter.");
    exit(1);
}
v12Pass('internal_slots_exclude_room_occupied_time');

$searchBlocked = $availability->isSlotAvailable(
    $serviceId,
    $staffAlt,
    $startAt,
    null,
    $branchId,
    true,
    false,
    $roomId
);
if ($searchBlocked !== false) {
    v12Fail('is_slot_available_room', 'Expected isSlotAvailable (search, internal, room) false when room occupied at same window.');
    exit(1);
}
v12Pass('is_slot_available_search_honors_room');

$bookingA = $availability->isSlotAvailable(
    $serviceId,
    $staffAlt,
    $startAt,
    null,
    $branchId,
    false,
    false,
    null
);
$bookingB = $availability->isSlotAvailable(
    $serviceId,
    $staffAlt,
    $startAt,
    null,
    $branchId,
    false,
    false,
    $roomId
);
if ($bookingA !== $bookingB) {
    v12Fail('booking_path_ignores_room_param', 'Booking-path isSlotAvailable must ignore 8th room arg (parity with write pipeline).');
    exit(1);
}
v12Pass('booking_path_is_slot_available_unchanged_by_room_search_param');

$publicSlots = $availability->getAvailableSlots($serviceId, $date, $staffAlt, $branchId, 'public', $roomId);
if ($publicSlots !== $availability->getAvailableSlots($serviceId, $date, $staffAlt, $branchId, 'public', null)) {
    v12Fail('public_unchanged', 'Public audience must ignore room_id for occupancy (6th arg should not affect public slot lists).');
    exit(1);
}
v12Pass('public_slot_list_ignores_room_id_argument');

echo "\nAll {$passed} check(s) passed.\n";
