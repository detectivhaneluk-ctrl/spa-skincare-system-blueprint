<?php

declare(strict_types=1);

/**
 * Foundation 07: appointments.check_staff_availability_in_search (availability search read path only).
 *
 * From system/:
 *   php scripts/verify_appointment_check_staff_availability_in_search_foundation_07.php --branch-code=SMOKE_A
 *
 * When a DB fixture exists (service_staff + staff with no weekly schedule row for an open branch day, no exception on that date),
 * proves: search slots expand with setting false; isSlotAvailable without search flag still rejects the same candidate.
 * When no fixture: SKIP with reason (still proves org-default stability for a branch-only patch).
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

function v7Pass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function v7Fail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function v7ResolveScopeByBranchCode(Database $db, string $code): array
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

function v7DateForWeekday(int $dow): string
{
    $t = strtotime('today UTC');
    if ($t === false) {
        throw new RuntimeException('today UTC');
    }
    for ($i = 0; $i < 14; $i++) {
        $ts = strtotime('+' . $i . ' days', $t);
        if ($ts === false) {
            continue;
        }
        if ((int) date('w', $ts) === $dow) {
            return date('Y-m-d', $ts);
        }
    }
    throw new RuntimeException('Could not resolve date for weekday ' . $dow);
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
$availability = app(AvailabilityService::class);

try {
    $scope = v7ResolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    v7Fail('scope', $e->getMessage());
    exit(1);
}

$branchId = $scope['branch_id'];
$orgId = $scope['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

$snapshot = $settings->getAppointmentSettings($branchId);
$orgBeforeCheck = $settings->getAppointmentSettings(null)['check_staff_availability_in_search'] ?? null;

try {
    $settings->patchAppointmentSettings(['check_staff_availability_in_search' => true], $branchId);
    if (($settings->getAppointmentSettings($branchId)['check_staff_availability_in_search'] ?? null) !== true) {
        v7Fail('patch_true', 'Could not set check_staff_availability_in_search true on branch');
    } else {
        v7Pass('reload_check_staff_in_search_true');
    }

    $candidates = $db->fetchAll(
        'SELECT DISTINCT s.id AS service_id, st.id AS staff_id, boh.day_of_week AS dow
         FROM branch_operating_hours boh
         INNER JOIN services s ON s.deleted_at IS NULL AND s.is_active = 1 AND (s.branch_id = ? OR s.branch_id IS NULL)
         INNER JOIN service_staff ss ON ss.service_id = s.id
         INNER JOIN staff st ON st.id = ss.staff_id AND st.deleted_at IS NULL AND st.is_active = 1
             AND (st.branch_id = ? OR st.branch_id IS NULL)
         WHERE boh.branch_id = ?
           AND COALESCE(TRIM(boh.start_time), \'\') <> \'\'
           AND COALESCE(TRIM(boh.end_time), \'\') <> \'\'
           AND NOT EXISTS (
               SELECT 1 FROM staff_schedules sch
               WHERE sch.staff_id = st.id AND sch.day_of_week = boh.day_of_week
           )
         LIMIT 80',
        [$branchId, $branchId, $branchId]
    );

    $fixture = null;
    foreach ($candidates as $c) {
        $dow = (int) ($c['dow'] ?? 0);
        $date = v7DateForWeekday($dow);
        $ex = $db->fetchOne(
            'SELECT 1 AS ok FROM staff_availability_exceptions
             WHERE staff_id = ? AND exception_date = ? AND deleted_at IS NULL LIMIT 1',
            [(int) $c['staff_id'], $date]
        );
        if ($ex !== null) {
            continue;
        }
        $slotsTight = $availability->getAvailableSlots((int) $c['service_id'], $date, (int) $c['staff_id'], $branchId);
        if ($slotsTight !== []) {
            continue;
        }
        $fixture = [
            'service_id' => (int) $c['service_id'],
            'staff_id' => (int) $c['staff_id'],
            'date' => $date,
        ];
        break;
    }

    if ($fixture === null) {
        echo "SKIP  search_expand_fixture: No (service, staff, day) found with open branch hours, service_staff link, no staff_schedules row for that weekday, no staff_availability_exceptions on the resolved calendar date, and empty getAvailableSlots while check_staff_availability_in_search=true.\n";
    } else {
        $settings->patchAppointmentSettings(['check_staff_availability_in_search' => false], $branchId);
        $slotsLoose = $availability->getAvailableSlots(
            $fixture['service_id'],
            $fixture['date'],
            $fixture['staff_id'],
            $branchId
        );
        if ($slotsLoose === []) {
            v7Fail('search_expands', 'Expected non-empty slots when check_staff_availability_in_search=false for fixture');
        } else {
            v7Pass('search_bypasses_staff_schedule_when_false');
        }

        $slotHm = $slotsLoose[0];
        $startAt = $fixture['date'] . ' ' . (strlen($slotHm) === 5 ? $slotHm . ':00' : $slotHm);
        $bookingOk = $availability->isSlotAvailable(
            $fixture['service_id'],
            $fixture['staff_id'],
            $startAt,
            null,
            $branchId,
            false
        );
        if ($bookingOk !== false) {
            v7Fail('booking_still_full_check', 'isSlotAvailable (booking path) should reject off-schedule slot; got true for ' . $startAt);
        } else {
            v7Pass('booking_validation_still_enforces_staff_window');
        }

        $settings->patchAppointmentSettings(['check_staff_availability_in_search' => true], $branchId);
        $slotsAgain = $availability->getAvailableSlots(
            $fixture['service_id'],
            $fixture['date'],
            $fixture['staff_id'],
            $branchId
        );
        if ($slotsAgain !== []) {
            v7Fail('restore_search_tight', 'Expected empty slots after restoring check_staff true');
        } else {
            v7Pass('default_true_restores_tight_search');
        }
    }

    $settings->patchAppointmentSettings(['check_staff_availability_in_search' => false], $branchId);
    $orgAfter = $settings->getAppointmentSettings(null)['check_staff_availability_in_search'] ?? null;
    if ($orgAfter !== $orgBeforeCheck) {
        v7Fail('org_stable', 'Organization default check_staff_availability_in_search changed after branch-only patch');
    } else {
        v7Pass('org_default_stable_under_branch_search_toggle_patch');
    }
} finally {
    $settings->setAppointmentSettings($snapshot, $branchId);
    v7Pass('restored_branch_snapshot');
}

echo "\nDone (branch_id={$branchId}, org_id={$orgId}, code={$branchCode}). Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
