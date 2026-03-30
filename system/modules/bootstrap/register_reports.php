<?php

declare(strict_types=1);

$container->singleton(\Modules\Reports\Repositories\ReportRepository::class, fn ($c) => new \Modules\Reports\Repositories\ReportRepository($c->get(\Core\App\Database::class), $c->get(\Modules\Sales\Services\SalesTenantScope::class)));
$container->singleton(\Modules\Reports\Services\ReportService::class, fn ($c) => new \Modules\Reports\Services\ReportService($c->get(\Modules\Reports\Repositories\ReportRepository::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\Reports\Controllers\ReportController::class, fn ($c) => new \Modules\Reports\Controllers\ReportController($c->get(\Modules\Reports\Services\ReportService::class)));

