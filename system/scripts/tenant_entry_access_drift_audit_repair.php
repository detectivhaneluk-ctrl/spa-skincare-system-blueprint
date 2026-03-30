<?php

declare(strict_types=1);

/**
 * TENANT-ENTRY-ACCESS-CANONICALIZATION:
 * audit + optional safe repair for tenant-entry access drift.
 *
 * Usage (from `system/`):
 *   php scripts/tenant_entry_access_drift_audit_repair.php
 *   php scripts/tenant_entry_access_drift_audit_repair.php --apply-safe
 *   php scripts/tenant_entry_access_drift_audit_repair.php --json
 *   php scripts/tenant_entry_access_drift_audit_repair.php --json --apply-safe
 */

$systemPath = dirname(__DIR__);
require $systemPath . '/bootstrap.php';
require $systemPath . '/modules/bootstrap.php';

$args = array_slice($argv, 1);
$applySafe = in_array('--apply-safe', $args, true);
$jsonOut = in_array('--json', $args, true);

try {
    $db = app(\Core\App\Database::class);
    $branchAccess = app(\Core\Branch\TenantBranchAccessService::class);
    $principalAccess = app(\Core\Auth\PrincipalAccessService::class);

    $membershipTable = $db->fetchOne(
        'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
        ['user_organization_memberships']
    );
    if ($membershipTable === null) {
        fwrite(STDERR, "ERROR: user_organization_memberships table not present.\n");
        exit(1);
    }

    $users = $db->fetchAll(
        'SELECT u.id, u.email, u.branch_id
         FROM users u
         WHERE u.deleted_at IS NULL
         ORDER BY u.id ASC'
    );

    $stats = [
        'ACCESS_OK' => 0,
        'SAFE_MEMBERSHIP_BACKFILL' => 0,
        'MANUAL_REVIEW_REQUIRED' => 0,
        'INTENTIONAL_BLOCKED' => 0,
    ];
    $applied = 0;
    $rows = [];

    foreach ($users as $u) {
        $userId = (int) ($u['id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        $email = (string) ($u['email'] ?? '');
        $pinnedBranchId = isset($u['branch_id']) && $u['branch_id'] !== null ? (int) $u['branch_id'] : null;
        $isPlatformPrincipal = $principalAccess->isPlatformPrincipal($userId);

        $allowedBranchIds = $branchAccess->allowedBranchIdsForUser($userId);
        $activeMembershipRows = $db->fetchAll(
            'SELECT m.organization_id, m.status, m.default_branch_id
             FROM user_organization_memberships m
             INNER JOIN organizations o ON o.id = m.organization_id AND o.deleted_at IS NULL
             WHERE m.user_id = ?
             ORDER BY m.organization_id ASC',
            [$userId]
        );
        $allMembershipRows = $db->fetchAll(
            'SELECT organization_id, status, default_branch_id
             FROM user_organization_memberships
             WHERE user_id = ?
             ORDER BY organization_id ASC',
            [$userId]
        );

        $activeMembershipOrgIds = [];
        foreach ($activeMembershipRows as $m) {
            $orgId = isset($m['organization_id']) ? (int) $m['organization_id'] : 0;
            if ($orgId > 0) {
                $activeMembershipOrgIds[] = $orgId;
            }
        }
        $activeMembershipOrgIds = array_values(array_unique($activeMembershipOrgIds));

        $pinnedOrgId = null;
        if ($pinnedBranchId !== null && $pinnedBranchId > 0) {
            $branchRow = $db->fetchOne(
                'SELECT b.organization_id
                 FROM branches b
                 INNER JOIN organizations o ON o.id = b.organization_id AND o.deleted_at IS NULL
                 WHERE b.id = ? AND b.deleted_at IS NULL
                 LIMIT 1',
                [$pinnedBranchId]
            );
            if ($branchRow !== null && isset($branchRow['organization_id'])) {
                $oid = (int) $branchRow['organization_id'];
                $pinnedOrgId = $oid > 0 ? $oid : null;
            }
        }

        $classification = 'MANUAL_REVIEW_REQUIRED';
        $reason = 'membership-aware contradiction';
        $canSafeBackfill = false;
        $safeBackfillOrganizationId = null;

        if ($isPlatformPrincipal) {
            $classification = 'INTENTIONAL_BLOCKED';
            $reason = 'platform principal excluded from tenant-plane membership repair';
        } elseif ($pinnedBranchId === null || $pinnedBranchId <= 0) {
            if ($allowedBranchIds === []) {
                $classification = 'INTENTIONAL_BLOCKED';
                $reason = 'no pinned branch and no active tenant membership';
            } else {
                $classification = 'ACCESS_OK';
                $reason = 'membership-only tenant access';
            }
        } elseif ($pinnedOrgId === null) {
            $classification = 'MANUAL_REVIEW_REQUIRED';
            $reason = 'pinned branch missing or branch organization not live';
        } elseif (in_array($pinnedBranchId, $allowedBranchIds, true)) {
            $classification = 'ACCESS_OK';
            $reason = 'pinned branch is membership-authorized';
        } else {
            $hasConflictingMembershipState = false;
            foreach ($allMembershipRows as $m) {
                $orgId = isset($m['organization_id']) ? (int) $m['organization_id'] : 0;
                $status = (string) ($m['status'] ?? '');
                if ($orgId <= 0) {
                    continue;
                }
                if ($orgId !== $pinnedOrgId || $status !== 'active') {
                    $hasConflictingMembershipState = true;
                    break;
                }
            }

            $deterministicNoMembership = count($allMembershipRows) === 0;
            if (!$isPlatformPrincipal && !$hasConflictingMembershipState && $deterministicNoMembership) {
                $classification = 'SAFE_MEMBERSHIP_BACKFILL';
                $reason = 'pinned branch deterministic with zero membership rows';
                $canSafeBackfill = true;
                $safeBackfillOrganizationId = $pinnedOrgId;
            } elseif ($allowedBranchIds === [] && $activeMembershipOrgIds === []) {
                $classification = 'INTENTIONAL_BLOCKED';
                $reason = 'tenant user currently blocked by membership policy';
            } else {
                $classification = 'MANUAL_REVIEW_REQUIRED';
                $reason = 'ambiguous or conflicting membership state';
            }
        }

        if ($applySafe && $canSafeBackfill && $safeBackfillOrganizationId !== null) {
            $db->query(
                'INSERT INTO user_organization_memberships (user_id, organization_id, status, default_branch_id)
                 VALUES (?, ?, ?, ?)',
                [$userId, $safeBackfillOrganizationId, 'active', $pinnedBranchId]
            );
            $applied++;
            $classification = 'ACCESS_OK';
            $reason = 'safe membership backfill applied';
            $allowedBranchIds = $branchAccess->allowedBranchIdsForUser($userId);
        }

        $stats[$classification]++;
        $rows[] = [
            'user_id' => $userId,
            'email' => $email,
            'is_platform_principal' => $isPlatformPrincipal,
            'pinned_branch_id' => $pinnedBranchId,
            'pinned_organization_id' => $pinnedOrgId,
            'active_membership_organization_ids' => $activeMembershipOrgIds,
            'allowed_branch_ids' => $allowedBranchIds,
            'classification' => $classification,
            'reason' => $reason,
        ];
    }

    $report = [
        'wave' => 'TENANT-ENTRY-ACCESS-CANONICALIZATION',
        'apply_safe' => $applySafe,
        'safe_backfills_applied' => $applied,
        'scanned_users' => count($rows),
        'classification_counts' => $stats,
        'users' => $rows,
    ];

    if ($jsonOut) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    echo 'wave: ' . $report['wave'] . PHP_EOL;
    echo 'apply_safe: ' . ($applySafe ? 'true' : 'false') . PHP_EOL;
    echo 'safe_backfills_applied: ' . $applied . PHP_EOL;
    echo 'scanned_users: ' . $report['scanned_users'] . PHP_EOL;
    foreach ($stats as $class => $count) {
        echo 'count_' . strtolower($class) . ': ' . $count . PHP_EOL;
    }
    echo PHP_EOL;
    foreach ($rows as $r) {
        echo '[' . $r['classification'] . '] '
            . 'user_id=' . $r['user_id']
            . ' email=' . $r['email']
            . ' pinned_branch_id=' . ($r['pinned_branch_id'] ?? 'null')
            . ' pinned_organization_id=' . ($r['pinned_organization_id'] ?? 'null')
            . ' allowed_branch_ids=' . json_encode($r['allowed_branch_ids'])
            . ' active_membership_org_ids=' . json_encode($r['active_membership_organization_ids'])
            . ' reason="' . $r['reason'] . '"'
            . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

exit(0);
