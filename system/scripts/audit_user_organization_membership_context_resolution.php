<?php

declare(strict_types=1);

/**
 * FOUNDATION-46 — read-only verifier: membership read service contract + resolver wiring + context mode constant.
 * FOUNDATION-57 — resolver ctor must include {@see UserOrganizationMembershipStrictGateService} (reflection check).
 * FOUNDATION-54 — second in-tree non-HTTP consumer of {@see UserOrganizationMembershipStrictGateService::assertSingleActiveMembershipForOrgTruth}
 * (optional positive path when table present, same sample user, read-service vs raw SQL parity, gate state `single`).
 *
 * Proves:
 * - Table present: service matches raw SQL (F-46 contract).
 * - Table absent: repository/service returns safe empty answers (no fatal); legacy resolver path can run.
 *
 * Usage (from `system/`):
 *   php scripts/audit_user_organization_membership_context_resolution.php
 *   php scripts/audit_user_organization_membership_context_resolution.php --json
 *
 * Exit codes:
 *   0 — checks passed (membership table present or absent; branch-specific contract)
 *   1 — no database selected, contract mismatch, reflection failure, or unsafe reads when table absent
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

    $ref = new ReflectionClass(\Core\Organization\OrganizationContext::class);
    if (!$ref->hasConstant('MODE_MEMBERSHIP_SINGLE_ACTIVE')) {
        $errors[] = 'OrganizationContext::MODE_MEMBERSHIP_SINGLE_ACTIVE missing';
    }

    $resolverRef = new ReflectionClass(\Core\Organization\OrganizationContextResolver::class);
    $ctor = $resolverRef->getConstructor();
    if ($ctor === null || $ctor->getNumberOfParameters() < 4) {
        $errors[] = 'OrganizationContextResolver must accept Database + AuthService + UserOrganizationMembershipReadService + UserOrganizationMembershipStrictGateService';
    }

    $svc = app(\Modules\Organizations\Services\UserOrganizationMembershipReadService::class);

    if ($svc->countActiveMembershipsForUser(0) !== 0) {
        $errors[] = 'countActiveMembershipsForUser(0) expected 0';
    }
    if ($svc->listActiveOrganizationIdsForUser(0) !== []) {
        $errors[] = 'listActiveOrganizationIdsForUser(0) expected []';
    }
    if ($svc->getSingleActiveOrganizationIdForUser(0) !== null) {
        $errors[] = 'getSingleActiveOrganizationIdForUser(0) expected null';
    }

    $userRow = $db->fetchOne('SELECT id FROM users WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1');
    $sampleUserId = $userRow !== null ? (int) ($userRow['id'] ?? 0) : 0;

    if (!$membershipTablePresent) {
        if ($sampleUserId > 0) {
            try {
                $c = $svc->countActiveMembershipsForUser($sampleUserId);
                $ids = $svc->listActiveOrganizationIdsForUser($sampleUserId);
                $single = $svc->getSingleActiveOrganizationIdForUser($sampleUserId);
            } catch (Throwable $e) {
                $errors[] = 'membership read must not throw when table absent: ' . $e->getMessage();
                throw new RuntimeException('abort');
            }
            if ($c !== 0) {
                $errors[] = 'table absent: countActiveMembershipsForUser expected 0, got ' . $c;
            }
            if ($ids !== []) {
                $errors[] = 'table absent: listActiveOrganizationIdsForUser expected []';
            }
            if ($single !== null) {
                $errors[] = 'table absent: getSingleActiveOrganizationIdForUser expected null';
            }
        }
    } else {
        $positiveAssertSingleMembershipTruth = [
            'status' => 'skipped_no_sample_user',
            'sample_user_id' => null,
            'gate_organization_id' => null,
            'asserted_organization_id' => null,
        ];

        $rawCount = 0;
        $rawIds = [];
        if ($sampleUserId > 0) {
            $rawCount = (int) ($db->fetchOne(
                'SELECT COUNT(*) AS c
                 FROM user_organization_memberships m
                 INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
                 WHERE m.user_id = ? AND m.status = ?',
                [$sampleUserId, 'active']
            )['c'] ?? 0);

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
        }

        $svcCount = $svc->countActiveMembershipsForUser($sampleUserId);
        $svcIds = $svc->listActiveOrganizationIdsForUser($sampleUserId);
        $svcSingle = $svc->getSingleActiveOrganizationIdForUser($sampleUserId);

        $expectedSingle = ($sampleUserId > 0 && count($rawIds) === 1) ? $rawIds[0] : null;

        if ($sampleUserId > 0 && $svcCount !== $rawCount) {
            $errors[] = "count mismatch: service={$svcCount} raw={$rawCount} for user {$sampleUserId}";
        }
        if ($sampleUserId > 0 && $svcIds !== $rawIds) {
            $errors[] = 'listActiveOrganizationIdsForUser mismatch vs raw SQL';
        }
        if ($sampleUserId > 0 && $svcSingle !== $expectedSingle) {
            $errors[] = 'getSingleActiveOrganizationIdForUser mismatch vs raw-derived expectation';
        }

        $readParityOk = $sampleUserId > 0
            && $svcCount === $rawCount
            && $svcIds === $rawIds
            && $svcSingle === $expectedSingle;

        if ($sampleUserId > 0) {
            $positiveAssertSingleMembershipTruth['sample_user_id'] = $sampleUserId;
        }

        if ($sampleUserId <= 0) {
            // status remains skipped_no_sample_user
        } elseif (!$readParityOk) {
            $positiveAssertSingleMembershipTruth['status'] = 'skipped_sample_readparity_mismatch';
        } else {
            $gate = app(\Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::class);
            $g = $gate->getUserOrganizationMembershipState($sampleUserId);
            if (($g['state'] ?? null) !== \Modules\Organizations\Services\UserOrganizationMembershipStrictGateService::STATE_SINGLE) {
                $positiveAssertSingleMembershipTruth['status'] = 'skipped_gate_state_not_single';
            } else {
                $gateOrgId = isset($g['organization_id']) && $g['organization_id'] !== null ? (int) $g['organization_id'] : 0;
                $positiveAssertSingleMembershipTruth['gate_organization_id'] = $gateOrgId > 0 ? $gateOrgId : null;
                if ($gateOrgId <= 0) {
                    $errors[] = 'F-54 assert path: gate state single requires positive organization_id';
                } else {
                    try {
                        $assertedId = $gate->assertSingleActiveMembershipForOrgTruth($sampleUserId);
                        $positiveAssertSingleMembershipTruth['asserted_organization_id'] = $assertedId;
                        if ($assertedId !== $gateOrgId) {
                            $errors[] = 'assertSingleActiveMembershipForOrgTruth return must equal gate organization_id for context-resolution sample user';
                        } else {
                            $positiveAssertSingleMembershipTruth['status'] = 'passed';
                        }
                    } catch (RuntimeException $e) {
                        $errors[] = 'F-54 assert path: assertSingleActiveMembershipForOrgTruth threw unexpectedly: ' . $e->getMessage();
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    if ($e->getMessage() !== 'abort') {
        $errors[] = $e->getMessage();
    }
}

$ok = $errors === [];

$payload = [
    'auditor' => 'audit_user_organization_membership_context_resolution',
    'foundation_wave' => 'FOUNDATION-54',
    'membership_table_present' => $membershipTablePresent ?? null,
    'checks_passed' => $ok,
    'errors' => $errors,
    'positive_assert_single_membership_truth' => $positiveAssertSingleMembershipTruth,
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "auditor: audit_user_organization_membership_context_resolution\n";
    echo 'foundation_wave: FOUNDATION-54' . "\n";
    echo 'membership_table_present: ' . (isset($membershipTablePresent) ? ($membershipTablePresent ? 'true' : 'false') : 'unknown') . "\n";
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
