<?php

declare(strict_types=1);

/**
 * ROOM-OVERBOOKING-SETTING-01: appointments.allow_room_overbooking (internal room-aware only).
 *
 * From system/:
 *   php scripts/verify_appointment_allow_room_overbooking_setting_01.php --branch-code=SMOKE_A
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\App\SettingsService;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Appointments\Services\AppointmentService;
use Modules\Appointments\Services\AvailabilityService;

$passed = 0;
$failed = 0;

function vRPass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function vRFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function vRResolveScopeByBranchCode(Database $db, string $code): array
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
$settings = app(SettingsService::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$availability = app(AvailabilityService::class);
$repo = app(AppointmentRepository::class);
$appointmentService = app(AppointmentService::class);

try {
    $scope = vRResolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    vRFail('scope', $e->getMessage());
    exit(1);
}

$branchId = $scope['branch_id'];
$orgId = $scope['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

$branchEffectiveBefore = $settings->getAppointmentSettings($branchId);
$orgSnapshotBefore = $settings->getAppointmentSettings(null);

if (!empty($branchEffectiveBefore['allow_room_overbooking'])) {
    echo "SKIP  default_false_fixture: Branch already has allow_room_overbooking true; use a clean branch or set false first.\n";
    exit(0);
}

if ($settings->shouldEnforceAppointmentRoomExclusivity($branchId) !== true) {
    vRFail('enforce_default', 'Expected shouldEnforceAppointmentRoomExclusivity true when setting false.');
    exit(1);
}
vRPass('default_enforces_room_exclusivity');

$settings->patchAppointmentSettings(['allow_room_overbooking' => true], $branchId);
$orgAfterBranchPatch = $settings->getAppointmentSettings(null);
if (($orgAfterBranchPatch['allow_room_overbooking'] ?? false) !== ($orgSnapshotBefore['allow_room_overbooking'] ?? false)) {
    $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
    vRFail('org_stable', 'Organization-effective allow_room_overbooking changed after branch-only patch.');
    exit(1);
}
vRPass('branch_override_does_not_mutate_org_merge_snapshot');

if ($settings->getAppointmentSettings($branchId)['allow_room_overbooking'] !== true) {
    $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
    vRFail('branch_read_true', 'Branch read should show allow_room_overbooking true after patch.');
    exit(1);
}
vRPass('branch_patch_allow_room_overbooking_true');

if ($settings->shouldEnforceAppointmentRoomExclusivity($branchId) !== false) {
    $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
    vRFail('enforce_off_when_allowed', 'shouldEnforce must be false when overbooking allowed.');
    exit(1);
}
vRPass('policy_helper_reflects_branch_setting');

$block = $db->fetchOne(
    'SELECT id, room_id, service_id, staff_id, start_at, end_at
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
    $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
    echo "SKIP  slot_fixture: No blocking appointment with room on branch.\n";
    exit(0);
}

$serviceId = (int) $block['service_id'];
$roomId = (int) $block['room_id'];
$staffBusy = (int) $block['staff_id'];
$startAt = (string) $block['start_at'];

$altStaffRow = $db->fetchOne(
    'SELECT st.id AS id
     FROM service_staff ss
     INNER JOIN staff st ON st.id = ss.staff_id AND st.deleted_at IS NULL AND st.is_active = 1
     WHERE ss.service_id = ? AND st.id != ?
     LIMIT 1',
    [$serviceId, $staffBusy]
);

if ($altStaffRow === null) {
    $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
    echo "SKIP  alt_staff: No alternate staff for service (cannot isolate room in search).\n";
    exit(0);
}
$staffAlt = (int) $altStaffRow['id'];

if ($repo->hasRoomConflict($roomId, $startAt, (string) $block['end_at'], $branchId, 0) !== true) {
    $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
    vRFail('repo_still_conflict', 'Fixture must produce hasRoomConflict true at probe window.');
    exit(1);
}

$searchAllowsWhenOverbookOk = $availability->isSlotAvailable(
    $serviceId,
    $staffAlt,
    $startAt,
    null,
    $branchId,
    true,
    false,
    $roomId
);
if ($searchAllowsWhenOverbookOk !== true) {
    $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
    vRFail('search_allows_room_when_setting_true', 'Internal search with room_id should allow slot when overbooking enabled.');
    exit(1);
}
vRPass('internal_room_search_bypasses_conflict_when_allowed');

$pairForWrite = $db->fetchOne(
    'SELECT c.id AS client_id
     FROM clients c
     WHERE c.deleted_at IS NULL AND (c.branch_id = ? OR c.branch_id IS NULL)
     LIMIT 1',
    [$branchId]
);
$slotCreateId = null;
if ($pairForWrite !== null) {
    try {
        $slotCreateId = $appointmentService->createFromSlot([
            'client_id' => (int) $pairForWrite['client_id'],
            'service_id' => $serviceId,
            'staff_id' => $staffAlt,
            'start_time' => $startAt,
            'branch_id' => $branchId,
            'room_id' => $roomId,
        ]);
    } catch (\DomainException $e) {
        $slotCreateId = null;
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'room')) {
            $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
            vRFail('slot_create_room_when_allowed', 'createFromSlot with room should not fail on room conflict when overbooking allowed: ' . $e->getMessage());
            exit(1);
        }
        echo 'SKIP  slot_create_write: createFromSlot failed (non-room): ' . $e->getMessage() . "\n";
    }
    if ($slotCreateId !== null) {
        $rw = $db->fetchOne('SELECT room_id FROM appointments WHERE id = ?', [$slotCreateId]);
        if ($rw === null || (int) ($rw['room_id'] ?? 0) !== $roomId) {
            $repo->softDelete((int) $slotCreateId);
            $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
            vRFail('slot_create_persists_room', 'Expected room_id persisted on overbooking create.');
            exit(1);
        }
        $repo->softDelete((int) $slotCreateId);
        vRPass('internal_create_from_slot_allows_room_double_book_when_setting_true');
    }
} else {
    echo "SKIP  slot_create_write: No client row for branch.\n";
}

$staffStillBlocks = $availability->isSlotAvailable(
    $serviceId,
    $staffBusy,
    $startAt,
    null,
    $branchId,
    false,
    false,
    $roomId
);
if ($staffStillBlocks !== false) {
    $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
    vRFail('staff_still_enforced', 'Same staff at occupied time must still fail booking path when room overbooking allowed.');
    exit(1);
}
vRPass('staff_conflict_still_enforced_when_room_overbooking_true');

$bookingRoomArg = $availability->isSlotAvailable(
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
if ($bookingRoomArg !== $bookingNoRoomArg) {
    $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
    vRFail('internal_booking_ignores_room_arg', 'Booking isSlotAvailable must ignore 8th room param.');
    exit(1);
}
vRPass('internal_booking_path_unchanged_by_room_search_param');

$pubA = $availability->isSlotAvailable(
    $serviceId,
    $staffAlt,
    $startAt,
    null,
    $branchId,
    false,
    true,
    $roomId
);
$pubB = $availability->isSlotAvailable(
    $serviceId,
    $staffAlt,
    $startAt,
    null,
    $branchId,
    false,
    true,
    null
);
if ($pubA !== $pubB) {
    $settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);
    vRFail('public_neutral', 'Public channel must ignore room occupancy arg.');
    exit(1);
}
vRPass('public_channel_unchanged_by_room_arg');

$settings->patchAppointmentSettings(['allow_room_overbooking' => false], $branchId);

$searchBlocksAgain = !$availability->isSlotAvailable(
    $serviceId,
    $staffAlt,
    $startAt,
    null,
    $branchId,
    true,
    false,
    $roomId
);
if ($searchBlocksAgain !== true) {
    vRFail('restore_false', 'After restoring allow_room_overbooking false, internal search should block room conflict again.');
    exit(1);
}
vRPass('restore_false_preserves_prior_room_search_blocking');

$settings->patchAppointmentSettings(['allow_room_overbooking' => $branchEffectiveBefore['allow_room_overbooking']], $branchId);

echo "\nAll {$passed} check(s) passed.\n";
