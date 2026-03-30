<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\Audit\AuditService;
use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Core\Permissions\PermissionService;
use InvalidArgumentException;
use Modules\Organizations\Policies\FounderActionRiskPolicy;
use Modules\Organizations\Repositories\PlatformControlPlaneReadRepository;
use Modules\Organizations\Services\FounderSafeActionGuardrailService;
use Modules\Organizations\Services\FounderSafeActionPreviewService;
use Modules\Organizations\Services\OrganizationRegistryMutationService;
use Modules\Organizations\Services\OrganizationRegistryReadService;

/**
 * Founder salon lifecycle writes: create, edit, suspend/reactivate confirm, archive.
 * Mutations delegate to {@see OrganizationRegistryMutationService}; suspend/reactivate POST reuse
 * {@see PlatformOrganizationRegistryManageController} targets.
 */
final class PlatformSalonLifecycleController
{
    public function __construct(
        private OrganizationRegistryMutationService $mutation,
        private OrganizationRegistryReadService $read,
        private AuthService $auth,
        private SessionAuth $session,
        private PermissionService $permissions,
        private AuditService $audit,
        private FounderSafeActionGuardrailService $guardrail,
        private FounderSafeActionPreviewService $preview,
        private PlatformControlPlaneReadRepository $controlPlaneReads,
    ) {
    }

    private function assertManage(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        if (!$this->permissions->has((int) $user['id'], 'platform.organizations.manage')) {
            flash('error', 'Not permitted.');
            header('Location: /platform-admin/salons');
            exit;
        }
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
        if (!$this->permissions->has((int) $user['id'], 'platform.organizations.manage')) {
            flash('error', 'Not permitted.');
            header('Location: /platform-admin/salons');
            exit;
        }
    }

