<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

use Core\Auth\PostLoginHomePathResolver;
use Core\Auth\PrincipalPlaneResolver;

/**
 * Maps authoritative {@see \Core\Auth\UserAccessShapeService} payloads to founder-facing copy.
 * Raw shape arrays stay intact for diagnostics surfaces.
 */
final class FounderAccessPresenter
{
    /**
     * @return list<array{value:string,label:string}>
     */
    public function accessStatusFilterOptions(): array
    {
        return [
            ['value' => '', 'label' => 'Any access status'],
            ['value' => 'deactivated', 'label' => 'Deactivated'],
            ['value' => 'founder', 'label' => 'Founder account'],
            ['value' => 'tenant_admin_or_staff_single_branch', 'label' => 'Single-branch tenant'],
            ['value' => 'tenant_multi_branch', 'label' => 'Multi-branch account'],
            ['value' => 'tenant_orphan_blocked', 'label' => 'Blocked — missing valid org/branch access'],
            ['value' => 'tenant_suspended_organization', 'label' => 'Suspended organization binding'],
        ];
    }

    public function humanizeRoleCodes(?string $csv): string
    {
        $csv = trim((string) $csv);
        if ($csv === '') {
            return '—';
        }
        $parts = array_filter(array_map('trim', explode(',', $csv)));
        $out = [];
        foreach ($parts as $code) {
            $out[] = $this->roleCodeLabel($code);
        }

        return implode(', ', $out);
    }

    private function roleCodeLabel(string $code): string
    {
        return match ($code) {
            'platform_founder' => 'Founder',
            'tenant_admin' => 'Tenant admin',
            'reception' => 'Staff (reception)',
            'staff' => 'Staff',
            default => $code,
        };
    }

    public function humanAccessStatus(array $shape): string
    {
        if (!empty($shape['error'])) {
            return 'Needs review — access data incomplete';
        }
        $canon = (string) ($shape['canonical_state'] ?? '');
        if ($canon === 'deactivated') {
            return 'Deactivated';
        }
        if ($canon === 'founder' || !empty($shape['is_platform_principal'])) {
            return 'Founder account';
        }

        return match ($canon) {
            'tenant_admin_or_staff_single_branch' => 'Tenant access — single branch',
            'tenant_multi_branch' => 'Multi-branch account',
            'tenant_orphan_blocked' => 'Blocked — missing valid organization/branch access',
            'tenant_suspended_organization' => 'Blocked — suspended organization',
            default => 'Needs review',
        };
    }

    public function humanOrganizationStatus(array $shape): string
    {
        if (!empty($shape['error'])) {
            return 'Unknown';
        }
        if (!empty($shape['is_platform_principal']) || (string) ($shape['canonical_state'] ?? '') === 'founder') {
            return '—';
        }
        if (!empty($shape['tenant_org_suspended_binding'])) {
            return 'Suspended organization';
        }
        $memberships = $shape['organization_memberships'] ?? [];
        if (!is_array($memberships) || $memberships === []) {
            return 'No organization memberships';
        }
        $active = 0;
        foreach ($memberships as $m) {
            if (!is_array($m)) {
                continue;
            }
            if (($m['status'] ?? '') === 'active') {
                $active++;
            }
        }

        return $active > 0 ? 'Active memberships (' . $active . ')' : 'No active memberships';
    }

    public function humanBranchSummary(array $shape): string
    {
        $usable = $shape['usable_branch_ids'] ?? [];
        if (!is_array($usable)) {
            return '—';
        }
        $n = count($usable);
        if ($n === 0) {
            return 'No usable branches';
        }
        if ($n === 1) {
            return 'Single branch';
        }

        return 'Multi-branch (' . $n . ' locations)';
    }

