<?php

declare(strict_types=1);

/**
 * Foundation 06: appointments.allow_end_after_closing — branch-effective operating-hours guard.
 *
 * From system/:
 *   php scripts/verify_appointment_allow_end_after_closing_foundation_06.php --branch-code=SMOKE_A
 *
 * Requires branch_operating_hours rows for the branch (open day + optional fixtures for skipped checks).
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\App\SettingsService;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Appointments\Services\AppointmentService;
use Modules\Settings\Repositories\BranchOperatingHoursRepository;

$passed = 0;
$failed = 0;

function v6Pass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function v6Fail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

/**
 * @return array{branch_id: int, organization_id: int}
 */
function v6ResolveScopeByBranchCode(Database $db, string $code): array
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

function v6DateForWeekday(int $dow): string
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
    throw new RuntimeException('Could not resolve a calendar date for weekday ' . $dow);
}

/**
 * @return array{day_of_week:int,open:string,close:string}|null
 */
function v6FindOpenDay(Database $db, int $branchId): ?array
{
    $rows = $db->fetchAll(
        'SELECT day_of_week, start_time, end_time
         FROM branch_operating_hours
         WHERE branch_id = ?
         ORDER BY day_of_week ASC',
        [$branchId]
    );
    foreach ($rows as $r) {
        $s = substr(trim((string) ($r['start_time'] ?? '')), 0, 5);
        $e = substr(trim((string) ($r['end_time'] ?? '')), 0, 5);
        if ($s !== '' && $e !== '' && preg_match('/^\d{2}:\d{2}$/', $s) === 1 && preg_match('/^\d{2}:\d{2}$/', $e) === 1 && strcmp($e, $s) > 0) {
            return [
                'day_of_week' => (int) ($r['day_of_week'] ?? 0),
                'open' => $s,
                'close' => $e,
            ];
        }
    }

    return null;
}

/**
 * @return array{day_of_week:int}|null
 */
function v6FindClosedConfiguredDay(Database $db, int $branchId): ?array
{
    $rows = $db->fetchAll(
        'SELECT day_of_week, start_time, end_time
         FROM branch_operating_hours
         WHERE branch_id = ?
         ORDER BY day_of_week ASC',
        [$branchId]
    );
    foreach ($rows as $r) {
        $rawS = $r['start_time'] ?? null;
        $rawE = $r['end_time'] ?? null;
        $s = $rawS === null ? '' : trim((string) $rawS);
        $e = $rawE === null ? '' : trim((string) $rawE);
        if ($s === '' && $e === '') {
            return ['day_of_week' => (int) ($r['day_of_week'] ?? 0)];
        }
    }

    return null;
}

/**
 * @return int|null weekday 0–6 with no row
 */
