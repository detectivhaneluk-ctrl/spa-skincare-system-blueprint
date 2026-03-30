<?php

declare(strict_types=1);

/**
 * FOUNDATION-HARDENING-WAVE-REPAIR:
 * Audit and deterministic repair helper for legacy rows missing immutable entitlement snapshots.
 *
 * Usage:
 *   php scripts/snapshot_gap_preflight_repair.php
 *   php scripts/snapshot_gap_preflight_repair.php --json
 *   php scripts/snapshot_gap_preflight_repair.php --apply-safe
 *   php scripts/snapshot_gap_preflight_repair.php --apply-safe --json
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/modules/bootstrap.php';

use Core\App\Database;

$args = array_slice($argv, 1);
$json = in_array('--json', $args, true);
$applySafe = in_array('--apply-safe', $args, true);

$db = app(Database::class);
$pdo = $db->connection();

/**
 * @return string SAFE_BACKFILL_FROM_ORIGINAL_REFERENCE|MANUAL_REVIEW_REQUIRED|ALREADY_TERMINAL_IGNORE
 */
function classifyMembershipSale(array $row): string
{
    $status = (string) ($row['status'] ?? '');
    if (in_array($status, ['void', 'cancelled'], true)) {
        return 'ALREADY_TERMINAL_IGNORE';
    }
    if (in_array($status, ['draft', 'invoiced', 'paid', 'refund_review'], true)) {
        return 'MANUAL_REVIEW_REQUIRED';
    }
    if (
        $status === 'activated'
        && !empty($row['client_membership_snapshot_json'])
        && (int) ($row['membership_definition_id'] ?? 0) > 0
        && (int) ($row['client_membership_definition_id'] ?? 0) === (int) ($row['membership_definition_id'] ?? 0)
    ) {
        return 'SAFE_BACKFILL_FROM_ORIGINAL_REFERENCE';
    }

    return 'MANUAL_REVIEW_REQUIRED';
}

/**
 * @return string SAFE_BACKFILL_FROM_ORIGINAL_REFERENCE|MANUAL_REVIEW_REQUIRED|ALREADY_TERMINAL_IGNORE
 */
function classifyPublicCommercePackage(array $row): string
{
    $status = (string) ($row['status'] ?? '');
    if (in_array($status, ['failed', 'cancelled'], true)) {
        return 'ALREADY_TERMINAL_IGNORE';
    }
    if (
        !empty($row['client_package_snapshot_json'])
        && (int) ($row['package_id'] ?? 0) > 0
        && (int) ($row['client_package_package_id'] ?? 0) === (int) ($row['package_id'] ?? 0)
    ) {
        return 'SAFE_BACKFILL_FROM_ORIGINAL_REFERENCE';
    }

    return 'MANUAL_REVIEW_REQUIRED';
}

/**
 * @return string SAFE_BACKFILL_FROM_ORIGINAL_REFERENCE|MANUAL_REVIEW_REQUIRED|ALREADY_TERMINAL_IGNORE
 */
function classifyClientPackage(array $row): string
{
    $status = (string) ($row['status'] ?? '');
    if (in_array($status, ['used', 'expired', 'cancelled'], true)) {
        return 'ALREADY_TERMINAL_IGNORE';
    }
    if (
        !empty($row['purchase_snapshot_json'])
        && (int) ($row['package_id'] ?? 0) > 0
        && (int) ($row['purchase_package_id'] ?? 0) === (int) ($row['package_id'] ?? 0)
    ) {
        return 'SAFE_BACKFILL_FROM_ORIGINAL_REFERENCE';
    }

    return 'MANUAL_REVIEW_REQUIRED';
}

$membershipRows = $db->fetchAll(
    "SELECT ms.id,
            ms.status,
            ms.membership_definition_id,
            ms.client_membership_id,
            cm.membership_definition_id AS client_membership_definition_id,
            cm.entitlement_snapshot_json AS client_membership_snapshot_json
     FROM membership_sales ms
     LEFT JOIN client_memberships cm ON cm.id = ms.client_membership_id
     WHERE ms.definition_snapshot_json IS NULL
       AND ms.status IN ('draft','invoiced','paid','activated','refund_review','void','cancelled')
     ORDER BY ms.id ASC"
);

$publicCommerceRows = $db->fetchAll(
    "SELECT p.id,
            p.status,
            p.package_id,
            p.client_package_id,
            cp.package_id AS client_package_package_id,
            cp.package_snapshot_json AS client_package_snapshot_json
     FROM public_commerce_purchases p
     LEFT JOIN client_packages cp ON cp.id = p.client_package_id
     WHERE p.product_kind = 'package'
       AND p.package_snapshot_json IS NULL
     ORDER BY p.id ASC"
);

$clientPackageRows = $db->fetchAll(
    "SELECT cp.id,
            cp.status,
            cp.package_id,
            cp.notes,
            p.package_id AS purchase_package_id,
            p.package_snapshot_json AS purchase_snapshot_json
     FROM client_packages cp
     LEFT JOIN public_commerce_purchases p
       ON p.client_package_id = cp.id
      AND p.product_kind = 'package'
     WHERE cp.package_snapshot_json IS NULL
     ORDER BY cp.id ASC"
);

