<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Auth\AuthenticatedHomePathResolver;
use Core\Auth\SessionAuth;
use Core\Audit\AuditService;
use InvalidArgumentException;
use Modules\Organizations\Services\FounderSafeActionGuardrailService;
use Modules\Organizations\Services\FounderSupportEntryService;
use Throwable;

final class PlatformFounderSupportEntryController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private FounderSupportEntryService $supportEntry,
        private FounderSafeActionGuardrailService $guardrail,
        private AuditService $audit,
    ) {
    }

    public function postStart(): void
    {
        $this->assertManageCsrf();
        $founderId = $this->requireActorUserId();
        $tenantId = $this->requirePostPositiveInt('tenant_user_id', 'Tenant user id');
        $branchRaw = $_POST['branch_id'] ?? '';
        $branchId = $branchRaw === '' || $branchRaw === null ? null : (int) $branchRaw;
        if ($branchId !== null && $branchId <= 0) {
            $branchId = null;
        }
        try {
            $reason = $this->guardrail->requireValidatedReason((string) ($_POST['action_reason'] ?? ''));
            $this->guardrail->requireHighImpactConfirmation();
            $this->guardrail->requireSupportEntryPasswordStepUp($founderId);
            $this->guardrail->requireSupportEntryControlPlaneMfa($founderId);
            $this->supportEntry->startForFounderActor(
                $founderId,
                $tenantId,
                $branchId,
                $this->guardrail->auditMetadata(
                    $reason,
                    'Support entry session started (tenant-plane acting as target user).',
                    'requires_follow_up',
                    ['target_tenant_user_id' => $tenantId]
                )
            );
            $this->audit->log('founder_support_entry_allowed', 'platform_control_plane', $tenantId, $founderId, null, [
                'target_tenant_user_id' => $tenantId,
                'effect_summary' => 'Support entry session started after password step-up and control-plane MFA.',
            ], 'success', 'platform_control');
        } catch (InvalidArgumentException $e) {
            flash('error', $e->getMessage());
            header('Location: /platform-admin/access');
            exit;
        } catch (Throwable $e) {
            flash('error', 'Support entry could not be started.');
            header('Location: /platform-admin/access');
            exit;
        }
        flash(
            'success',
            'Support entry started. You are now acting in the tenant workspace as user #' . $tenantId
                . '. The session is audited; end support when finished. Next review: Security audit or Access when you return to the platform.'
        );
        $home = Application::container()->get(AuthenticatedHomePathResolver::class)->homePathForUserId($tenantId);
        header('Location: ' . $home);
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
            header('Location: /platform-admin/access');
            exit;
        }
        if (!Application::container()->get(\Core\Permissions\PermissionService::class)->has((int) $user['id'], 'platform.organizations.manage')) {
            flash('error', 'Not permitted.');
            header('Location: /platform-admin/access');
            exit;
        }
    }

    private function requireActorUserId(): int
    {
        $actor = (int) ($this->auth->user()['id'] ?? 0);
        if ($actor <= 0) {
            flash('error', 'Not authenticated.');
            header('Location: /platform-admin/access');
            exit;
        }

        return $actor;
    }

    private function requirePostPositiveInt(string $field, string $label): int
    {
        $v = (int) ($_POST[$field] ?? 0);
        if ($v <= 0) {
            flash('error', "{$label} must be a positive integer.");
            header('Location: /platform-admin/access');
            exit;
        }

        return $v;
    }
}
