<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\Audit\AuditService;

/**
 * Audit hooks for founder support-entry sessions ({@see FounderSupportEntryService}).
 */
final class FounderImpersonationAuditService
{
    public function __construct(private AuditService $audit)
    {
    }

    /**
     * @param array<string, mixed>|null $metadataExtra merged into audit metadata (operator_reason, effect_summary, etc.)
     */
    public function logSupportSessionStart(int $actorFounderUserId, int $contextTenantUserId, ?int $contextBranchId, ?string $correlationId = null, ?array $metadataExtra = null): void
    {
        $meta = [
            'context_tenant_user_id' => $contextTenantUserId,
            'context_branch_id' => $contextBranchId,
        ];
        if ($correlationId !== null && $correlationId !== '') {
            $meta['support_session_correlation_id'] = $correlationId;
        }
        if ($metadataExtra !== null && $metadataExtra !== []) {
            $meta = array_merge($meta, $metadataExtra);
        }
        $this->audit->log('founder_support_session_start', 'user', $contextTenantUserId, $actorFounderUserId, $contextBranchId, $meta);
    }

    public function logSupportSessionEnd(int $actorFounderUserId, int $contextTenantUserId, ?string $correlationId = null): void
    {
        $meta = [
            'context_tenant_user_id' => $contextTenantUserId,
        ];
        if ($correlationId !== null && $correlationId !== '') {
            $meta['support_session_correlation_id'] = $correlationId;
        }
        $this->audit->log('founder_support_session_end', 'user', $contextTenantUserId, $actorFounderUserId, null, $meta);
    }
}