$report = [
    'membership_sales' => [],
    'public_commerce_package_purchases' => [],
    'client_packages' => [],
    'safe_backfill_candidates' => [
        'membership_sales' => 0,
        'public_commerce_package_purchases' => 0,
        'client_packages' => 0,
    ],
    'manual_review_required' => [
        'membership_sales' => 0,
        'public_commerce_package_purchases' => 0,
        'client_packages' => 0,
    ],
    'already_terminal_ignore' => [
        'membership_sales' => 0,
        'public_commerce_package_purchases' => 0,
        'client_packages' => 0,
    ],
    'applied' => [
        'membership_sales' => 0,
        'public_commerce_package_purchases' => 0,
        'client_packages' => 0,
    ],
];

foreach ($membershipRows as $r) {
    $cls = classifyMembershipSale($r);
    $report['membership_sales'][] = [
        'id' => (int) $r['id'],
        'status' => (string) $r['status'],
        'classification' => $cls,
        'safe_reference' => !empty($r['client_membership_snapshot_json']) ? 'client_memberships.entitlement_snapshot_json' : null,
    ];
    if ($cls === 'SAFE_BACKFILL_FROM_ORIGINAL_REFERENCE') {
        $report['safe_backfill_candidates']['membership_sales']++;
    } elseif ($cls === 'ALREADY_TERMINAL_IGNORE') {
        $report['already_terminal_ignore']['membership_sales']++;
    } else {
        $report['manual_review_required']['membership_sales']++;
    }
}

foreach ($publicCommerceRows as $r) {
    $cls = classifyPublicCommercePackage($r);
    $report['public_commerce_package_purchases'][] = [
        'id' => (int) $r['id'],
        'status' => (string) $r['status'],
        'classification' => $cls,
        'safe_reference' => !empty($r['client_package_snapshot_json']) ? 'client_packages.package_snapshot_json' : null,
    ];
    if ($cls === 'SAFE_BACKFILL_FROM_ORIGINAL_REFERENCE') {
        $report['safe_backfill_candidates']['public_commerce_package_purchases']++;
    } elseif ($cls === 'ALREADY_TERMINAL_IGNORE') {
        $report['already_terminal_ignore']['public_commerce_package_purchases']++;
    } else {
        $report['manual_review_required']['public_commerce_package_purchases']++;
    }
}

foreach ($clientPackageRows as $r) {
    $cls = classifyClientPackage($r);
    $report['client_packages'][] = [
        'id' => (int) $r['id'],
        'status' => (string) $r['status'],
        'classification' => $cls,
        'safe_reference' => !empty($r['purchase_snapshot_json']) ? 'public_commerce_purchases.package_snapshot_json' : null,
    ];
    if ($cls === 'SAFE_BACKFILL_FROM_ORIGINAL_REFERENCE') {
        $report['safe_backfill_candidates']['client_packages']++;
    } elseif ($cls === 'ALREADY_TERMINAL_IGNORE') {
        $report['already_terminal_ignore']['client_packages']++;
    } else {
        $report['manual_review_required']['client_packages']++;
    }
}

if ($applySafe) {
    $pdo->beginTransaction();
    try {
        $updated = $db->query(
            "UPDATE membership_sales ms
             INNER JOIN client_memberships cm ON cm.id = ms.client_membership_id
             SET ms.definition_snapshot_json = cm.entitlement_snapshot_json
             WHERE ms.definition_snapshot_json IS NULL
               AND ms.status = 'activated'
               AND cm.membership_definition_id = ms.membership_definition_id
               AND cm.entitlement_snapshot_json IS NOT NULL"
        );
        $report['applied']['membership_sales'] = (int) ($updated->rowCount() ?? 0);

        $updated = $db->query(
            "UPDATE public_commerce_purchases p
             INNER JOIN client_packages cp ON cp.id = p.client_package_id
             SET p.package_snapshot_json = cp.package_snapshot_json
             WHERE p.product_kind = 'package'
               AND p.package_snapshot_json IS NULL
               AND cp.package_id = p.package_id
               AND cp.package_snapshot_json IS NOT NULL
               AND p.status NOT IN ('failed','cancelled')"
        );
        $report['applied']['public_commerce_package_purchases'] = (int) ($updated->rowCount() ?? 0);

        $updated = $db->query(
            "UPDATE client_packages cp
             INNER JOIN public_commerce_purchases p
               ON p.client_package_id = cp.id
              AND p.product_kind = 'package'
             SET cp.package_snapshot_json = p.package_snapshot_json
             WHERE cp.package_snapshot_json IS NULL
               AND p.package_id = cp.package_id
               AND p.package_snapshot_json IS NOT NULL
               AND cp.status NOT IN ('used','expired','cancelled')"
        );
        $report['applied']['client_packages'] = (int) ($updated->rowCount() ?? 0);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'snapshot_gap_preflight_repair apply failed: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo "snapshot_gap_preflight_repair\n";
    echo 'membership_sales_missing_snapshot: ' . count($report['membership_sales']) . PHP_EOL;
    echo 'public_commerce_package_missing_snapshot: ' . count($report['public_commerce_package_purchases']) . PHP_EOL;
    echo 'client_packages_missing_snapshot: ' . count($report['client_packages']) . PHP_EOL;
    echo 'safe_backfill_candidates: ' . json_encode($report['safe_backfill_candidates']) . PHP_EOL;
    echo 'manual_review_required: ' . json_encode($report['manual_review_required']) . PHP_EOL;
    echo 'already_terminal_ignore: ' . json_encode($report['already_terminal_ignore']) . PHP_EOL;
    if ($applySafe) {
        echo 'applied: ' . json_encode($report['applied']) . PHP_EOL;
    }
}