    public function humanExpectedDestination(array $shape): string
    {
        if (!empty($shape['error'])) {
            return 'Needs review';
        }
        $canon = (string) ($shape['canonical_state'] ?? '');
        if ($canon === 'deactivated') {
            return 'No destination — account deactivated';
        }
        if ($canon === 'founder' || !empty($shape['is_platform_principal'])) {
            return 'Platform control plane';
        }
        if ($canon === 'tenant_orphan_blocked' || $canon === 'tenant_suspended_organization') {
            return 'Blocked before tenant entry';
        }
        if ($canon === 'tenant_multi_branch') {
            return 'Branch chooser';
        }
        if ($canon === 'tenant_admin_or_staff_single_branch') {
            return 'Salon dashboard';
        }

        $path = (string) ($shape['expected_home_path'] ?? '');

        return match ($path) {
            PostLoginHomePathResolver::PATH_PLATFORM => 'Platform control plane',
            PostLoginHomePathResolver::PATH_TENANT_DASHBOARD => 'Salon dashboard',
            PostLoginHomePathResolver::PATH_TENANT_ENTRY => 'Branch chooser',
            '' => '—',
            default => 'Custom route',
        };
    }

    public function humanRiskAttention(array $shape): string
    {
        if (!empty($shape['error'])) {
            return 'Needs review — evaluation error';
        }
        $contr = $shape['contradictions'] ?? [];
        if (is_array($contr) && $contr !== []) {
            return 'Needs review — role or routing conflict';
        }
        $canon = (string) ($shape['canonical_state'] ?? '');
        if ($canon === 'tenant_orphan_blocked') {
            return 'Blocked access';
        }
        if ($canon === 'tenant_suspended_organization') {
            return 'Organization suspended';
        }
        $rep = $shape['suggested_repairs'] ?? [];
        if (is_array($rep) && $rep !== []) {
            return 'Repair recommended';
        }

        return '—';
    }

    /**
     * @return list<string>
     */
    public function humanRepairRecommendations(array $shape): array
    {
        $raw = $shape['suggested_repairs'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key) {
            $k = (string) $key;
            $out[] = match ($k) {
                'assign_active_organization_membership_and_consistent_branch_pin' => 'Assign valid organization and branch access (membership + branch pin).',
                'remove_ambiguous_tenant_roles_from_platform_principal_or_remove_platform_role' => 'Resolve mixed founder/tenant roles — remove extra tenant roles or remove platform founder role.',
                default => 'Repair recommended: ' . $k,
            };
        }

        return $out;
    }

    public function humanTenantEntryState(array $shape): string
    {
        if (!empty($shape['error'])) {
            return '—';
        }
        $canon = (string) ($shape['canonical_state'] ?? '');
        if ($canon === 'deactivated' || $canon === 'tenant_orphan_blocked' || $canon === 'tenant_suspended_organization') {
            return '—';
        }
        if ($canon === 'founder' || !empty($shape['is_platform_principal'])) {
            return '—';
        }
        $entry = $shape['tenant_entry_resolution'] ?? [];
        if (!is_array($entry)) {
            return '—';
        }
        $state = (string) ($entry['state'] ?? '');

        return match ($state) {
            'single' => 'Single branch resolved',
            'multi' => 'Multiple branches — choice required',
            'none' => 'No branch resolved',
            '' => '—',
            default => 'Needs review',
        };
    }

    public function humanPrincipalPlane(array $shape): string
    {
        $p = (string) ($shape['principal_plane'] ?? '');

        return match ($p) {
            PrincipalPlaneResolver::CONTROL_PLANE => 'Platform',
            PrincipalPlaneResolver::TENANT_PLANE => 'Tenant workspace',
            PrincipalPlaneResolver::BLOCKED_AUTHENTICATED => 'Blocked',
            '' => '—',
            default => 'Needs review',
        };
    }

    /**
     * Sign-in signal for access detail hero (copy only; semantics from shape).
     *
     * @return array{label: string, tone: 'neutral'|'success'|'warn'|'danger'}
     */
    public function accessDetailSignInBadge(array $shape): array
    {
        if (!empty($shape['error'])) {
            return ['label' => 'Needs review', 'tone' => 'warn'];
        }
        $contr = $shape['contradictions'] ?? [];
        if (is_array($contr) && $contr !== []) {
            return ['label' => 'Needs review', 'tone' => 'warn'];
        }
        $canon = (string) ($shape['canonical_state'] ?? '');
        if ($canon === 'deactivated') {
            return ['label' => 'Blocked', 'tone' => 'danger'];
        }
        if ($canon === 'tenant_orphan_blocked' || $canon === 'tenant_suspended_organization') {
            return ['label' => 'Blocked', 'tone' => 'danger'];
        }

        return ['label' => 'Healthy', 'tone' => 'success'];
    }

