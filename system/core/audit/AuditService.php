<?php

declare(strict_types=1);

namespace Core\Audit;

use Core\App\Application;
use Core\App\ClientIp;
use Core\App\Database;
use Core\App\RequestCorrelation;
use Core\Branch\BranchContext;

/**
 * Centralized audit logging. Columns (post-migration 125): request_id, organization_id (tenant),
 * outcome, action_category — plus legacy actor/action/target/branch/metadata.
 * Inserts fall back to the legacy column set when migration 125 is not applied.
 */
final class AuditService
{
    public function __construct(
        private Database $db,
        private BranchContext $branchContext
    ) {
    }

    /**
     * @param ?string $outcome success|failure|denied|unknown|null (null = omit on legacy insert)
     * @param ?string $actionCategory auth|booking|payments|...; null infers from $action
     */
    public function log(
        string $action,
        string $targetType,
        ?int $targetId = null,
        ?int $actorUserId = null,
        ?int $branchId = null,
        ?array $metadata = null,
        ?string $outcome = null,
        ?string $actionCategory = null,
    ): void {
        $actorUserId ??= $this->currentUserId();
        if ($branchId === null) {
            $branchId = $this->branchContext->getCurrentBranchId();
        }
        $auditIp = ClientIp::forRequest();
        $category = $actionCategory ?? $this->inferActionCategory($action);
        $orgId = $this->resolveOrganizationId($branchId);

        $base = [
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'branch_id' => $branchId,
            'ip_address' => $auditIp === '0.0.0.0' ? null : $auditIp,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null,
            'metadata_json' => $metadata ? json_encode($metadata) : null,
        ];

        $extended = array_merge($base, [
            'outcome' => $outcome,
            'action_category' => $category,
            'organization_id' => $orgId,
            'request_id' => RequestCorrelation::id(),
        ]);

        try {
            $this->db->insert('audit_logs', $extended);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (!str_contains($msg, 'outcome') && !str_contains($msg, 'organization_id') && !str_contains($msg, 'request_id') && !str_contains($msg, 'action_category')) {
                throw $e;
            }
            $this->db->insert('audit_logs', $base);
        }
    }

    private function resolveOrganizationId(?int $branchId): ?int
    {
        try {
            $c = Application::container();
            if ($c->has(\Core\Organization\OrganizationContext::class)) {
                $oid = $c->get(\Core\Organization\OrganizationContext::class)->getCurrentOrganizationId();
                if ($oid !== null && $oid > 0) {
                    return $oid;
                }
            }
        } catch (\Throwable) {
        }
        if ($branchId === null || $branchId <= 0) {
            return null;
        }
        try {
            $row = $this->db->fetchOne('SELECT organization_id FROM branches WHERE id = ?', [$branchId]);
            if ($row === null || !isset($row['organization_id']) || $row['organization_id'] === null) {
                return null;
            }
            $v = (int) $row['organization_id'];

            return $v > 0 ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function inferActionCategory(string $action): ?string
    {
        return match (true) {
            str_starts_with($action, 'login') || str_starts_with($action, 'logout') || str_contains($action, 'password') => 'auth',
            str_starts_with($action, 'appointment') || str_starts_with($action, 'series_') || str_contains($action, 'waitlist') || str_contains($action, 'blocked_slot') => 'booking',
            str_starts_with($action, 'payment') || str_starts_with($action, 'invoice') || str_starts_with($action, 'register_session') || str_contains($action, 'cash_movement') => 'payments',
            str_starts_with($action, 'intake') || str_starts_with($action, 'document') || str_starts_with($action, 'consent') => 'documents_intake',
            str_starts_with($action, 'media') => 'media',
            str_starts_with($action, 'marketing') => 'marketing',
            str_starts_with($action, 'founder') || str_starts_with($action, 'platform') || str_starts_with($action, 'support_entry') => 'platform_control',
            default => null,
        };
    }

    private function currentUserId(): ?int
    {
        $auth = Application::container()->get(\Core\Auth\SessionAuth::class);

        return $auth->auditActorUserId();
    }
}
