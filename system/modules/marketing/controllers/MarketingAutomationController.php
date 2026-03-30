<?php

declare(strict_types=1);

namespace Modules\Marketing\Controllers;

use Core\App\Application;
use Core\App\SettingsService;
use Core\Auth\AuthService;
use Core\Permissions\PermissionService;
use Modules\Marketing\Services\MarketingAutomationService;

final class MarketingAutomationController
{
    public function __construct(
        private MarketingAutomationService $service,
        private SettingsService $settings,
        private AuthService $auth,
        private PermissionService $permissions,
    ) {
    }

    public function index(): void
    {
        $branchId = $this->service->currentBranchId();
        $storageReady = $this->service->isStorageReady();
        $storageNotice = null;
        $rows = [];
        if ($storageReady) {
            $rows = $this->service->effectiveByBranch($branchId);
        } else {
            $storageNotice = MarketingAutomationService::EXCEPTION_STORAGE_NOT_READY;
        }
        $schedulerAcknowledged = $storageReady
            && $this->settings->getMarketingAutomationsSchedulerAcknowledged($branchId);
        $anyAutomationEnabled = false;
        foreach ($rows as $r) {
            if (!empty($r['enabled'])) {
                $anyAutomationEnabled = true;
                break;
            }
        }
        $flash = flash();
        $title = 'Automated emails';
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();
        $user = $this->auth->user();
        $userId = isset($user['id']) ? (int) $user['id'] : null;
        $canManageMarketing = $userId !== null && $this->permissions->has($userId, 'marketing.manage');
        require base_path('modules/marketing/views/automations/index.php');
    }

    public function saveSchedulerAcknowledgment(): void
    {
        try {
            $branchId = $this->service->currentBranchId();
            $ack = isset($_POST['scheduler_acknowledged']) && (string) $_POST['scheduler_acknowledged'] === '1';
            $this->settings->setMarketingAutomationsSchedulerAcknowledged($ack, $branchId);
            flash('success', $ack ? 'Scheduler acknowledgment saved for this branch.' : 'Scheduler acknowledgment cleared for this branch.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        header('Location: /marketing/automations');
        exit;
    }

    public function saveSettings(string $key): void
    {
        try {
            if (!$this->service->isStorageReady()) {
                throw new \DomainException(MarketingAutomationService::EXCEPTION_STORAGE_NOT_READY);
            }
            $branchId = $this->service->currentBranchId();
            $catalog = MarketingAutomationService::catalog();
            if (!isset($catalog[$key])) {
                throw new \InvalidArgumentException('Unknown automation key.');
            }
            $fields = array_keys((array) ($catalog[$key]['defaults'] ?? []));
            $config = [];
            foreach ($fields as $field) {
                if (!array_key_exists($field, $_POST)) {
                    throw new \InvalidArgumentException(sprintf('%s is required.', $field));
                }
                $config[$field] = $_POST[$field];
            }

            $enabled = isset($_POST['enabled']) && (string) $_POST['enabled'] === '1';
            $this->service->upsertSettings($branchId, $key, $config, $enabled);
            flash('success', 'Automation settings saved.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        header('Location: /marketing/automations');
        exit;
    }

    public function toggle(string $key): void
    {
        try {
            if (!$this->service->isStorageReady()) {
                throw new \DomainException(MarketingAutomationService::EXCEPTION_STORAGE_NOT_READY);
            }
            $branchId = $this->service->currentBranchId();
            $enabled = $this->service->toggle($branchId, $key);
            flash('success', $enabled ? 'Automation enabled.' : 'Automation disabled.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }

        header('Location: /marketing/automations');
        exit;
    }
}
