<?php

declare(strict_types=1);

namespace Modules\Memberships\Services;

use Core\App\Application;
use Core\App\Database;
use Core\App\SettingsService;
use Core\Audit\AuditService;
use Core\Branch\BranchContext;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Memberships\Repositories\ClientMembershipRepository;
use Modules\Memberships\Repositories\MembershipBenefitUsageRepository;
use Modules\Memberships\Repositories\MembershipDefinitionRepository;
use Modules\Memberships\Support\MembershipEntitlementSnapshot;
use Modules\Notifications\Services\NotificationService;
use Modules\Notifications\Services\OutboundTransactionalNotificationService;

/**
 * Membership definitions + client_memberships: issuance, benefits, billing hooks, renewal reminders.
 * DB `status` may lag calendar truth until {@see markExpired} / lifecycle sync; {@see resolveClientMembershipLifecycleState()} reflects dates.
 * Renewal billing is invoice-driven ({@see MembershipBillingService}); no card/PSP automation in-repo.
 */
final class MembershipService
{
    public const DEFINITION_STATUSES = ['active', 'inactive'];
    /** @var list<string> */
    public const DEFINITION_BILLING_INTERVAL_UNITS = ['day', 'week', 'month', 'year'];
    public const CLIENT_MEMBERSHIP_STATUSES = ['active', 'paused', 'expired', 'cancelled'];

    /** Authoritative lifecycle labels (date + status); use {@see resolveClientMembershipLifecycleState()}. */
    public const LIFECYCLE_CANCELLED = 'cancelled';
    public const LIFECYCLE_SCHEDULED = 'scheduled';
    public const LIFECYCLE_ACTIVE = 'active';
    public const LIFECYCLE_EXPIRED = 'expired';
    public const LIFECYCLE_PAUSED = 'paused';

    public function __construct(
        private MembershipDefinitionRepository $definitions,
        private ClientMembershipRepository $clientMemberships,
        private ClientRepository $clients,
        private MembershipBenefitUsageRepository $benefitUsages,
        private Database $db,
        private AuditService $audit,
        private BranchContext $branchContext,
        private SettingsService $settings,
        private NotificationService $notifications,
        private OutboundTransactionalNotificationService $outboundTransactional,
        private MembershipBillingService $membershipBilling,
        private MembershipLifecycleService $lifecycle
    ) {
    }

