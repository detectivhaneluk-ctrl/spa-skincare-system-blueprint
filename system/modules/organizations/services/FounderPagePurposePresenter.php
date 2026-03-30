<?php

declare(strict_types=1);

namespace Modules\Organizations\Services;

/**
 * Human-readable page purpose for founder control plane — copy only, no domain logic.
 *
 * @phpstan-type PurposePage array{
 *   panel_title: string,
 *   what_for: string,
 *   when_use: list<string>,
 *   when_not: list<string>,
 *   next_best: list<array{label: string, href: string}>,
 *   wrong_page_hint?: string
 * }
 */
final class FounderPagePurposePresenter
{
    /**
     * @return PurposePage
     */
    public function forPage(string $key): array
    {
        return match ($key) {
            'dashboard' => [
                'panel_title' => 'This page',
                'what_for' => 'Overview and quick routing across the platform — a snapshot of access signals, kill-switch state, and shortcuts into each module.',
                'when_use' => [
                    'You want a platform snapshot or the best place to start triage.',
                    'You need links to Incidents, Access, Organizations, Branches, or Security.',
                ],
                'when_not' => [
                    'Deep repair work — open the right module after you know the root cause.',
                    'Deployment-wide public emergency blocks alone — confirm on Security when needed.',
                ],
                'next_best' => [
                    ['label' => 'Incident Center', 'href' => '/platform-admin/incidents'],
                    ['label' => 'Operator guide', 'href' => '/platform-admin/guide'],
                ],
            ],
            'incidents' => [
                'panel_title' => 'Incident Center',
                'what_for' => 'Diagnose and route operational problems — what is wrong, how severe it is, and the first place to look. Safe actions happen in other modules.',
                'when_use' => [
                    'You need to understand what is broken and which module owns the next step.',
                    'You want a filtered list of active signals with severity and affected scale.',
                ],
                'when_not' => [
                    'Final repairs by themselves — open Access, Organizations, Branches, or Security to apply changes.',
                    'Guessing fixes without reading “First place to look” and “Primary open”.',
                ],
                'next_best' => [
                    ['label' => 'Access', 'href' => '/platform-admin/access'],
                    ['label' => 'Organizations', 'href' => '/platform-admin/salons'],
                    ['label' => 'Branches', 'href' => '/platform-admin/branches'],
                    ['label' => 'Security', 'href' => '/platform-admin/security'],
                ],
                'wrong_page_hint' => 'If you already know the user account, go straight to Access; if the whole company is suspended, review organization lifecycle first.',
            ],
            'access' => [
                'panel_title' => 'Access',
                'what_for' => 'Sign-in and access problems for people — scan accounts, open a user, then run repairs, provisioning, or diagnostics on the user page.',
                'when_use' => [
                    'The problem belongs to a person or login account.',
                    'You need to provision tenant admin/reception or review membership and branch access.',
                ],
                'when_not' => [
                    'Tenant lifecycle root cause when the organization is suspended — review Organizations first.',
                    'Location catalog naming — use Branches when metadata is the issue.',
                ],
                'next_best' => [
                    ['label' => 'Organizations', 'href' => '/platform-admin/salons'],
                    ['label' => 'Incident Center', 'href' => '/platform-admin/incidents'],
                ],
            ],
            'access_detail' => [
                'panel_title' => 'User access (detail)',
                'what_for' => 'Actions for one account — repairs, guided repair, activation, membership, support entry, and diagnostics.',
                'when_use' => [
                    'You are ready to change this user’s access or follow the safest next step for their shape.',
                ],
                'when_not' => [
                    'Assuming branch or name edits fix organization suspension — repair here may be incomplete until tenant/company lifecycle is reviewed.',
                ],
                'next_best' => [
                    ['label' => 'Organizations', 'href' => '/platform-admin/salons'],
                    ['label' => 'Incident Center', 'href' => '/platform-admin/incidents'],
                ],
                'wrong_page_hint' => 'If the root cause is organization suspension, review organization state; affected users may still need follow-up in Access after reactivation.',
            ],
            'access_provision' => [
                'panel_title' => 'Provision users',
                'what_for' => 'Create tenant admin or reception accounts through the controlled provisioning flow.',
                'when_use' => [
                    'You need a new staff login tied to an organization and branch.',
                ],
                'when_not' => [
                    'Fixing a broken existing account — open that user in Access instead.',
                    'Changing org suspension — use Organizations.',
                ],
                'next_best' => [
                    ['label' => 'Access list', 'href' => '/platform-admin/access'],
                    ['label' => 'Organizations', 'href' => '/platform-admin/salons'],
                ],
            ],
            'branches' => [
                'panel_title' => 'Branches',
                'what_for' => 'Location and branch catalog — scan branches, open one to edit metadata or ownership context.',
                'when_use' => [
                    'Branch catalog or branch metadata needs review.',
                    'You need to create a branch or inspect rows under an organization.',
                ],
                'when_not' => [
                    'First-line fix for login issues caused by organization suspension — review tenant/company lifecycle first.',
                ],
                'next_best' => [
                    ['label' => 'Organizations', 'href' => '/platform-admin/salons'],
                    ['label' => 'Access', 'href' => '/platform-admin/access'],
                ],
            ],
            'branch_edit' => [
                'panel_title' => 'Edit branch',
                'what_for' => 'Change branch fields and review operational impact for this location.',
                'when_use' => [
                    'Name, code, or deactivation for this branch is required.',
                ],
                'when_not' => [
                    'Expecting name or code edits alone to fix a suspended-organization access incident — unblock org state first when that is the root cause.',
                ],
                'next_best' => [
                    ['label' => 'Organizations', 'href' => '/platform-admin/salons'],
                    ['label' => 'Access', 'href' => '/platform-admin/access'],
                ],
                'wrong_page_hint' => 'Editing branch name or code will not fix a suspended-organization access incident if org suspension is the root cause.',
            ],
            'security' => [
                'panel_title' => 'Security',
                'what_for' => 'Deployment-wide public emergency controls and the founder audit trail for access-related actions.',
                'when_use' => [
                    'Public booking, anonymous APIs, or public commerce must be centrally blocked or reviewed.',
                    'You need the audit log for high-impact founder actions.',
                ],
                'when_not' => [
                    'Routine staff sign-in repair — use Access; these controls affect public entry points, not staff workspace permissions alone.',
                ],
                'next_best' => [
                    ['label' => 'Incident Center', 'href' => '/platform-admin/incidents'],
                    ['label' => 'Access', 'href' => '/platform-admin/access'],
                ],
                'wrong_page_hint' => 'Kill switches are public emergency controls across the deployment — not a substitute for user-level access repair.',
            ],
            'organizations' => [
                'panel_title' => 'Organizations',
                'what_for' => 'Tenant/company lifecycle in the registry — list, open, suspend/reactivate through safe previews, and guided recovery.',
                'when_use' => [
                    'A whole organization may be suspended, reactivated, or causing downstream incidents.',
                ],
                'when_not' => [
                    'Individual user-only issues with no org angle — start in Access.',
                    'Branch naming without lifecycle questions — use Branches.',
                ],
                'next_best' => [
                    ['label' => 'Access', 'href' => '/platform-admin/access'],
                    ['label' => 'Incident Center', 'href' => '/platform-admin/incidents'],
                ],
            ],
            'organization_detail' => [
                'panel_title' => 'Organization (detail)',
                'what_for' => 'Lifecycle actions and impact for one tenant — suspension, reactivation, and blast radius context.',
                'when_use' => [
                    'You need to change org state or understand downstream effects on branches and users.',
                ],
                'when_not' => [
                    'Purely user-level fallout with no org change — after org recovery, affected users may still need review in Access.',
                ],
                'next_best' => [
                    ['label' => 'Access', 'href' => '/platform-admin/access'],
                    ['label' => 'Branches', 'href' => '/platform-admin/branches'],
                ],
                'wrong_page_hint' => 'Reactivating the org may restore downstream tenant access, but affected users may still need review in Access.',
            ],
            'organization_edit' => [
                'panel_title' => 'Edit organization',
                'what_for' => 'Update registry profile fields (name, code) for this organization.',
                'when_use' => [
                    'Metadata corrections that do not replace lifecycle workflows.',
                ],
                'when_not' => [
                    'Suspension or reactivation — use the safe previews and guided recovery from the organization detail page.',
                ],
                'next_best' => [
                    ['label' => 'Organizations registry', 'href' => '/platform-admin/salons'],
                    ['label' => 'Dashboard', 'href' => '/platform-admin'],
                ],
            ],
            'branch_create' => [
                'panel_title' => 'New branch',
                'what_for' => 'Create a branch row linked to an organization — codes are unique across the deployment.',
                'when_use' => [
                    'A new location row is required under an active organization.',
                ],
                'when_not' => [
                    'Fixing user access without a new branch — use Access or Organizations first.',
                ],
                'next_best' => [
                    ['label' => 'Branches list', 'href' => '/platform-admin/branches'],
                    ['label' => 'Organizations', 'href' => '/platform-admin/salons'],
                ],
            ],
            'access_diagnostics' => [
                'panel_title' => 'Diagnostics',
                'what_for' => 'Technical details for one account — raw access-shape payload and database fields for deep verification.',
                'when_use' => [
                    'You need engine-level truth beyond the human summary on the user page.',
                ],
                'when_not' => [
                    'Day-to-day repair — prefer Access user detail and guided repair first.',
                ],
                'next_best' => [
                    ['label' => 'Access list', 'href' => '/platform-admin/access'],
                    ['label' => 'Incident Center', 'href' => '/platform-admin/incidents'],
                ],
            ],
            'guide' => [
                'panel_title' => 'Operator guide',
                'what_for' => 'A short in-product handbook — how modules fit together and how to triage without reading backend architecture docs.',
                'when_use' => [
                    'Onboarding or when you are unsure which module to open.',
                ],
                'when_not' => [
                    'Replacing the live dashboards — use Incidents and Access for current truth.',
                ],
                'next_best' => [
                    ['label' => 'Dashboard', 'href' => '/platform-admin'],
                    ['label' => 'Incident Center', 'href' => '/platform-admin/incidents'],
                ],
            ],
            default => [
                'panel_title' => 'Platform',
                'what_for' => 'Founder control plane — operational tools for the deployment.',
                'when_use' => ['You need platform-level visibility or safe actions.'],
                'when_not' => ['Tenant product work outside platform-admin.'],
                'next_best' => [['label' => 'Dashboard', 'href' => '/platform-admin']],
            ],
        };
    }
}
