<?php

declare(strict_types=1);

namespace Modules\Memberships\Services;

use Core\App\Application;
use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Core\Organization\OrganizationRepositoryScope;
use Modules\Memberships\Repositories\ClientMembershipRepository;

/**
 * Single authority for client_membership lifecycle transitions (cancel, schedule cancel, pause, resume, expiry finalization).
 * Billing rule while paused: no auto-renew scheduling ({@code next_billing_at} cleared, {@code billing_auto_renew_enabled}=0) until {@see resumeNow} restores via {@see MembershipBillingService::initializeAfterAssign} when not scheduled to cancel.
 * Terminal date transitions rely on {@see runExpiryPass} (cron/script); DB `status` can remain active/paused past `ends_at` until that pass runs.
 */
final class MembershipLifecycleService
{
    public function __construct(
        private Database $db,
        private ClientMembershipRepository $clientMemberships,
        private AuditService $audit,
        private BranchContext $branchContext,
        private OrganizationRepositoryScope $orgScope,
        private SettingsService $settings,
        private MembershipBillingService $membershipBilling
    ) {
    }

    /**
     * Immediate cancel: no access, no future renewal. Idempotent if already cancelled/expired.
     */
    public function cancelNow(int $clientMembershipId, ?string $reason = null, ?string $notes = null): void
    {
        $this->transactional(function () use ($clientMembershipId, $reason, $notes): void {
            $branchCtx = $this->requireTenantBranchId();
            $current = $this->clientMemberships->findForUpdateInTenantScope($clientMembershipId, $branchCtx);
            if (!$current) {
                throw new \RuntimeException('Client membership not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($this->branchIdFromRow($current));
            $st = (string) ($current['status'] ?? '');
            if ($st === 'cancelled' || $st === 'expired') {
                return;
            }
            if ($st !== 'active' && $st !== 'paused') {
                throw new \DomainException('Membership cannot be cancelled in its current state.');
            }
            $userId = $this->currentUserId();
            $patch = [
                'status' => 'cancelled',
                'cancel_at_period_end' => 0,
                'cancelled_at' => date('Y-m-d H:i:s'),
                'paused_at' => null,
                'notes' => $notes !== null ? trim($notes) : $current['notes'],
                'updated_by' => $userId,
                'billing_auto_renew_enabled' => 0,
                'next_billing_at' => null,
                'billing_state' => 'inactive',
                'lifecycle_reason' => $reason !== null ? trim($reason) : $current['lifecycle_reason'] ?? null,
            ];
            $this->clientMemberships->updateInTenantScope($clientMembershipId, $patch, $branchCtx);
            $after = $this->clientMemberships->findInTenantScope($clientMembershipId, $branchCtx);
            $this->audit->log('membership_cancelled', 'client_membership', $clientMembershipId, $userId, $this->branchIdFromRow($current), [
                'before' => $current,
                'after' => $after,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Keep access until earned term end; stop future renewal billing. Idempotent if already scheduled or not active.
     */
    public function scheduleCancellationAtPeriodEnd(int $clientMembershipId, ?string $reason = null): void
    {
        $this->transactional(function () use ($clientMembershipId, $reason): void {
            $branchCtx = $this->requireTenantBranchId();
            $current = $this->clientMemberships->findForUpdateInTenantScope($clientMembershipId, $branchCtx);
            if (!$current) {
                throw new \RuntimeException('Client membership not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($this->branchIdFromRow($current));
            if ((string) ($current['status'] ?? '') !== 'active') {
                throw new \DomainException('Only active memberships can schedule cancellation at period end.');
            }
            if (!empty($current['cancel_at_period_end'])) {
                return;
            }
            $userId = $this->currentUserId();
            $this->clientMemberships->updateInTenantScope($clientMembershipId, [
                'cancel_at_period_end' => 1,
                'billing_auto_renew_enabled' => 0,
                'next_billing_at' => null,
                'billing_state' => 'inactive',
                'lifecycle_reason' => $reason !== null ? trim($reason) : $current['lifecycle_reason'] ?? null,
                'updated_by' => $userId,
            ], $branchCtx);
            $after = $this->clientMemberships->findInTenantScope($clientMembershipId, $branchCtx);
            $this->audit->log('membership_cancellation_scheduled', 'client_membership', $clientMembershipId, $userId, $this->branchIdFromRow($current), [
                'after' => $after,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Undo schedule-at-end; restore renewal scheduling when definition billing still applies. Idempotent.
     */
    public function revokeScheduledCancellation(int $clientMembershipId, ?string $reason = null): void
    {
        $this->transactional(function () use ($clientMembershipId, $reason): void {
            $branchCtx = $this->requireTenantBranchId();
            $current = $this->clientMemberships->findForUpdateInTenantScope($clientMembershipId, $branchCtx);
            if (!$current) {
                throw new \RuntimeException('Client membership not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($this->branchIdFromRow($current));
            if (empty($current['cancel_at_period_end'])) {
                return;
            }
            if ((string) ($current['status'] ?? '') !== 'active') {
                throw new \DomainException('Cannot revoke scheduled cancellation unless membership is active.');
            }
            $userId = $this->currentUserId();
            $this->clientMemberships->updateInTenantScope($clientMembershipId, [
                'cancel_at_period_end' => 0,
                'lifecycle_reason' => $reason !== null ? trim($reason) : $current['lifecycle_reason'] ?? null,
                'updated_by' => $userId,
            ], $branchCtx);
            $this->membershipBilling->initializeAfterAssign($clientMembershipId, $branchCtx);
            $after = $this->clientMemberships->findInTenantScope($clientMembershipId, $branchCtx);
            $this->audit->log('membership_cancellation_revoked', 'client_membership', $clientMembershipId, $userId, $this->branchIdFromRow($current), [
                'after' => $after,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Pause: block access/benefits; stop auto-renew scheduling (no new invoices) until resume.
     */
    public function pauseNow(int $clientMembershipId, ?string $reason = null): void
    {
        $this->transactional(function () use ($clientMembershipId, $reason): void {
            $branchCtx = $this->requireTenantBranchId();
            $current = $this->clientMemberships->findForUpdateInTenantScope($clientMembershipId, $branchCtx);
            if (!$current) {
                throw new \RuntimeException('Client membership not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($this->branchIdFromRow($current));
            if ((string) ($current['status'] ?? '') === 'paused') {
                return;
            }
            if ((string) ($current['status'] ?? '') !== 'active') {
                throw new \DomainException('Only active memberships can be paused.');
            }
            $userId = $this->currentUserId();
            $this->clientMemberships->updateInTenantScope($clientMembershipId, [
                'status' => 'paused',
                'paused_at' => date('Y-m-d H:i:s'),
                'billing_auto_renew_enabled' => 0,
                'next_billing_at' => null,
                'billing_state' => 'inactive',
                'lifecycle_reason' => $reason !== null ? trim($reason) : $current['lifecycle_reason'] ?? null,
                'updated_by' => $userId,
            ], $branchCtx);
            $after = $this->clientMemberships->findInTenantScope($clientMembershipId, $branchCtx);
            $this->audit->log('membership_paused', 'client_membership', $clientMembershipId, $userId, $this->branchIdFromRow($current), [
                'after' => $after,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Resume from paused; restore access when dates allow; re-init billing if not scheduled to cancel at period end.
     */
    public function resumeNow(int $clientMembershipId, ?string $reason = null): void
    {
        $this->transactional(function () use ($clientMembershipId, $reason): void {
            $branchCtx = $this->requireTenantBranchId();
            $current = $this->clientMemberships->findForUpdateInTenantScope($clientMembershipId, $branchCtx);
            if (!$current) {
                throw new \RuntimeException('Client membership not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($this->branchIdFromRow($current));
            $st = (string) ($current['status'] ?? '');
            if ($st === 'active' && empty($current['paused_at'])) {
                return;
            }
            if ($st !== 'paused') {
                throw new \DomainException('Only paused memberships can be resumed.');
            }
            $userId = $this->currentUserId();
            $this->clientMemberships->updateInTenantScope($clientMembershipId, [
                'status' => 'active',
                'paused_at' => null,
                'lifecycle_reason' => $reason !== null ? trim($reason) : $current['lifecycle_reason'] ?? null,
                'updated_by' => $userId,
            ], $branchCtx);
            if (empty($current['cancel_at_period_end'])) {
                $this->membershipBilling->initializeAfterAssign($clientMembershipId, $branchCtx);
            }
            $after = $this->clientMemberships->findInTenantScope($clientMembershipId, $branchCtx);
            $this->audit->log('membership_resumed', 'client_membership', $clientMembershipId, $userId, $this->branchIdFromRow($current), [
                'after' => $after,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Finalize terminal states after calendar end + grace: scheduled cancel → cancelled; otherwise → expired.
     * Safe to run from cron; idempotent.
     * Candidates come from {@see ClientMembershipRepository::listExpiryTerminalCandidatesForGlobalCron} (org/branch-anchored listing;
     * each row locked with {@see ClientMembershipRepository::findForUpdateInTenantScope} — no wide unscoped id-only pass).
     *
     * @return int rows updated
     */
    public function runExpiryPass(?int $branchId = null): int
    {
        $candidates = $this->clientMemberships->listExpiryTerminalCandidatesForGlobalCron($branchId);
        $today = new \DateTimeImmutable('today');
        $updatedBy = $this->currentUserId();
        $n = 0;
        foreach ($candidates as $row) {
            $ends = trim((string) ($row['ends_at'] ?? ''));
            if ($ends === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends) !== 1) {
                continue;
            }
            $lockBranch = (int) ($row['lock_branch_id'] ?? 0);
            if ($lockBranch <= 0) {
                continue;
            }
            $graceDays = max(0, (int) ($this->settings->getMembershipSettings($lockBranch)['grace_period_days'] ?? 0));
            try {
                $graceEnd = (new \DateTimeImmutable($ends))->add(new \DateInterval('P' . $graceDays . 'D'));
            } catch (\Throwable) {
                continue;
            }
            if ($today <= $graceEnd) {
                continue;
            }
            $id = (int) $row['id'];
            try {
                $applied = false;
                $this->transactional(function () use ($id, $updatedBy, &$applied, $lockBranch): void {
                    $cm = $this->clientMemberships->findForUpdateInTenantScope($id, $lockBranch);
                    if (!$cm) {
                        return;
                    }
                    if (!in_array((string) ($cm['status'] ?? ''), ['active', 'paused'], true)) {
                        return;
                    }
                    $endsCheck = trim((string) ($cm['ends_at'] ?? ''));
                    if ($endsCheck === '' || $endsCheck >= (new \DateTimeImmutable('today'))->format('Y-m-d')) {
                        return;
                    }
                    $bidInner = $this->branchIdFromRow($cm);
                    $graceDaysInner = max(0, (int) ($this->settings->getMembershipSettings($bidInner)['grace_period_days'] ?? 0));
                    try {
                        $graceEndInner = (new \DateTimeImmutable($endsCheck))->add(new \DateInterval('P' . $graceDaysInner . 'D'));
                    } catch (\Throwable) {
                        return;
                    }
                    if ((new \DateTimeImmutable('today')) <= $graceEndInner) {
                        return;
                    }
                    $wasScheduled = !empty($cm['cancel_at_period_end']);
                    if ($wasScheduled) {
                        $this->updateClientMembershipAfterExpiryLock($id, [
                            'status' => 'cancelled',
                            'cancel_at_period_end' => 0,
                            'cancelled_at' => date('Y-m-d H:i:s'),
                            'paused_at' => null,
                            'billing_auto_renew_enabled' => 0,
                            'next_billing_at' => null,
                            'billing_state' => 'inactive',
                            'updated_by' => $updatedBy,
                        ], $cm);
                    } else {
                        $this->updateClientMembershipAfterExpiryLock($id, [
                            'status' => 'expired',
                            'paused_at' => null,
                            'updated_by' => $updatedBy,
                        ], $cm);
                    }
                    $after = $this->clientMemberships->findInTenantScope($id, $lockBranch);
                    $this->audit->log('membership_lifecycle_synced', 'client_membership', $id, $updatedBy, $bidInner, [
                        'transition' => $wasScheduled ? 'scheduled_cancel_to_cancelled' : 'term_end_to_expired',
                        'after' => $after,
                    ]);
                    $applied = true;
                });
                if ($applied) {
                    ++$n;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $n;
    }

    /**
     * Single-membership repair: same terminal rules as {@see runExpiryPass} (idempotent).
     */
    public function syncLifecycleFromCanonicalTruth(int $clientMembershipId): void
    {
        $updatedBy = $this->currentUserId();
        try {
            $this->transactional(function () use ($clientMembershipId, $updatedBy): void {
                $ctxPin = $this->branchContext->getCurrentBranchId();
                $ctxPin = $ctxPin !== null && $ctxPin > 0 ? (int) $ctxPin : null;
                $cm = $ctxPin !== null
                    ? $this->clientMemberships->findForUpdateInTenantScope($clientMembershipId, $ctxPin)
                    : ($this->orgScope->isBranchDerivedResolvedOrganizationContext()
                        ? $this->clientMemberships->findForUpdateInResolvedTenantScope($clientMembershipId)
                        : $this->clientMemberships->findForUpdateForRepair($clientMembershipId));
                if (!$cm) {
                    return;
                }
                if (!in_array((string) ($cm['status'] ?? ''), ['active', 'paused'], true)) {
                    return;
                }
                $endsCheck = trim((string) ($cm['ends_at'] ?? ''));
                if ($endsCheck === '' || $endsCheck >= (new \DateTimeImmutable('today'))->format('Y-m-d')) {
                    return;
                }
                $bidInner = $this->branchIdFromRow($cm);
                $graceDaysInner = max(0, (int) ($this->settings->getMembershipSettings($bidInner)['grace_period_days'] ?? 0));
                try {
                    $graceEndInner = (new \DateTimeImmutable($endsCheck))->add(new \DateInterval('P' . $graceDaysInner . 'D'));
                } catch (\Throwable) {
                    return;
                }
                if ((new \DateTimeImmutable('today')) <= $graceEndInner) {
                    return;
                }
                $wasScheduled = !empty($cm['cancel_at_period_end']);
                if ($wasScheduled) {
                    $this->updateClientMembershipAfterExpiryLock($clientMembershipId, [
                        'status' => 'cancelled',
                        'cancel_at_period_end' => 0,
                        'cancelled_at' => date('Y-m-d H:i:s'),
                        'paused_at' => null,
                        'billing_auto_renew_enabled' => 0,
                        'next_billing_at' => null,
                        'billing_state' => 'inactive',
                        'updated_by' => $updatedBy,
                    ], $cm);
                } else {
                    $this->updateClientMembershipAfterExpiryLock($clientMembershipId, [
                        'status' => 'expired',
                        'paused_at' => null,
                        'updated_by' => $updatedBy,
                    ], $cm);
                }
                $after = ($bidInner !== null && $bidInner > 0)
                    ? $this->clientMemberships->findInTenantScope($clientMembershipId, $bidInner)
                    : ($ctxPin !== null
                        ? $this->clientMemberships->findInTenantScope($clientMembershipId, $ctxPin)
                        : ($this->orgScope->isBranchDerivedResolvedOrganizationContext()
                            ? $this->clientMemberships->findInResolvedTenantScope($clientMembershipId)
                            : $this->clientMemberships->findForRepair($clientMembershipId)));
                $this->audit->log('membership_lifecycle_synced', 'client_membership', $clientMembershipId, $updatedBy, $bidInner, [
                    'transition' => $wasScheduled ? 'sync_scheduled_cancel_terminal' : 'sync_term_end_expired',
                    'after' => $after,
                ]);
            });
        } catch (\Throwable) {
            // ignore single-row repair failures
        }
    }

    /**
     * After {@see findForUpdateInTenantScope} or repair {@see ClientMembershipRepository::findForUpdate}: apply patch with org predicate when a pin resolves.
     *
     * @param array<string, mixed> $cm locked row
     * @param array<string, mixed> $patch normalized columns
     */
    private function updateClientMembershipAfterExpiryLock(int $id, array $patch, array $cm): void
    {
        $pin = $this->resolveBranchPinForMembershipMutation($cm);
        if ($pin !== null && $pin > 0) {
            $this->clientMemberships->updateInTenantScope($id, $patch, $pin);

            return;
        }
        $this->clientMemberships->updateForRepairById($id, $patch);
    }

    /** @param array<string, mixed> $cm */
    private function resolveBranchPinForMembershipMutation(array $cm): ?int
    {
        $bid = $this->branchIdFromRow($cm);
        if ($bid !== null && $bid > 0) {
            return $bid;
        }
        $any = $this->orgScope->getAnyLiveBranchIdForResolvedTenantOrganization();

        return ($any !== null && $any > 0) ? $any : null;
    }

    /** @param array<string, mixed> $row */
    private function branchIdFromRow(array $row): ?int
    {
        if (!isset($row['branch_id']) || $row['branch_id'] === '' || $row['branch_id'] === null) {
            return null;
        }

        return (int) $row['branch_id'];
    }

    private function requireTenantBranchId(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for membership lifecycle operations.');
        }

        return $branchId;
    }

    private function transactional(callable $fn): void
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $fn();
            if ($started) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($started) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function currentUserId(): ?int
    {
        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();

        return isset($user['id']) ? (int) $user['id'] : null;
    }
}