    public function createDefinition(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $tenantBranch = $this->requireTenantBranchIdForDefinitionMutations();
            $data = $this->branchContext->enforceBranchOnCreate($data);
            $data['branch_id'] = $tenantBranch;
            $durationDays = (int) ($data['duration_days'] ?? 0);
            if ($durationDays <= 0) {
                throw new \DomainException('duration_days must be greater than zero.');
            }
            if (empty($data['name']) || trim((string) $data['name']) === '') {
                throw new \DomainException('Membership name is required.');
            }
            $userId = $this->currentUserId();
            $billingEnabled = !empty($data['billing_enabled']);
            $billingPatch = $this->normalizeDefinitionBillingPayload($data, $billingEnabled);
            $id = $this->definitions->create([
                'branch_id' => $tenantBranch,
                'name' => trim((string) $data['name']),
                'description' => isset($data['description']) ? trim((string) $data['description']) : null,
                'duration_days' => $durationDays,
                'price' => array_key_exists('price', $data) ? $data['price'] : null,
                'benefits_json' => isset($data['benefits_json']) ? $data['benefits_json'] : null,
                'status' => in_array($data['status'] ?? 'active', self::DEFINITION_STATUSES, true) ? ($data['status'] ?? 'active') : 'active',
                'public_online_eligible' => !empty($data['public_online_eligible']) ? 1 : 0,
                'created_by' => $userId,
                'updated_by' => $userId,
            ] + $billingPatch);
            $created = $this->definitions->findInTenantScope($id, $tenantBranch);
            $this->audit->log('membership_definition_created', 'membership_definition', $id, $userId, $created['branch_id'] ?? null, ['definition' => $created]);
            if (!empty($billingPatch['billing_enabled'])) {
                $this->audit->log('membership_definition_billing_updated', 'membership_definition', $id, $userId, $created['branch_id'] ?? null, [
                    'before' => null,
                    'after' => $this->billingFieldsSnapshot($created),
                ]);
            }
            return $id;
        }, 'membership definition create');
    }

    public function updateDefinition(int $id, array $data): void
    {
        $this->transactional(function () use ($id, $data): void {
            $tenantBranch = $this->requireTenantBranchIdForDefinitionMutations();
            $current = $this->definitions->findInTenantScope($id, $tenantBranch);
            if (!$current) {
                throw new \RuntimeException('Membership definition not found.');
            }
            $this->branchContext->assertBranchMatchOrGlobalEntity($current['branch_id'] !== null && $current['branch_id'] !== '' ? (int) $current['branch_id'] : null);
            if (array_key_exists('duration_days', $data) && (int) $data['duration_days'] <= 0) {
                throw new \DomainException('duration_days must be greater than zero.');
            }
            if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
                throw new \DomainException('Membership name is required.');
            }
            if (array_key_exists('status', $data) && !in_array((string) $data['status'], self::DEFINITION_STATUSES, true)) {
                throw new \DomainException('Invalid definition status.');
            }
            $mergedBilling = $current;
            foreach (
                [
                    'billing_enabled', 'billing_interval_unit', 'billing_interval_count',
                    'renewal_price', 'renewal_invoice_due_days', 'billing_auto_renew_enabled',
                ] as $bk
            ) {
                if (array_key_exists($bk, $data)) {
                    $mergedBilling[$bk] = $data[$bk];
                }
            }
            $be = array_key_exists('billing_enabled', $data) ? !empty($data['billing_enabled']) : !empty($current['billing_enabled']);
            $billingNorm = $this->normalizeDefinitionBillingPayload($mergedBilling, $be);
            $payload = array_merge($data, $billingNorm);
            $userId = $this->currentUserId();
            $payload['updated_by'] = $userId;
            if (isset($payload['name'])) {
                $payload['name'] = trim((string) $payload['name']);
            }
            if (array_key_exists('duration_days', $payload)) {
                $payload['duration_days'] = (int) $payload['duration_days'];
            }
            if (array_key_exists('public_online_eligible', $payload)) {
                $payload['public_online_eligible'] = !empty($payload['public_online_eligible']) ? 1 : 0;
            }
            $beforeBilling = $this->billingFieldsSnapshot($current);
            $this->definitions->updateInTenantScope($id, $tenantBranch, $payload);
            $updated = $this->definitions->findInTenantScope($id, $tenantBranch);
            $this->audit->log('membership_definition_updated', 'membership_definition', $id, $userId, $current['branch_id'] ?? null, ['before' => $current, 'after' => $updated]);
            $afterBilling = $this->billingFieldsSnapshot($updated);
            if ($beforeBilling !== $afterBilling) {
                $this->audit->log('membership_definition_billing_updated', 'membership_definition', $id, $userId, $current['branch_id'] ?? null, [
                    'before' => $beforeBilling,
                    'after' => $afterBilling,
                ]);
            }
        }, 'membership definition update');
    }

    /**
     * Staff manual assign. Branch on payload is normally set by {@see \Modules\Memberships\Controllers\ClientMembershipController}
     * (operator context or HQ inference + optional {@code assign_branch_id}) before this method runs.
     */
    public function assignToClient(array $data): int
    {
        return $this->transactional(function () use ($data): int {
            $data = $this->branchContext->enforceBranchOnCreate($data);

            return $this->assignToClientAuthoritative($data, $this->currentUserId());
        }, 'client membership assign');
    }

    /**
     * Single safe issuance authority for new client_memberships rows. Call inside an outer DB transaction when
     * concurrency matters: locks the client row, validates branch scope, rejects overlapping / in-flight rows for
     * the same client + definition + branch scope, then creates the row and billing bootstrap.
     *
     * Same creation rules as {@see assignToClient} without an outer transaction or {@see BranchContext::enforceBranchOnCreate}.
     * Caller must supply branch-safe payload (e.g. after enforceBranchOnCreate). Used for invoice-paid activation paths.
     *
     * @param array{client_id:int, membership_definition_id:int, branch_id?:?int, starts_at?:string, notes?:?string, membership_entitlement_snapshot?:array<string,mixed>} $data
     */
    public function assignToClientAuthoritative(array $data, ?int $actorUserId): int
    {
        $definitionId = (int) ($data['membership_definition_id'] ?? 0);
        $clientId = (int) ($data['client_id'] ?? 0);
        $startsAt = isset($data['starts_at']) ? (string) $data['starts_at'] : date('Y-m-d');

        if ($definitionId <= 0 || $clientId <= 0) {
            throw new \DomainException('membership_definition_id and client_id are required.');
        }

        $issuanceBranchId = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
            ? (int) $data['branch_id']
            : null;

        $clientRow = $this->clients->findForUpdate($clientId);
        if (!$clientRow) {
            throw new \DomainException('Client not found.');
        }

        $clientBranchId = isset($clientRow['branch_id']) && $clientRow['branch_id'] !== '' && $clientRow['branch_id'] !== null
            ? (int) $clientRow['branch_id']
            : null;

        $ctxBranch = $this->branchContext->getCurrentBranchId();
        if ($ctxBranch !== null) {
            if ($clientBranchId === null || $clientBranchId !== $ctxBranch) {
                $this->audit->log('client_membership_issuance_denied_branch_mismatch', 'client', $clientId, $actorUserId, $ctxBranch, [
                    'denial_reason' => 'client_not_in_branch_context',
                    'context_branch_id' => $ctxBranch,
                    'client_branch_id' => $clientBranchId,
                    'membership_definition_id' => $definitionId,
                    'issuance_branch_id' => $issuanceBranchId,
                ]);
                throw new \DomainException('Client is not in the issuance branch scope.');
            }
            if ($issuanceBranchId !== null && $issuanceBranchId !== $ctxBranch) {
                $this->audit->log('client_membership_issuance_denied_branch_mismatch', 'client', $clientId, $actorUserId, $ctxBranch, [
                    'denial_reason' => 'payload_branch_not_context',
                    'context_branch_id' => $ctxBranch,
                    'issuance_branch_id' => $issuanceBranchId,
                    'membership_definition_id' => $definitionId,
                ]);
                throw new \DomainException('Issuance branch does not match your assigned branch.');
            }
            $issuanceBranchId = $ctxBranch;
        } else {
            if ($issuanceBranchId !== null) {
                if ($clientBranchId !== $issuanceBranchId) {
                    $this->audit->log('client_membership_issuance_denied_branch_mismatch', 'client', $clientId, $actorUserId, $issuanceBranchId, [
                        'denial_reason' => 'client_branch_not_issuance_branch',
                        'client_branch_id' => $clientBranchId,
                        'issuance_branch_id' => $issuanceBranchId,
                        'membership_definition_id' => $definitionId,
                    ]);
                    throw new \DomainException('Client is not in the issuance branch scope.');
                }
            } elseif ($clientBranchId !== null) {
                $this->audit->log('client_membership_issuance_denied_branch_mismatch', 'client', $clientId, $actorUserId, null, [
                    'denial_reason' => 'global_issuance_requires_global_client',
                    'client_branch_id' => $clientBranchId,
                    'membership_definition_id' => $definitionId,
                ]);
                throw new \DomainException('Client is not in the issuance branch scope.');
            }
        }

        $snapshotIn = isset($data['membership_entitlement_snapshot']) && is_array($data['membership_entitlement_snapshot'])
            ? $data['membership_entitlement_snapshot']
            : null;

        if ($snapshotIn !== null) {
            if ((int) ($snapshotIn['membership_definition_id'] ?? 0) !== $definitionId) {
                throw new \DomainException('Membership entitlement snapshot does not match definition.');
            }
            $snapBranch = (int) ($snapshotIn['definition_branch_id'] ?? 0);
            if ($issuanceBranchId === null || $issuanceBranchId <= 0 || $snapBranch !== $issuanceBranchId) {
                throw new \DomainException('Membership entitlement snapshot is not compatible with the issuance branch.');
            }
            $durationDays = (int) ($snapshotIn['duration_days'] ?? 0);
            if ($durationDays <= 0) {
                throw new \DomainException('Invalid membership duration in entitlement snapshot.');
            }
            $defStatus = (string) ($snapshotIn['definition_status'] ?? '');
            if ($defStatus !== '' && $defStatus !== 'active') {
                throw new \DomainException('Membership definition was not active at sale time.');
            }
            $defRow = $this->definitions->findInTenantScope($definitionId, $snapBranch);
            if (!$defRow || !empty($defRow['deleted_at'])) {
                throw new \DomainException('Membership definition not found or not active.');
            }
            $entitlementSnapshot = $snapshotIn;
        } else {
            if ($issuanceBranchId !== null) {
                $definition = $this->definitions->findInTenantScope($definitionId, $issuanceBranchId);
            } else {
                $definition = null;
                $f = $this->definitions->findBranchOwnedInResolvedOrganization($definitionId);
                if ($f !== null) {
                    throw new \DomainException('Membership definition is not assignable without a branch-scoped issuance context.');
                }
            }
            if (!$definition || ($definition['status'] ?? '') !== 'active') {
                throw new \DomainException('Membership definition not found or not active.');
            }

            $defBranchId = isset($definition['branch_id']) && $definition['branch_id'] !== '' && $definition['branch_id'] !== null
                ? (int) $definition['branch_id']
                : null;
            if ($defBranchId !== null && $issuanceBranchId !== null && $defBranchId !== $issuanceBranchId) {
                $this->audit->log('client_membership_issuance_denied_branch_mismatch', 'membership_definition', $definitionId, $actorUserId, $issuanceBranchId, [
                    'denial_reason' => 'definition_branch_not_issuance_branch',
                    'definition_branch_id' => $defBranchId,
                    'issuance_branch_id' => $issuanceBranchId,
                    'client_id' => $clientId,
                ]);
                throw new \DomainException('Membership definition is not compatible with the issuance branch.');
            }
            if ($defBranchId !== null && $issuanceBranchId === null) {
                $this->audit->log('client_membership_issuance_denied_branch_mismatch', 'membership_definition', $definitionId, $actorUserId, null, [
                    'denial_reason' => 'branch_specific_definition_requires_branch_on_membership',
                    'definition_branch_id' => $defBranchId,
                    'client_id' => $clientId,
                ]);
                throw new \DomainException('Membership definition is not compatible with the issuance branch.');
            }

            $snapBranchForPayload = $defBranchId !== null && $defBranchId > 0
                ? $defBranchId
                : (int) $issuanceBranchId;
            $entitlementSnapshot = MembershipEntitlementSnapshot::fromDefinitionRow($definition, $snapBranchForPayload);
        }

        $durationForTerm = (int) ($entitlementSnapshot['duration_days'] ?? 0);
        if ($durationForTerm <= 0) {
            throw new \DomainException('Invalid membership duration.');
        }

        $starts = new \DateTimeImmutable($startsAt);
        $ends = $starts->add(new \DateInterval('P' . $durationForTerm . 'D'));
        $endsAt = $ends->format('Y-m-d');

        $overlapBranchCtx = $this->branchContext->getCurrentBranchId();
        if ($overlapBranchCtx === null || $overlapBranchCtx <= 0) {
            if ($issuanceBranchId !== null && $issuanceBranchId > 0) {
                $overlapBranchCtx = $issuanceBranchId;
            } elseif ($clientBranchId !== null && $clientBranchId > 0) {
                $overlapBranchCtx = $clientBranchId;
            }
        }
        if ($overlapBranchCtx === null || $overlapBranchCtx <= 0) {
            throw new \DomainException('Cannot resolve branch for membership overlap check.');
        }

        $blocking = $this->clientMemberships->findBlockingIssuanceRowInTenantScope(
            $clientId,
            $definitionId,
            $issuanceBranchId,
            $startsAt,
            $endsAt,
            (int) $overlapBranchCtx
        );
        if ($blocking !== null) {
            $this->audit->log('client_membership_issuance_denied_duplicate_overlap', 'client', $clientId, $actorUserId, $issuanceBranchId, [
                'denial_reason' => 'duplicate_or_overlapping_membership',
                'membership_definition_id' => $definitionId,
                'issuance_branch_id' => $issuanceBranchId,
                'blocking_client_membership_id' => (int) ($blocking['id'] ?? 0),
                'blocking_status' => (string) ($blocking['status'] ?? ''),
                'blocking_starts_at' => (string) ($blocking['starts_at'] ?? ''),
                'blocking_ends_at' => (string) ($blocking['ends_at'] ?? ''),
                'proposed_starts_at' => $startsAt,
                'proposed_ends_at' => $endsAt,
                'rule' => 'overlap_or_active_paused_reaching_proposed_start',
            ]);
            throw new \DomainException(
                'A membership for this plan already exists or overlaps the requested period for this branch.'
            );
        }

        $branchForTerms = $issuanceBranchId;
        $termsBlock = $this->settings->membershipTermsDocumentBlock($branchForTerms);
        $baseNotes = isset($data['notes']) ? trim((string) $data['notes']) : '';
        $notesOut = $baseNotes;
        if ($termsBlock !== null) {
            $notesOut = $notesOut === '' ? $termsBlock : $notesOut . "\n\n" . $termsBlock;
        }
        $notesFinal = $notesOut === '' ? null : $notesOut;

        $snapshotJson = MembershipEntitlementSnapshot::encode($entitlementSnapshot);

        $id = $this->clientMemberships->create([
            'client_id' => $clientId,
            'membership_definition_id' => $definitionId,
            'branch_id' => $issuanceBranchId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'active',
            'notes' => $notesFinal,
            'entitlement_snapshot_json' => $snapshotJson,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);
        $ctxB = $this->branchContext->getCurrentBranchId();
        $readBranch = ($ctxB !== null && $ctxB > 0) ? (int) $ctxB : null;
        if ($readBranch === null && $issuanceBranchId !== null && $issuanceBranchId > 0) {
            $readBranch = $issuanceBranchId;
        }
        if ($readBranch === null && $clientBranchId !== null && $clientBranchId > 0) {
            $readBranch = $clientBranchId;
        }
        if ($readBranch === null) {
            throw new \DomainException('Cannot resolve branch to verify created membership.');
        }
        $created = $this->clientMemberships->findInTenantScope($id, $readBranch);
        if (!$created) {
            throw new \DomainException('Created membership is not visible in the current tenant scope.');
        }
        $this->audit->log('client_membership_created', 'client_membership', $id, $actorUserId, $created['branch_id'] ?? null, ['client_membership' => $created]);
        $this->membershipBilling->initializeAfterAssign($id, $readBranch);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findClientMembership(int $id, ?int $branchContextId = null): ?array
    {
        if ($branchContextId !== null && $branchContextId > 0) {
            return $this->clientMemberships->findInTenantScope($id, $branchContextId);
        }
        $b = $this->branchContext->getCurrentBranchId();
        if ($b !== null && $b > 0) {
            $scoped = $this->clientMemberships->findInTenantScope($id, $b);
            if ($scoped !== null) {
                return $scoped;
            }
        }

        return $this->clientMemberships->findInResolvedTenantScope($id);
    }

    public function cancelClientMembership(int $id, ?string $notes = null): void
    {
        $this->lifecycle->cancelNow($id, null, $notes);
    }

    /**
     * Mark active client memberships as expired only after calendar end date + branch-effective grace_period_days.
     * Safe to call from cron or manually; idempotent. Benefit redemption remains calendar-bound (see {@see isUsableForBenefits()}).
     */
    public function markExpired(?int $branchId = null): int
    {
        return $this->lifecycle->runExpiryPass($branchId);
    }

    /**
     * Whether a membership is still in a post-sale access window: not cancelled, started, and today <= ends_at + grace (branch-effective).
     * Uses {@see getMembershipSettings()} for grace_period_days. Grace does **not** extend benefit redemption —
     * use {@see isUsableForBenefits()} / {@see resolveClientMembershipLifecycleState()} for appointment claims.
     */
    public function isAccessible(array $clientMembership, ?int $branchId = null): bool
    {
        if (($clientMembership['status'] ?? '') === 'cancelled') {
            return false;
        }
        $status = (string) ($clientMembership['status'] ?? '');
        if ($status === 'paused') {
            return false;
        }
        if ($status !== 'active' && $status !== 'expired') {
            return false;
        }
        $endsAt = trim((string) ($clientMembership['ends_at'] ?? ''));
        if ($endsAt === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsAt) !== 1) {
            return false;
        }
        $resolvedBranch = $branchId;
        if ($resolvedBranch === null && isset($clientMembership['branch_id']) && $clientMembership['branch_id'] !== '' && $clientMembership['branch_id'] !== null) {
            $resolvedBranch = (int) $clientMembership['branch_id'];
        }
        $graceDays = max(0, (int) ($this->settings->getMembershipSettings($resolvedBranch)['grace_period_days'] ?? 0));
        try {
            $graceEnd = (new \DateTimeImmutable($endsAt))->add(new \DateInterval('P' . $graceDays . 'D'));
        } catch (\Throwable) {
            return false;
        }
        $today = new \DateTimeImmutable('today');
        if ($today > $graceEnd) {
            return false;
        }
        $starts = trim((string) ($clientMembership['starts_at'] ?? ''));
        if ($starts !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts) === 1) {
            try {
                if ($today < new \DateTimeImmutable($starts)) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * Effective lifecycle from dates + row status (ignores grace — not a benefit entitlement).
     * When `status` is still `active` but `ends_at` is past, this returns expired even if cron has not yet updated the row.
     *
     * @param array<string, mixed> $row client_memberships row (+ optional definition_status for strict checks)
     */
    public function resolveClientMembershipLifecycleState(array $row): string
    {
        $status = (string) ($row['status'] ?? '');
        if ($status === 'cancelled') {
            return self::LIFECYCLE_CANCELLED;
        }
        if ($status === 'paused') {
            return self::LIFECYCLE_PAUSED;
        }
        if ($status === 'expired') {
            return self::LIFECYCLE_EXPIRED;
        }
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $starts = (string) ($row['starts_at'] ?? '');
        $ends = (string) ($row['ends_at'] ?? '');
        if ($ends !== '' && $today > $ends) {
            return self::LIFECYCLE_EXPIRED;
        }
        if ($starts !== '' && $today < $starts) {
            return self::LIFECYCLE_SCHEDULED;
        }
        if ($status === 'active') {
            return self::LIFECYCLE_ACTIVE;
        }

        return self::LIFECYCLE_EXPIRED;
    }

    /**
     * True when membership may redeem a covered benefit on appointment date (not grace-only access).
     * Delegates to {@see MembershipBenefitEntitlementEvaluator} after {@see resolveClientMembershipLifecycleState()}.
     *
     * @param array<string, mixed> $clientMembership client_memberships row plus definition_status / definition_deleted_at when joined
     */
    public function isUsableForBenefits(array $clientMembership, string $onDateYmd): bool
    {
        $life = $this->resolveClientMembershipLifecycleState($clientMembership);

        return MembershipBenefitEntitlementEvaluator::isEligibleForBenefitUseOnDate(
            $life,
            isset($clientMembership['definition_status']) ? (string) $clientMembership['definition_status'] : null,
            $clientMembership['definition_deleted_at'] ?? null,
            (string) ($clientMembership['starts_at'] ?? ''),
            (string) ($clientMembership['ends_at'] ?? ''),
            $onDateYmd
        );
    }

    /**
     * Record one membership benefit use for an appointment (transactional; caller must hold DB transaction).
     * Idempotent per appointment_id (unique key). Safe failure messages for internal callers only.
     *
     * @throws \DomainException when membership cannot be used
     */
    public function consumeBenefitForAppointment(
        int $clientMembershipId,
        int $appointmentId,
        int $clientId,
        ?int $appointmentBranchId,
        string $appointmentStartAt
    ): void {
        $pin = $appointmentBranchId !== null && $appointmentBranchId > 0
            ? $appointmentBranchId
            : $this->branchContext->getCurrentBranchId();
        if ($pin === null || $pin <= 0) {
            throw new \DomainException('Branch context is required to redeem membership benefits.');
        }
        $row = $this->clientMemberships->lockWithDefinitionInTenantScope($clientMembershipId, $pin);
        if (!$row) {
            throw new \DomainException('Membership is not valid for this appointment.');
        }

        $this->branchContext->assertBranchMatchOrGlobalEntity(
            $row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null
        );

        if ((int) ($row['client_id'] ?? 0) !== $clientId) {
            throw new \DomainException('Membership is not valid for this appointment.');
        }

        $mb = (int) ($row['branch_id'] ?? 0);
        $ab = $appointmentBranchId !== null && $appointmentBranchId !== 0 ? $appointmentBranchId : null;
        if ($mb > 0 && $ab !== null && $mb !== $ab) {
            throw new \DomainException('Membership is not valid for this appointment.');
        }

        $startTs = strtotime($appointmentStartAt);
        if ($startTs === false) {
            throw new \DomainException('Membership is not valid for this appointment.');
        }
        $onDate = date('Y-m-d', $startTs);

        if (!$this->isUsableForBenefits($row, $onDate)) {
            throw new \DomainException('Membership is not valid for this appointment.');
        }

        $cap = $this->parseIncludedVisitsCap($row['definition_benefits_json'] ?? $row['benefits_json'] ?? null);
        if ($cap !== null) {
            $used = $this->benefitUsages->countForClientMembership($clientMembershipId);
            if ($used >= $cap) {
                throw new \DomainException('Membership visit allowance is exhausted.');
            }
        }

        try {
            $this->benefitUsages->insert([
                'client_membership_id' => $clientMembershipId,
                'appointment_id' => $appointmentId,
                'client_id' => $clientId,
                'branch_id' => $appointmentBranchId,
                'created_by' => $this->currentUserId(),
            ]);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return;
            }
            throw $e;
        }

        $branchId = $row['branch_id'] !== null && $row['branch_id'] !== '' ? (int) $row['branch_id'] : null;
        $this->audit->log('membership_usage_consumed', 'client_membership', $clientMembershipId, $this->currentUserId(), $branchId, [
            'client_id' => $clientId,
            'appointment_id' => $appointmentId,
            'membership_definition_id' => (int) ($row['membership_definition_id'] ?? 0),
        ]);
    }

    /**
     * Removes membership benefit usage for an appointment (same DB transaction as cancellation).
     * Idempotent when no usage row exists. Table has no reversal columns; delete restores visit cap counts.
     *
     * @param array<string, mixed> $lockedAppointment Cancel path `appointments` row (`FOR UPDATE` or equivalent shape)
     */
    public function releaseBenefitUsageForCancelledAppointment(int $appointmentId, array $lockedAppointment): void
    {
        $usage = $this->benefitUsages->lockByAppointmentId($appointmentId);
        if ($usage === null) {
            return;
        }

        $apptClient = (int) ($lockedAppointment['client_id'] ?? 0);
        if ($apptClient <= 0 || (int) ($usage['client_id'] ?? 0) !== $apptClient) {
            throw new \DomainException('Membership benefit usage does not match this appointment.');
        }

        $ub = $usage['branch_id'] ?? null;
        $ab = $lockedAppointment['branch_id'] ?? null;
        $ubInt = ($ub !== null && $ub !== '') ? (int) $ub : null;
        $abInt = ($ab !== null && $ab !== '') ? (int) $ab : null;
        if ($ubInt !== null && $ubInt > 0 && $abInt !== null && $abInt > 0 && $ubInt !== $abInt) {
            throw new \DomainException('Membership benefit usage does not match this appointment branch.');
        }

        $deleted = $this->benefitUsages->deleteByAppointmentId($appointmentId);
        if ($deleted !== 1) {
            throw new \RuntimeException('Failed to release membership benefit usage for appointment.');
        }

        $cmId = (int) ($usage['client_membership_id'] ?? 0);
        $auditBranch = $ubInt !== null && $ubInt > 0 ? $ubInt : ($abInt !== null && $abInt > 0 ? $abInt : null);
        $this->audit->log('membership_usage_released', 'client_membership', $cmId, $this->currentUserId(), $auditBranch, [
            'client_id' => $apptClient,
            'appointment_id' => $appointmentId,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeDefinitionBillingPayload(array $data, bool $billingEnabled): array
    {
        $dueDays = array_key_exists('renewal_invoice_due_days', $data)
            ? max(0, (int) $data['renewal_invoice_due_days'])
            : 14;
        $autoRenew = array_key_exists('billing_auto_renew_enabled', $data)
            ? (!empty($data['billing_auto_renew_enabled']) ? 1 : 0)
            : 1;

        if (!$billingEnabled) {
            return [
                'billing_enabled' => 0,
                'billing_interval_unit' => null,
                'billing_interval_count' => null,
                'renewal_price' => null,
                'renewal_invoice_due_days' => $dueDays,
                'billing_auto_renew_enabled' => $autoRenew,
            ];
        }

        $unit = isset($data['billing_interval_unit']) ? trim((string) $data['billing_interval_unit']) : '';
        if (!in_array($unit, self::DEFINITION_BILLING_INTERVAL_UNITS, true)) {
            throw new \DomainException('Billing interval unit must be one of: day, week, month, year.');
        }
        $intervalCount = (int) ($data['billing_interval_count'] ?? 0);
        if ($intervalCount < 1) {
            throw new \DomainException('Billing interval count must be at least 1.');
        }

        $out = [
            'billing_enabled' => 1,
            'billing_interval_unit' => $unit,
            'billing_interval_count' => $intervalCount,
            'renewal_price' => null,
            'renewal_invoice_due_days' => $dueDays,
            'billing_auto_renew_enabled' => $autoRenew,
        ];
        if (array_key_exists('renewal_price', $data) && $data['renewal_price'] !== null && $data['renewal_price'] !== '') {
            $rp = round((float) $data['renewal_price'], 2);
            if (!is_finite($rp)) {
                throw new \DomainException('Renewal price is invalid.');
            }
            if ($rp < 0) {
                throw new \DomainException('Renewal price cannot be negative.');
            }
            $out['renewal_price'] = $rp;
        }
        $renewal = $out['renewal_price'];
        $price = isset($data['price']) && $data['price'] !== null && $data['price'] !== '' ? (float) $data['price'] : 0.0;
        if (!is_finite($price)) {
            throw new \DomainException('Price is invalid.');
        }
        if (($renewal === null || $renewal <= 0) && $price <= 0) {
            throw new \DomainException('Billing-enabled memberships require a positive renewal_price or price.');
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row membership_definitions row
     * @return array<string, int|float|string|null>
     */
    private function billingFieldsSnapshot(array $row): array
    {
        $rp = $row['renewal_price'] ?? null;
        $rpOut = $rp !== null && $rp !== '' ? round((float) $rp, 2) : null;

        return [
            'billing_enabled' => !empty($row['billing_enabled']) ? 1 : 0,
            'billing_interval_unit' => isset($row['billing_interval_unit']) && $row['billing_interval_unit'] !== ''
                ? (string) $row['billing_interval_unit']
                : null,
            'billing_interval_count' => isset($row['billing_interval_count']) && $row['billing_interval_count'] !== '' && $row['billing_interval_count'] !== null
                ? (int) $row['billing_interval_count']
                : null,
            'renewal_price' => $rpOut,
            'renewal_invoice_due_days' => (int) ($row['renewal_invoice_due_days'] ?? 14),
            'billing_auto_renew_enabled' => !empty($row['billing_auto_renew_enabled']) ? 1 : 0,
        ];
    }

    private function parseIncludedVisitsCap(mixed $benefitsJson): ?int
    {
        if ($benefitsJson === null || $benefitsJson === '') {
            return null;
        }
        if (is_string($benefitsJson)) {
            $decoded = json_decode($benefitsJson, true);
        } elseif (is_array($benefitsJson)) {
            $decoded = $benefitsJson;
        } else {
            return null;
        }
        if (!is_array($decoded) || !array_key_exists('included_visits', $decoded)) {
            return null;
        }
        $v = (int) $decoded['included_visits'];

        return $v > 0 ? $v : null;
    }

    /**
     * Eligible memberships (by branch renewal_reminder_days): customer email is queued via outbound first; staff in-app notice is separate.
     * Uses {@see ClientMembershipRepository::listActiveNonExpiredForRenewalScanGlobalOps} — cross-tenant cron scan (scheduled jobs only).
     * `created` counts in-app rows only. Outbound counters reflect queue/idempotency — not inbox delivery.
     *
     * @return array{
     *   scanned:int,
     *   eligible:int,
     *   created:int,
     *   skipped_duplicate:int,
     *   skipped_disabled_or_zero_reminder:int,
     *   skipped_notifications_disabled:int,
     *   outreach_pending_enqueued:int,
     *   outreach_duplicate_ignored:int,
     *   outreach_skipped:int,
     *   outreach_failed:int
     * }
     */
    public function dispatchRenewalReminders(): array
    {
        $today = new \DateTimeImmutable('today');
        $rows = $this->clientMemberships->listActiveNonExpiredForRenewalScanGlobalOps();
        $stats = [
            'scanned' => count($rows),
            'eligible' => 0,
            'created' => 0,
            'skipped_duplicate' => 0,
            'skipped_disabled_or_zero_reminder' => 0,
            'skipped_notifications_disabled' => 0,
            'outreach_pending_enqueued' => 0,
            'outreach_duplicate_ignored' => 0,
            'outreach_skipped' => 0,
            'outreach_failed' => 0,
        ];

        foreach ($rows as $row) {
            $branchId = isset($row['branch_id']) && $row['branch_id'] !== '' && $row['branch_id'] !== null
                ? (int) $row['branch_id']
                : null;
            $settings = $this->settings->getMembershipSettings($branchId);
            $reminderDays = max(0, (int) ($settings['renewal_reminder_days'] ?? 0));
            if ($reminderDays <= 0) {
                ++$stats['skipped_disabled_or_zero_reminder'];
                continue;
            }

            $endsAtRaw = (string) ($row['ends_at'] ?? '');
            if ($endsAtRaw === '') {
                continue;
            }
            $endsAt = new \DateTimeImmutable($endsAtRaw);
            if ($endsAt < $today) {
                continue;
            }

            $daysUntilExpiry = (int) $today->diff($endsAt)->format('%a');
            if ($daysUntilExpiry !== $reminderDays) {
                continue;
            }

            ++$stats['eligible'];
            $membershipId = (int) $row['id'];
            $outreach = $this->safeEnqueueMembershipRenewalReminder($membershipId, $row);
            match ($outreach['outcome']) {
                'pending_enqueued' => ++$stats['outreach_pending_enqueued'],
                'duplicate_ignored' => ++$stats['outreach_duplicate_ignored'],
                'enqueue_exception' => ++$stats['outreach_failed'],
                default => ++$stats['outreach_skipped'],
            };

            $title = sprintf('Membership renewal reminder (%s)', $endsAt->format('Y-m-d'));
            if ($this->notifications->existsByTypeEntityAndTitle('membership_renewal_reminder', 'client_membership', $membershipId, $title)) {
                ++$stats['skipped_duplicate'];
                continue;
            }

            $clientName = trim(((string) ($row['client_first_name'] ?? '')) . ' ' . ((string) ($row['client_last_name'] ?? '')));
            $planName = trim((string) ($row['definition_name'] ?? 'Membership'));
            $baseMessage = sprintf(
                '%s membership "%s" expires on %s.',
                $clientName !== '' ? $clientName . '\'s' : 'Client',
                $planName !== '' ? $planName : 'Membership',
                $endsAt->format('Y-m-d')
            );
            $message = $baseMessage . ' ' . $this->staffNoteForMembershipRenewalOutreach($outreach);

            try {
                $nid = $this->notifications->create([
                    'branch_id' => $branchId,
                    'user_id' => null,
                    'type' => 'membership_renewal_reminder',
                    'title' => $title,
                    'message' => $message,
                    'entity_type' => 'client_membership',
                    'entity_id' => $membershipId,
                ]);
                if ($nid > 0) {
                    ++$stats['created'];
                } else {
                    ++$stats['skipped_notifications_disabled'];
                }
            } catch (\Throwable $notifEx) {
                slog('warning', 'notifications.membership_renewal_reminder', $notifEx->getMessage(), [
                    'client_membership_id' => $membershipId,
                    'branch_id' => $branchId,
                ]);
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $row
     * @param array{outcome?: string, detail?: string|null} $result
     */
    private function recordMembershipRenewalOutreach(int $clientMembershipId, array $row, array $result): void
    {
        $branchId = isset($row['branch_id']) && $row['branch_id'] !== '' && $row['branch_id'] !== null
            ? (int) $row['branch_id']
            : null;
        $this->audit->log('membership_renewal_reminder_outreach_recorded', 'client_membership', $clientMembershipId, $this->currentUserId(), $branchId, [
            'outcome' => $result['outcome'] ?? 'unknown',
            'detail' => $result['detail'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{outcome: string, detail?: string|null}
     */
    private function safeEnqueueMembershipRenewalReminder(int $clientMembershipId, array $row): array
    {
        try {
            $result = $this->outboundTransactional->enqueueMembershipRenewalReminder($clientMembershipId, $row);
            $this->recordMembershipRenewalOutreach($clientMembershipId, $row, $result);

            return $result;
        } catch (\Throwable $e) {
            $branchId = isset($row['branch_id']) && $row['branch_id'] !== '' && $row['branch_id'] !== null
                ? (int) $row['branch_id']
                : null;
            $this->audit->log('membership_renewal_reminder_outreach_failed', 'client_membership', $clientMembershipId, $this->currentUserId(), $branchId, [
                'error' => $e->getMessage(),
            ]);
            slog('error', 'outbound.membership.renewal_reminder', $e->getMessage(), [
                'client_membership_id' => $clientMembershipId,
                'branch_id' => $branchId,
            ]);

            return ['outcome' => 'enqueue_exception', 'detail' => $e->getMessage()];
        }
    }

    /**
     * @param array{outcome?: string, detail?: string|null} $outreach
     */
    private function staffNoteForMembershipRenewalOutreach(array $outreach): string
    {
        $o = (string) ($outreach['outcome'] ?? 'unknown');

        return match ($o) {
            'pending_enqueued' => 'Customer reminder email queued for outbound worker (not proof of delivery).',
            'duplicate_ignored' => 'Outbound idempotency: no new queue row for this membership + end date.',
            'outbound_event_gated' => 'Outbound membership.renewal_reminder disabled by settings — no customer email queued.',
            'skipped_no_client_id' => 'No client on membership — customer email not queued.',
            'skipped_client_not_found' => 'Client record missing — customer email not queued.',
            'skipped_no_client_email' => 'No valid customer email — outbound skipped row recorded.',
            'skipped_template_error' => 'Template render failed — outbound skipped row recorded.',
            'enqueue_exception' => 'Outbound enqueue error: ' . trim((string) ($outreach['detail'] ?? '')),
            default => 'Outbound outreach outcome: ' . $o,
        };
    }

    private function transactional(callable $fn, string $label): mixed
    {
        $pdo = $this->db->connection();
        $started = false;
        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $started = true;
            }
            $result = $fn();
            if ($started) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function requireTenantBranchIdForDefinitionMutations(): int
    {
        $branchId = $this->branchContext->getCurrentBranchId();
        if ($branchId === null || $branchId <= 0) {
            throw new \DomainException('Tenant branch context is required for membership definition operations.');
        }

        return $branchId;
    }

    private function currentUserId(): ?int
    {
        $user = Application::container()->get(\Core\Auth\AuthService::class)->user();
        return $user['id'] ?? null;
    }
}
