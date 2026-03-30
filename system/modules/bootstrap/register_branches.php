<?php

declare(strict_types=1);

$container->singleton(\Modules\Branches\Controllers\BranchAdminController::class, fn ($c) => new \Modules\Branches\Controllers\BranchAdminController($c->get(\Core\Branch\BranchDirectory::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\Permissions\PermissionService::class)));

