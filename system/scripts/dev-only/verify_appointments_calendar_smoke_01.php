<?php

declare(strict_types=1);

/**
 * APPOINTMENTS-CALENDAR-SMOKE-VERIFY-01
 *
 * Automated checks after {@see seed_appointments_calendar_realistic_01.php}.
 *
 * Phase 1 truth audit (code paths — see plan APPOINTMENTS-REALISTIC-DATA-SMOKE-TEST-AND-CALENDAR-TRUTH-01):
 * - Calendar columns: GET /calendar/day staff[] = AvailabilityService::listActiveStaff (getEligibleStaff + staff group scope).
 * - Appointments: listDayAppointmentsGroupedByStaff groups by staff_id; staff_id<=0 dropped (orphan risk).
 * - service_staff: any mapping row => only mapped staff; no mapping => all scoped staff.
 * - staff_groups: if branch has active groups, only members pass isStaffInScopeForBranch.
 * - branch_operating_hours + staff_schedules required for realistic slots; blocked via appointment_blocked_slots.
 * - Day calendar UI: staff columns only (no room lane); room_id still affects conflicts when enforced.
 * - Create page lists all branch services/staff; slots API enforces mapping. Edit uses listStaffSelectableForService.
 * - Full-page create has no inline new-client control (client <select> only).
 * - Day list uses short-TTL SharedCache; writes call invalidateDayCalendarCache.
 *
 * MANUAL UI CHECKLIST (operational truth):
 * 1) Log in as tenant-admin-a@example.test (seed_branch_smoke_data.php) / password from that script output.
 * 2) Open /appointments/calendar/day?branch_id=<id>&date=<day1> — branch_id and day1 from seed JSON stdout.
 * 3) Confirm one column per active scoped staff; CAL_SEED appointments render with plausible heights; blocked strips visible.
 * 4) From calendar, open new booking drawer, book one slot; reload calendar — new row appears.
 * 5) Open an existing [CAL_SEED_V1] appointment detail → edit; confirm staff/service/times match DB; reschedule/cancel and verify grid.
 * 6) GET /calendar/day?date=...&branch_id=... with Accept: application/json (authenticated session) — JSON staff count vs UI.
 *
 * Usage (from system/):
 *   php scripts/dev-only/verify_appointments_calendar_smoke_01.php
 *   php scripts/dev-only/verify_appointments_calendar_smoke_01.php --branch-code=SMOKE_A --day-offset=28
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\App\SettingsService;
use Core\Branch\BranchContext;
use Core\Kernel\AssuranceLevel;
use Core\Kernel\ExecutionSurface;
use Core\Kernel\RequestContextHolder;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationContext;
use Modules\Appointments\Services\AppointmentService;
use Modules\Appointments\Services\AvailabilityService;

$branchCode = 'SMOKE_A';
$dayOffset = 28;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--branch-code=')) {
        $branchCode = trim(substr($arg, strlen('--branch-code=')));
    }
    if (str_starts_with($arg, '--day-offset=')) {
        $dayOffset = max(7, (int) substr($arg, strlen('--day-offset=')));
    }
}

$db = app(Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$contextHolder = app(RequestContextHolder::class);
$availability = app(AvailabilityService::class);
$appointmentService = app(AppointmentService::class);
$settings = app(SettingsService::class);

$adminRow = $db->fetchOne('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1', ['tenant-admin-a@example.test']);
if ($adminRow === null) {
    fwrite(STDERR, "FAIL: missing tenant-admin-a@example.test\n");
    exit(1);
}
$adminUserId = (int) $adminRow['id'];

$scopeRow = $db->fetchOne(
    'SELECT b.id AS branch_id, b.organization_id AS organization_id
     FROM branches b
     WHERE b.code = ? AND b.deleted_at IS NULL LIMIT 1',
    [$branchCode]
);
if ($scopeRow === null) {
    fwrite(STDERR, "FAIL: unknown branch {$branchCode}\n");
    exit(1);
}
$branchId = (int) $scopeRow['branch_id'];
$orgId = (int) $scopeRow['organization_id'];

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
$contextHolder->set(TenantContext::resolvedTenant(
    actorId: $adminUserId,
    organizationId: $orgId,
    branchId: $branchId,
    isSupportEntry: false,
    supportActorId: null,
    assuranceLevel: AssuranceLevel::SESSION,
    executionSurface: ExecutionSurface::CLI,
    organizationResolutionMode: OrganizationContext::MODE_BRANCH_DERIVED,
));

$d1 = (new DateTimeImmutable('today'))->modify("+{$dayOffset} days")->format('Y-m-d');
$d2 = (new DateTimeImmutable('today'))->modify('+' . ($dayOffset + 1) . ' days')->format('Y-m-d');

$failed = 0;
$pass = static function (string $m) use (&$failed): void {
    echo "PASS  {$m}\n";
};
$fail = static function (string $m, string $d = '') use (&$failed): void {
    $failed++;
    fwrite(STDERR, 'FAIL  ' . $m . ($d !== '' ? (': ' . $d) : '') . "\n");
};

$seedStaff = $db->fetchOne(
    'SELECT COUNT(*) AS c FROM staff WHERE branch_id = ? AND deleted_at IS NULL AND email LIKE ?',
    [$branchId, 'cal-seed-staff-%@example.invalid']
);
$c = (int) ($seedStaff['c'] ?? 0);
if ($c >= 6) {
    $pass('seed staff rows (cal-seed-staff-*) >= 6');
} else {
    $fail('seed staff count', "expected >= 6, got {$c} — run seed_appointments_calendar_realistic_01.php");
}

$svcCount = (int) ($db->fetchOne(
    'SELECT COUNT(*) AS c FROM services WHERE branch_id = ? AND deleted_at IS NULL AND name LIKE ?',
    [$branchId, 'CALSVC %']
)['c'] ?? 0);
if ($svcCount >= 8) {
    $pass('seed services (CALSVC %) >= 8');
} else {
    $fail('seed services', "got {$svcCount}");
}

$apptD1 = (int) ($db->fetchOne(
    'SELECT COUNT(*) AS c FROM appointments WHERE branch_id = ? AND deleted_at IS NULL AND DATE(start_at) = ? AND notes LIKE ?',
    [$branchId, $d1, '[CAL_SEED_V1]%']
)['c'] ?? 0);
if ($apptD1 >= 10) {
    $pass("appointments day1 {$d1} >= 10");
} else {
    $fail('appointments day1', "date={$d1} count={$apptD1}");
}

$apptTotal = (int) ($db->fetchOne(
    'SELECT COUNT(*) AS c FROM appointments WHERE branch_id = ? AND deleted_at IS NULL AND notes LIKE ? AND DATE(start_at) IN (?,?)',
    [$branchId, '[CAL_SEED_V1]%', $d1, $d2]
)['c'] ?? 0);
if ($apptTotal >= 18) {
    $pass('appointments across day1+day2 >= 18');
} else {
    $fail('appointments two-day total', "count={$apptTotal}");
}

$blk = (int) ($db->fetchOne(
    "SELECT COUNT(*) AS c FROM appointment_blocked_slots WHERE branch_id = ? AND deleted_at IS NULL AND block_date = ? AND title LIKE 'CAL_SEED%'",
    [$branchId, $d1]
)['c'] ?? 0);
if ($blk >= 3) {
    $pass('blocked slots on day1 >= 3');
} else {
    $fail('blocked slots', "count={$blk}");
}

$staffCols = $availability->listActiveStaff($branchId);
$grouped = $availability->listDayAppointmentsGroupedByStaff($d1, $branchId);
$blocked = $availability->listDayBlockedSlotsGroupedByStaff($d1, $branchId);

$staffIdsWithAppts = array_keys($grouped);
foreach ($staffIdsWithAppts as $sid) {
    $found = false;
    foreach ($staffCols as $col) {
        if ((int) ($col['id'] ?? 0) === (int) $sid) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $fail('calendar staff column for appointment', "staff_id={$sid} has appointments but not in listActiveStaff");
    }
}
if ($failed === 0) {
    $pass('every staff_id with day1 appointments appears in listActiveStaff');
}

$durRows = $db->fetchAll(
    'SELECT a.id, a.start_at, a.end_at, a.service_id, s.duration_minutes
     FROM appointments a
     INNER JOIN services s ON s.id = a.service_id
     WHERE a.branch_id = ? AND a.deleted_at IS NULL AND a.notes LIKE ? AND DATE(a.start_at) IN (?,?)',
    [$branchId, '[CAL_SEED_V1]%', $d1, $d2]
);
foreach ($durRows as $dr) {
    $svcId = (int) ($dr['service_id'] ?? 0);
    $dur = $availability->getServiceDurationMinutes($svcId, $branchId);
    $startTs = strtotime((string) $dr['start_at']);
    $endTs = strtotime((string) $dr['end_at']);
    if ($startTs === false || $endTs === false) {
        $fail('appointment timestamps', 'id=' . (int) ($dr['id'] ?? 0));
        continue;
    }
    $actualMin = (int) round(($endTs - $startTs) / 60);
    if ($actualMin !== $dur) {
        $fail('duration vs service', "appt {$dr['id']} expected {$dur}m got {$actualMin}m");
    }
}
if ($failed === 0) {
    $pass('CAL_SEED appointment durations match getServiceDurationMinutes');
}

$row0 = $db->fetchOne(
    'SELECT client_id, service_id, staff_id, start_at, end_at FROM appointments
     WHERE branch_id = ? AND deleted_at IS NULL AND notes LIKE ? AND DATE(start_at) = ?
     ORDER BY id ASC LIMIT 1',
    [$branchId, '[CAL_SEED_V1]%', $d1]
);
if ($row0 === null) {
    $fail('double-book probe', 'no seed appointment on day1');
} elseif (!$settings->shouldEnforceBufferedStaffAppointmentOverlap($branchId, false)) {
    echo "SKIP  double-book probe (internal allow_staff_concurrency / overlap not enforced)\n";
} else {
    $slotFree = $availability->isSlotAvailable(
        (int) $row0['service_id'],
        (int) $row0['staff_id'],
        (string) $row0['start_at'],
        null,
        $branchId,
        false,
        false
    );
    if (!$slotFree) {
        $pass('same staff/service/start marked unavailable after seed booking (overlap enforced)');
    } else {
        $fail('double-booking guard', 'isSlotAvailable still true for occupied seed slot');
    }
}

$blockOk = false;
try {
    $staff0 = $db->fetchOne(
        'SELECT id FROM staff WHERE branch_id = ? AND email = ? AND deleted_at IS NULL LIMIT 1',
        [$branchId, 'cal-seed-staff-1@example.invalid']
    );
    $svc30 = $db->fetchOne(
        'SELECT id FROM services WHERE branch_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1',
        [$branchId, 'CALSVC Express 30']
    );
    if ($staff0 && $svc30) {
        $sid = (int) $staff0['id'];
        $serviceId = (int) $svc30['id'];
        $startAt = $d1 . ' 13:30:00';
        $endAt = $d1 . ' 14:00:00';
        $clientId = (int) ($db->fetchOne(
            'SELECT id FROM clients WHERE branch_id = ? AND deleted_at IS NULL AND email LIKE ? LIMIT 1',
            [$branchId, 'cal-seed-client-%@example.invalid']
        )['id'] ?? 0);
        if ($clientId > 0) {
            $appointmentService->create([
                'client_id' => $clientId,
                'service_id' => $serviceId,
                'staff_id' => $sid,
                'branch_id' => $branchId,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => 'scheduled',
                'notes' => '[CAL_SEED_V1] blocked probe',
            ]);
        }
    }
} catch (\DomainException $e) {
    if (str_contains($e->getMessage(), 'unavailable') || str_contains($e->getMessage(), 'Staff time')) {
        $blockOk = true;
    }
}
if ($blockOk) {
    $pass('booking into CAL_SEED afternoon block rejected');
} else {
    $fail('blocked-time guard', 'expected DomainException on 13:30 booking for staff-1');
}

echo "\nSummary: branch_id={$branchId} day1={$d1} listActiveStaff=" . count($staffCols)
    . ' grouped_staff_keys=' . count($grouped)
    . ' blocked_staff_keys=' . count($blocked)
    . " failed={$failed}\n";

exit($failed > 0 ? 1 : 0);
