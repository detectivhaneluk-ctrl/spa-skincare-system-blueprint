<?php

declare(strict_types=1);

namespace Modules\Marketing\Controllers;

use Core\App\Application;
use Core\Auth\AuthService;
use Core\Branch\BranchDirectory;
use Core\Permissions\PermissionService;
use Modules\Marketing\Repositories\MarketingCampaignRepository;
use Modules\Marketing\Repositories\MarketingCampaignRecipientRepository;
use Modules\Marketing\Repositories\MarketingCampaignRunRepository;
use Modules\Marketing\Services\MarketingCampaignService;
use Modules\Marketing\Services\MarketingSegmentEvaluator;

final class MarketingCampaignController
{
    public function __construct(
        private MarketingCampaignRepository $campaigns,
        private MarketingCampaignRunRepository $runs,
        private MarketingCampaignRecipientRepository $recipients,
        private MarketingCampaignService $service,
        private BranchDirectory $branchDirectory,
        private AuthService $auth,
        private PermissionService $permissions
    ) {
    }

    public function index(): void
    {
        $contextBranch = Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId();
        $statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
        if ($statusFilter !== '' && !in_array($statusFilter, MarketingCampaignService::CAMPAIGN_STATUSES, true)) {
            $statusFilter = '';
        }
        $channelFilter = isset($_GET['channel']) ? trim((string) $_GET['channel']) : '';
        if ($channelFilter !== '' && $channelFilter !== 'email') {
            $channelFilter = '';
        }
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        if (strlen($q) > 200) {
            $q = substr($q, 0, 200);
        }
        $filters = [
            'branch_id' => $contextBranch,
            'status' => $statusFilter !== '' ? $statusFilter : null,
            'channel' => $channelFilter !== '' ? $channelFilter : null,
            'q' => $q !== '' ? $q : null,
        ];
        $campaignIndexLimit = 100;
        $items = $this->service->listCampaignsForIndex($filters, $campaignIndexLimit, 0);
        $totalCount = $this->service->countCampaignsForIndex($filters);
        $flash = flash();
        $title = 'Campaigns';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $user = $this->auth->user();
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $canManageMarketing = $userId !== null && $this->permissions->has($userId, 'marketing.manage');
        $filterQ = $q;
        $filterStatus = $statusFilter;
        $filterChannel = $channelFilter;
        require base_path('modules/marketing/views/campaigns/index.php');
    }

    public function create(): void
    {
        $campaign = [
            'name' => '',
            'branch_id' => Application::container()->get(\Core\Branch\BranchContext::class)->getCurrentBranchId(),
            'segment_key' => MarketingSegmentEvaluator::SEGMENT_MARKETING_OPT_IN_EMAIL,
            'dormant_days' => 90,
            'lookahead_days' => 14,
            'recent_days' => 30,
            'subject' => '',
            'body_text' => '',
            'status' => 'draft',
        ];
        $errors = [];
        $branches = $this->getBranches();
        $segmentKeys = MarketingSegmentEvaluator::ALLOWED_SEGMENT_KEYS;
        $title = 'Create campaign';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/marketing/views/campaigns/create.php');
    }

    public function store(): void
    {
        $data = $this->parseCampaignPost();
        $errors = $this->validateCampaignForm($data);
        if (!empty($errors)) {
            $campaign = $data;
            $branches = $this->getBranches();
            $segmentKeys = MarketingSegmentEvaluator::ALLOWED_SEGMENT_KEYS;
            $title = 'Create campaign';
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/marketing/views/campaigns/create.php');
            return;
        }
        try {
            $data['branch_id'] = Application::container()->get(\Core\Branch\BranchContext::class)->enforceBranchOnCreate([
                'branch_id' => $data['branch_id'] ?? null,
            ])['branch_id'] ?? null;
            $id = $this->service->createCampaign($data);
            flash('success', 'Campaign created.');
            header('Location: /marketing/campaigns/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $campaign = $data;
            $branches = $this->getBranches();
            $segmentKeys = MarketingSegmentEvaluator::ALLOWED_SEGMENT_KEYS;
            $title = 'Create campaign';
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/marketing/views/campaigns/create.php');
        }
    }

    public function show(int $id): void
    {
        $campaign = $this->campaigns->findInTenantScopeForStaff($id);
        if (!$campaign) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($campaign)) {
            return;
        }
        $runRows = $this->runs->listByCampaignIdInTenantScopeForStaff($id, 30);
        $showVm = $this->service->campaignShowReadModel($campaign, $runRows);
        $previewCount = null;
        $previewError = null;
        if (isset($_GET['preview']) && (string) $_GET['preview'] === '1') {
            try {
                $previewCount = count($this->service->previewAudience($id));
            } catch (\Throwable $e) {
                $previewError = $e->getMessage();
            }
        }
        $flash = flash();
        $title = (string) ($campaign['name'] ?? 'Campaign');
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $user = $this->auth->user();
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $canManageMarketing = $userId !== null && $this->permissions->has($userId, 'marketing.manage');
        require base_path('modules/marketing/views/campaigns/show.php');
    }

