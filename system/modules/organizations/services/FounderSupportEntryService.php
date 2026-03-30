<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\App\Database;
use Core\Auth\PrincipalPlaneResolver;
use Core\Auth\SessionAuth;
use Core\Branch\TenantBranchAccessService;
use InvalidArgumentException;

/**
 * FOUNDER-IMPERSONATION-FOUNDATION-01: explicit support-entry session (founder → tenant effective user), audited start/stop.
 */
final class FounderSupportEntryService
{
    public function __construct(
        private Database $db,
        private SessionAuth $session,
        private PrincipalPlaneResolver $principalPlane,
        private TenantBranchAccessService $tenantBranchAccess,
        private FounderImpersonationAuditService $supportAudit,
    ) {
    }

    /**
     * @param array<string, mixed>|null $auditMetadata merged into support-session audit row
     *
     * @return non-empty-string correlation id for audit pairing
     *
     * HTTP entry must enforce password step-up, control-plane MFA, and safe-action reason/checkbox before calling; this service does not read POST.
     */
    public function startForFounderActor(int $founderUserId, int $tenantUserId, ?int $branchId, ?array $auditMetadata = null): string
    {
        $sessionUserId = $this->session->id();
        if ($sessionUserId !== $founderUserId) {
            throw new InvalidArgumentException('Session user does not match founder actor.');
        }
        if (!$this->principalPlane->isControlPlane($founderUserId)) {
            throw new InvalidArgumentException('Only a platform principal may start a support entry session.');
        }
        if ($this->session->isSupportEntryActive()) {
            throw new InvalidArgumentException('A support entry session is already active.');
        }
        if (!$this->principalPlane->isTenantPlane($tenantUserId)) {
            throw new InvalidArgumentException('Target user is not eligible for tenant-plane support entry.');
        }
        $row = $this->db->fetchOne(
            'SELECT id, email, name, deleted_at FROM users WHERE id = ? LIMIT 1',
            [$tenantUserId]
        );
        if ($row === null || (($row['deleted_at'] ?? null) !== null && (string) $row['deleted_at'] !== '')) {
            throw new InvalidArgumentException('Target user was not found or is inactive.');
        }
        $allowed = $this->tenantBranchAccess->allowedBranchIdsForUser($tenantUserId);
        if ($allowed === []) {
            throw new InvalidArgumentException('Target user has no usable branch access.');
        }
        $resolvedBranch = null;
        if ($branchId !== null && $branchId > 0) {
            if (!in_array($branchId, $allowed, true)) {
                throw new InvalidArgumentException('Selected branch is not allowed for this user.');
            }
            $resolvedBranch = $branchId;
        }

        $correlationId = bin2hex(random_bytes(16));
        $label = (string) ($this->db->fetchOne(
            'SELECT email FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1',
            [$founderUserId]
        )['email'] ?? '');
        if ($label === '') {
            $label = 'platform_user#' . $founderUserId;
        }

        $this->session->beginSupportEntry($founderUserId, $label, $tenantUserId, $correlationId, $resolvedBranch);
        $this->supportAudit->logSupportSessionStart($founderUserId, $tenantUserId, $resolvedBranch, $correlationId, $auditMetadata);

        return $correlationId;
    }

    public function stopActive(): void
    {
        if (!$this->session->isSupportEntryActive()) {
            throw new InvalidArgumentException('No active support entry session.');
        }
        $actor = (int) $this->session->supportActorUserId();
        $tenantId = (int) $this->session->id();
        $correlation = $this->session->supportSessionCorrelationId();
        $this->supportAudit->logSupportSessionEnd($actor, $tenantId, $correlation);
        $this->session->endSupportEntry();
    }
}
