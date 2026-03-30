<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Core\Permissions\PermissionService;
use InvalidArgumentException;
use Modules\Organizations\Policies\FounderActionRiskPolicy;
use Modules\Organizations\Services\FounderAccessPresenter;
use Modules\Organizations\Services\FounderSafeActionGuardrailService;
use Modules\Organizations\Services\PlatformFounderSecurityService;
use Throwable;

/**
 * Founder audit visibility + platform-wide public surface kill switches.
 * FOUNDER-AUDIT-VISIBILITY-AND-PUBLIC-SURFACE-CONTROL-01.
 */
final class PlatformFounderSecurityController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private PlatformFounderSecurityService $security,
        private PermissionService $permissions,
        private FounderAccessPresenter $founderPresenter,
        private FounderSafeActionGuardrailService $guardrail,
    ) {
    }

    public function index(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        $csrf = $this->session->csrfToken();
        $title = 'Security';
        $auditRows = $this->security->listAccessPlaneAuditEvents(200);
        $killState = $this->security->getPublicSurfaceKillSwitchState();
        $canManage = $this->permissions->has((int) $user['id'], 'platform.organizations.manage');
        $founderPresenter = $this->founderPresenter;
        $flashMsg = flash();
        $founderGuardrailResult = is_array($flashMsg) ? ($flashMsg['founder_guardrail_result'] ?? null) : null;
        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/founder_security.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    public function postPublicSurfaceKillSwitches(): void
    {
        $this->assertManageCsrf();
        $actor = $this->requireActorUserId();
        try {
            $reason = $this->guardrail->requireValidatedReason((string) ($_POST['action_reason'] ?? ''));
            $this->guardrail->requireHighImpactConfirmation();
            $this->guardrail->requirePlatformManagePasswordStepUp($actor, FounderActionRiskPolicy::ACTION_SECURITY_KILL_SWITCH);
            $state = [
                'kill_online_booking' => $this->boolFromCheckbox('kill_online_booking'),
                'kill_anonymous_public_apis' => $this->boolFromCheckbox('kill_anonymous_public_apis'),
                'kill_public_commerce' => $this->boolFromCheckbox('kill_public_commerce'),
            ];
            $this->security->applyPublicSurfaceKillSwitches(
                $actor,
                $state,
                $this->guardrail->auditMetadata(
                    $reason,
                    'Deployment-wide public kill switches updated.',
                    'reversible',
                    []
                )
            );
            flash('success', 'Public surface kill switches updated.');
            flash('founder_guardrail_result', [
                'what_changed' => 'Anonymous/public traffic behavior for booking, anonymous APIs, and public commerce now matches the saved switch positions.',
                'what_unchanged' => 'Authenticated staff sessions were not signed out by this action alone.',
                'next_review_url' => '/platform-admin/security',
                'next_review_label' => 'Security (verify switches and audit)',
                'rollback_hint' => 'Turn the same switches off here when the incident is resolved.',
            ]);
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        } catch (Throwable $e) {
            flash('error', 'Update failed; no changes were applied.');
        }
        header('Location: /platform-admin/security');
        exit;
    }

    private function boolFromCheckbox(string $field): bool
    {
        $v = $_POST[$field] ?? '0';

        return (string) $v !== '' && (string) $v !== '0';
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
            header('Location: /platform-admin/security');
            exit;
        }
        if (!$this->permissions->has((int) $user['id'], 'platform.organizations.manage')) {
            flash('error', 'Not permitted.');
            header('Location: /platform-admin/security');
            exit;
        }
    }

    private function requireActorUserId(): int
    {
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        if ($actor <= 0) {
            flash('error', 'Not authenticated.');
            header('Location: /platform-admin/security');
            exit;
        }

        return $actor;
    }
}