    public function edit(int $id): void
    {
        $campaign = $this->campaigns->findInTenantScopeForStaff($id);
        if (!$campaign) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($campaign)) {
            return;
        }
        $cfg = $this->decodeConfigFromCampaign($campaign);
        $campaign['dormant_days'] = $cfg['dormant_days'] ?? 90;
        $campaign['lookahead_days'] = $cfg['lookahead_days'] ?? 14;
        $campaign['recent_days'] = $cfg['recent_days'] ?? 30;
        $errors = [];
        $branches = $this->getBranches();
        $segmentKeys = MarketingSegmentEvaluator::ALLOWED_SEGMENT_KEYS;
        $title = 'Edit campaign';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/marketing/views/campaigns/edit.php');
    }

    public function update(int $id): void
    {
        $campaign = $this->campaigns->findInTenantScopeForStaff($id);
        if (!$campaign) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($campaign)) {
            return;
        }
        $data = $this->parseCampaignPost();
        $errors = $this->validateCampaignForm($data, false);
        if (!empty($errors)) {
            $data['id'] = $id;
            $campaign = array_merge($campaign, $data);
            $branches = $this->getBranches();
            $segmentKeys = MarketingSegmentEvaluator::ALLOWED_SEGMENT_KEYS;
            $title = 'Edit campaign';
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/marketing/views/campaigns/edit.php');
            return;
        }
        try {
            $patch = [
                'name' => $data['name'],
                'segment_key' => $data['segment_key'],
                'segment_config' => $data['segment_config'] ?? [],
                'subject' => $data['subject'],
                'body_text' => $data['body_text'],
                'status' => $data['status'] ?? 'draft',
            ];
            $this->service->updateCampaign($id, $patch);
            flash('success', 'Campaign updated.');
            header('Location: /marketing/campaigns/' . $id);
            exit;
        } catch (\Throwable $e) {
            $errors['_general'] = $e->getMessage();
            $campaign = array_merge($campaign, $data);
            $branches = $this->getBranches();
            $segmentKeys = MarketingSegmentEvaluator::ALLOWED_SEGMENT_KEYS;
            $title = 'Edit campaign';
            $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
            require base_path('modules/marketing/views/campaigns/edit.php');
        }
    }

    public function freezeRun(int $campaignId): void
    {
        $campaign = $this->campaigns->findInTenantScopeForStaff($campaignId);
        if (!$campaign) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        if (!$this->ensureBranchAccess($campaign)) {
            return;
        }
        try {
            $runId = $this->service->freezeRecipientSnapshot($campaignId);
            flash('success', 'Run #' . $runId . ' created with frozen recipient snapshot.');
            header('Location: /marketing/campaigns/runs/' . $runId . '/recipients');
            exit;
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            header('Location: /marketing/campaigns/' . $campaignId);
            exit;
        }
    }

    public function dispatchRun(int $runId): void
    {
        $run = $this->runs->findInTenantScopeForStaff($runId);
        if (!$run) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $campaign = $this->campaigns->findInTenantScopeForStaff((int) ($run['campaign_id'] ?? 0));
        if (!$campaign || !$this->ensureBranchAccess($campaign)) {
            return;
        }
        try {
            $this->service->dispatchFrozenRun($runId);
            flash('success', 'Recipients enqueued on the outbound mail queue. Run the outbound worker to deliver.');
            header('Location: /marketing/campaigns/runs/' . $runId . '/recipients');
            exit;
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            header('Location: /marketing/campaigns/runs/' . $runId . '/recipients');
            exit;
        }
    }

    public function cancelRun(int $runId): void
    {
        $run = $this->runs->findInTenantScopeForStaff($runId);
        if (!$run) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $campaign = $this->campaigns->findInTenantScopeForStaff((int) ($run['campaign_id'] ?? 0));
        if (!$campaign || !$this->ensureBranchAccess($campaign)) {
            return;
        }
        try {
            $this->service->cancelFrozenRun($runId);
            flash('success', 'Run cancelled.');
            header('Location: /marketing/campaigns/' . (int) ($campaign['id'] ?? 0));
            exit;
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            header('Location: /marketing/campaigns/' . (int) ($campaign['id'] ?? 0));
            exit;
        }
    }

    public function runRecipients(int $runId): void
    {
        $run = $this->runs->findInTenantScopeForStaff($runId);
        if (!$run) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(404);
            return;
        }
        $campaign = $this->campaigns->findInTenantScopeForStaff((int) ($run['campaign_id'] ?? 0));
        if (!$campaign || !$this->ensureBranchAccess($campaign)) {
            return;
        }
        $recipients = $this->recipients->listByRunIdInTenantScopeForStaff($runId, 500);
        $flash = flash();
        $title = 'Campaign run recipients';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        require base_path('modules/marketing/views/campaigns/run-recipients.php');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCampaignPost(): array
    {
        $dormant = isset($_POST['dormant_days']) ? (int) $_POST['dormant_days'] : 90;
        $lookahead = isset($_POST['lookahead_days']) ? (int) $_POST['lookahead_days'] : 14;
        $recent = isset($_POST['recent_days']) ? (int) $_POST['recent_days'] : 30;
        $segmentKey = isset($_POST['segment_key']) ? trim((string) $_POST['segment_key']) : '';

        return [
            'name' => isset($_POST['name']) ? trim((string) $_POST['name']) : '',
            'branch_id' => isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int) $_POST['branch_id'] : null,
            'segment_key' => $segmentKey,
            'segment_config' => self::segmentConfigForKey($segmentKey, $dormant, $lookahead, $recent),
            'subject' => isset($_POST['subject']) ? trim((string) $_POST['subject']) : '',
            'body_text' => isset($_POST['body_text']) ? trim((string) $_POST['body_text']) : '',
            'status' => isset($_POST['status']) ? trim((string) $_POST['status']) : 'draft',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function validateCampaignForm(array $data, bool $requireAll = true): array
    {
        $errors = [];
        if ($requireAll || isset($_POST['name'])) {
            if (trim((string) ($data['name'] ?? '')) === '') {
                $errors['name'] = 'Name is required.';
            }
        }
        if (!MarketingSegmentEvaluator::isAllowedSegmentKey((string) ($data['segment_key'] ?? ''))) {
            $errors['segment_key'] = 'Invalid segment.';
        }
        if ($requireAll || isset($_POST['subject'])) {
            if (trim((string) ($data['subject'] ?? '')) === '') {
                $errors['subject'] = 'Subject is required.';
            }
        }
        if ($requireAll || isset($_POST['body_text'])) {
            if (trim((string) ($data['body_text'] ?? '')) === '') {
                $errors['body_text'] = 'Body is required.';
            }
        }
        $st = (string) ($data['status'] ?? 'draft');
        if (!in_array($st, MarketingCampaignService::CAMPAIGN_STATUSES, true)) {
            $errors['status'] = 'Invalid status.';
        }

        return $errors;
    }

    /**
     * @return array<string, int>
     */
    private static function segmentConfigForKey(string $key, int $dormant, int $lookahead, int $recent): array
    {
        return match ($key) {
            MarketingSegmentEvaluator::SEGMENT_DORMANT_NO_RECENT_COMPLETED => ['dormant_days' => max(1, min(3650, $dormant))],
            MarketingSegmentEvaluator::SEGMENT_BIRTHDAY_UPCOMING => ['lookahead_days' => max(1, min(366, $lookahead))],
            MarketingSegmentEvaluator::SEGMENT_WAITLIST_ENGAGED_RECENT => ['recent_days' => max(1, min(3650, $recent))],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $campaign
     * @return array<string, int>
     */
    private function decodeConfigFromCampaign(array $campaign): array
    {
        $raw = $campaign['segment_config_json'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }
        $s = is_string($raw) ? $raw : (string) $raw;
        $d = json_decode($s, true);

        return is_array($d) ? $d : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getBranches(): array
    {
        return $this->branchDirectory->getActiveBranchesForSelection();
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function ensureBranchAccess(array $entity): bool
    {
        try {
            $branchId = isset($entity['branch_id']) && $entity['branch_id'] !== '' && $entity['branch_id'] !== null
                ? (int) $entity['branch_id']
                : null;
            Application::container()->get(\Core\Branch\BranchContext::class)->assertBranchMatchOrGlobalEntity($branchId);
            return true;
        } catch (\DomainException $e) {
            Application::container()->get(\Core\Errors\HttpErrorHandler::class)->handle(403);
            return false;
        }
    }
}
