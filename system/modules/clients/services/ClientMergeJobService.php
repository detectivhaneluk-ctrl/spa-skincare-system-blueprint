<?php

declare(strict_types=1);

namespace Modules\Clients\Services;

use Core\App\Database;
use Core\Branch\BranchContext;
use Core\Errors\AccessDeniedException;
use Core\Errors\SafeDomainException;
use Core\Kernel\Authorization\AuthorizerInterface;
use Core\Kernel\Authorization\ResourceAction;
use Core\Kernel\Authorization\ResourceRef;
use Core\Kernel\RequestContextHolder;
use Core\Organization\OrganizationContext;
use Core\Organization\OutOfBandLifecycleGuard;
use Core\Organization\OrganizationScopedBranchAssert;
use Modules\Clients\Repositories\ClientMergeJobRepository;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Clients\Support\ClientMergeJobStalePolicy;
use Modules\Clients\Support\ClientMergeJobStatuses;

/**
 * Enqueue and execute client merge jobs (async worker path).
 *
 * Architecture note — background worker context:
 *   The async worker paths (claimAndExecuteNextMergeJob, claimAndExecuteMergeJobByRuntimeId,
 *   reconcileStaleRunningJobs) deliberately set BranchContext + OrganizationContext from stored
 *   job row data in order to establish tenant scope for background execution. This is a valid
 *   infrastructure pattern for async workers — not in-scope for the RequestContextHolder
 *   request-pipeline pattern. The enqueue path (enqueueMergeJob, getJobForCurrentTenant) uses
 *   RequestContextHolder as the canonical kernel pattern.
 */
final class ClientMergeJobService
{
    public function __construct(
        private Database $db,
        private ClientMergeJobRepository $jobRepo,
        private ClientRepository $clientRepo,
        private ClientService $clientService,
        private BranchContext $branchContext,
        private OrganizationContext $organizationContext,
        private OutOfBandLifecycleGuard $outOfBandLifecycleGuard,
        private OrganizationScopedBranchAssert $organizationScopedBranchAssert,
        private RequestContextHolder $contextHolder,
        private \Core\Runtime\Queue\RuntimeAsyncJobRepository $runtimeAsyncJobs,
        private AuthorizerInterface $authorizer,
    ) {
    }

