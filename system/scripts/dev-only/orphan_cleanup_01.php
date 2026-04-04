<?php
declare(strict_types=1);

/**
 * ORPHAN-CLEANUP-01
 * Soft-deletes appointments and blocked slots that reference staff_ids
 * which no longer exist as active (non-deleted) staff records.
 *
 * Safe: uses soft-delete (sets deleted_at = NOW()) — data is recoverable.
 * Only affects past-dated records where the staff row is gone.
 */

require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/modules/bootstrap.php';

$db = app(\Core\App\Database::class);

// ── 1. Identify orphaned appointment ids ────────────────────────────────────
$orphanAppts = $db->fetchAll(
    "SELECT a.id, a.staff_id, a.branch_id, a.start_at, a.status
     FROM appointments a
     WHERE a.deleted_at IS NULL
       AND a.staff_id IS NOT NULL
       AND a.staff_id > 0
       AND NOT EXISTS (
         SELECT 1 FROM staff st WHERE st.id = a.staff_id AND st.deleted_at IS NULL
       )
     ORDER BY a.id",
    []
);

if (empty($orphanAppts)) {
    echo "INFO  No orphaned appointments found.\n";
} else {
    $ids = array_column($orphanAppts, 'id');
    $ph  = implode(', ', array_fill(0, count($ids), '?'));

    echo "INFO  Soft-deleting " . count($ids) . " orphaned appointments: ids=[" . implode(',', $ids) . "]\n";

    $stmt = $db->query(
        "UPDATE appointments SET deleted_at = NOW() WHERE id IN ({$ph}) AND deleted_at IS NULL",
        $ids
    );
    $affected = $stmt->rowCount();
    echo "DONE  Rows updated: {$affected}\n";
}

// ── 2. Identify orphaned blocked slot ids ───────────────────────────────────
$orphanBlocked = $db->fetchAll(
    "SELECT b.id, b.staff_id, b.branch_id, b.block_date, b.title
     FROM appointment_blocked_slots b
     WHERE b.deleted_at IS NULL
       AND b.staff_id IS NOT NULL
       AND b.staff_id > 0
       AND NOT EXISTS (
         SELECT 1 FROM staff st WHERE st.id = b.staff_id AND st.deleted_at IS NULL
       )
     ORDER BY b.id",
    []
);

if (empty($orphanBlocked)) {
    echo "INFO  No orphaned blocked slots found.\n";
} else {
    $bids = array_column($orphanBlocked, 'id');
    $bph  = implode(', ', array_fill(0, count($bids), '?'));

    echo "INFO  Soft-deleting " . count($bids) . " orphaned blocked slots: ids=[" . implode(',', $bids) . "]\n";

    $stmt2 = $db->query(
        "UPDATE appointment_blocked_slots SET deleted_at = NOW() WHERE id IN ({$bph}) AND deleted_at IS NULL",
        $bids
    );
    $affected2 = $stmt2->rowCount();
    echo "DONE  Rows updated: {$affected2}\n";
}

// ── 3. Verify clean state ────────────────────────────────────────────────────
$remaining = (int) ($db->fetchOne(
    "SELECT COUNT(*) AS c FROM appointments WHERE deleted_at IS NULL
       AND staff_id > 0
       AND NOT EXISTS (SELECT 1 FROM staff st WHERE st.id = appointments.staff_id AND st.deleted_at IS NULL)",
    []
)['c'] ?? 0);

$remainingBlk = (int) ($db->fetchOne(
    "SELECT COUNT(*) AS c FROM appointment_blocked_slots WHERE deleted_at IS NULL
       AND staff_id > 0
       AND NOT EXISTS (SELECT 1 FROM staff st WHERE st.id = appointment_blocked_slots.staff_id AND st.deleted_at IS NULL)",
    []
)['c'] ?? 0);

if ($remaining === 0 && $remainingBlk === 0) {
    echo "PASS  Post-cleanup: zero orphaned records remain.\n";
} else {
    fwrite(STDERR, "FAIL  Post-cleanup: {$remaining} orphaned appointments + {$remainingBlk} blocked slots still present.\n");
    exit(1);
}
