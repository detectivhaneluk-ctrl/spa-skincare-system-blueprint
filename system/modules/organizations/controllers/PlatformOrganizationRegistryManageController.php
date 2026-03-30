<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Core\Audit\AuditService;
use InvalidArgumentException;
use Modules\Organizations\Policies\FounderActionRiskPolicy;
use Modules\Organizations\Services\FounderSafeActionGuardrailService;
use Modules\Organizations\Services\OrganizationRegistryMutationService;
use Modules\Organizations\Services\OrganizationRegistryReadService;

/**
 * FOUNDATION-44 — platform organization registry HTTP mutations ({@code platform.organizations.manage}).
 * FOUNDER-OPS-SAFE-ACTION-GUARDRAILS-01: suspend/reactivate require preview flow with reason + audit.
 */
final class PlatformOrganizationRegistryManageController
{
    public function __construct(
        private OrganizationRegistryMutationService $mutation,
        private OrganizationRegistryReadService $read,
        private AuthService $auth,
        private SessionAuth $session,
        private AuditService $audit,
        private FounderSafeActionGuardrailService $guardrail,
    ) {
    }

    public function create(): void
    {
        header('Location: /platform-admin/salons/create', true, 302);
        exit;
    }

    public function store(): void
    {
        $this->assertManageCsrf();
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $codeRaw = trim((string) ($_POST['code'] ?? ''));
        $payload = ['name' => $name];
        if ($codeRaw !== '') {
            $payload['code'] = $codeRaw;
        }

        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ORG_REGISTRY_CREATE);
            $row = $this->mutation->createOrganization($payload);
            flash('success', 'Organization created.');
            header('Location: /platform-admin/salons/' . (int) $row['id']);
            exit;
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            $org = ['name' => $name, 'code' => $codeRaw];
            $errors = [$e->getMessage()];
            $flash = flash();
            $csrf = Application::container()->get(SessionAuth::class)->csrfToken();
            $title = 'New organization';
            require base_path('modules/organizations/views/platform-registry/create.php');
        }
    }

    public function edit(int $id): void
    {
        $id = (int) $id;
        header('Location: /platform-admin/salons/' . $id . '/edit', true, 302);
        exit;
    }

    public function update(int $id): void
    {
        $this->assertManageCsrf();
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        $id = (int) $id;
        if ($this->read->getOrganizationById($id) === null) {
            flash('error', 'Organization not found.');
            header('Location: /platform-admin/salons');
            exit;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $codeRaw = isset($_POST['code']) ? trim((string) $_POST['code']) : null;
        $payload = ['name' => $name];
        if (array_key_exists('code', $_POST)) {
            $payload['code'] = $codeRaw === '' ? null : $codeRaw;
        }

        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ORG_REGISTRY_UPDATE);
            $row = $this->mutation->updateOrganizationProfile($id, $payload);
            if ($row === null) {
                flash('error', 'Organization not found.');
                header('Location: /platform-admin/salons');
                exit;
            }
            flash('success', 'Organization updated.');
            header('Location: /platform-admin/salons/' . $id);
            exit;
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            $org = $this->read->getOrganizationById($id) ?? [];
            $errors = [$e->getMessage()];
            $flash = flash();
            $csrf = Application::container()->get(SessionAuth::class)->csrfToken();
            $title = 'Edit salon';
            require base_path('modules/organizations/views/platform-registry/edit.php');
        }
    }

    public function suspend(int $id): void
    {
        $this->assertManageCsrf();
        $id = (int) $id;
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        try {
            $reason = $this->guardrail->requireValidatedReason((string) ($_POST['action_reason'] ?? ''));
            $this->guardrail->requireHighImpactConfirmation();
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ORG_SUSPEND);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /platform-admin/salons/' . $id);
            exit;
        }
        try {
            $row = $this->mutation->suspendOrganization($id);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /platform-admin/salons/' . $id);
            exit;
        }
        if ($row === null) {
            flash('error', 'Organization not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        $this->audit->log(
            'founder_organization_suspended',
            'organization',
            $id,
            $actor,
            null,
            $this->guardrail->auditMetadata(
                $reason,
                'Organization suspended: suspended_at set.',
                'reversible',
                ['rollback_hint' => 'Reactivate organization from organization detail when safe.']
            )
        );
        flash('success', 'Organization suspended.');
        flash('founder_guardrail_result', [
            'what_changed' => 'This organization is now suspended. Tenant users with active membership here are blocked from the salon workspace until you reactivate.',
            'what_unchanged' => 'No users or branches were deleted; historical data remains.',
            'next_review_url' => '/platform-admin/salons/' . $id,
            'next_review_label' => 'Organization detail (verify suspension and impact counts)',
            'rollback_hint' => 'Use Reactivate organization on this page when the incident is resolved.',
        ]);
        header('Location: /platform-admin/salons/' . $id);
        exit;
    }

    public function reactivate(int $id): void
    {
        $this->assertManageCsrf();
        $id = (int) $id;
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        try {
            $reason = $this->guardrail->requireValidatedReason((string) ($_POST['action_reason'] ?? ''));
            $this->guardrail->requireHighImpactConfirmation();
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_ORG_REACTIVATE);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /platform-admin/salons/' . $id);
            exit;
        }
        try {
            $row = $this->mutation->reactivateOrganization($id);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /platform-admin/salons/' . $id);
            exit;
        }
        if ($row === null) {
            flash('error', 'Organization not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        $this->audit->log(
            'founder_organization_reactivated',
            'organization',
            $id,
            $actor,
            null,
            $this->guardrail->auditMetadata(
                $reason,
                'Organization reactivated: suspended_at cleared.',
                'reversible',
                ['rollback_hint' => 'Suspend again from this page if the org must go offline.']
            )
        );
        flash('success', 'Organization reactivated.');
        flash('founder_guardrail_result', [
            'what_changed' => 'Suspension was cleared for this organization. Tenant routing may succeed again for bound users subject to access-shape rules.',
            'what_unchanged' => 'Memberships and branch pins were not changed by reactivation alone.',
            'next_review_url' => '/platform-admin/salons/' . $id,
            'next_review_label' => 'Organization detail (confirm lifecycle and impact)',
            'rollback_hint' => 'If this was premature, suspend again from the lifecycle section.',
        ]);
        header('Location: /platform-admin/salons/' . $id);
        exit;
    }

    private function assertManageCsrf(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $name = (string) config('app.csrf_token_name', 'csrf_token');
        $token = (string) ($_POST[$name] ?? '');
        if (!$this->session->validateCsrf($token)) {
            flash('error', 'Invalid security token.');
            header('Location: /platform-admin/salons');
            exit;
        }
        if (!Application::container()->get(\Core\Permissions\PermissionService::class)->has((int) $user['id'], 'platform.organizations.manage')) {
            flash('error', 'Not permitted.');
            header('Location: /platform-admin/salons');
            exit;
        }
    }
}
