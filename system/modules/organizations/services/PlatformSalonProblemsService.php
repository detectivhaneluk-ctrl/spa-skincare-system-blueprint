<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

/**
 * Human-readable salon (organization) problems for the founder control plane.
 * Avoids raw dumps; uses registry + optional access-shape hints.
 */
final class PlatformSalonProblemsService
{
    /**
     * @param array<string, mixed> $org organizations row
     * @param array<string, mixed>|null $primaryAdmin from {@see PlatformSalonRegistryReadRepository::batchPrimaryAdminForOrganizations}
     * @param array<string, mixed>|null $accessShape from {@see \Core\Auth\UserAccessShapeService} when evaluating primary admin
     * @return list<array{issue_key:string, severity:string, title:string, detail:string, action_label:?string, action_url:?string}>
     */
    public function buildProblems(
        array $org,
        int $branchCount,
        ?array $primaryAdmin,
        ?array $accessShape,
        int $organizationId
    ): array {
        $out = [];
        $id = max(0, $organizationId);
        $deleted = !empty($org['deleted_at']);
        $suspended = !$deleted && !empty($org['suspended_at']);

        if ($deleted) {
            $out[] = [
                'issue_key' => 'archived',
                'severity' => 'high',
                'title' => 'Archived',
                'detail' => 'Salon is not operational.',
                'action_label' => 'Access registry',
                'action_url' => '/platform-admin/access',
            ];
        }
        if ($suspended) {
            $out[] = [
                'issue_key' => 'suspended',
                'severity' => 'high',
                'title' => 'Suspended',
                'detail' => 'Tenant access is blocked.',
                'action_label' => 'Reactivate salon',
                'action_url' => '/platform-admin/salons/' . $id . '/reactivate-confirm',
            ];
        }
        if (!$deleted && $branchCount === 0) {
            $out[] = [
                'issue_key' => 'no_branch',
                'severity' => 'high',
                'title' => 'No branch',
                'detail' => 'Staff have no location to open.',
                'action_label' => 'Add branch',
                'action_url' => '/platform-admin/salons/' . $id . '/branches/create',
            ];
        }
        if ($primaryAdmin === null && !$deleted) {
            $out[] = [
                'issue_key' => 'no_admin',
                'severity' => 'medium',
                'title' => 'No admin access',
                'detail' => 'No primary admin is available.',
                'action_label' => 'Provision admin',
                'action_url' => '/platform-admin/access/provision',
            ];
        }
        if ($primaryAdmin !== null && !empty($primaryAdmin['deleted_at']) && !$deleted) {
            $out[] = [
                'issue_key' => 'admin_login_off',
                'severity' => 'high',
                'title' => 'Admin login off',
                'detail' => 'Primary account cannot sign in.',
                'action_label' => 'Open account',
                'action_url' => '/platform-admin/access/' . (int) ($primaryAdmin['id'] ?? 0),
            ];
        }

        if ($accessShape !== null && empty($accessShape['error'])) {
            $contradictions = $accessShape['contradictions'] ?? [];
            if (is_array($contradictions) && $contradictions !== []) {
                $out[] = [
                    'issue_key' => 'access_mismatch',
                    'severity' => 'medium',
                    'title' => 'Access needs review',
                    'detail' => 'Account binding conflicts.',
                    'action_label' => 'Review account',
                    'action_url' => '/platform-admin/access/' . (int) ($accessShape['user_id'] ?? 0),
                ];
            }
            $state = (string) ($accessShape['canonical_state'] ?? '');
            if ($state === 'tenant_orphan_blocked') {
                $out[] = [
                    'issue_key' => 'tenant_path_blocked',
                    'severity' => 'medium',
                    'title' => 'Login path incomplete',
                    'detail' => 'Finish setup in Access.',
                    'action_label' => 'Complete setup',
                    'action_url' => '/platform-admin/access/' . (int) ($accessShape['user_id'] ?? 0) . '/guided-repair',
                ];
            }
            if ($state === 'tenant_suspended_organization' && !$suspended && !$deleted) {
                $out[] = [
                    'issue_key' => 'tenant_suspended_binding',
                    'severity' => 'medium',
                    'title' => 'Login tied to suspension',
                    'detail' => 'This account still points here.',
                    'action_label' => 'Review account',
                    'action_url' => '/platform-admin/access/' . (int) ($accessShape['user_id'] ?? 0),
                ];
            }
        }

        return $out;
    }

    public function countProblems(
        array $org,
        int $branchCount,
        ?array $primaryAdmin,
        ?array $accessShape,
        int $organizationId
    ): int {
        return count($this->buildProblems($org, $branchCount, $primaryAdmin, $accessShape, $organizationId));
    }
}
