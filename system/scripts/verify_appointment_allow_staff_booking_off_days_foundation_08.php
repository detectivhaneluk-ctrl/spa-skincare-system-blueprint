<?php

declare(strict_types=1);

/**
 * Foundation 08: appointments.allow_staff_booking_on_off_days (internal booking + internal slot search only; public strict).
 *
 * From system/:
 *   php scripts/verify_appointment_allow_staff_booking_off_days_foundation_08.php --branch-code=SMOKE_A
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

function v8Pass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function v8Fail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function v8ResolveScopeByBranchCode(Database $db, string $code): array
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

function v8DateForWeekday(int $dow): string
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
    $scope = v8ResolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    v8Fail('scope', $e->getMessage());
    exit(1);
}

$branchId = $scope['branch_id'];
$orgId = $scope['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

$snapshot = $settings->getAppointmentSettings($branchId);
$orgBeforeOff = $settings->getAppointmentSettings(null)['allow_staff_booking_on_off_days'] ?? null;

try {
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
        $date = v8DateForWeekday($dow);
        $ex = $db->fetchOne(
            'SELECT 1 AS ok FROM staff_availability_exceptions
             WHERE staff_id = ? AND exception_date = ? AND deleted_at IS NULL LIMIT 1',
            [(int) $c['staff_id'], $date]
        );
        if ($ex !== null) {
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
        echo "SKIP  off_day_fixture: No qualifying service/staff/day (same criteria as foundation 07 search fixture).\n";
    } else {
        $settings->patchAppointmentSettings([
            'allow_staff_booking_on_off_days' => false,
            'check_staff_availability_in_search' => true,
        ], $branchId);

        $internalSlotsOff = $availability->getAvailableSlots(
            $fixture['service_id'],
            $fixture['date'],
            $fixture['staff_id'],
            $branchId,
            'internal'
        );
        if ($internalSlotsOff !== []) {
            v8Fail('internal_search_tight_when_false', 'Expected empty internal slots when off-day bypass false');
        } else {
            v8Pass('internal_slot_search_empty_off_day_when_setting_false');
        }

        $publicSlotsOff = $availability->getAvailableSlots(
            $fixture['service_id'],
            $fixture['date'],
            $fixture['staff_id'],
            $branchId,
            'public'
        );
        if ($publicSlotsOff !== []) {
            v8Fail('public_search_tight', 'Expected empty public slots on staff off-day');
        } else {
            v8Pass('public_slot_search_empty_on_staff_off_day');
        }

        $settings->patchAppointmentSettings(['allow_staff_booking_on_off_days' => true], $branchId);

        $internalSlotsOn = $availability->getAvailableSlots(
            $fixture['service_id'],
            $fixture['date'],
            $fixture['staff_id'],
            $branchId,
            'internal'
        );
        if ($internalSlotsOn === []) {
            v8Fail('internal_search_expands', 'Expected non-empty internal slots when bypass true');
        } else {
            v8Pass('internal_slot_search_non_empty_when_bypass_true');
        }

        $publicSlotsOn = $availability->getAvailableSlots(
            $fixture['service_id'],
            $fixture['date'],
            $fixture['staff_id'],
            $branchId,
            'public'
        );
        if ($publicSlotsOn !== []) {
            v8Fail('public_unchanged_when_bypass_true', 'Public search must stay empty on off-day even when setting true');
        } else {
            v8Pass('public_slot_search_still_empty_when_bypass_true');
        }

        $slotHm = $internalSlotsOn[0];
        $startAt = $fixture['date'] . ' ' . (strlen($slotHm) === 5 ? $slotHm . ':00' : $slotHm);

        if ($availability->isSlotAvailable($fixture['service_id'], $fixture['staff_id'], $startAt, null, $branchId, false, true)) {
            v8Fail('public_booking_channel_blocks', 'isSlotAvailable with public channel must reject off-day slot');
        } else {
            v8Pass('is_slot_available_public_channel_still_strict');
        }

        if (!$availability->isSlotAvailable($fixture['service_id'], $fixture['staff_id'], $startAt, null, $branchId, false, false)) {
            v8Fail('internal_booking_channel_allows', 'isSlotAvailable internal channel should allow when bypass true');
        } else {
            v8Pass('is_slot_available_internal_channel_allows_when_bypass_true');
        }
    }

    $settings->patchAppointmentSettings(['allow_staff_booking_on_off_days' => true], $branchId);
    $orgAfter = $settings->getAppointmentSettings(null)['allow_staff_booking_on_off_days'] ?? null;
    if ($orgAfter !== $orgBeforeOff) {
        v8Fail('org_stable', 'Organization default allow_staff_booking_on_off_days changed after branch-only patch');
    } else {
        v8Pass('org_default_stable_under_branch_off_day_patch');
    }
} finally {
    $settings->setAppointmentSettings($snapshot, $branchId);
    v8Pass('restored_branch_snapshot');
}

echo "\nDone (branch_id={$branchId}, org_id={$orgId}, code={$branchCode}). Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
