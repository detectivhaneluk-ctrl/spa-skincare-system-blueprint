<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Core\Permissions\PermissionService;
use InvalidArgumentException;
use Modules\Organizations\Policies\FounderActionRiskPolicy;
use Modules\Organizations\Services\FounderSafeActionGuardrailService;
use Modules\Organizations\Services\OrganizationRegistryReadService;
use Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository;
use Modules\Organizations\Services\TenantUserProvisioningService;
use Throwable;

/**
 * Salon-scoped people provisioning for the founder control plane (delegates to {@see TenantUserProvisioningService}).
 */
final class PlatformSalonPeopleController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private PermissionService $permissions,
        private OrganizationRegistryReadService $orgRead,
        private PlatformSalonRegistryReadRepository $salonReads,
        private TenantUserProvisioningService $provisioning,
        private FounderSafeActionGuardrailService $guardrail,
    ) {
    }

    public function createForm(int $id): void
    {
        $this->assertManage();
        $id = (int) $id;
        $ctx = $this->requireSalonContext($id);
        if ($ctx === null) {
            return;
        }
        $csrf = $this->session->csrfToken();
        $title = 'Add person';
        $salonName = (string) ($ctx['org']['name'] ?? '');
        $organizationId = $id;
        $branches = $ctx['branches'];
        $errors = [];
        $flash = flash();
        ob_start();
        require base_path('modules/organizations/views/platform_salons/people/create.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function store(int $id): void
    {
        $this->assertManageCsrf();
        $id = (int) $id;
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        $ctx = $this->requireSalonContext($id);
        if ($ctx === null) {
            return;
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $branchId = (int) ($_POST['branch_id'] ?? 0);
        $role = strtolower(trim((string) ($_POST['role'] ?? 'admin')));
        if (!in_array($role, ['admin', 'reception'], true)) {
            $role = 'admin';
        }

        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_SALON_PEOPLE_CREATE);
            if ($role === 'reception') {
                $this->provisioning->provisionTenantStaff($email, $password, $name, $id, $branchId, 'reception', $actor);
            } else {
                $this->provisioning->provisionTenantAdmin($email, $password, $name, $id, $branchId, $actor);
            }
            flash('success', 'Person added.');
            header('Location: /platform-admin/salons/' . $id . '#people', true, 302);
            exit;
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable) {
            flash('error', 'Person was not created.');
        }

        $csrf = $this->session->csrfToken();
        $title = 'Add person';
        $salonName = (string) ($ctx['org']['name'] ?? '');
        $organizationId = $id;
        $branches = $ctx['branches'];
        $errors = [];
        $flash = flash();
        ob_start();
        require base_path('modules/organizations/views/platform_salons/people/create.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    /**
     * @return array{org: array<string, mixed>, branches: list<array<string, mixed>>}|null
     */
    private function requireSalonContext(int $organizationId): ?array
    {
        $org = $this->orgRead->getOrganizationById($organizationId);
        if ($org === null) {
            flash('error', 'Salon not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        if (!empty($org['deleted_at'])) {
            flash('error', 'Archived salon.');
            header('Location: /platform-admin/salons/' . $organizationId);
            exit;
        }
        if (!empty($org['suspended_at'])) {
            flash('error', 'Reactivate the salon before adding people.');
            header('Location: /platform-admin/salons/' . $organizationId);
            exit;
        }
        if (!$this->salonReads->membershipPivotExists()) {
            flash('error', 'This environment cannot provision salon people (membership table missing).');
            header('Location: /platform-admin/salons/' . $organizationId);
            exit;
        }
        $branches = $this->salonReads->listBranchesForOrganization($organizationId);
        if ($branches === []) {
            flash('error', 'Add a branch before adding people.');
            header('Location: /platform-admin/salons/' . $organizationId . '#branches');
            exit;
        }

        return ['org' => $org, 'branches' => $branches];
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
}
