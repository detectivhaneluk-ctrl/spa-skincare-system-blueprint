<?php

declare(strict_types=1);

/**
 * APPOINTMENT-STAFF-CONCURRENCY-SETTING-01: appointments.allow_staff_concurrency (internal buffered overlap bypass).
 *
 * From system/:
 *   php scripts/verify_appointment_allow_staff_concurrency_setting_01.php --branch-code=SMOKE_A
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\App\SettingsService;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Appointments\Services\AvailabilityService;

$passed = 0;
$failed = 0;

function vSPass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function vSFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function vSResolveScopeByBranchCode(Database $db, string $code): array
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

try {
    $scope = vSResolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    vSFail('scope', $e->getMessage());
    exit(1);
}

$branchId = $scope['branch_id'];
$orgId = $scope['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

$branchEffectiveBefore = $settings->getAppointmentSettings($branchId);
$orgSnapshotBefore = $settings->getAppointmentSettings(null);

if (!empty($branchEffectiveBefore['allow_staff_concurrency'])) {
    echo "SKIP  default_false_fixture: Branch already has allow_staff_concurrency true; use a clean branch or set false first.\n";
    exit(0);
}

if ($settings->shouldEnforceBufferedStaffAppointmentOverlap($branchId, false) !== true) {
    vSFail('enforce_default_internal', 'Expected internal overlap enforcement when setting false.');
    exit(1);
}
vSPass('default_enforces_buffered_staff_overlap_internal');

if ($settings->shouldEnforceBufferedStaffAppointmentOverlap($branchId, true) !== true) {
    vSFail('enforce_default_public', 'Expected public channel always enforces overlap.');
    exit(1);
}
vSPass('public_channel_always_enforces_overlap');

$block = $db->fetchOne(
    'SELECT id, service_id, staff_id, start_at, end_at
     FROM appointments
     WHERE deleted_at IS NULL
       AND staff_id IS NOT NULL AND staff_id > 0
       AND service_id IS NOT NULL AND service_id > 0
       AND status IN (\'scheduled\', \'confirmed\', \'in_progress\', \'completed\')
       AND branch_id = ?
     ORDER BY id DESC
     LIMIT 1',
    [$branchId]
);

if ($block === null) {
    echo "SKIP  overlap_fixture: No blocking appointment with staff+service on branch.\n";
    exit(0);
}

$serviceId = (int) $block['service_id'];
$staffId = (int) $block['staff_id'];
$startAt = (string) $block['start_at'];

$internalBlocked = !$availability->isSlotAvailable(
    $serviceId,
    $staffId,
    $startAt,
    null,
    $branchId,
    false,
    false,
    null
);
if ($internalBlocked !== true) {
    echo "SKIP  overlap_probe: Internal booking path not blocked at fixture window (not attributable to staff overlap alone).\n";
    exit(0);
}
vSPass('internal_blocked_without_concurrency_bypass');

$settings->patchAppointmentSettings(['allow_staff_concurrency' => true], $branchId);
$orgAfterBranchPatch = $settings->getAppointmentSettings(null);
if (($orgAfterBranchPatch['allow_staff_concurrency'] ?? false) !== ($orgSnapshotBefore['allow_staff_concurrency'] ?? false)) {
    $settings->patchAppointmentSettings(['allow_staff_concurrency' => $branchEffectiveBefore['allow_staff_concurrency']], $branchId);
    vSFail('org_stable', 'Organization-effective allow_staff_concurrency changed after branch-only patch.');
    exit(1);
}
vSPass('branch_override_does_not_mutate_org_merge_snapshot');

if ($settings->getAppointmentSettings($branchId)['allow_staff_concurrency'] !== true) {
    $settings->patchAppointmentSettings(['allow_staff_concurrency' => $branchEffectiveBefore['allow_staff_concurrency']], $branchId);
    vSFail('branch_read_true', 'Branch read should show allow_staff_concurrency true after patch.');
    exit(1);
}
vSPass('branch_patch_allow_staff_concurrency_true');

if ($settings->shouldEnforceBufferedStaffAppointmentOverlap($branchId, false) !== false) {
    $settings->patchAppointmentSettings(['allow_staff_concurrency' => $branchEffectiveBefore['allow_staff_concurrency']], $branchId);
    vSFail('enforce_off_internal', 'Internal should skip overlap check when concurrency allowed.');
    exit(1);
}
vSPass('policy_helper_reflects_branch_setting_internal');

$internalAllows = $availability->isSlotAvailable(
    $serviceId,
    $staffId,
    $startAt,
    null,
    $branchId,
    false,
    false,
    null
);
if ($internalAllows !== true) {
    $settings->patchAppointmentSettings(['allow_staff_concurrency' => $branchEffectiveBefore['allow_staff_concurrency']], $branchId);
    vSFail('internal_allows_when_setting_true', 'Internal booking path should allow same staff/window when concurrency enabled: still blocked.');
    exit(1);
}
vSPass('internal_booking_allows_buffered_overlap_when_setting_true');

$publicStillBlocks = !$availability->isSlotAvailable(
    $serviceId,
    $staffId,
    $startAt,
    null,
    $branchId,
    false,
    true,
    null
);
if ($publicStillBlocks !== true) {
    $settings->patchAppointmentSettings(['allow_staff_concurrency' => $branchEffectiveBefore['allow_staff_concurrency']], $branchId);
    vSFail('public_still_enforced', 'Public channel must still block overlapping staff when branch allows internal concurrency.');
    exit(1);
}
vSPass('public_channel_still_blocks_overlap_when_internal_concurrency_true');

$settings->patchAppointmentSettings(['allow_staff_concurrency' => false], $branchId);

$internalBlocksAgain = !$availability->isSlotAvailable(
    $serviceId,
    $staffId,
    $startAt,
    null,
    $branchId,
    false,
    false,
    null
);
if ($internalBlocksAgain !== true) {
    vSFail('restore_false', 'After restoring allow_staff_concurrency false, internal path should block again.');
    exit(1);
}
vSPass('restore_false_preserves_prior_internal_blocking');

$settings->patchAppointmentSettings(['allow_staff_concurrency' => $branchEffectiveBefore['allow_staff_concurrency']], $branchId);

echo "\nAll {$passed} check(s) passed.\n";