    /**
     * @throws SafeDomainException
     * @throws AccessDeniedException
     * @throws \InvalidArgumentException
     * @throws \DomainException
     */
    public function enqueueMergeJob(int $primaryId, int $secondaryId, ?string $notes, int $requestedByUserId): int
    {
        $ctx = $this->contextHolder->requireContext();
        $scope = $ctx->requireResolvedTenant();
        $this->authorizer->requireAuthorized($ctx, ResourceAction::CLIENT_MODIFY, ResourceRef::collection('client'));
        $organizationId = $scope['organization_id'];
        $branchId = $scope['branch_id'];

        if ($primaryId <= 0 || $secondaryId <= 0 || $primaryId === $secondaryId) {
            throw new \InvalidArgumentException('Primary and secondary clients must be different valid ids.');
        }

        $primary = $this->clientRepo->find($primaryId);
        $secondary = $this->clientRepo->find($secondaryId);
        if (!$primary || !$secondary) {
            throw new SafeDomainException(
                'CLIENT_NOT_FOUND',
                'Primary or secondary client was not found.',
                'missing client row on enqueue',
                404
            );
        }

        $primaryBranchId = $primary['branch_id'] !== null && $primary['branch_id'] !== '' ? (int) $primary['branch_id'] : null;
        if ($primaryBranchId !== null && $primaryBranchId !== $branchId) {
            throw new AccessDeniedException('Primary client is not accessible in the current branch context.');
        }
        $secondaryBranchId = $secondary['branch_id'] !== null && $secondary['branch_id'] !== '' ? (int) $secondary['branch_id'] : null;
        if ($secondaryBranchId !== null && $secondaryBranchId !== $branchId) {
            throw new AccessDeniedException('Secondary client is not accessible in the current branch context.');
        }
        $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($primaryBranchId);
        $this->organizationScopedBranchAssert->assertBranchOwnedByResolvedOrganization($secondaryBranchId);

        if (!empty($secondary['merged_into_client_id'])) {
            throw new SafeDomainException(
                'SECONDARY_MERGED',
                'Secondary client is already merged.',
                'secondary merged_into_client_id set',
                422
            );
        }

        if ($this->jobRepo->existsQueuedOrRunningForPair($organizationId, $primaryId, $secondaryId)) {
            throw new SafeDomainException(
                'MERGE_JOB_PENDING',
                'A merge for this client pair is already queued or running.',
                'duplicate job',
                409
            );
        }

        $notesTrim = $notes !== null ? trim($notes) : null;
        $notesStored = $notesTrim !== null && $notesTrim !== '' ? $notesTrim : null;

        $jobId = $this->jobRepo->createJob([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'primary_client_id' => $primaryId,
            'secondary_client_id' => $secondaryId,
            'requested_by_user_id' => $requestedByUserId > 0 ? $requestedByUserId : null,
            'status' => ClientMergeJobStatuses::QUEUED,
            'current_step' => null,
            'merge_notes' => $notesStored,
        ]);
        $this->runtimeAsyncJobs->enqueue(
            \Core\Runtime\Queue\RuntimeAsyncJobWorkload::QUEUE_DEFAULT,
            \Core\Runtime\Queue\RuntimeAsyncJobWorkload::JOB_CLIENTS_MERGE_EXECUTE,
            [
                'client_merge_job_id' => $jobId,
                'schema' => \Core\Runtime\Queue\RuntimeAsyncJobWorkload::PAYLOAD_SCHEMA,
            ],
            5
        );

        return $jobId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getJobForCurrentTenant(int $jobId): ?array
    {
        if ($jobId <= 0) {
            return null;
        }
        $ctx = $this->contextHolder->requireContext();
        $scope = $ctx->requireResolvedTenant();
        $job = $this->jobRepo->findByIdForOrganization($jobId, $scope['organization_id']);
        if ($job === null) {
            return null;
        }

        return $job;
    }

    /**
     * Recover stuck {@code running} jobs (e.g. worker died after merge commit). Returns how many jobs were reconciled.
     */
    public function reconcileStaleRunningJobs(int $maxJobs = 10): int
    {
        $maxJobs = max(1, min(100, $maxJobs));
        $n = 0;
        for ($i = 0; $i < $maxJobs; $i++) {
            $did = $this->db->transaction(function (): bool {
                return $this->reconcileOneStaleRunningWithinTransaction();
            });
            if (!$did) {
                break;
            }
            ++$n;
        }

        return $n;
    }

    /**
     * Claim the oldest queued job and run merge under job scope. Safe for cron (returns false when idle).
     * Reconciles a limited number of stale running jobs first so crash/restart recovery stays honest.
     */
    public function claimAndExecuteNextMergeJob(): bool
    {
        $this->reconcileStaleRunningJobs(5);

        $job = $this->jobRepo->claimNextQueuedJob();
        if ($job === null) {
            return false;
        }
        $this->executeClaimedJob($job);

        return true;
    }

    /**
     * Runtime worker entry: claim and run a specific queued merge job when client_merge_jobs.id matches.
     * No-op when the row is not queued (already processed, failed, or running).
     */
    public function claimAndExecuteMergeJobByRuntimeId(int $clientMergeJobId): void
    {
        if ($clientMergeJobId <= 0) {
            return;
        }
        $this->reconcileStaleRunningJobs(5);
        $job = $this->jobRepo->claimSpecificQueuedJob($clientMergeJobId);
        if ($job === null) {
            return;
        }
        $this->executeClaimedJob($job);
    }

    /**
     * Must run inside {@see Database::transaction()} so {@code FOR UPDATE} applies.
     */
    private function reconcileOneStaleRunningWithinTransaction(): bool
    {
        $job = $this->jobRepo->findOldestStaleRunningForUpdate(ClientMergeJobStalePolicy::RUNNING_STALE_MINUTES);
        if ($job === null || $job === []) {
            return false;
        }
        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId <= 0) {
            return false;
        }

        $branchId = (int) ($job['branch_id'] ?? 0);
        $organizationId = (int) ($job['organization_id'] ?? 0);
        if ($branchId <= 0 || $organizationId <= 0) {
            $this->markJobFailed(
                $jobId,
                $organizationId > 0 ? $organizationId : null,
                'RECONCILE_INVALID_JOB',
                'Merge job could not be reconciled (missing scope).',
                'branch_id or organization_id missing on stale running row'
            );

            return true;
        }

        $this->branchContext->setCurrentBranchId($branchId);
        $this->organizationContext->setFromResolution($organizationId, OrganizationContext::MODE_BRANCH_DERIVED);

        try {
            /** @phpstan-ignore-next-line */
            if (!$this->branchContext->getCurrentBranchId()) {
                throw new \RuntimeException('Branch context unavailable after set');
            }
        } catch (\Throwable $e) {
            $this->markJobFailed(
                $jobId,
                $organizationId > 0 ? $organizationId : null,
                'RECONCILE_SCOPE',
                'Merge job could not be reconciled (tenant scope unavailable).',
                $e->getMessage()
            );

            return true;
        }

        $primaryId = (int) ($job['primary_client_id'] ?? 0);
        $secondaryId = (int) ($job['secondary_client_id'] ?? 0);
        if ($primaryId <= 0 || $secondaryId <= 0) {
            $this->markJobFailed(
                $jobId,
                $organizationId > 0 ? $organizationId : null,
                'RECONCILE_INVALID_IDS',
                'Merge job could not be reconciled (invalid client ids).',
                'primary or secondary id invalid'
            );

            return true;
        }

        $primary = $this->clientRepo->find($primaryId, false);
        $secondary = $this->clientRepo->find($secondaryId, true);

        if ($secondary === null) {
            $this->markJobFailed(
                $jobId,
                $organizationId > 0 ? $organizationId : null,
                'RECONCILE_AMBIGUOUS',
                'This merge job could not be recovered automatically. Please review the client records.',
                'secondary client not visible in tenant scope'
            );

            return true;
        }

        $mergedInto = (int) ($secondary['merged_into_client_id'] ?? 0);

        if ($mergedInto === $primaryId) {
            if ($primary === null) {
                $this->markJobFailed(
                    $jobId,
                    $organizationId > 0 ? $organizationId : null,
                    'RECONCILE_PRIMARY_MISSING',
                    'Merge outcome could not be confirmed (primary client missing).',
                    'secondary shows merged_into primary but primary row not found live'
                );

                return true;
            }

            $resultJson = null;
            try {
                $resultJson = json_encode([
                    'reconciled' => true,
                    'detection' => 'secondary_merged_into_primary',
                    'primary_id' => $primaryId,
                    'secondary_id' => $secondaryId,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $resultJson = null;
            }

            $this->jobRepo->updateByIdForOrganization($jobId, $organizationId, [
                'status' => ClientMergeJobStatuses::SUCCEEDED,
                'finished_at' => date('Y-m-d H:i:s'),
                'current_step' => 'reconciled_completed_merge',
                'error_code' => null,
                'error_message_public' => null,
                'error_detail_internal' => null,
                'result_json' => $resultJson,
            ]);

            return true;
        }

        if ($mergedInto === 0) {
            $del = $secondary['deleted_at'] ?? null;
            $isLive = $del === null || $del === '';
            if ($isLive) {
                $this->jobRepo->updateByIdForOrganization($jobId, $organizationId, [
                    'status' => ClientMergeJobStatuses::QUEUED,
                    'started_at' => null,
                    'current_step' => null,
                    'error_code' => null,
                    'error_message_public' => null,
                    'error_detail_internal' => null,
                ]);

                return true;
            }

            $this->markJobFailed(
                $jobId,
                $organizationId > 0 ? $organizationId : null,
                'RECONCILE_INCONSISTENT',
                'This merge job could not be recovered automatically. Please review the client records.',
                'secondary soft-deleted without merged_into_client_id'
            );

            return true;
        }

        if ($mergedInto > 0 && $mergedInto !== $primaryId) {
            $this->markJobFailed(
                $jobId,
                $organizationId > 0 ? $organizationId : null,
                'RECONCILE_MERGE_MISMATCH',
                'This merge job conflicts with existing merge data. Please review the client records.',
                'secondary merged_into_client_id=' . $mergedInto . ' expected primary=' . $primaryId
            );

            return true;
        }

        $this->markJobFailed(
            $jobId,
            $organizationId > 0 ? $organizationId : null,
            'RECONCILE_AMBIGUOUS',
            'This merge job could not be recovered automatically. Please review the client records.',
            'reconcile fallthrough; merged_into=' . $mergedInto . ' primary=' . $primaryId
        );

        return true;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function executeClaimedJob(array $job): void
    {
        $jobId = (int) ($job['id'] ?? 0);
        $job = $this->jobRepo->findByIdForWorker($jobId) ?? $job;
        $branchId = (int) ($job['branch_id'] ?? 0);
        $organizationId = (int) ($job['organization_id'] ?? 0);
        if ($jobId <= 0 || $branchId <= 0 || $organizationId <= 0) {
            $this->markJobFailed(
                $jobId,
                $organizationId > 0 ? $organizationId : null,
                'INVALID_JOB',
                'Merge job is missing organization or branch scope.',
                'invalid job row'
            );

            return;
        }

        $this->branchContext->setCurrentBranchId($branchId);
        $this->organizationContext->setFromResolution($organizationId, OrganizationContext::MODE_BRANCH_DERIVED);

        try {
            /** @phpstan-ignore-next-line */
            if (!$this->branchContext->getCurrentBranchId()) {
                throw new \RuntimeException('Branch context unavailable after set');
            }
        } catch (\Throwable $e) {
            $this->markJobFailed(
                $jobId,
                $organizationId > 0 ? $organizationId : null,
                'SCOPE_ERROR',
                'Could not resolve tenant scope for this merge job.',
                $e->getMessage()
            );

            return;
        }

        $actor = isset($job['requested_by_user_id']) && $job['requested_by_user_id'] !== null && $job['requested_by_user_id'] !== ''
            ? (int) $job['requested_by_user_id']
            : null;
        $primaryId = (int) ($job['primary_client_id'] ?? 0);
        $secondaryId = (int) ($job['secondary_client_id'] ?? 0);
        $notes = isset($job['merge_notes']) && is_string($job['merge_notes']) && $job['merge_notes'] !== ''
            ? $job['merge_notes']
            : null;

        try {
            $this->outOfBandLifecycleGuard->assertExecutionAllowedForBranch($branchId, $organizationId, $actor);
        } catch (\DomainException $e) {
            $this->markJobFailed(
                $jobId,
                $organizationId > 0 ? $organizationId : null,
                'LIFECYCLE_BLOCKED',
                'Merge job is no longer allowed for this tenant context.',
                $e->getMessage()
            );

            return;
        }

        try {
            $result = $this->clientService->mergeClientsAsActor($primaryId, $secondaryId, $notes, $actor);
            $resultJson = null;
            try {
                $resultJson = json_encode([
                    'primary_id' => $result['primary_id'],
                    'secondary_id' => $result['secondary_id'],
                    'remapped_rows' => $result['remapped_rows'],
                    'secondary_linked_counts' => $result['secondary_linked_counts'],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $resultJson = null;
            }

            $this->jobRepo->updateByIdForOrganization($jobId, $organizationId, [
                'status' => ClientMergeJobStatuses::SUCCEEDED,
                'finished_at' => date('Y-m-d H:i:s'),
                'current_step' => null,
                'error_code' => null,
                'error_message_public' => null,
                'error_detail_internal' => null,
                'result_json' => $resultJson,
            ]);
        } catch (SafeDomainException $e) {
            $this->markJobFailed($jobId, $organizationId > 0 ? $organizationId : null, $e->publicCode, $e->publicMessage, $e->getMessage());
        } catch (\Throwable $e) {
            slog('error', 'clients.merge_job.execute', $e->getMessage(), ['job_id' => $jobId]);
            $this->markJobFailed(
                $jobId,
                $organizationId > 0 ? $organizationId : null,
                'MERGE_FAILED',
                'Merge failed. Please try again or contact support.',
                $e->getMessage()
            );
        }
    }

    private function markJobFailed(int $jobId, ?int $organizationId, string $code, string $publicMessage, string $internalDetail): void
    {
        if ($jobId <= 0) {
            return;
        }
        $patch = [
            'status' => ClientMergeJobStatuses::FAILED,
            'finished_at' => date('Y-m-d H:i:s'),
            'current_step' => null,
            'error_code' => mb_substr($code, 0, 64),
            'error_message_public' => mb_substr($publicMessage, 0, 512),
            'error_detail_internal' => $internalDetail,
        ];
        if ($organizationId !== null && $organizationId > 0) {
            $this->jobRepo->updateByIdForOrganization($jobId, $organizationId, $patch);
        } else {
            $this->jobRepo->updateByIdForWorker($jobId, $patch);
        }
    }
}
