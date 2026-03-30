<?php

declare(strict_types=1);

/**
 * Foundation 09: appointments.client_itinerary_show_staff / client_itinerary_show_space
 * (branch-effective reads; masks {@see \Core\Contracts\ClientAppointmentProfileProvider::listRecent} output).
 *
 * From system/:
 *   php scripts/verify_client_itinerary_display_toggles_foundation_09.php --branch-code=SMOKE_A
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\App\SettingsService;
use Core\Branch\BranchContext;
use Core\Contracts\ClientAppointmentProfileProvider;
use Core\Organization\OrganizationContext;

$passed = 0;
$failed = 0;

function v9Pass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function v9Fail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function v9ResolveScopeByBranchCode(Database $db, string $code): array
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

$settings = app(SettingsService::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$db = app(Database::class);
$appointmentsProfile = app(ClientAppointmentProfileProvider::class);

try {
    $scope = v9ResolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    v9Fail('scope', $e->getMessage());
    exit(1);
}

$branchId = $scope['branch_id'];
$orgId = $scope['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

$origEffective = $settings->getAppointmentSettings($branchId);
$origStaffEff = (bool) $origEffective['client_itinerary_show_staff'];
$origSpaceEff = (bool) $origEffective['client_itinerary_show_space'];

$staffFixture = $db->fetchOne(
    'SELECT a.client_id AS client_id
     FROM appointments a
     WHERE a.branch_id = ? AND a.deleted_at IS NULL AND a.staff_id IS NOT NULL
     LIMIT 1',
    [$branchId]
);
$roomFixture = $db->fetchOne(
    'SELECT a.client_id AS client_id
     FROM appointments a
     WHERE a.branch_id = ? AND a.deleted_at IS NULL AND a.room_id IS NOT NULL
     LIMIT 1',
    [$branchId]
);

if ($staffFixture === null) {
    v9Fail('fixture_staff', 'No appointment with staff_id on this branch; cannot prove staff toggle.');
    exit(1);
}

$clientIdStaff = (int) $staffFixture['client_id'];

try {
    $orgBefore = $settings->getAppointmentSettings(null);

    $settings->patchAppointmentSettings([
        'client_itinerary_show_staff' => true,
        'client_itinerary_show_space' => false,
    ], $branchId);
    $readBack = $settings->getAppointmentSettings($branchId);
    if (!$readBack['client_itinerary_show_staff']) {
        v9Fail('persist_staff_true', 'Expected branch-effective client_itinerary_show_staff true after patch.');
        throw new RuntimeException('abort');
    }
    v9Pass('settings_persist_reload_branch_scope');

    $orgMid = $settings->getAppointmentSettings(null);
    if ($orgMid['client_itinerary_show_staff'] !== $orgBefore['client_itinerary_show_staff']) {
        v9Fail('org_not_mutated', 'Organization-default client_itinerary_show_staff changed after branch-only patch.');
        throw new RuntimeException('abort');
    }
    v9Pass('branch_override_does_not_mutate_org_default');

    $rowsOn = $appointmentsProfile->listRecent($clientIdStaff, 10);
    $anyStaff = false;
    foreach ($rowsOn as $row) {
        if (($row['staff_name'] ?? null) !== null && trim((string) $row['staff_name']) !== '') {
            $anyStaff = true;
            break;
        }
    }
    if (!$anyStaff) {
        v9Fail('staff_visible_when_on', 'Expected at least one non-empty staff_name when toggle true.');
        throw new RuntimeException('abort');
    }

    $settings->patchAppointmentSettings(['client_itinerary_show_staff' => false], $branchId);
    $rowsOff = $appointmentsProfile->listRecent($clientIdStaff, 10);
    foreach ($rowsOff as $row) {
        if (($row['staff_name'] ?? null) !== null) {
            v9Fail('staff_suppressed', 'Expected staff_name null when client_itinerary_show_staff false.');
            throw new RuntimeException('abort');
        }
    }
    v9Pass('staff_toggle_masks_list_recent');

    if ($roomFixture !== null) {
        $clientIdRoom = (int) $roomFixture['client_id'];
        $settings->patchAppointmentSettings([
            'client_itinerary_show_staff' => true,
            'client_itinerary_show_space' => true,
        ], $branchId);
        $rowsSpaceOn = $appointmentsProfile->listRecent($clientIdRoom, 10);
        $anyRoom = false;
        foreach ($rowsSpaceOn as $row) {
            if (($row['room_name'] ?? null) !== null && trim((string) $row['room_name']) !== '') {
                $anyRoom = true;
                break;
            }
        }
        if (!$anyRoom) {
            v9Fail('space_visible_when_on', 'Expected non-empty room_name for an appointment with room_id when toggle true.');
            throw new RuntimeException('abort');
        }
        $settings->patchAppointmentSettings(['client_itinerary_show_space' => false], $branchId);
        $rowsSpaceOff = $appointmentsProfile->listRecent($clientIdRoom, 10);
        foreach ($rowsSpaceOff as $row) {
            if (($row['room_name'] ?? null) !== null) {
                v9Fail('space_suppressed', 'Expected room_name null when client_itinerary_show_space false.');
                throw new RuntimeException('abort');
            }
        }
        v9Pass('space_toggle_masks_list_recent');
    } else {
        echo "SKIP  space_toggle_masks_list_recent: no appointment with room_id on this branch (cannot prove space column without fixture).\n";
    }
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'abort') {
        throw $e;
    }
} finally {
    $settings->patchAppointmentSettings([
        'client_itinerary_show_staff' => $origStaffEff,
        'client_itinerary_show_space' => $origSpaceEff,
    ], $branchId);
}

if ($failed > 0) {
    fwrite(STDERR, "\nFailed {$failed} check(s).\n");
    exit(1);
}

echo "\nAll {$passed} check(s) passed.\n";
