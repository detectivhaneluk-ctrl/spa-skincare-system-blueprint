<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Core\Permissions\PermissionService;
use InvalidArgumentException;
use Modules\Organizations\Policies\FounderActionRiskPolicy;
use Modules\Organizations\Repositories\PlatformSalonRegistryReadRepository;
use Modules\Organizations\Services\FounderAccessManagementService;
use Modules\Organizations\Services\FounderSafeActionGuardrailService;
use Modules\Organizations\Services\OrganizationRegistryReadService;
use Throwable;

/**
 * Salon-scoped primary admin login identity: email, password, enable/disable login.
 * Every mutation verifies the target is the resolved primary admin for the salon.
 */
final class PlatformSalonAdminAccessController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private PermissionService $permissions,
        private OrganizationRegistryReadService $orgRead,
        private PlatformSalonRegistryReadRepository $salonReads,
        private FounderAccessManagementService $founderAccess,
        private FounderSafeActionGuardrailService $guardrail,
    ) {
    }

    /**
     * @return array{org: array<string, mixed>, admin: array<string, mixed>, user_id: int}
     */
    private function requireSalonAndPrimaryAdmin(int $organizationId): array
    {
        $org = $this->orgRead->getOrganizationById($organizationId);
        if ($org === null) {
            flash('error', 'Salon not found.');
            header('Location: /platform-admin/salons');
            exit;
        }
        if (!empty($org['deleted_at'])) {
            flash('error', 'This salon is archived. Admin access cannot be changed here.');
            header('Location: /platform-admin/salons/' . $organizationId);
            exit;
        }
        $admins = $this->salonReads->batchPrimaryAdminForOrganizations([$organizationId]);
        $admin = $admins[$organizationId] ?? null;
        $uid = (int) ($admin['id'] ?? 0);
        if ($admin === null || $uid <= 0) {
            flash('error', 'No primary admin is resolved for this salon.');
            header('Location: /platform-admin/salons/' . $organizationId);
            exit;
        }

        return ['org' => $org, 'admin' => $admin, 'user_id' => $uid];
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

    private function redirectToSalonAdmin(int $organizationId): void
    {
        header('Location: /platform-admin/salons/' . $organizationId . '#admin-access', true, 302);
        exit;
    }

    public function emailForm(int $id): void
    {
        $this->assertManage();
        $id = (int) $id;
        $ctx = $this->requireSalonAndPrimaryAdmin($id);
        $csrf = $this->session->csrfToken();
        $title = 'Change login email';
        $salonId = $id;
        $admin = $ctx['admin'];
        $errors = [];
        $flash = flash();
        ob_start();
        require base_path('modules/organizations/views/platform_salons/admin_access/email.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function emailPost(int $id): void
    {
        $this->assertManage();
        $this->assertManageCsrf();
        $id = (int) $id;
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        $ctx = $this->requireSalonAndPrimaryAdmin($id);
        $uid = $ctx['user_id'];
        $newEmail = trim((string) ($_POST['email'] ?? ''));
        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_SALON_ADMIN_EMAIL);
            $this->founderAccess->updateUserEmailByFounder($actor, $uid, $newEmail);
            flash('success', 'Login email updated.');
            $this->redirectToSalonAdmin($id);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            $csrf = $this->session->csrfToken();
            $title = 'Change login email';
            $salonId = $id;
            $admin = $ctx['admin'];
            $errors = [$e->getMessage()];
            $flash = flash();
            ob_start();
            require base_path('modules/organizations/views/platform_salons/admin_access/email.php');
            $content = ob_get_clean();
            require shared_path('layout/platform_admin.php');
        }
    }

    public function passwordForm(int $id): void
    {
        $this->assertManage();
        $id = (int) $id;
        $ctx = $this->requireSalonAndPrimaryAdmin($id);
        $csrf = $this->session->csrfToken();
        $title = 'Set new password';
        $salonId = $id;
        $admin = $ctx['admin'];
        $errors = [];
        $flash = flash();
        ob_start();
        require base_path('modules/organizations/views/platform_salons/admin_access/password.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function passwordPost(int $id): void
    {
        $this->assertManage();
        $this->assertManageCsrf();
        $id = (int) $id;
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        $ctx = $this->requireSalonAndPrimaryAdmin($id);
        $uid = $ctx['user_id'];
        $p1 = (string) ($_POST['password'] ?? '');
        $p2 = (string) ($_POST['password_confirm'] ?? '');
        if ($p1 !== $p2) {
            flash('error', 'Passwords do not match.');
            $csrf = $this->session->csrfToken();
            $title = 'Set new password';
            $salonId = $id;
            $admin = $ctx['admin'];
            $errors = ['Passwords do not match.'];
            $flash = flash();
            ob_start();
            require base_path('modules/organizations/views/platform_salons/admin_access/password.php');
            $content = ob_get_clean();
            require shared_path('layout/platform_admin.php');
            return;
        }
        try {
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_SALON_ADMIN_PASSWORD);
            $this->founderAccess->setUserPasswordByFounder($actor, $uid, $p1);
            flash('success', 'Password updated.');
            $this->redirectToSalonAdmin($id);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            $csrf = $this->session->csrfToken();
            $title = 'Set new password';
            $salonId = $id;
            $admin = $ctx['admin'];
            $errors = [$e->getMessage()];
            $flash = flash();
            ob_start();
            require base_path('modules/organizations/views/platform_salons/admin_access/password.php');
            $content = ob_get_clean();
            require shared_path('layout/platform_admin.php');
        }
    }

    public function disableLoginConfirm(int $id): void
    {
        $this->assertManage();
        $id = (int) $id;
        $ctx = $this->requireSalonAndPrimaryAdmin($id);
        $uid = $ctx['user_id'];
        $deleted = ($ctx['admin']['deleted_at'] ?? null) !== null && (string) ($ctx['admin']['deleted_at'] ?? '') !== '';
        if ($deleted) {
            flash('error', 'Login is already disabled.');
            $this->redirectToSalonAdmin($id);
        }
        $csrf = $this->session->csrfToken();
        $title = 'Disable login';
        $salonId = $id;
        $admin = $ctx['admin'];
        $action = 'disable';
        ob_start();
        require base_path('modules/organizations/views/platform_salons/admin_access/login_toggle_confirm.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function disableLoginPost(int $id): void
    {
        $this->assertManage();
        $this->assertManageCsrf();
        $id = (int) $id;
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        $ctx = $this->requireSalonAndPrimaryAdmin($id);
        $uid = $ctx['user_id'];
        try {
            $reason = $this->guardrail->requireValidatedReason((string) ($_POST['action_reason'] ?? ''));
            $this->guardrail->requireHighImpactConfirmation();
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_SALON_ADMIN_DISABLE_LOGIN);
            $this->founderAccess->setUserActive(
                $actor,
                $uid,
                false,
                $this->guardrail->auditMetadata($reason, 'Account soft-deleted; sign-in blocked.', 'reversible', [])
            );
            flash('success', 'Login disabled.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Could not update login state.');
        }
        $this->redirectToSalonAdmin($id);
    }

    public function enableLoginConfirm(int $id): void
    {
        $this->assertManage();
        $id = (int) $id;
        $ctx = $this->requireSalonAndPrimaryAdmin($id);
        $uid = $ctx['user_id'];
        $deleted = ($ctx['admin']['deleted_at'] ?? null) !== null && (string) ($ctx['admin']['deleted_at'] ?? '') !== '';
        if (!$deleted) {
            flash('error', 'Login is already enabled.');
            $this->redirectToSalonAdmin($id);
        }
        $csrf = $this->session->csrfToken();
        $title = 'Enable login';
        $salonId = $id;
        $admin = $ctx['admin'];
        $action = 'enable';
        ob_start();
        require base_path('modules/organizations/views/platform_salons/admin_access/login_toggle_confirm.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function enableLoginPost(int $id): void
    {
        $this->assertManage();
        $this->assertManageCsrf();
        $id = (int) $id;
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        $ctx = $this->requireSalonAndPrimaryAdmin($id);
        $uid = $ctx['user_id'];
        try {
            $reason = $this->guardrail->requireValidatedReason((string) ($_POST['action_reason'] ?? ''));
            $this->guardrail->requireHighImpactConfirmation();
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_SALON_ADMIN_ENABLE_LOGIN);
            $this->founderAccess->setUserActive(
                $actor,
                $uid,
                true,
                $this->guardrail->auditMetadata($reason, 'Account soft-delete cleared; sign-in may resume if access allows.', 'reversible', [])
            );
            flash('success', 'Login enabled.');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Could not update login state.');
        }
        $this->redirectToSalonAdmin($id);
    }
}