function v6FindUnconfiguredWeekday(Database $db, int $branchId): ?int
{
    $have = [];
    $rows = $db->fetchAll(
        'SELECT day_of_week FROM branch_operating_hours WHERE branch_id = ?',
        [$branchId]
    );
    foreach ($rows as $r) {
        $have[(int) ($r['day_of_week'] ?? -1)] = true;
    }
    for ($d = 0; $d <= 6; $d++) {
        if (!isset($have[$d])) {
            return $d;
        }
    }

    return null;
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
$repo = app(BranchOperatingHoursRepository::class);
$appointmentService = app(AppointmentService::class);

if (!$repo->isTableAvailable()) {
    v6Fail('storage', 'branch_operating_hours table missing; apply migration 092.');
    exit(1);
}

try {
    $scope = v6ResolveScopeByBranchCode($db, $branchCode);
} catch (Throwable $e) {
    v6Fail('scope', $e->getMessage());
    exit(1);
}

$branchId = $scope['branch_id'];
$orgId = $scope['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);

$openDay = v6FindOpenDay($db, $branchId);
if ($openDay === null) {
    v6Fail('fixture_open_day', 'No branch_operating_hours row with non-empty start/end and closing after opening for branch_id=' . $branchId);
    exit(1);
}

$dateOpen = v6DateForWeekday($openDay['day_of_week']);
$open = $openDay['open'];
$close = $openDay['close'];
$startInside = $dateOpen . ' ' . $open . ':00';
$closeTs = strtotime($dateOpen . ' ' . $close . ':00');
if ($closeTs === false) {
    v6Fail('fixture_close_ts', 'Invalid close time ' . $close);
    exit(1);
}
$endAfterClose = null;
for ($add = 300; $add <= 86400; $add += 300) {
    $endTs = $closeTs + $add;
    $endStr = date('Y-m-d H:i:s', $endTs);
    if (substr($endStr, 0, 10) !== $dateOpen) {
        continue;
    }
    $endHm = substr($endStr, 11, 5);
    if (strcmp($endHm, $close) > 0) {
        $endAfterClose = $endStr;
        break;
    }
}
if ($endAfterClose === null) {
    v6Fail('fixture_end_after_close', 'Could not derive same-day end time strictly after close=' . $close);
    exit(1);
}
$openTs = strtotime($dateOpen . ' ' . $open . ':00');
if ($openTs === false) {
    v6Fail('fixture_open_ts', 'Invalid open time ' . $open);
    exit(1);
}
$startBeforeOpen = date('Y-m-d H:i:s', $openTs - 3600);
$endAfterStartBefore = date('Y-m-d H:i:s', $openTs - 1800);
if (strcmp(substr($startBeforeOpen, 11, 5), $open) >= 0) {
    v6Fail('fixture_start_before', 'Could not place start before open=' . $open);
    exit(1);
}

$invokeAssert = static function (AppointmentService $svc, string $startAt, string $endAt, int $bid): void {
    $m = new \ReflectionMethod(AppointmentService::class, 'assertWithinBranchOperatingHours');
    $m->setAccessible(true);
    $m->invoke($svc, $startAt, $endAt, $bid);
};

$snapshot = $settings->getAppointmentSettings($branchId);
$orgBeforeAllow = $settings->getAppointmentSettings(null)['allow_end_after_closing'] ?? null;

try {
    $settings->patchAppointmentSettings(['allow_end_after_closing' => false], $branchId);
    $readFalse = $settings->getAppointmentSettings($branchId);
    if ($readFalse['allow_end_after_closing'] !== false) {
        v6Fail('patch_false', 'Expected allow_end_after_closing false');
    } else {
        v6Pass('reload_allow_end_after_closing_false');
    }

    $threw = false;
    try {
        $invokeAssert($appointmentService, $startInside, $endAfterClose, $branchId);
    } catch (DomainException) {
        $threw = true;
    }
    if (!$threw) {
        v6Fail('block_when_false', 'Expected DomainException when end after close and setting false');
    } else {
        v6Pass('end_after_close_blocked_when_false');
    }

    $settings->patchAppointmentSettings(['allow_end_after_closing' => true], $branchId);
    if ($settings->getAppointmentSettings($branchId)['allow_end_after_closing'] !== true) {
        v6Fail('patch_true', 'Expected allow_end_after_closing true');
    } else {
        v6Pass('reload_allow_end_after_closing_true');
    }

    $threw2 = false;
    try {
        $invokeAssert($appointmentService, $startInside, $endAfterClose, $branchId);
    } catch (DomainException $e) {
        $threw2 = true;
    }
    if ($threw2) {
        v6Fail('allow_when_true', 'Expected no exception when end after close and setting true');
    } else {
        v6Pass('end_after_close_allowed_when_true');
    }

    $threw3 = false;
    try {
        $invokeAssert($appointmentService, $startBeforeOpen, $endAfterStartBefore, $branchId);
    } catch (DomainException) {
        $threw3 = true;
    }
    if (!$threw3) {
        v6Fail('start_before_open', 'Expected DomainException for start before opening even when allow_end_after_closing true');
    } else {
        v6Pass('start_before_open_still_blocked_when_true');
    }

    $orgAfterBranchTrue = $settings->getAppointmentSettings(null)['allow_end_after_closing'] ?? null;
    if ($orgAfterBranchTrue !== $orgBeforeAllow) {
        v6Fail('org_stable', 'Organization default allow_end_after_closing changed after branch-only patch');
    } else {
        v6Pass('org_default_stable_under_branch_allow_end_patch');
    }

    $closed = v6FindClosedConfiguredDay($db, $branchId);
    if ($closed !== null) {
        $dateClosed = v6DateForWeekday($closed['day_of_week']);
        $mid = $dateClosed . ' 12:00:00';
        $midEnd = $dateClosed . ' 13:00:00';
        $threw4 = false;
        try {
            $invokeAssert($appointmentService, $mid, $midEnd, $branchId);
        } catch (DomainException $e) {
            $threw4 = stripos($e->getMessage(), 'closed') !== false;
        }
        if (!$threw4) {
            v6Fail('closed_day', 'Expected closed-day rejection for ' . $dateClosed);
        } else {
            v6Pass('closed_day_still_blocked');
        }
    } else {
        v6Pass('closed_day_skipped_no_explicit_closed_row');
    }

    $unconfigured = v6FindUnconfiguredWeekday($db, $branchId);
    if ($unconfigured !== null) {
        $dateU = v6DateForWeekday($unconfigured);
        $uStart = $dateU . ' 10:00:00';
        $uEnd = $dateU . ' 11:00:00';
        $threw5 = false;
        try {
            $invokeAssert($appointmentService, $uStart, $uEnd, $branchId);
        } catch (DomainException $e) {
            $threw5 = str_contains($e->getMessage(), 'not configured');
        }
        if (!$threw5) {
            v6Fail('unconfigured_day', 'Expected not-configured rejection for ' . $dateU);
        } else {
            v6Pass('unconfigured_day_still_blocked');
        }
    } else {
        v6Pass('unconfigured_day_skipped_full_week_fixture');
    }
} finally {
    $settings->setAppointmentSettings($snapshot, $branchId);
    v6Pass('restored_branch_snapshot');
}

echo "\nDone (branch_id={$branchId}, org_id={$orgId}, code={$branchCode}). Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
