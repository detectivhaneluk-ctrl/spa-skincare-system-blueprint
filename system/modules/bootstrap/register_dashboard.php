<?php

declare(strict_types=1);

$container->singleton(\Modules\Dashboard\Repositories\DashboardReadRepository::class, fn ($c) => new \Modules\Dashboard\Repositories\DashboardReadRepository($c->get(\Core\App\Database::class), $c->get(\Modules\Sales\Services\SalesTenantScope::class)));
$container->singleton(\Modules\Dashboard\Services\DashboardSnapshotService::class, fn ($c) => new \Modules\Dashboard\Services\DashboardSnapshotService($c->get(\Modules\Dashboard\Repositories\DashboardReadRepository::class), $c->get(\Modules\Notifications\Repositories\NotificationRepository::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\Dashboard\Services\DashboardShellSummaryService::class, fn ($c) => new \Modules\Dashboard\Services\DashboardShellSummaryService($c->get(\Modules\Clients\Repositories\ClientRepository::class), $c->get(\Modules\Staff\Repositories\StaffRepository::class), $c->get(\Modules\ServicesResources\Repositories\ServiceRepository::class), $c->get(\Modules\Dashboard\Repositories\DashboardReadRepository::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Auth\SessionAuth::class)));
$container->singleton(\Modules\Dashboard\Services\TenantOperatorDashboardService::class, fn ($c) => new \Modules\Dashboard\Services\TenantOperatorDashboardService($c->get(\Modules\Dashboard\Services\DashboardSnapshotService::class), $c->get(\Modules\Dashboard\Services\DashboardShellSummaryService::class), $c->get(\Modules\Dashboard\Repositories\DashboardReadRepository::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Branch\BranchDirectory::class)));
$container->singleton(\Modules\Dashboard\Controllers\DashboardController::class, fn ($c) => new \Modules\Dashboard\Controllers\DashboardController($c->get(\Core\Auth\AuthService::class), $c->get(\Core\Auth\SessionAuth::class), $c->get(\Modules\Dashboard\Services\TenantOperatorDashboardService::class)));

