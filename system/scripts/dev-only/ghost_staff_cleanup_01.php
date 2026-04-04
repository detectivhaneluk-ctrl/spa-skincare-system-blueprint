<?php
declare(strict_types=1);

/**
 * GHOST-STAFF-CLEANUP-01
 *
 * Removes test/ghost staff that are not manageable from the staff module:
 *
 *  id=1  'Smoke Staff'        branch_id=NULL → appears on ALL branch calendars,
 *                              invisible in staff lists (branch filter excludes NULL branch_id)
 *  id=13 'Proof StaffB-Smoke' branch_id=12   → smoke/proof test record
 *  ids 17,19,21,23,25,27,29,31,33,35,37  'TDP Staff C' branch_id=13 → 11 identical test duplicates
 *  id=39 'TDP Staff C'        branch_id=31   → test duplicate
 *
 * CalSeed staff (ids 43-48, branch=11) are intentionally kept:
 *  - They ARE visible and manageable in branch 11 staff module
 *  - The calendar smoke test (verify_appointments_calendar_smoke_01.php) depends on them
 *
 * Cascade order:
 *  1. Soft-delete their appointments
 *  2. Soft-delete their blocked slots
 *  3. Soft-delete the staff rows
 *  4. Verify clean state
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);

$ghostIds = [1, 13, 17, 19, 21, 23, 25, 27, 29, 31, 33, 35, 37, 39];
$ph       = implode(', ', array_fill(0, count($ghostIds), '?'));

echo "Ghost staff ids: [" . implode(', ', $ghostIds) . "]\n\n";

// ── Step 1: appointments ────────────────────────────────────────────────────
$apptsBefore = $db->fetchAll(
    "SELECT id FROM appointments WHERE deleted_at IS NULL AND staff_id IN ({$ph})",
    $ghostIds
);
if (!empty($apptsBefore)) {
    $ids = array_column($apptsBefore, 'id');
    $aph = implode(', ', array_fill(0, count($ids), '?'));
    $stmt = $db->query("UPDATE appointments SET deleted_at = NOW() WHERE id IN ({$aph}) AND deleted_at IS NULL", $ids);
    echo "DONE  Soft-deleted {$stmt->rowCount()} appointment(s): ids=[" . implode(',', $ids) . "]\n";
} else {
    echo "INFO  No live appointments to delete for ghost staff.\n";
}

// ── Step 2: blocked slots ───────────────────────────────────────────────────
$blkBefore = $db->fetchAll(
    "SELECT id FROM appointment_blocked_slots WHERE deleted_at IS NULL AND staff_id IN ({$ph})",
    $ghostIds
);
if (!empty($blkBefore)) {
    $bids = array_column($blkBefore, 'id');
    $bph  = implode(', ', array_fill(0, count($bids), '?'));
    $stmt2 = $db->query("UPDATE appointment_blocked_slots SET deleted_at = NOW() WHERE id IN ({$bph}) AND deleted_at IS NULL", $bids);
    echo "DONE  Soft-deleted {$stmt2->rowCount()} blocked slot(s): ids=[" . implode(',', $bids) . "]\n";
} else {
    echo "INFO  No live blocked slots to delete for ghost staff.\n";
}

// ── Step 3: soft-delete the staff rows ─────────────────────────────────────
$stmtS = $db->query(
    "UPDATE staff SET deleted_at = NOW() WHERE id IN ({$ph}) AND deleted_at IS NULL",
    $ghostIds
);
echo "DONE  Soft-deleted {$stmtS->rowCount()} ghost staff row(s).\n";

// ── Step 4: verification ────────────────────────────────────────────────────
echo "\n=== VERIFICATION ===\n";

$remaining = (int)($db->fetchOne(
    "SELECT COUNT(*) AS c FROM staff WHERE deleted_at IS NULL AND id IN ({$ph})",
    $ghostIds
)['c'] ?? 0);

$orphanAppts = (int)($db->fetchOne(
    "SELECT COUNT(*) AS c FROM appointments WHERE deleted_at IS NULL
     AND staff_id > 0
     AND NOT EXISTS (SELECT 1 FROM staff st WHERE st.id = appointments.staff_id AND st.deleted_at IS NULL)",
    []
)['c'] ?? 0);

$orphanBlk = (int)($db->fetchOne(
    "SELECT COUNT(*) AS c FROM appointment_blocked_slots WHERE deleted_at IS NULL
     AND staff_id > 0
     AND NOT EXISTS (SELECT 1 FROM staff st WHERE st.id = appointment_blocked_slots.staff_id AND st.deleted_at IS NULL)",
    []
)['c'] ?? 0);

if ($remaining === 0) {
    echo "PASS  All ghost staff rows deleted.\n";
} else {
    fwrite(STDERR, "FAIL  {$remaining} ghost staff still present.\n");
}

if ($orphanAppts === 0) {
    echo "PASS  No orphaned appointments remain.\n";
} else {
    fwrite(STDERR, "FAIL  {$orphanAppts} orphaned appointments remain.\n");
}

if ($orphanBlk === 0) {
    echo "PASS  No orphaned blocked slots remain.\n";
} else {
    fwrite(STDERR, "FAIL  {$orphanBlk} orphaned blocked slots remain.\n");
}

echo "\n=== CLEAN CALENDAR COLUMNS per branch ===\n";
$branches = $db->fetchAll("SELECT id, name, code FROM branches WHERE deleted_at IS NULL ORDER BY id", []);
$availability = app(\Modules\Appointments\Services\AvailabilityService::class);

$orgRow = $db->fetchOne("SELECT organization_id FROM branches WHERE deleted_at IS NULL ORDER BY id LIMIT 1", []);
if ($orgRow) {
    $orgContext = app(\Core\Organization\OrganizationContext::class);
    $orgContext->setFromResolution((int)$orgRow['organization_id'], \Core\Organization\OrganizationContext::MODE_BRANCH_DERIVED);
}

foreach ($branches as $br) {
    $bid = (int)$br['id'];
    try {
        $calStaff = $availability->listActiveStaff($bid);
        echo "\n  Branch #{$bid} '{$br['name']}': " . count($calStaff) . " staff\n";
        foreach ($calStaff as $cs) {
            $fn = trim(($cs['first_name'] ?? '') . ' ' . ($cs['last_name'] ?? ''));
            echo "    → id={$cs['id']} '{$fn}'\n";
        }
        if (empty($calStaff)) {
            echo "    (no active staff on this branch)\n";
        }
    } catch (\Throwable $e) {
        echo "\n  Branch #{$bid}: ERROR " . $e->getMessage() . "\n";
    }
}

echo "\n=== FINAL COUNTS ===\n";
$totStaff = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM staff WHERE deleted_at IS NULL AND is_active=1", [])['c'] ?? 0);
$totAppts = (int)($db->fetchOne("SELECT COUNT(*) AS c FROM appointments WHERE deleted_at IS NULL", [])['c'] ?? 0);
echo "Active staff: {$totStaff}\n";
echo "Total appointments: {$totAppts}\n";
