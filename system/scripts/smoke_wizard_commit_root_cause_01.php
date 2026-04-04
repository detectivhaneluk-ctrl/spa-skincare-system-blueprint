<?php

declare(strict_types=1);

/**
 * SMOKE: Wizard Commit Root Cause Investigation
 *
 * Checks:
 * 1. Migrations 135/136/137 applied (booking_chains, booking_chain columns, booking_payment_summaries)
 * 2. Tables/columns present in the database
 * 3. Direct INSERT test for booking_chains → verify table is functional
 * 4. Direct INSERT test for booking_payment_summaries → verify table is functional
 * 5. Direct UPDATE test for appointments.booking_chain_id/booking_chain_order columns
 *
 * This does NOT create real appointments — it only checks the schema paths the wizard commit uses.
 * All test rows are rolled back / deleted.
 */

require dirname(__DIR__) . '/bootstrap.php';

$db  = app(\Core\App\Database::class);
$pdo = $db->connection();

$pass  = 0;
$fail  = 0;
$lines = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $lines;
    $status = $ok ? 'PASS' : 'FAIL';
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
    $lines[] = sprintf('[%s] %s%s', $status, $label, $detail !== '' ? ': ' . $detail : '');
}

// ── 1. Migrations table presence ─────────────────────────────────────────────

$migTableExists = (bool) $pdo->query("SHOW TABLES LIKE 'migrations'")->fetch();
check('migrations table exists', $migTableExists);

if ($migTableExists) {
    $m135 = $pdo->query("SELECT migration FROM migrations WHERE migration = '135_create_booking_chains_table.sql'")->fetch();
    check('migration 135_create_booking_chains_table.sql stamped', (bool) $m135);

    $m136 = $pdo->query("SELECT migration FROM migrations WHERE migration = '136_appointments_add_booking_chain.sql'")->fetch();
    check('migration 136_appointments_add_booking_chain.sql stamped', (bool) $m136);

    $m137 = $pdo->query("SELECT migration FROM migrations WHERE migration = '137_create_booking_payment_summaries_table.sql'")->fetch();
    check('migration 137_create_booking_payment_summaries_table.sql stamped', (bool) $m137);
}

// ── 2. Table existence ────────────────────────────────────────────────────────

$bookingChainsExists = (bool) $pdo->query("SHOW TABLES LIKE 'booking_chains'")->fetch();
check('booking_chains table exists', $bookingChainsExists);

$bpsExists = (bool) $pdo->query("SHOW TABLES LIKE 'booking_payment_summaries'")->fetch();
check('booking_payment_summaries table exists', $bpsExists);

// ── 3. appointments table columns ────────────────────────────────────────────

$colsRaw = $pdo->query("SHOW COLUMNS FROM appointments")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($colsRaw, 'Field');

$hasChainId    = in_array('booking_chain_id', $colNames, true);
$hasChainOrder = in_array('booking_chain_order', $colNames, true);

check('appointments.booking_chain_id column exists', $hasChainId);
check('appointments.booking_chain_order column exists', $hasChainOrder);

// ── 4. Schema functional tests (rolled back) ──────────────────────────────────

if ($bookingChainsExists) {
    // Try inserting into booking_chains (rolled back)
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO booking_chains (branch_id, booking_mode, chain_order_count, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([1, 'standalone', 1, null]);
        $testChainId = (int) $pdo->lastInsertId();
        $pdo->rollBack();
        check('INSERT booking_chains (rolled back)', $testChainId > 0, "chain_id={$testChainId}");
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        check('INSERT booking_chains (rolled back)', false, $e->getMessage());
    }
} else {
    check('INSERT booking_chains (rolled back)', false, 'table does not exist — migration 135 not applied');
}

if ($bpsExists) {
    // Try inserting into booking_payment_summaries (rolled back)
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO booking_payment_summaries
             (booking_chain_id, primary_appointment_id, branch_id, payment_mode, subtotal, tax_amount, total_amount, currency, line_count, tax_basis)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([null, 1, 1, 'skip_payment', 0.00, 0.00, 0.00, 'GBP', 1, 'zero_tax_v1']);
        $testBpsId = (int) $pdo->lastInsertId();
        $pdo->rollBack();
        check('INSERT booking_payment_summaries (rolled back)', $testBpsId > 0, "bps_id={$testBpsId}");
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        check('INSERT booking_payment_summaries (rolled back)', false, $e->getMessage());
    }
} else {
    check('INSERT booking_payment_summaries (rolled back)', false, 'table does not exist — migration 137 not applied');
}

if ($hasChainId && $hasChainOrder) {
    // Try UPDATE on a real appointment (pick the first existing one, roll back)
    $firstAppt = $pdo->query('SELECT id FROM appointments WHERE deleted_at IS NULL ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($firstAppt) {
        $apptId = (int) $firstAppt['id'];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE appointments SET booking_chain_id = NULL, booking_chain_order = NULL WHERE id = ?');
            $stmt->execute([$apptId]);
            $pdo->rollBack();
            check('UPDATE appointments.booking_chain_id/order (rolled back)', true, "appt_id={$apptId}");
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            check('UPDATE appointments.booking_chain_id/order (rolled back)', false, $e->getMessage());
        }
    } else {
        check('UPDATE appointments.booking_chain_id/order (rolled back)', true, 'no appointments in DB — column check only');
    }
} else {
    check('UPDATE appointments.booking_chain_id/order (rolled back)', false, 'columns missing — migration 136 not applied');
}

// ── 5. Summary ────────────────────────────────────────────────────────────────

echo "\n=== SMOKE: WIZARD COMMIT SCHEMA ROOT CAUSE ===\n\n";
foreach ($lines as $line) {
    echo $line . "\n";
}
echo "\n";
echo "PASS: {$pass}  FAIL: {$fail}\n";
echo $fail > 0 ? "STATUS: SCHEMA-FAIL — run migrations 135/136/137 to fix\n" : "STATUS: SCHEMA-OK\n";
echo "\n";

exit($fail > 0 ? 1 : 0);
