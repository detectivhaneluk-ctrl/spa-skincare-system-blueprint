<?php

declare(strict_types=1);

/**
 * INVOICE-SEQUENCE-PHASE-2 — DB smoke: per-org allocator, format, legacy depot row untouched in rolled-back txn.
 *
 * Requires: migration 116 applied; seeded smoke branches SMOKE_A and SMOKE_C (different orgs).
 *
 * From repo root:
 *   php system/scripts/smoke_invoice_per_organization_sequence_phase2_01.php
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\Branch\BranchContext;
use Core\Organization\OrganizationContext;
use Modules\Sales\Repositories\InvoiceRepository;

$db = app(\Core\App\Database::class);
$branchContext = app(BranchContext::class);
$orgContext = app(OrganizationContext::class);
$invoiceRepo = app(InvoiceRepository::class);

$passed = 0;
$failed = 0;
function seq2Pass(string $name): void { global $passed; $passed++; echo "PASS  {$name}\n"; }
function seq2Fail(string $name, string $detail): void { global $failed; $failed++; fwrite(STDERR, "FAIL  {$name}: {$detail}\n"); }

$resolveScope = static function (string $branchCode) use ($db): array {
    $row = $db->fetchOne(
        'SELECT b.id AS branch_id, b.organization_id AS organization_id
         FROM branches b
         INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
         WHERE b.code = ? AND b.deleted_at IS NULL
         LIMIT 1',
        [$branchCode]
    );
    if ($row === null) {
        throw new RuntimeException('Missing branch code ' . $branchCode . ' (seed smoke branches first).');
    }

    return ['branch_id' => (int) $row['branch_id'], 'organization_id' => (int) $row['organization_id']];
};

$setScope = static function (int $branchId, int $orgId) use ($branchContext, $orgContext): void {
    $branchContext->setCurrentBranchId($branchId);
    $orgContext->setFromResolution($orgId, OrganizationContext::MODE_BRANCH_DERIVED);
};

$scopeA = $resolveScope('SMOKE_A');
$scopeC = $resolveScope('SMOKE_C');

if ($scopeA['organization_id'] === $scopeC['organization_id']) {
    seq2Fail('fixture_distinct_orgs', 'SMOKE_A and SMOKE_C must differ in organization_id');
    echo "\nSummary: {$passed} passed, {$failed} failed.\n";
    exit(1);
}

$legacyRow = $db->fetchOne(
    'SELECT next_number FROM invoice_number_sequences WHERE organization_id = 0 AND sequence_key = ? LIMIT 1',
    ['invoice']
);
if ($legacyRow === null) {
    seq2Fail('legacy_depot_row_present', 'expected (organization_id=0, sequence_key=invoice) after migrations');
    echo "\nSummary: {$passed} passed, {$failed} failed.\n";
    exit(1);
}
$legacyNextBefore = (int) ($legacyRow['next_number'] ?? 0);

$pdo = $db->connection();
$pdo->beginTransaction();

try {
    $setScope($scopeA['branch_id'], $scopeA['organization_id']);
    $n1 = $invoiceRepo->allocateNextInvoiceNumber();
    $n2 = $invoiceRepo->allocateNextInvoiceNumber();

    if (preg_match('/^ORG(\d+)-INV-(\d{8})$/', $n1, $m1) !== 1) {
        throw new RuntimeException('bad format n1: ' . $n1);
    }
    if ((int) $m1[1] !== $scopeA['organization_id']) {
        throw new RuntimeException('n1 org id mismatch');
    }
    if (preg_match('/^ORG(\d+)-INV-(\d{8})$/', $n2, $m2) !== 1) {
        throw new RuntimeException('bad format n2: ' . $n2);
    }
    if ((int) $m2[2] !== (int) $m1[2] + 1) {
        throw new RuntimeException('same-org sequence did not increment: ' . $n1 . ' -> ' . $n2);
    }

    $setScope($scopeC['branch_id'], $scopeC['organization_id']);
    $n3 = $invoiceRepo->allocateNextInvoiceNumber();
    if (preg_match('/^ORG(\d+)-INV-(\d{8})$/', $n3, $m3) !== 1) {
        throw new RuntimeException('bad format n3: ' . $n3);
    }
    if ((int) $m3[1] !== $scopeC['organization_id']) {
        throw new RuntimeException('n3 org id mismatch for SMOKE_C');
    }

    seq2Pass('org_a_two_allocations_increment');
    seq2Pass('org_c_distinct_org_prefix');

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    seq2Fail('allocator_transaction', $e->getMessage());
    echo "\nSummary: {$passed} passed, {$failed} failed.\n";
    exit(1);
}

$legacyRowAfter = $db->fetchOne(
    'SELECT next_number FROM invoice_number_sequences WHERE organization_id = 0 AND sequence_key = ? LIMIT 1',
    ['invoice']
);
$legacyNextAfter = (int) ($legacyRowAfter['next_number'] ?? -1);

if ($legacyNextAfter === $legacyNextBefore) {
    seq2Pass('legacy_depot_row_unchanged_after_rolled_back_allocations');
} else {
    seq2Fail(
        'legacy_depot_row_unchanged_after_rolled_back_allocations',
        "expected legacy next_number {$legacyNextBefore}, got {$legacyNextAfter}"
    );
}

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
