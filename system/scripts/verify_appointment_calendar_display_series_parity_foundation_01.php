<?php

declare(strict_types=1);

/**
 * APPOINTMENT-CALENDAR-DISPLAY-PARITY-FOUNDATION-01: series-linked vs standalone calendar display settings.
 *
 * Proves: branch-scoped patch/read for `calendar_series_*` divergent from `calendar_service_*`;
 * organization default merge unchanged under branch-only patch; optional `series_id` on day list when fixture exists.
 *
 * From system/:
 *   php scripts/verify_appointment_calendar_display_series_parity_foundation_01.php --branch-code=SMOKE_A
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

function vCalPass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function vCalFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function vCalResolveScopeByBranchCode(Database $db, string $code): array
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
    $scope = vCalResolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    vCalFail('scope', $e->getMessage());
    exit(1);
}

$branchId = $scope['branch_id'];
$orgId = $scope['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

$readBranch = $branchId;
$snapshot = $settings->getAppointmentSettings($readBranch);
$orgBaseline = $settings->getAppointmentSettings(null);
$writeBranchId = $branchId;

try {
    $settings->patchAppointmentSettings([
        'calendar_service_show_start_time' => true,
        'calendar_service_label_mode' => 'client_only',
        'calendar_series_show_start_time' => false,
        'calendar_series_label_mode' => 'service_only',
    ], $writeBranchId);

    $read = $settings->getAppointmentSettings($readBranch);
    if ($read['calendar_service_show_start_time'] !== true || $read['calendar_service_label_mode'] !== 'client_only') {
        vCalFail('reload_service_pair', json_encode($read));
    } else {
        vCalPass('reload_calendar_service_display_divergent_from_series');
    }
    if ($read['calendar_series_show_start_time'] !== false || $read['calendar_series_label_mode'] !== 'service_only') {
        vCalFail('reload_series_pair', json_encode($read));
    } else {
        vCalPass('reload_calendar_series_display_independent');
    }

    $orgAfter = $settings->getAppointmentSettings(null)['calendar_service_label_mode'] ?? '';
    $orgBefore = $orgBaseline['calendar_service_label_mode'] ?? '';
    if ($orgAfter !== $orgBefore) {
        vCalFail('org_default_stable', 'getAppointmentSettings(null) calendar_service_label_mode changed after branch-only patch');
    } else {
        vCalPass('org_default_stable_under_branch_only_patch');
    }

    $seriesRow = $db->fetchOne(
        'SELECT id, DATE(start_at) AS day_date, series_id
         FROM appointments
         WHERE branch_id = ? AND deleted_at IS NULL AND series_id IS NOT NULL AND series_id > 0
         ORDER BY start_at DESC
         LIMIT 1',
        [$branchId]
    );
    if ($seriesRow === null) {
        echo "SKIP  listDayAppointmentsGroupedByStaff series_id shape (no non-deleted appointment with series_id on branch)\n";
    } else {
        $day = (string) ($seriesRow['day_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            vCalFail('series_fixture_date', $day);
        } else {
            $grouped = $availability->listDayAppointmentsGroupedByStaff($day, $branchId);
            $found = false;
            foreach ($grouped as $list) {
                foreach ($list as $a) {
                    if ((int) ($a['id'] ?? 0) === (int) $seriesRow['id']) {
                        $sid = $a['series_id'] ?? null;
                        if ($sid !== null && (int) $sid === (int) $seriesRow['series_id']) {
                            $found = true;
                        }
                        break 2;
                    }
                }
            }
            if (!$found) {
                vCalFail('day_list_series_id', 'Expected series_id on grouped row for appointment ' . (int) $seriesRow['id']);
            } else {
                vCalPass('day_list_includes_series_id_for_series_fixture');
            }
        }
    }
} finally {
    $settings->setAppointmentSettings($snapshot, $writeBranchId);
    vCalPass('restored_branch_snapshot');
}

echo "\nDone (branch_id={$branchId}, org_id={$orgId}, code={$branchCode}). Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
