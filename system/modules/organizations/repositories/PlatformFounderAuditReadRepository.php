<?php

declare(strict_types=1);

namespace Modules\Organizations\Repositories;

use Core\App\Database;

/**
 * Read-only audit slice for founder control plane (access / provisioning / repairs).
 * FOUNDER-AUDIT-VISIBILITY-AND-PUBLIC-SURFACE-CONTROL-01.
 */
final class PlatformFounderAuditReadRepository
{
    /** @var list<string> */
    public const ACCESS_PLANE_ACTIONS = [
        'founder_user_activated',
        'founder_user_deactivated',
        'founder_membership_suspended',
        'founder_membership_unsuspended',
        'founder_tenant_access_repaired',
        'founder_platform_principal_roles_canonicalized',
        'founder_provision_tenant_admin',
        'founder_provision_tenant_staff',
        'founder_branch_created',
        'founder_branch_updated',
        'founder_branch_deactivated',
        'founder_public_surface_kill_switches_updated',
        'founder_support_session_start',
        'founder_support_session_end',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccessPlaneEvents(int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $actions = self::ACCESS_PLANE_ACTIONS;
        $ph = implode(', ', array_fill(0, count($actions), '?'));

        return $this->db->fetchAll(
            "SELECT a.id, a.action, a.target_type, a.target_id, a.actor_user_id, a.branch_id, a.metadata_json, a.created_at,
                    u.email AS actor_email, u.name AS actor_name
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.action IN ({$ph})
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT {$limit}",
            $actions
        );
    }
}
