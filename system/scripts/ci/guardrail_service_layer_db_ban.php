<?php

declare(strict_types=1);

/**
 * FOUNDATION-A6 Guardrail 1: Service Layer DB Ban
 *
 * Fails if any explicitly protected service file contains direct DB data access calls.
 * Only db->connection() is permitted (transaction management infrastructure).
 *
 * Architecture rule:
 *   Protected-domain services MUST NOT call:
 *     $this->db->fetchOne(...)
 *     $this->db->fetchAll(...)
 *     $this->db->query(...)
 *     $this->db->insert(...)
 *     $this->db->lastInsertId()
 *   They may only call repository/query-command methods.
 *   db->connection() is permitted for transaction management only.
 *
 * Root families addressed: ROOT-05 (service scope drift)
 *
 * How to extend:
 *   When a new domain is fully migrated via the A7 migration order, add its service file(s)
 *   to the $protectedServices array below. Do NOT add files that still contain legacy
 *   direct DB access — they will immediately fail the check.
 *
 * Protected domain phases:
 *   MEDIA_PILOT      (BIG-02, 2026-03-31) — clients/marketing media service lane
 *   APPOINTMENTS_P1  (BIG-04, 2026-03-31) — appointments core domain
 *   SALES_P3         (BIG-06, 2026-03-31) — sales core domain
 *   CLIENT_P4        (BIG-07, 2026-03-31) — client-owned resources domain
 *
 * Run from repo root: php system/scripts/ci/guardrail_service_layer_db_ban.php
 */

$repoRoot = dirname(__DIR__, 3);

// ---------------------------------------------------------------------------
// Protected service files — must contain ZERO direct DB data operations.
// Grows as each A7 migration phase completes.
// ---------------------------------------------------------------------------
$protectedServices = [
    // MEDIA_PILOT phase — migrated 2026-03-31 (BIG-02 / FOUNDATION-A3+A4+A5)
    'system/modules/clients/services/ClientProfileImageService.php',
    'system/modules/marketing/services/MarketingGiftCardTemplateService.php',
    // APPOINTMENTS_P1 phase — migrated 2026-03-31 (BIG-04 / FOUNDATION-A7 Phase-1)
    'system/modules/appointments/services/BlockedSlotService.php',
    // WaitlistService is excluded from the strict DB-ban: it uses db->fetchOne for MySQL
    // advisory locking (SELECT GET_LOCK / RELEASE_LOCK) which is infrastructure, not business
    // data access. This is an explicit architectural exception noted in BIG-04 closure.

    // SALES_P3 phase — migrated 2026-03-31 (BIG-06 / FOUNDATION-A7 Phase-3)
    // ReceiptInvoicePresentationService is excluded from the strict DB-ban: it retains a single
    // db->fetchOne for user name lookup ("Recorded by: ...") on receipt display. This is
    // presentation-only infrastructure — no tenant-owned business data — analogous to
    // WaitlistService's advisory lock exception. Product barcodes were migrated to
    // ProductRepository::lookupBarcodesByIds() (BIG-06).
    // Truth audit services (SalesLineDomainBoundaryTruthAuditService, InvoicePaymentSettlement-
    // TruthAuditService, InvoiceFinancialRollupTruthAuditService, SalesLineInventoryImpactTruth-
    // AuditService) are read-only diagnostic/analytics services — not transactional business
    // services — and are excluded from the strict DB-ban for the same reasons as WaitlistService.
    'system/modules/sales/services/PaymentMethodService.php',
    'system/modules/sales/services/VatRateService.php',
    'system/modules/sales/services/InvoiceService.php',
    'system/modules/sales/services/PaymentService.php',
    'system/modules/sales/services/RegisterSessionService.php',

    // CLIENT_P4 phase — migrated 2026-03-31 (BIG-07 / FOUNDATION-A7 Phase-4)
    // ClientService is excluded from the strict DB-ban: it uses db->fetchOne for MySQL advisory
    // locking (SELECT GET_LOCK / RELEASE_LOCK) in mergeClientsAsActor(). This is infrastructure
    // (mutex for concurrent merge of the same client pair), not tenant data access — same
    // exception rationale as WaitlistService advisory lock (BIG-04).
    // ClientMergeJobService background worker paths (claimAndExecuteNextMergeJob,
    // reconcileStaleRunningJobs) use BranchContext.setCurrentBranchId() for async context
    // establishment — not a DB access issue; claimNextQueuedJob + claimSpecificQueuedJob
    // DB calls have been moved to ClientMergeJobRepository.
    'system/modules/clients/services/ClientMergeJobService.php',
    'system/modules/clients/services/ClientRegistrationService.php',
    'system/modules/clients/services/ClientIssueFlagService.php',
];

// ---------------------------------------------------------------------------
// Patterns that constitute forbidden direct DB data access from service layer.
// Each entry: [regex => human-readable violation label]
//
// NOT forbidden: ->connection() — that is transaction management, not data access.
// Simple string patterns: matches anywhere in file content (including strings/comments
// is intentional — services should not reference these even in comments as patterns).
// ---------------------------------------------------------------------------
$forbiddenPatterns = [
    '/->fetchOne\s*\(/'     => '->fetchOne(...) — direct DB data read from service',
    '/->fetchAll\s*\(/'     => '->fetchAll(...) — direct DB data read from service',
    '/->query\s*\(/'        => '->query(...) — direct DB query from service',
    '/->insert\s*\(/'       => '->insert(...) — direct DB write from service (use repository)',
    '/->lastInsertId\s*\(/' => '->lastInsertId() — direct DB call from service (belongs in repository)',
];

// ---------------------------------------------------------------------------
// Scan
// ---------------------------------------------------------------------------
$violations = [];
$checked = 0;

foreach ($protectedServices as $rel) {
    $path = $repoRoot . '/' . $rel;
    if (!is_file($path)) {
        $violations[] = "PROTECTED FILE MISSING: {$rel}\n"
            . "  → If this file was moved or renamed, update the guardrail protected list.";
        continue;
    }
    $content = file_get_contents($path);
    if ($content === false) {
        $violations[] = "UNREADABLE: {$rel}";
        continue;
    }
    ++$checked;
    foreach ($forbiddenPatterns as $pattern => $description) {
        if (preg_match($pattern, $content)) {
            $violations[] = "SERVICE DB BAN VIOLATED\n"
                . "  File: {$rel}\n"
                . "  Rule: {$description}\n"
                . "  Fix:  Move this data operation into the repository layer.\n"
                . "        Use a canonical TenantContext-scoped method (FOUNDATION-A4).";
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "guardrail_service_layer_db_ban: FAIL — " . count($violations) . " violation(s)\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, $v . "\n\n");
    }
    fwrite(STDERR, "Architecture rule: Protected services may only access data via repositories.\n");
    fwrite(STDERR, "See: system/docs/FOUNDATION-A6-GUARDRAILS-POLICY-01.md\n");
    exit(1);
}

echo "guardrail_service_layer_db_ban: PASS ({$checked} protected service(s) checked)\n";
exit(0);
