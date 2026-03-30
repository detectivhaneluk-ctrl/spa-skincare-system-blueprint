<?php

declare(strict_types=1);

/**
 * Foundation 14: canonical room conflict — internal search ({@see AvailabilityService::isSlotAvailable} + room)
 * agrees with {@see AppointmentRepository::hasRoomConflict}; write paths delegate to the same SQL.
 *
 * From system/:
 *   php scripts/verify_appointment_room_conflict_canonicalization_foundation_14.php --branch-code=SMOKE_A
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Appointments\Services\AvailabilityService;

$passed = 0;
$failed = 0;

function v14Pass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function v14Fail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function v14ResolveScopeByBranchCode(Database $db, string $code): array
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
$availability = app(AvailabilityService::class);
$repo = app(AppointmentRepository::class);

try {
    $scope = v14ResolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    v14Fail('scope', $e->getMessage());
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
       AND room_id IS NOT NULL AND room_id > 0
       AND status NOT IN (\'cancelled\', \'no_show\')
       AND branch_id = ?
     ORDER BY id DESC
     LIMIT 1',
    [$branchId]
);

if ($block === null) {
    echo "SKIP  room_canonical_fixture: No active blocking appointment with room_id on this branch.\n";
    exit(0);
}

$serviceId = (int) $block['service_id'];
$roomId = (int) $block['room_id'];
$staffBusy = (int) $block['staff_id'];
$startAt = (string) $block['start_at'];
$occupierId = (int) $block['id'];
$durationMin = $availability->getServiceDurationMinutes($serviceId);
if ($durationMin <= 0) {
    echo "SKIP  room_canonical_duration: service_id {$serviceId} has no positive duration.\n";
    exit(0);
}
$endProbe = date('Y-m-d H:i:s', strtotime($startAt) + $durationMin * 60);
$blockEnd = (string) $block['end_at'];
$endAt = $blockEnd !== '' ? $blockEnd : $endProbe;

$repoSaysConflict = $repo->hasRoomConflict($roomId, $startAt, $endAt, $branchId, 0);
if ($repoSaysConflict !== true) {
    v14Fail('repo_conflict_expected', 'hasRoomConflict should see the fixture occupier for the same window.');
    exit(1);
}
v14Pass('has_room_conflict_matches_fixture_occupancy');

$repoSaysNoSelf = $repo->hasRoomConflict($roomId, $startAt, $endAt, $branchId, $occupierId);
if ($repoSaysNoSelf !== false) {
    v14Fail('repo_self_exclude', 'Excluding occupier appointment id must yield no room conflict for same window.');
    exit(1);
}
v14Pass('has_room_conflict_excludes_current_appointment_id');

$altStaffRow = $db->fetchOne(
    'SELECT st.id AS id
     FROM service_staff ss
     INNER JOIN staff st ON st.id = ss.staff_id AND st.deleted_at IS NULL AND st.is_active = 1
     WHERE ss.service_id = ? AND st.id != ?
     LIMIT 1',
    [$serviceId, $staffBusy]
);

if ($altStaffRow === null) {
    echo "SKIP  search_write_parity: No alternate staff for service_id {$serviceId} (cannot compare search vs repo in isolation).\n";
} else {
    $staffAlt = (int) $altStaffRow['id'];
    $searchBlocksRoom = !$availability->isSlotAvailable(
        $serviceId,
        $staffAlt,
        $startAt,
        null,
        $branchId,
        true,
        false,
        $roomId
    );
    if ($searchBlocksRoom !== true) {
        v14Fail(
            'search_write_parity',
            'Internal isSlotAvailable(search, room) should be false when hasRoomConflict is true for same window.'
        );
        exit(1);
    }
    v14Pass('internal_slot_search_uses_same_has_room_conflict_truth');
}

$bookingWithRoomArg = $availability->isSlotAvailable(
    $serviceId,
    $staffBusy,
    $startAt,
    null,
    $branchId,
    false,
    false,
    $roomId
);
$bookingNoRoomArg = $availability->isSlotAvailable(
    $serviceId,
    $staffBusy,
    $startAt,
    null,
    $branchId,
    false,
    false,
    null
);
if ($bookingWithRoomArg !== $bookingNoRoomArg) {
    v14Fail('public_booking_channel_ignores_room_arg', 'Booking-path isSlotAvailable must ignore 8th room arg.');
    exit(1);
}
v14Pass('booking_is_slot_available_ignores_room_search_param');

$bookingPublicA = $availability->isSlotAvailable(
    $serviceId,
    $staffBusy,
    $startAt,
    null,
    $branchId,
    false,
    true,
    $roomId
);
$bookingPublicB = $availability->isSlotAvailable(
    $serviceId,
    $staffBusy,
    $startAt,
    null,
    $branchId,
    false,
    true,
    null
);
if ($bookingPublicA !== $bookingPublicB) {
    v14Fail('public_channel_room_arg_neutral', 'Public channel isSlotAvailable must ignore room occupancy arg.');
    exit(1);
}
v14Pass('public_availability_channel_unchanged_by_room_arg');

$cancelledLone = $db->fetchOne(
    'SELECT a.id, a.room_id, a.start_at, a.end_at, a.branch_id
     FROM appointments a
     WHERE a.deleted_at IS NULL
       AND a.status = \'cancelled\'
       AND a.room_id IS NOT NULL AND a.room_id > 0
       AND a.branch_id = ?
       AND NOT EXISTS (
         SELECT 1 FROM appointments o
         WHERE o.deleted_at IS NULL
           AND o.status NOT IN (\'cancelled\', \'no_show\')
           AND o.room_id = a.room_id
           AND o.id != a.id
           AND o.start_at < a.end_at AND o.end_at > a.start_at
           AND o.branch_id = a.branch_id
       )
     LIMIT 1',
    [$branchId]
);

if ($cancelledLone === null) {
    echo "SKIP  cancelled_ignored: No isolated cancelled appointment with room (cannot prove status exclusion alone).\n";
} else {
    $crId = (int) $cancelledLone['room_id'];
    $cs = (string) $cancelledLone['start_at'];
    $ce = (string) $cancelledLone['end_at'];
    $cb = $cancelledLone['branch_id'] !== null && $cancelledLone['branch_id'] !== ''
        ? (int) $cancelledLone['branch_id']
        : null;
    if ($cb === null || $cb <= 0) {
        echo "SKIP  cancelled_ignored: Cancelled fixture has no resolvable branch_id for predicate.\n";
    } else {
        if ($repo->hasRoomConflict($crId, $cs, $ce, $cb, 0) !== false) {
            v14Fail('cancelled_not_blocking', 'Lone cancelled row in room should not trigger hasRoomConflict.');
            exit(1);
        }
        v14Pass('cancelled_status_excluded_from_room_conflict');
    }
}

echo "\nAll {$passed} check(s) passed.\n";
