<?php

declare(strict_types=1);

namespace Modules\Organizations\Controllers;

use Core\Auth\AuthService;
use Core\Auth\SessionAuth;
use Modules\Organizations\Services\FounderIncidentCenterService;
use Modules\Organizations\Services\FounderIncidentPresenter;
use Modules\Organizations\Services\PlatformFounderIssuesInboxService;

/**
 * Founder Incident Center — identify, explain, route (no repairs on this screen).
 */
final class PlatformFounderIncidentCenterController
{
    public function __construct(
        private AuthService $auth,
        private SessionAuth $session,
        private FounderIncidentCenterService $incidents,
        private PlatformFounderIssuesInboxService $issuesInbox,
    ) {
    }

    public function index(): void
    {
        $this->renderIncidentCenter('Incident Center', '/platform-admin/incidents', false);
    }

    /** Founder exceptions inbox — one row per salon; diagnostics table remains on /incidents. */
    public function problems(): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }

        $csrf = $this->session->csrfToken();
        $title = 'Issues';
        $q = trim((string) ($_GET['q'] ?? ''));
        $filter = trim((string) ($_GET['filter'] ?? 'all'));
        $sev = isset($_GET['severity']) && (string) $_GET['severity'] !== '' ? (string) $_GET['severity'] : null;
        $page = $this->issuesInbox->build($q, $filter, $sev);

        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/founder_issues_center.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }

    private function renderIncidentCenter(string $title, string $filterBasePath, bool $compactIncidentView): void
    {
        $user = $this->auth->user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }

        $csrf = $this->session->csrfToken();
        $cat = isset($_GET['category']) ? (string) $_GET['category'] : '';
        $sev = isset($_GET['severity']) ? (string) $_GET['severity'] : '';

        $page = $this->incidents->build($cat, $sev);
        $presenter = new FounderIncidentPresenter();

        ob_start();
        require base_path('modules/organizations/views/platform_control_plane/founder_incidents_index.php');
        $content = ob_get_clean();
        require shared_path('layout/platform_admin.php');
    }
}
