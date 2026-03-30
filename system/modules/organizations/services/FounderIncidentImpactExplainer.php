<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

/**
 * Maps Incident Center rows to clearer cause → impact → next step and root vs downstream labels.
 * Read-only copy; does not query the database. FOUNDER-OPS-IMPACT-EXPLAINER-01.
 */
final class FounderIncidentImpactExplainer
{
    public const ROLE_ROOT = 'root_cause';
    public const ROLE_DOWNSTREAM = 'downstream_effect';
    public const ROLE_INDEPENDENT = 'independent_signal';
    public const ROLE_DEPLOYMENT = 'deployment_wide';

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function enrich(array $row): array
    {
        $id = (string) ($row['id'] ?? '');
        $meta = $this->metaFor($id, $row);

        $row['problem_role'] = $meta['role'];
        $row['problem_role_label'] = $meta['label'];
        $row['cause_summary'] = $meta['cause'];
        $row['impact_line'] = $meta['impact'];
        $row['recommended_next_step'] = $meta['next'];
        $row['investigation_note'] = $meta['investigate'];

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{role:string,label:string,cause:string,impact:string,next:string,investigate:string}
     */
    private function metaFor(string $id, array $row): array
    {
        return match ($id) {
            'access_eval_errors' => [
                'role' => self::ROLE_INDEPENDENT,
                'label' => 'Needs data review',
                'cause' => 'The access-shape engine could not finish evaluation for some login rows — canonical tenant vs platform state is unknown for those accounts.',
                'impact' => 'Operators cannot rely on filters or automated guidance for those users; the wrong repair might be applied.',
                'next' => 'Open Access, use Diagnostics per failing user, fix missing or inconsistent rows, then re-check until evaluation succeeds.',
                'investigate' => 'Start with Access list; sort by Diagnostics for users reporting login issues.',
            ],
            'access_orphan_blocked' => [
                'role' => self::ROLE_ROOT,
                'label' => 'Primary — broken tenant path',
                'cause' => 'Accounts are in tenant_orphan_blocked: the directory user exists, but there is no valid membership + usable branch path into a tenant workspace.',
                'impact' => 'Affected users cannot reach the tenant dashboard or location chooser until membership and branch context align.',
                'next' => 'In Access, assign an active organization membership and a consistent branch pin (or use guided repair). This is not fixed by renaming branches or editing org display names alone.',
                'investigate' => 'Open Access with the tenant_orphan_blocked filter; pick one user and confirm membership + branch rows.',
            ],
            'access_suspended_org_binding' => [
                'role' => self::ROLE_DOWNSTREAM,
                'label' => 'Downstream — org suspension',
                'cause' => 'These users are tenant_suspended_organization because a branch pin and/or active membership ties them to an organization whose registry row is suspended. The root cause is organization policy state, not the user record alone.',
                'impact' => 'Tenant sign-in stays blocked for this binding until the organization is reactivated or memberships move to a healthy organization.',
                'next' => 'Go to Organizations: find the suspended org(s) these users bind to. Reactivate there if policy allows, or move users in Access to another org/branch. Editing branch names on Branches does not lift org suspension.',
                'investigate' => 'Organizations (suspended) first, then Access filtered to tenant_suspended_organization.',
            ],
            'access_deactivated_accounts' => [
                'role' => self::ROLE_ROOT,
                'label' => 'Primary — account off',
                'cause' => 'The user row is soft-deleted — authentication is disabled regardless of roles or memberships.',
                'impact' => 'Those accounts cannot sign in; tenant and platform routing will not run for them.',
                'next' => 'If deactivation was a mistake, activate the account from Access. Branch edits alone are not a substitute.',
                'investigate' => 'Access with deactivated filter; confirm intent before activation.',
            ],
            'access_founder_contradictions' => [
                'role' => self::ROLE_ROOT,
                'label' => 'Primary — boundary conflict',
                'cause' => 'Platform principals carry tenant-plane signals (extra roles or usable tenant branches) that contradict control-plane boundary rules.',
                'impact' => 'Routing and privilege boundaries are ambiguous until roles are canonicalized.',
                'next' => 'Open each affected user in Access; use Diagnostics, then canonicalize the platform principal or remove conflicting tenant roles deliberately.',
                'investigate' => 'Access + Diagnostics; Security audit for related actions.',
            ],
            'org_branch_suspended_orgs' => [
                'role' => self::ROLE_ROOT,
                'label' => 'Root — organization state',
                'cause' => 'The organization registry has suspended_at set — this is the policy choke point for every member branch and membership tied to that org.',
                'impact' => 'Tenant operations for that organization freeze: staff may be blocked; branch rows may still exist but are not operable under normal rules.',
                'next' => 'Review each suspended org in Organizations. If suspension was intentional, communicate before clearing memberships. If not, plan reactivation or data moves.',
                'investigate' => 'Organizations list; open Incident Center for downstream access counts.',
            ],
            'org_branch_branches_under_suspended' => [
                'role' => self::ROLE_DOWNSTREAM,
                'label' => 'Downstream — org suspension',
                'cause' => 'These are non-deleted branch rows whose owning organization is suspended. Catalog rows remain; tenant workflows for the location are blocked because of the parent org state.',
                'impact' => 'Staff and customers tied to this org cannot run normal tenant flows until the org is reactivated or users move to another org.',
                'next' => 'Fix the owning organization in Organizations first (reactivate or permanent closure). Branch edits alone do not change org suspension.',
                'investigate' => 'Organizations (suspended) → then Branches to verify counts.',
            ],
            'org_branch_deleted_org_links' => [
                'role' => self::ROLE_ROOT,
                'label' => 'Primary — integrity risk',
                'cause' => 'Active branch rows point at organizations that are soft-deleted — this violates normal registry expectations.',
                'impact' => 'Routing and reporting may break unpredictably; do not assume tenant entry works.',
                'next' => 'Inspect each branch and organization pair; align with registry rules (move branches, restore org, or retire rows) before changing access.',
                'investigate' => 'Branches list → open each affected org; use Access for user linkage.',
            ],
            'public_kill_switches' => [
                'role' => self::ROLE_DEPLOYMENT,
                'label' => 'Deployment-wide',
                'cause' => 'Deployment-wide emergency stops for anonymous/public traffic are enabled (count reflects how many switches are on).',
                'impact' => 'Anonymous/public booking and commerce paths change for the whole deployment regardless of a single tenant’s settings.',
                'next' => 'Confirm intent in Security; turn switches off when the incident is over. Do not confuse this with a single organization suspension.',
                'investigate' => 'Security page only.',
            ],
            'data_health_orphan_accounts' => [
                'role' => self::ROLE_INDEPENDENT,
                'label' => 'Data health — same as orphan',
                'cause' => 'Same signal as tenant_orphan_blocked: accounts exist in the directory without a consistent tenant entry path. Often overlaps with access_orphan_blocked.',
                'impact' => 'Operations and reporting can skew when users appear in the directory but cannot enter tenant workspaces.',
                'next' => 'Repair memberships and branch pins from Access; verify organization registry alignment.',
                'investigate' => 'Access (blocked filter); treat as access repair, not org rename.',
            ],
            'data_health_contradictions' => [
                'role' => self::ROLE_INDEPENDENT,
                'label' => 'Data health — contradictions',
                'cause' => 'Platform principals carry tenant-plane contradictions that the shape engine flags.',
                'impact' => 'Security and routing ambiguity until resolved.',
                'next' => 'Resolve in Access using diagnostics; canonicalize when intent is clear.',
                'investigate' => 'Access + Security audit.',
            ],
            default => [
                'role' => self::ROLE_INDEPENDENT,
                'label' => 'Review',
                'cause' => (string) ($row['cause_summary'] ?? ''),
                'impact' => (string) ($row['impact_line'] ?? ''),
                'next' => (string) ($row['recommended_next_step'] ?? ''),
                'investigate' => 'Use the Primary open link for this row.',
            ],
        };
    }
}