    public function create(): void
    {
        $this->assertManage();
        $org = ['name' => '', 'code' => ''];
        $errors = [];
        $flash = flash();
        $csrf = $this->session->csrfToken();
        $title = 'Add salon';
        ob_start();
        require base_path('modules/organizations/views/platform_salons/create.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
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
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_SALON_CREATE);
            $row = $this->mutation->createOrganization($payload);
            flash('success', 'Salon created.');
            header('Location: /platform-admin/salons/' . (int) $row['id']);
            exit;
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            $org = ['name' => $name, 'code' => $codeRaw];
            $errors = [$e->getMessage()];
            $flash = flash();
            $csrf = $this->session->csrfToken();
            $title = 'Add salon';
            ob_start();
            require base_path('modules/organizations/views/platform_salons/create.php');
            $content = ob_get_clean();
            require shared_path('layout/platform_admin.php');
        }
    }

    public function edit(int $id): void
    {
        $this->assertManage();
        $id = (int) $id;
        $org = $this->read->getOrganizationById($id);
        if ($org === null) {
            flash('error', 'Salon not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        if (!empty($org['deleted_at'])) {
            flash('error', 'Archived salons cannot be edited.');
            header('Location: /platform-admin/salons/' . $id);
            exit;
        }
        $errors = [];
        $flash = flash();
        $csrf = $this->session->csrfToken();
        $title = 'Edit salon';
        ob_start();
        require base_path('modules/organizations/views/platform_salons/edit.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function update(int $id): void
    {
        $this->assertManageCsrf();
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        $id = (int) $id;
        if ($this->read->getOrganizationById($id) === null) {
            flash('error', 'Salon not found.');
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
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_SALON_UPDATE);
            $row = $this->mutation->updateOrganizationProfile($id, $payload);
            if ($row === null) {
                flash('error', 'Salon not found.');
                header('Location: /platform-admin/salons');
                exit;
            }
            flash('success', 'Salon updated.');
            header('Location: /platform-admin/salons/' . $id);
            exit;
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            $org = $this->read->getOrganizationById($id) ?? [];
            $errors = [$e->getMessage()];
            $flash = flash();
            $csrf = $this->session->csrfToken();
            $title = 'Edit salon';
            ob_start();
            require base_path('modules/organizations/views/platform_salons/edit.php');
            $content = ob_get_clean();
            require shared_path('layout/platform_admin.php');
        }
    }

    public function suspendPreview(int $id): void
    {
        $this->assertManage();
        $id = (int) $id;
        $org = $this->read->getOrganizationById($id);
        if ($org === null) {
            flash('error', 'Salon not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        if (!empty($org['deleted_at'])) {
            flash('error', 'Archived salons cannot be suspended.');
            header('Location: /platform-admin/salons/' . $id);
            exit;
        }
        if (!empty($org['suspended_at'])) {
            flash('error', 'This salon is already suspended.');
            header('Location: /platform-admin/salons/' . $id);
            exit;
        }
        $p = $this->preview->buildOrgSuspendPreview($id);
        $p = $this->salonizeSuspendPreview($p, $org);
        $this->renderPreview($p, 'Confirm salon suspension');
    }

    public function reactivatePreview(int $id): void
    {
        $this->assertManage();
        $id = (int) $id;
        $org = $this->read->getOrganizationById($id);
        if ($org === null) {
            flash('error', 'Salon not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        if (!empty($org['deleted_at'])) {
            flash('error', 'Archived salons cannot be reactivated.');
            header('Location: /platform-admin/salons/' . $id);
            exit;
        }
        if (empty($org['suspended_at'])) {
            flash('error', 'This salon is not suspended.');
            header('Location: /platform-admin/salons/' . $id);
            exit;
        }
        $p = $this->preview->buildOrgReactivatePreview($id);
        $p = $this->salonizeReactivatePreview($p, $org);
        $this->renderPreview($p, 'Confirm salon reactivation');
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $org
     * @return array<string, mixed>
     */
    private function salonizeSuspendPreview(array $p, array $org): array
    {
        $id = (int) ($org['id'] ?? 0);
        $name = trim((string) ($org['name'] ?? ''));
        $p['title'] = 'Suspend salon';
        $p['headline'] = 'You are about to suspend salon “' . $name . '” (#' . $id . ').';
        $p['submit_label'] = 'Suspend salon';
        $p['confirm_checkbox_label'] = 'I understand this salon will be suspended until I reactivate it.';
        $p['salon_founder_confirm'] = true;
        $p['cancel_url'] = '/platform-admin/salons/' . $id;
        $p['founder_salon_name'] = $name;
        $p['founder_lede'] = 'The salon will be paused until you reactivate it.';
        $p['founder_transition'] = 'Active → Suspended';
        $p['founder_changes'] = [
            'Salon is suspended',
            'Tenant access is blocked',
        ];
        $p['founder_stays'] = [
            'Branches and people stay as they are',
        ];
        $p['founder_audit_note'] = 'This action is recorded.';

        return $p;
    }

    /**
     * @param array<string, mixed> $p
     * @param array<string, mixed> $org
     * @return array<string, mixed>
     */
    private function salonizeReactivatePreview(array $p, array $org): array
    {
        $id = (int) ($org['id'] ?? 0);
        $name = trim((string) ($org['name'] ?? ''));
        $p['title'] = 'Reactivate salon';
        $p['headline'] = 'You are about to clear suspension for “' . $name . '” (#' . $id . ').';
        $p['submit_label'] = 'Reactivate salon';
        $p['confirm_checkbox_label'] = 'I understand and want to reactivate this salon.';
        $p['salon_founder_confirm'] = true;
        $p['cancel_url'] = '/platform-admin/salons/' . $id;
        $p['founder_salon_name'] = $name;
        $p['founder_lede'] = 'Default organization will return to active operation.';
        $p['founder_transition'] = 'Suspended → Active';
        $p['founder_changes'] = [
            'Salon becomes active again',
            'Tenant access can resume',
        ];
        $p['founder_stays'] = [
            'Branches and people stay as they are',
        ];
        $p['founder_audit_note'] = 'This action is recorded.';

        return $p;
    }

    public function archiveConfirm(int $id): void
    {
        $this->assertManage();
        $id = (int) $id;
        $org = $this->read->getOrganizationById($id);
        if ($org === null) {
            flash('error', 'Salon not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        if (!empty($org['deleted_at'])) {
            flash('error', 'This salon is already archived.');
            header('Location: /platform-admin/salons/' . $id);
            exit;
        }
        $branches = $this->controlPlaneReads->countNonDeletedBranchesForOrganization($id);
        if ($branches > 0) {
            $preview = [
                'error' => 'Cannot archive while this salon has ' . $branches . ' branch(es). Remove or deactivate branches first.',
            ];
        } else {
            $name = trim((string) ($org['name'] ?? ''));
            $preview = [
                'title' => 'Archive salon',
                'headline' => 'You are about to archive “' . $name . '” (#' . $id . ').',
                'preview_bullets' => [
                    'Sets deleted_at on this organization row (soft archive).',
                    'No user rows or branches are hard-deleted by this action.',
                ],
                'what_will_change' => 'The salon is marked archived and hidden from normal operational flows.',
                'what_stays' => 'Historical data and database rows remain.',
                'reversibility' => 'requires_follow_up',
                'reversibility_detail' => 'Reversal is not offered from this UI.',
                'rollback_hint' => 'Contact engineering if this archive must be undone.',
                'post_url' => '/platform-admin/salons/' . $id . '/archive',
                'submit_label' => 'Archive salon',
                'confirm_checkbox_label' => 'I understand this is an end-of-life soft archive for this salon.',
            ];
        }
        $csrf = $this->session->csrfToken();
        $title = !empty($preview['error']) ? 'Cannot archive' : 'Archive salon';
        $salonId = $id;
        ob_start();
        require base_path('modules/organizations/views/platform_salons/archive_confirm.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function archive(int $id): void
    {
        $this->assertManageCsrf();
        $id = (int) $id;
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        try {
            $reason = $this->guardrail->requireValidatedReason((string) ($_POST['action_reason'] ?? ''));
            $this->guardrail->requireHighImpactConfirmation();
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_SALON_ARCHIVE);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /platform-admin/salons/' . $id . '/archive-confirm');
            exit;
        }
        if ((string) ($_POST['confirm_archive_salon'] ?? '') !== '1') {
            flash('error', 'Check the archive confirmation box to continue.');
            header('Location: /platform-admin/salons/' . $id . '/archive-confirm');
            exit;
        }
        try {
            $row = $this->mutation->archiveOrganization($id);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /platform-admin/salons/' . $id);
            exit;
        }
        if ($row === null) {
            flash('error', 'Salon not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        $this->audit->log(
            'founder_organization_archived',
            'organization',
            $id,
            $actor,
            null,
            $this->guardrail->auditMetadata(
                $reason,
                'Organization archived: deleted_at set.',
                'requires_follow_up',
                ['rollback_hint' => 'Un-archive requires database or support procedures.']
            )
        );
        flash('success', 'Salon archived.');
        header('Location: /platform-admin/salons/' . $id);
        exit;
    }

    /**
     * @param array<string, mixed> $p
     */
    private function renderPreview(array $p, string $fallbackTitle): void
    {
        $csrf = $this->session->csrfToken();
        $title = (string) ($p['title'] ?? $fallbackTitle);
        $preview = $p;
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/safe_action_preview.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }
}
