<?php

declare(strict_types=1);

namespace Modules\Auth\Controllers;

use Core\App\Application;
use Core\Branch\BranchContext;
use Core\Branch\BranchDirectory;
use Core\Organization\OrganizationLifecycleGate;
use Modules\Auth\Services\TenantEntryResolverService;

final class TenantEntryController
{
    private const SESSION_KEY = 'branch_id';

    public function resolve(): void
    {
        $auth = Application::container()->get(\Core\Auth\AuthService::class);
        $user = $auth->user();
        if (!$user) {
            header('Location: /login');
            exit;
        }

        $userId = (int) ($user['id'] ?? 0);
        $lifecycleGate = Application::container()->get(OrganizationLifecycleGate::class);
        if ($lifecycleGate->isTenantUserBoundToSuspendedOrganization($userId)) {
            unset($_SESSION[self::SESSION_KEY]);
            Application::container()->get(BranchContext::class)->setCurrentBranchId(null);
            require base_path('modules/auth/views/tenant-suspended.php');
            return;
        }
        $resolver = Application::container()->get(TenantEntryResolverService::class);
        $decision = $resolver->resolveForUser($userId);
        $csrf = Application::container()->get(\Core\Auth\SessionAuth::class)->csrfToken();

        if ($decision['state'] === 'single') {
            $_SESSION[self::SESSION_KEY] = (int) $decision['branch_id'];
            header('Location: /dashboard');
            exit;
        }

        $title = 'Select Branch';
        $hideNav = true;
        if ($decision['state'] === 'multiple') {
            $branchIds = $decision['branch_ids'];
            $allBranches = Application::container()->get(BranchDirectory::class)->listAllActiveBranchesUnscopedForTenantEntryResolver();
            $branches = array_values(array_filter($allBranches, static function (array $row) use ($branchIds): bool {
                $id = isset($row['id']) ? (int) $row['id'] : 0;

                return $id > 0 && in_array($id, $branchIds, true);
            }));
            require base_path('modules/auth/views/tenant-entry-chooser.php');
            return;
        }

        unset($_SESSION[self::SESSION_KEY]);
        Application::container()->get(BranchContext::class)->setCurrentBranchId(null);
        require base_path('modules/auth/views/tenant-entry-blocked.php');
    }
}
