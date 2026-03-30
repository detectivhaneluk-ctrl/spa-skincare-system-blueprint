<?php

declare(strict_types=1);

/**
 * FOUNDATION-48 — read-only verifier: strict-gate state model + dry-run backfill counts + duplicate safety.
 * FOUNDATION-51 — first in-tree consumer of {@see UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth}
 * (positive path only when sample user gate state is `single`; otherwise explicit skip — still read-only, no HTTP).
 *
 * Does not call backfill with mutations (no {@see UserOrganizationMembershipBackfillService::run(false)}).
 *
 * Usage (from `system/`):
 *   php scripts/audit_user_organization_membership_backfill_and_gate.php
 *   php scripts/audit_user_organization_membership_backfill_and_gate.php --json
 *
 * Exit codes: 0 pass, 1 fail.
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$json = in_array('--json', array_slice($argv, 1), true);
$errors = [];
$positiveAssertSingleMembershipTruth = [
    'status' => 'skipped_table_absent',
    'sample_user_id' => null,
    'gate_organization_id' => null,
    'asserted_organization_id' => null,
];

try {
    $db = app(\Core\App\Database::class);
    $pdo = $db->connection();
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($dbName) || $dbName === '') {
        $errors[] = 'no database selected';
        throw new RuntimeException('abort');
    }

    $tbl = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1'
    );
    $tbl->execute([$dbName, 'user_organization_memberships']);
    $membershipTablePresent = $tbl->fetchColumn() !== false;

    $gateClass = \Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::class;
    $ref = new ReflectionClass($gateClass);
    if (!$ref->hasMethod('getUserOrganizationMembershipState')) {
        $errors[] = 'UserOrganizationMembershipStrictGateService::getUserOrganizationMembershipState missing';
    }
    if (!$ref->hasMethod('assertSingleActiveMembershipForOrgTruth')) {
        $errors[] = 'UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth missing';
    }

    $gate = app($gateClass);
    $repo = app(\Modules\Organizations\Repositories\UserOrganizationMembershipReadRepository::class);

    if ($repo->isMembershipTablePresent() !== $membershipTablePresent) {
        $errors[] = 'isMembershipTablePresent() must match information_schema for user_organization_memberships';
    }

    $s0 = $gate->getUserOrganizationMembershipState(0);
    if (($s0['state'] ?? null) === \Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::STATE_TABLE_ABSENT) {
        if (($s0['active_count'] ?? -1) !== 0) {
            $errors[] = 'table_absent state must have active_count 0';
        }
    } elseif (($s0['state'] ?? null) !== \Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::STATE_NONE) {
        $errors[] = 'userId 0 expected STATE_NONE when table present';
    }

    if ($membershipTablePresent) {
        $positiveAssertSingleMembershipTruth = [
            'status' => 'skipped_no_single_sample_user',
            'sample_user_id' => null,
            'gate_organization_id' => null,
            'asserted_organization_id' => null,
        ];

        $membershipCountBefore = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM user_organization_memberships')['c'] ?? 0);

        $dry1 = app(\Modules\Organizations\Services\UserOrganizationMembershipBackfillService::class)->run(true);
        $dry2 = app(\Modules\Organizations\Services\UserOrganizationMembershipBackfillService::class)->run(true);

        if ($dry1 !== $dry2) {
            $errors[] = 'dry-run backfill counts must be deterministic across two invocations';
        }

        $sum = ($dry1['inserted'] ?? 0) + ($dry1['skipped_existing'] ?? 0) + ($dry1['skipped_ambiguous'] ?? 0)
            + ($dry1['skipped_no_branch'] ?? 0) + ($dry1['skipped_missing_branch_org'] ?? 0);
        if (($dry1['scanned'] ?? 0) !== $sum) {
            $errors[] = 'dry-run bucket sum must equal scanned';
        }

        $membershipCountAfter = (int) ($db->fetchOne('SELECT COUNT(*) AS c FROM user_organization_memberships')['c'] ?? 0);
        if ($membershipCountBefore !== $membershipCountAfter) {
            $errors[] = 'verifier must not mutate membership rows; row count changed';
        }

        try {
            $gate->assertSingleActiveMembershipForOrgTruth(0);
            $errors[] = 'assertSingleActiveMembershipForOrgTruth(0) must throw';
        } catch (RuntimeException) {
            // expected
        }

        $sampleUserRow = $db->fetchOne('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
        $sampleUserId = $sampleUserRow !== null ? (int) ($sampleUserRow['id'] ?? 0) : 0;
        if ($sampleUserId > 0) {
            $g = $gate->getUserOrganizationMembershipState($sampleUserId);
            $rawIds = [];
            $rawRows = $db->fetchAll(
                'SELECT m.organization_id AS organization_id
                 FROM user_organization_memberships m
                 INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
                 WHERE m.user_id = ? AND m.status = ?
                 ORDER BY m.organization_id ASC',
                [$sampleUserId, 'active']
            );
            foreach ($rawRows as $r) {
                $rawIds[] = (int) $r['organization_id'];
            }
            $rawCount = count($rawIds);
            $expectedState = $rawCount === 0
                ? \Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::STATE_NONE
                : ($rawCount === 1
                    ? \Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::STATE_SINGLE
                    : \Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::STATE_MULTIPLE);
            $sampleGateMatchesRawSql = true;
            if (($g['state'] ?? null) !== $expectedState) {
                $errors[] = 'gate state mismatch vs raw SQL for sample user';
                $sampleGateMatchesRawSql = false;
            }
            if (($g['organization_ids'] ?? []) !== $rawIds) {
                $errors[] = 'gate organization_ids mismatch';
                $sampleGateMatchesRawSql = false;
            }

            if (
                $sampleGateMatchesRawSql
                && ($g['state'] ?? null) === \Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::STATE_SINGLE
            ) {
                $positiveAssertSingleMembershipTruth['sample_user_id'] = $sampleUserId;
                $gateOrgId = isset($g['organization_id']) && $g['organization_id'] !== null ? (int) $g['organization_id'] : 0;
                $positiveAssertSingleMembershipTruth['gate_organization_id'] = $gateOrgId > 0 ? $gateOrgId : null;
                if ($gateOrgId <= 0) {
                    $errors[] = 'positive assert path: gate state single requires positive organization_id';
                } else {
                    try {
                        $assertedId = $gate->assertSingleActiveMembershipForOrgTruth($sampleUserId);
                        $positiveAssertSingleMembershipTruth['asserted_organization_id'] = $assertedId;
                        if ($assertedId !== $gateOrgId) {
                            $errors[] = 'assertSingleActiveMembershipForOrgTruth return must equal gate organization_id for single-state sample user';
                        } else {
                            $positiveAssertSingleMembershipTruth['status'] = 'passed';
                        }
                    } catch (RuntimeException $e) {
                        $errors[] = 'positive assert path: assertSingleActiveMembershipForOrgTruth threw unexpectedly: ' . $e->getMessage();
                    }
                }
            }
        }
    } else {
        $abs = $gate->getUserOrganizationMembershipState(1);
        if (($abs['state'] ?? null) !== \Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::STATE_TABLE_ABSENT) {
            $errors[] = 'table absent: expected STATE_TABLE_ABSENT';
        }
    }
} catch (Throwable $e) {
    if ($e->getMessage() !== 'abort') {
        $errors[] = $e->getMessage();
    }
}

$ok = $errors === [];

$payload = [
    'auditor' => 'audit_user_organization_membership_backfill_and_gate',
    'foundation_wave' => 'FOUNDATION-51',
    'checks_passed' => $ok,
    'errors' => $errors,
    'positive_assert_single_membership_truth' => $positiveAssertSingleMembershipTruth,
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "auditor: audit_user_organization_membership_backfill_and_gate\n";
    echo 'foundation_wave: FOUNDATION-51' . "\n";
    echo 'checks_passed: ' . ($ok ? 'true' : 'false') . "\n";
    $p = $positiveAssertSingleMembershipTruth;
    echo 'positive_assert_single_membership_truth.status: ' . ($p['status'] ?? 'unknown') . "\n";
    if (($p['sample_user_id'] ?? null) !== null) {
        echo 'positive_assert_single_membership_truth.sample_user_id: ' . $p['sample_user_id'] . "\n";
    }
    if (($p['gate_organization_id'] ?? null) !== null) {
        echo 'positive_assert_single_membership_truth.gate_organization_id: ' . $p['gate_organization_id'] . "\n";
    }
    if (($p['asserted_organization_id'] ?? null) !== null) {
        echo 'positive_assert_single_membership_truth.asserted_organization_id: ' . $p['asserted_organization_id'] . "\n";
    }
    foreach ($errors as $e) {
        echo "ERROR: {$e}\n";
    }
}

exit($ok ? 0 : 1);