    /**
     * @return array{label: string, tone: 'neutral'|'success'|'warn'|'danger'}
     */
    public function accessDetailOrgBadge(array $shape): array
    {
        if (!empty($shape['error'])) {
            return ['label' => 'Needs review', 'tone' => 'warn'];
        }
        if (!empty($shape['is_platform_principal']) || (string) ($shape['canonical_state'] ?? '') === 'founder') {
            return ['label' => '—', 'tone' => 'neutral'];
        }
        $memberships = $shape['organization_memberships'] ?? [];
        if (!is_array($memberships) || $memberships === []) {
            return ['label' => 'None', 'tone' => 'warn'];
        }
        $susp = 0;
        $active = 0;
        $total = count($memberships);
        foreach ($memberships as $m) {
            if (!is_array($m)) {
                continue;
            }
            if (!empty($m['org_suspended'])) {
                $susp++;
            }
            if (($m['status'] ?? '') === 'active') {
                $active++;
            }
        }
        if ($susp > 0 && $susp < $total) {
            return ['label' => 'Mixed', 'tone' => 'warn'];
        }
        if (!empty($shape['tenant_org_suspended_binding']) || ($susp > 0 && $susp === $total)) {
            return ['label' => 'Suspended', 'tone' => 'danger'];
        }
        if ($active > 0) {
            return ['label' => 'Active', 'tone' => 'success'];
        }

        return ['label' => 'Needs review', 'tone' => 'warn'];
    }

    /**
     * @return array{label: string, tone: 'neutral'|'success'|'warn'|'danger'}
     */
    public function accessDetailBranchBadge(array $shape): array
    {
        if (!empty($shape['error'])) {
            return ['label' => 'Needs review', 'tone' => 'warn'];
        }
        if (!empty($shape['is_platform_principal']) || (string) ($shape['canonical_state'] ?? '') === 'founder') {
            return ['label' => '—', 'tone' => 'neutral'];
        }
        $usable = $shape['usable_branch_ids'] ?? [];
        if (!is_array($usable)) {
            return ['label' => 'Needs review', 'tone' => 'warn'];
        }
        $n = count($usable);
        if ($n === 0) {
            return ['label' => 'Missing', 'tone' => 'danger'];
        }
        if ($n > 1) {
            return ['label' => 'Multi-branch', 'tone' => 'neutral'];
        }

        return ['label' => 'Single', 'tone' => 'success'];
    }

    public function humanAuditAction(string $action): string
    {
        return match ($action) {
            'founder_user_activated' => 'User activated',
            'founder_user_deactivated' => 'User deactivated',
            'founder_membership_suspended' => 'Membership suspended',
            'founder_membership_unsuspended' => 'Membership reactivated',
            'founder_tenant_access_repaired' => 'Tenant access repaired',
            'founder_platform_principal_roles_canonicalized' => 'Founder roles canonicalized',
            'founder_provision_tenant_admin' => 'Tenant admin provisioned',
            'founder_provision_tenant_staff' => 'Staff user provisioned',
            'founder_branch_created' => 'Branch created',
            'founder_branch_updated' => 'Branch updated',
            'founder_branch_deactivated' => 'Branch deactivated',
            'founder_organization_suspended' => 'Organization suspended',
            'founder_organization_reactivated' => 'Organization reactivated',
            'founder_public_surface_kill_switches_updated' => 'Public kill switches updated',
            'founder_guided_repair_organization_reactivated' => 'Organization reactivated (guided recovery)',
            'founder_support_session_start' => 'Support entry started',
            'founder_support_session_end' => 'Support entry ended',
            default => $action,
        };
    }
}
