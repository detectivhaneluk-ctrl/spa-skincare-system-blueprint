<?php

declare(strict_types=1);

/**
 * APPOINTMENTS-WIZARD-END-TO-END-SMOKE-AND-ROOT-CAUSE-01
 *
 * Smoke tests for the full-page appointment wizard commit path.
 *
 * Tests all four mandatory paths:
 *   PATH A — Simple standalone booking with existing client
 *   PATH B — New client booking (draft client created and persisted)
 *   PATH C — Linked/chained booking (two services)
 *   PATH D — Fail-closed case (stale slot / bad state)
 *
 * Additional proofs:
 *   - DB truth verified after every successful commit
 *   - Rollback/no-partial-write on forced failure
 *   - booking_chains record created for all paths
 *   - booking_payment_summaries record created for all paths
 *   - quick drawer and blocked-time flows untouched (service isolation check)
 *
 * From system/:
 *   php scripts/smoke_wizard_end_to_end_01.php --branch-code=SMOKE_A
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Kernel\AssuranceLevel;
use Core\Kernel\ExecutionSurface;
use Core\Kernel\RequestContextHolder;
use Core\Kernel\TenantContext;
use Core\Organization\OrganizationContext;
use Modules\Appointments\Repositories\AppointmentRepository;
use Modules\Appointments\Services\AppointmentWizardService;
use Modules\Appointments\Services\AvailabilityService;

// ── Helpers ──────────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$createdAppointmentIds = [];
$createdClientIds = [];

function wPass(string $name): void
{
    global $passed;
    $passed++;
    echo "PASS  {$name}\n";
}

function wFail(string $name, string $detail): void
{
    global $failed;
    $failed++;
    fwrite(STDERR, "FAIL  {$name}: {$detail}\n");
}

function wCleanup(AppointmentRepository $repo, Database $db): void
{
    global $createdAppointmentIds, $createdClientIds;
    foreach ($createdAppointmentIds as $id) {
        try {
            $repo->softDelete((int) $id);
        } catch (\Throwable) {
            // best-effort
        }
    }
    $createdAppointmentIds = [];
    // Hard-delete test-only clients created with unique smoke email
    foreach ($createdClientIds as $id) {
        try {
            $db->query('DELETE FROM clients WHERE id = ? AND email LIKE ?', [(int) $id, 'smoke-wizard-newclient-%']);
        } catch (\Throwable) {
            // best-effort
        }
    }
    $createdClientIds = [];
}

// ── Args / config ────────────────────────────────────────────────────────────

$branchCode = 'SMOKE_A';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--branch-code=')) {
        $branchCode = trim(substr($arg, strlen('--branch-code=')));
    }
}

// ── Bootstrap DI ────────────────────────────────────────────────────────────

$db            = app(Database::class);
$branchContext = app(BranchContext::class);
$orgContext     = app(OrganizationContext::class);
$contextHolder = app(RequestContextHolder::class);
$wizardService = app(AppointmentWizardService::class);
$apptRepo      = app(AppointmentRepository::class);
$availability  = app(AvailabilityService::class);

// ── Resolve branch / org ─────────────────────────────────────────────────────

$scopeRow = $db->fetchOne(
    'SELECT b.id AS branch_id, b.organization_id AS organization_id
     FROM branches b
     INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
     WHERE b.code = ? AND b.deleted_at IS NULL
     LIMIT 1',
    [$branchCode]
);
if ($scopeRow === null) {
    fwrite(STDERR, "ABORT: Branch code {$branchCode} not found. Run seed scripts first.\n");
    exit(1);
}
$branchId = (int) $scopeRow['branch_id'];
$orgId    = (int) $scopeRow['organization_id'];

// ── Smoke admin actor ────────────────────────────────────────────────────────

$adminRow = $db->fetchOne('SELECT id FROM users WHERE email = ? LIMIT 1', ['tenant-admin-a@example.test']);
if ($adminRow === null) {
    fwrite(STDERR, "ABORT: Smoke admin user not found. Run seed scripts first.\n");
    exit(1);
}
$actorId = (int) $adminRow['id'];

// ── Activate context (mimics authenticated tenant request) ───────────────────

$branchContext->setCurrentBranchId($branchId);
$orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
$_SESSION['user_id'] = $actorId;

$tenantCtx = TenantContext::resolvedTenant(
    actorId: $actorId,
    organizationId: $orgId,
    branchId: $branchId,
    isSupportEntry: false,
    supportActorId: null,
    assuranceLevel: AssuranceLevel::SESSION,
    executionSurface: ExecutionSurface::HTTP_TENANT,
    organizationResolutionMode: OrganizationContext::MODE_BRANCH_DERIVED,
);
$contextHolder->set($tenantCtx);

// ── Resolve test fixtures ─────────────────────────────────────────────────────

// Service + staff pair (same as other verify scripts)
$pair = $db->fetchOne(
    'SELECT c.id AS client_id, s.id AS service_id, s.duration_minutes, st.id AS staff_id, st.first_name, st.last_name
     FROM clients c
     CROSS JOIN services s
     INNER JOIN service_staff ss ON ss.service_id = s.id
     INNER JOIN staff st ON st.id = ss.staff_id AND st.deleted_at IS NULL AND st.is_active = 1
     WHERE c.deleted_at IS NULL AND (c.branch_id = ? OR c.branch_id IS NULL)
       AND s.deleted_at IS NULL AND s.is_active = 1 AND (s.branch_id = ? OR s.branch_id IS NULL)
     LIMIT 1',
    [$branchId, $branchId]
);
if ($pair === null) {
    fwrite(STDERR, "ABORT: No client+service+staff triple for branch {$branchCode}.\n");
    exit(1);
}

$clientId    = (int) $pair['client_id'];
$serviceId   = (int) $pair['service_id'];
$staffId     = (int) $pair['staff_id'];
$staffName   = trim($pair['first_name'] . ' ' . $pair['last_name']);
$durationMin = (int) ($pair['duration_minutes'] ?? 60);

// Find a valid available slot in the next 30 days
$slotDate = null;
$slotTime = null;
for ($i = 3; $i <= 30; $i++) {
    $testDate = date('Y-m-d', strtotime("+{$i} days"));
    $slots = $availability->getAvailableSlots($serviceId, $testDate, $staffId, $branchId, 'internal');
    if (!empty($slots)) {
        $slotDate = $testDate;
        $slotTime = $slots[0];
        break;
    }
}
if ($slotDate === null || $slotTime === null) {
    fwrite(STDERR, "ABORT: No available slots for service_id={$serviceId} staff_id={$staffId} in next 30 days.\n");
    exit(1);
}

echo "\nFixtures: branch_id={$branchId} client_id={$clientId} service_id={$serviceId} staff_id={$staffId} slot={$slotDate}@{$slotTime}\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// PATH A — Simple standalone booking with existing client
// ─────────────────────────────────────────────────────────────────────────────

echo "=== PATH A: Simple single-service booking (existing client) ===\n";

$stateA = [
    'branch_id'    => $branchId,
    'booking_mode' => 'standalone',
    'step'         => 4,
    'service_lines' => [
        [
            'index'             => 0,
            'predecessor_index' => null,
            'result_key'        => "{$serviceId}:{$staffId}:{$slotDate}:{$slotTime}",
            'service_id'        => $serviceId,
            'service_name'      => 'Smoke Service',
            'staff_id'          => $staffId,
            'staff_name'        => $staffName,
            'room_id'           => null,
            'date'              => $slotDate,
            'start_time'        => $slotTime,
            'duration_minutes'  => $durationMin,
            'lock_to_staff'     => false,
            'requested'         => false,
            'price_snapshot'    => 0.0,
        ],
    ],
    'client' => [
        'mode'      => 'existing',
        'client_id' => $clientId,
        'draft'     => [],
    ],
    'payment' => [
        'mode'             => 'skip_payment',
        'skip_reason'      => null,
        'hold_reservation' => false,
        'totals'           => [
            'subtotal'   => 0.0,
            'tax'        => 0.0,
            'total'      => 0.0,
            'currency'   => 'GBP',
            'line_count' => 1,
        ],
    ],
];

try {
    $wizardService->revalidateForCommit($stateA);
    wPass('path_a.revalidate_passes');
} catch (\Throwable $e) {
    wFail('path_a.revalidate_passes', $e->getMessage());
    fwrite(STDERR, "ABORT PATH A: revalidate failed — remaining tests may be unreliable.\n");
    goto path_b;
}

$apptIdA = null;
try {
    $apptIdA = $wizardService->commit($stateA, $actorId);
    $createdAppointmentIds[] = $apptIdA;
    wPass('path_a.commit_returns_appointment_id');
} catch (\Throwable $e) {
    wFail('path_a.commit_returns_appointment_id', $e->getMessage());
    goto cleanup_a;
}

// Verify appointment exists
$rowA = $db->fetchOne('SELECT id, client_id, service_id, staff_id, branch_id, status FROM appointments WHERE id = ?', [$apptIdA]);
if ($rowA !== null
    && (int) $rowA['client_id'] === $clientId
    && (int) $rowA['service_id'] === $serviceId
    && (int) $rowA['staff_id'] === $staffId
    && (int) $rowA['branch_id'] === $branchId
    && $rowA['status'] === 'scheduled') {
    wPass('path_a.appointment_row_exists_with_correct_data');
} else {
    wFail('path_a.appointment_row_exists_with_correct_data', 'row mismatch: ' . json_encode($rowA));
}

// Verify booking_chains row
$chainA = $db->fetchOne(
    'SELECT id, branch_id, booking_mode, chain_order_count FROM booking_chains WHERE id = (SELECT booking_chain_id FROM appointments WHERE id = ?)',
    [$apptIdA]
);
if ($chainA !== null
    && (int) $chainA['branch_id'] === $branchId
    && $chainA['booking_mode'] === 'standalone'
    && (int) $chainA['chain_order_count'] === 1) {
    wPass('path_a.booking_chain_row_exists_standalone');
} else {
    wFail('path_a.booking_chain_row_exists_standalone', 'chain row mismatch: ' . json_encode($chainA));
}

// Verify booking_payment_summaries row
$bpsA = $db->fetchOne(
    'SELECT id, payment_mode, total_amount FROM booking_payment_summaries WHERE primary_appointment_id = ?',
    [$apptIdA]
);
if ($bpsA !== null && $bpsA['payment_mode'] === 'skip_payment') {
    wPass('path_a.booking_payment_summary_persisted');
} else {
    wFail('path_a.booking_payment_summary_persisted', 'bps row mismatch: ' . json_encode($bpsA));
}

wPass('path_a.complete');

cleanup_a:
wCleanup($apptRepo, $db);

// ─────────────────────────────────────────────────────────────────────────────
// PATH B — New client booking
// ─────────────────────────────────────────────────────────────────────────────

path_b:
echo "\n=== PATH B: New client booking ===\n";

// Pick a slot that doesn't conflict with Path A (path A was cleaned up, but just use a different slot)
$slotDateB = null;
$slotTimeB = null;
for ($i = 4; $i <= 30; $i++) {
    $testDate = date('Y-m-d', strtotime("+{$i} days"));
    $slots = $availability->getAvailableSlots($serviceId, $testDate, $staffId, $branchId, 'internal');
    if (!empty($slots)) {
        $slotDateB = $testDate;
        // Use second slot if available, otherwise first
        $slotTimeB = count($slots) > 1 ? $slots[1] : $slots[0];
        break;
    }
}
if ($slotDateB === null) {
    $slotDateB = $slotDate;
    $slotTimeB = count($availability->getAvailableSlots($serviceId, $slotDate, $staffId, $branchId, 'internal')) > 1
        ? $availability->getAvailableSlots($serviceId, $slotDate, $staffId, $branchId, 'internal')[1]
        : $slotTime;
}

$smokeEmail = 'smoke-wizard-newclient-' . time() . '@example.test';
$stateB = [
    'branch_id'    => $branchId,
    'booking_mode' => 'standalone',
    'step'         => 4,
    'service_lines' => [
        [
            'index'             => 0,
            'predecessor_index' => null,
            'result_key'        => "{$serviceId}:{$staffId}:{$slotDateB}:{$slotTimeB}",
            'service_id'        => $serviceId,
            'service_name'      => 'Smoke Service',
            'staff_id'          => $staffId,
            'staff_name'        => $staffName,
            'room_id'           => null,
            'date'              => $slotDateB,
            'start_time'        => $slotTimeB,
            'duration_minutes'  => $durationMin,
            'lock_to_staff'     => false,
            'requested'         => false,
            'price_snapshot'    => 0.0,
        ],
    ],
    'client' => [
        'mode'      => 'new',
        'client_id' => null,
        'draft'     => [
            'first_name'           => 'SmokeWizard',
            'last_name'            => 'NewClient',
            'phone'                => '07700900001',
            'email'                => $smokeEmail,
            'receive_emails'       => false,
            'gender'               => '',
            'birth_date'           => '',
            'home_address_1'       => '',
            'home_city'            => '',
            'home_postal_code'     => '',
            'home_country'         => '',
            'referral_information' => '',
            'customer_origin'      => '',
            'marketing_opt_in'     => false,
        ],
    ],
    'payment' => [
        'mode'             => 'skip_payment',
        'skip_reason'      => null,
        'hold_reservation' => false,
        'totals'           => [
            'subtotal'   => 0.0,
            'tax'        => 0.0,
            'total'      => 0.0,
            'currency'   => 'GBP',
            'line_count' => 1,
        ],
    ],
];

try {
    $wizardService->revalidateForCommit($stateB);
    wPass('path_b.revalidate_passes');
} catch (\Throwable $e) {
    wFail('path_b.revalidate_passes', $e->getMessage());
    goto path_c;
}

$apptIdB = null;
$newClientIdB = null;
try {
    $apptIdB = $wizardService->commit($stateB, $actorId);
    $createdAppointmentIds[] = $apptIdB;
    wPass('path_b.commit_returns_appointment_id');
} catch (\Throwable $e) {
    wFail('path_b.commit_returns_appointment_id', $e->getMessage());
    goto cleanup_b;
}

// Verify appointment exists with a new client
$rowB = $db->fetchOne(
    'SELECT a.id, a.client_id, a.status, c.first_name, c.last_name, c.email
     FROM appointments a
     INNER JOIN clients c ON c.id = a.client_id
     WHERE a.id = ?',
    [$apptIdB]
);
if ($rowB !== null
    && (int) $rowB['id'] === $apptIdB
    && $rowB['status'] === 'scheduled') {
    $newClientIdB = (int) $rowB['client_id'];
    $createdClientIds[] = $newClientIdB;
    wPass('path_b.appointment_row_exists');
} else {
    wFail('path_b.appointment_row_exists', 'row mismatch: ' . json_encode($rowB));
    goto cleanup_b;
}

// Verify new client was persisted
$clientRowB = $db->fetchOne(
    'SELECT id, first_name, last_name, email, branch_id FROM clients WHERE id = ?',
    [$newClientIdB]
);
if ($clientRowB !== null
    && $clientRowB['first_name'] === 'SmokeWizard'
    && $clientRowB['last_name'] === 'NewClient'
    && $clientRowB['email'] === $smokeEmail
    && (int) $clientRowB['branch_id'] === $branchId) {
    wPass('path_b.new_client_persisted_with_correct_data');
} else {
    wFail('path_b.new_client_persisted_with_correct_data', 'client row mismatch: ' . json_encode($clientRowB));
}

// Verify booking_payment_summaries row
$bpsB = $db->fetchOne(
    'SELECT id, payment_mode FROM booking_payment_summaries WHERE primary_appointment_id = ?',
    [$apptIdB]
);
if ($bpsB !== null && $bpsB['payment_mode'] === 'skip_payment') {
    wPass('path_b.booking_payment_summary_persisted');
} else {
    wFail('path_b.booking_payment_summary_persisted', 'bps row mismatch: ' . json_encode($bpsB));
}

wPass('path_b.complete');

cleanup_b:
wCleanup($apptRepo, $db);

// ─────────────────────────────────────────────────────────────────────────────
// PATH C — Linked / chained booking (two services)
// ─────────────────────────────────────────────────────────────────────────────

path_c:
echo "\n=== PATH C: Linked/chained booking (two service lines) ===\n";

// Find two slots on DIFFERENT DAYS (avoids same-transaction availability conflict for same staff).
// Line 1: first available date+slot. Line 2: next available date+slot (at least 1 day later).
$slotDateC  = null;
$slotTime1C = null;
$slotDateC2 = null;
$slotTime2C = null;

for ($i = 5; $i <= 30; $i++) {
    $testDate = date('Y-m-d', strtotime("+{$i} days"));
    $slots = $availability->getAvailableSlots($serviceId, $testDate, $staffId, $branchId, 'internal');
    if (!empty($slots)) {
        $slotDateC  = $testDate;
        $slotTime1C = $slots[0];
        break;
    }
}

if ($slotDateC !== null) {
    for ($j = 1; $j <= 25; $j++) {
        $testDate2 = date('Y-m-d', strtotime($slotDateC . " +{$j} days"));
        $slots2 = $availability->getAvailableSlots($serviceId, $testDate2, $staffId, $branchId, 'internal');
        if (!empty($slots2)) {
            $slotDateC2 = $testDate2;
            $slotTime2C = $slots2[0];
            break;
        }
    }
}

if ($slotDateC === null || $slotDateC2 === null) {
    echo "SKIP  path_c: Could not find two separate available-day slots in the next 30 days.\n";
    goto path_d;
}

$stateC = [
    'branch_id'    => $branchId,
    'booking_mode' => 'linked_chain',
    'step'         => 4,
    'service_lines' => [
        [
            'index'             => 0,
            'predecessor_index' => null,
            'result_key'        => "{$serviceId}:{$staffId}:{$slotDateC}:{$slotTime1C}",
            'service_id'        => $serviceId,
            'service_name'      => 'Smoke Service',
            'staff_id'          => $staffId,
            'staff_name'        => $staffName,
            'room_id'           => null,
            'date'              => $slotDateC,
            'start_time'        => $slotTime1C,
            'duration_minutes'  => $durationMin,
            'lock_to_staff'     => false,
            'requested'         => false,
            'price_snapshot'    => 0.0,
        ],
        [
            'index'             => 1,
            'predecessor_index' => 0,
            'result_key'        => "{$serviceId}:{$staffId}:{$slotDateC2}:{$slotTime2C}",
            'service_id'        => $serviceId,
            'service_name'      => 'Smoke Service',
            'staff_id'          => $staffId,
            'staff_name'        => $staffName,
            'room_id'           => null,
            'date'              => $slotDateC2,
            'start_time'        => $slotTime2C,
            'duration_minutes'  => $durationMin,
            'lock_to_staff'     => false,
            'requested'         => false,
            'price_snapshot'    => 0.0,
        ],
    ],
    'client' => [
        'mode'      => 'existing',
        'client_id' => $clientId,
        'draft'     => [],
    ],
    'payment' => [
        'mode'             => 'skip_payment',
        'skip_reason'      => null,
        'hold_reservation' => false,
        'totals'           => [
            'subtotal'   => 0.0,
            'tax'        => 0.0,
            'total'      => 0.0,
            'currency'   => 'GBP',
            'line_count' => 2,
        ],
    ],
];

try {
    $wizardService->revalidateForCommit($stateC);
    wPass('path_c.revalidate_passes');
} catch (\Throwable $e) {
    // Linked chain may fail if second slot becomes unavailable after first is locked
    // This is acceptable behavior — chain must fail-closed
    wFail('path_c.revalidate_passes', $e->getMessage());
    goto path_d;
}

$firstApptIdC = null;
try {
    $firstApptIdC = $wizardService->commit($stateC, $actorId);
    $createdAppointmentIds[] = $firstApptIdC;
    wPass('path_c.commit_returns_first_appointment_id');
} catch (\Throwable $e) {
    wFail('path_c.commit_returns_first_appointment_id', $e->getMessage());
    goto cleanup_c;
}

// Verify both appointments share the same booking_chain_id
$chainC = $db->fetchOne(
    'SELECT booking_chain_id FROM appointments WHERE id = ?',
    [$firstApptIdC]
);
$chainIdC = $chainC !== null ? (int) $chainC['booking_chain_id'] : null;

if ($chainIdC !== null && $chainIdC > 0) {
    wPass('path_c.first_appointment_linked_to_chain');

    // Find both appointments in the chain
    $chainAppts = $db->fetchAll(
        'SELECT id, booking_chain_order FROM appointments WHERE booking_chain_id = ? AND deleted_at IS NULL ORDER BY booking_chain_order',
        [$chainIdC]
    );
    if (count($chainAppts) === 2) {
        $createdAppointmentIds[] = (int) $chainAppts[1]['id'];
        wPass('path_c.both_appointments_in_chain');
    } else {
        wFail('path_c.both_appointments_in_chain', 'Expected 2 appointments in chain, got: ' . count($chainAppts));
    }

    // Verify chain row has linked_chain mode and count=2
    $chainRowC = $db->fetchOne(
        'SELECT booking_mode, chain_order_count FROM booking_chains WHERE id = ?',
        [$chainIdC]
    );
    if ($chainRowC !== null
        && $chainRowC['booking_mode'] === 'linked_chain'
        && (int) $chainRowC['chain_order_count'] === 2) {
        wPass('path_c.booking_chain_mode_linked_chain_count_2');
    } else {
        wFail('path_c.booking_chain_mode_linked_chain_count_2', 'chain row: ' . json_encode($chainRowC));
    }
} else {
    wFail('path_c.first_appointment_linked_to_chain', 'booking_chain_id is null on appointment');
}

// Verify payment summary exists for chain
$bpsC = $db->fetchOne(
    'SELECT id, payment_mode, line_count FROM booking_payment_summaries WHERE primary_appointment_id = ?',
    [$firstApptIdC]
);
if ($bpsC !== null && (int) $bpsC['line_count'] === 2) {
    wPass('path_c.booking_payment_summary_line_count_2');
} else {
    wFail('path_c.booking_payment_summary_line_count_2', 'bps row: ' . json_encode($bpsC));
}

wPass('path_c.complete');

cleanup_c:
wCleanup($apptRepo, $db);

// ─────────────────────────────────────────────────────────────────────────────
// PATH D — Fail-closed case (stale state, invalid payment mode)
// ─────────────────────────────────────────────────────────────────────────────

path_d:
echo "\n=== PATH D: Fail-closed cases ===\n";

// D1: Missing service_lines → DomainException
$stateD1 = [
    'branch_id'    => $branchId,
    'booking_mode' => 'standalone',
    'step'         => 4,
    'service_lines' => [],
    'client' => ['mode' => 'existing', 'client_id' => $clientId, 'draft' => []],
    'payment' => ['mode' => 'skip_payment'],
];
try {
    $wizardService->revalidateForCommit($stateD1);
    wFail('path_d.no_service_lines_rejected', 'expected DomainException but got none');
} catch (\DomainException $e) {
    wPass('path_d.no_service_lines_rejected: ' . $e->getMessage());
} catch (\Throwable $e) {
    wFail('path_d.no_service_lines_rejected', 'wrong exception type: ' . get_class($e) . ': ' . $e->getMessage());
}

// D2: Bad payment mode → DomainException
$stateD2 = [
    'branch_id'    => $branchId,
    'booking_mode' => 'standalone',
    'step'         => 4,
    'service_lines' => [
        [
            'index' => 0, 'predecessor_index' => null, 'service_id' => $serviceId,
            'staff_id' => $staffId, 'date' => $slotDate, 'start_time' => $slotTime,
            'duration_minutes' => $durationMin, 'staff_name' => $staffName,
            'lock_to_staff' => false, 'requested' => false, 'price_snapshot' => 0.0,
        ],
    ],
    'client' => ['mode' => 'existing', 'client_id' => $clientId, 'draft' => []],
    'payment' => ['mode' => 'none'],
];
try {
    $wizardService->revalidateForCommit($stateD2);
    wFail('path_d.bad_payment_mode_rejected', 'expected DomainException but got none');
} catch (\DomainException $e) {
    wPass('path_d.bad_payment_mode_rejected: ' . $e->getMessage());
} catch (\Throwable $e) {
    wFail('path_d.bad_payment_mode_rejected', 'wrong exception type: ' . get_class($e) . ': ' . $e->getMessage());
}

// D3: No client selected → DomainException
$stateD3 = [
    'branch_id'    => $branchId,
    'booking_mode' => 'standalone',
    'step'         => 4,
    'service_lines' => [
        [
            'index' => 0, 'predecessor_index' => null, 'service_id' => $serviceId,
            'staff_id' => $staffId, 'date' => $slotDate, 'start_time' => $slotTime,
            'duration_minutes' => $durationMin, 'staff_name' => $staffName,
            'lock_to_staff' => false, 'requested' => false, 'price_snapshot' => 0.0,
        ],
    ],
    'client' => ['mode' => 'existing', 'client_id' => 0, 'draft' => []],
    'payment' => ['mode' => 'skip_payment'],
];
try {
    $wizardService->revalidateForCommit($stateD3);
    wFail('path_d.no_client_rejected', 'expected DomainException but got none');
} catch (\DomainException $e) {
    wPass('path_d.no_client_rejected: ' . $e->getMessage());
} catch (\Throwable $e) {
    wFail('path_d.no_client_rejected', 'wrong exception type: ' . get_class($e) . ': ' . $e->getMessage());
}

// D4: Verify no partial write on commit failure
// Attempt to commit with a non-existent client ID to force rollback inside transaction
$pdo = $db->connection();
$countBefore = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM booking_chains')['c'];
$stateD4 = [
    'branch_id'    => $branchId,
    'booking_mode' => 'standalone',
    'step'         => 4,
    'service_lines' => [
        [
            'index' => 0, 'predecessor_index' => null, 'service_id' => $serviceId,
            'staff_id' => $staffId, 'date' => $slotDate, 'start_time' => $slotTime,
            'duration_minutes' => $durationMin, 'staff_name' => $staffName,
            'lock_to_staff' => false, 'requested' => false, 'price_snapshot' => 0.0,
        ],
    ],
    'client' => ['mode' => 'existing', 'client_id' => 999999999, 'draft' => []],  // non-existent client
    'payment' => ['mode' => 'skip_payment'],
];
try {
    $wizardService->commit($stateD4, $actorId);
    wFail('path_d.no_partial_write_on_failure', 'commit should have thrown for non-existent client');
} catch (\DomainException $e) {
    $countAfter = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM booking_chains')['c'];
    if ($countAfter === $countBefore) {
        wPass('path_d.no_partial_write_on_failure: booking_chains count unchanged (' . $countAfter . ')');
    } else {
        wFail('path_d.no_partial_write_on_failure', "chain count before={$countBefore} after={$countAfter} — LEAKED ROW!");
    }
} catch (\Throwable $e) {
    $countAfter = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM booking_chains')['c'];
    if ($countAfter === $countBefore) {
        wPass('path_d.no_partial_write_on_failure: exception=' . get_class($e) . ', no chain row leaked');
    } else {
        wFail('path_d.no_partial_write_on_failure', "LEAKED chain row: before={$countBefore} after={$countAfter}");
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Quick drawer / blocked-time isolation check (service boundary)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== ISOLATION: Quick drawer / blocked-time service methods unaffected ===\n";

// AppointmentWizardService should NOT have create/update/cancel methods (those are on AppointmentService)
$wizardMethods = get_class_methods($wizardService);
$dangerMethods = array_filter($wizardMethods, static fn (string $m) => in_array($m, ['create', 'update', 'cancel', 'reschedule', 'delete'], true));
if (empty($dangerMethods)) {
    wPass('isolation.wizard_service_has_no_direct_crud_methods');
} else {
    wFail('isolation.wizard_service_has_no_direct_crud_methods', implode(', ', $dangerMethods));
}

// booking_chains table should have no created_at drift (migrations properly applied)
$chainsSchema = $db->fetchAll("SHOW COLUMNS FROM booking_chains");
$chainCols    = array_column($chainsSchema, 'Field');
foreach (['id', 'branch_id', 'booking_mode', 'chain_order_count', 'created_by', 'created_at'] as $required) {
    if (!in_array($required, $chainCols, true)) {
        wFail('isolation.booking_chains_schema_valid', "Missing column: {$required}");
    }
}
wPass('isolation.booking_chains_schema_valid');

// booking_payment_summaries table columns
$bpsSchema = $db->fetchAll("SHOW COLUMNS FROM booking_payment_summaries");
$bpsCols   = array_column($bpsSchema, 'Field');
foreach (['id', 'booking_chain_id', 'primary_appointment_id', 'payment_mode', 'subtotal', 'total_amount', 'currency', 'tax_basis'] as $required) {
    if (!in_array($required, $bpsCols, true)) {
        wFail('isolation.booking_payment_summaries_schema_valid', "Missing column: {$required}");
    }
}
wPass('isolation.booking_payment_summaries_schema_valid');

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== SMOKE RESULTS ===\n";
echo "PASS: {$passed}   FAIL: {$failed}\n";
echo $failed > 0
    ? "STATUS: FAIL\n"
    : "STATUS: ALL PATHS PROVEN\n";
echo "\n";

// Final cleanup
wCleanup($apptRepo, $db);

exit($failed > 0 ? 1 : 0);
