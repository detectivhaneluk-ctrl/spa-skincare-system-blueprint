<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Modules\Organizations\Services\FounderSafeActionPreviewService;

/**
 * GET previews for dangerous founder actions (reason required on POST).
 * FOUNDER-OPS-SAFE-ACTION-GUARDRAILS-01.
 */
final class PlatformFounderSafeActionController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private FounderSafeActionPreviewService $preview,
    ) {
    }

    public function orgSuspendPreview(int $id): void
    {
        $this->requireAuth();
        $p = $this->preview->buildOrgSuspendPreview((int) $id);
        $this->renderPreview($p, 'Confirm organization suspension');
    }

    public function orgReactivatePreview(int $id): void
    {
        $this->requireAuth();
        $p = $this->preview->buildOrgReactivatePreview((int) $id);
        $this->renderPreview($p, 'Confirm organization reactivation');
    }

    public function userDeactivatePreview(int $id): void
    {
        $this->requireAuth();
        $p = $this->preview->buildUserDeactivatePreview((int) $id);
        $this->renderPreview($p, 'Confirm account deactivation');
    }

    public function userActivatePreview(int $id): void
    {
        $this->requireAuth();
        $p = $this->preview->buildUserActivatePreview((int) $id);
        $this->renderPreview($p, 'Confirm account activation');
    }

    public function accessRepairPreview(int $id): void
    {
        $this->requireAuth();
        $orgId = isset($_GET['organization_id']) ? (int) $_GET['organization_id'] : 0;
        $branchId = isset($_GET['branch_id']) ? (int) $_GET['branch_id'] : 0;
        if ($orgId <= 0 || $branchId <= 0) {
            flash('error', 'Choose organization and branch on the Access user page, then open this preview again with both ids in the URL (or use the link from guided repair).');
            header('Location: /platform-admin/access/' . (int) $id);
            exit;
        }
        $p = $this->preview->buildAccessRepairPreview((int) $id, $orgId, $branchId);
        $this->renderPreview($p, 'Confirm tenant access repair');
    }

    public function branchDeactivatePreview(int $id): void
    {
        $this->requireAuth();
        $p = $this->preview->buildBranchDeactivatePreview((int) $id);
        $this->renderPreview($p, 'Confirm branch deactivation');
    }

    public function killSwitchesPreview(): void
    {
        $this->requireAuth();
        $current = \Core\App\Application::container()->get(\Modules\Organizations\Services\PlatformFounderSecurityService::class)
            ->getPublicSurfaceKillSwitchState();
        $p = $this->preview->buildKillSwitchPreview([
            'kill_online_booking' => !empty($current['kill_online_booking']),
            'kill_anonymous_public_apis' => !empty($current['kill_anonymous_public_apis']),
            'kill_public_commerce' => !empty($current['kill_public_commerce']),
        ]);
        $this->renderPreview($p, 'Confirm public kill switch changes');
    }

    public function supportEntryPreview(): void
    {
        $this->requireAuth();
        $tenantId = isset($_GET['tenant_user_id']) ? (int) $_GET['tenant_user_id'] : 0;
        $branchId = isset($_GET['branch_id']) ? (int) $_GET['branch_id'] : 0;
        if ($tenantId <= 0) {
            flash('error', 'Missing tenant_user_id for support entry preview.');
            header('Location: /platform-admin/access');
            exit;
        }
        $p = $this->preview->buildSupportEntryPreview($tenantId, $branchId > 0 ? $branchId : null);
        $this->renderPreview($p, 'Confirm support entry');
    }

    private function requireAuth(): void
    {
        if ($this->auth->user() === null) {
            header('Location: /login');
            exit;
        }
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
