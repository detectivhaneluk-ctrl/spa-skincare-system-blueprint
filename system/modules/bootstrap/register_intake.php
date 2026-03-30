<?php

declare(strict_types=1);

$container->singleton(\Modules\Intake\Repositories\IntakeFormTemplateRepository::class, fn ($c) => new \Modules\Intake\Repositories\IntakeFormTemplateRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Intake\Repositories\IntakeFormTemplateFieldRepository::class, fn ($c) => new \Modules\Intake\Repositories\IntakeFormTemplateFieldRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Intake\Repositories\IntakeFormAssignmentRepository::class, fn ($c) => new \Modules\Intake\Repositories\IntakeFormAssignmentRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Intake\Repositories\IntakeFormSubmissionRepository::class, fn ($c) => new \Modules\Intake\Repositories\IntakeFormSubmissionRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Intake\Repositories\IntakeFormSubmissionValueRepository::class, fn ($c) => new \Modules\Intake\Repositories\IntakeFormSubmissionValueRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Intake\Services\IntakeFormService::class, fn ($c) => new \Modules\Intake\Services\IntakeFormService(
    $c->get(\Modules\Intake\Repositories\IntakeFormTemplateRepository::class),
    $c->get(\Modules\Intake\Repositories\IntakeFormTemplateFieldRepository::class),
    $c->get(\Modules\Intake\Repositories\IntakeFormAssignmentRepository::class),
    $c->get(\Modules\Intake\Repositories\IntakeFormSubmissionRepository::class),
    $c->get(\Modules\Intake\Repositories\IntakeFormSubmissionValueRepository::class),
    $c->get(\Modules\Clients\Repositories\ClientRepository::class),
    $c->get(\Modules\Appointments\Repositories\AppointmentRepository::class),
    $c->get(\Core\Branch\BranchContext::class),
    $c->get(\Core\Auth\SessionAuth::class),
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Audit\AuditService::class),
    $c->get(\Core\App\SettingsService::class),
    $c->get(\Core\Organization\OrganizationRepositoryScope::class)
));
$container->singleton(\Modules\Intake\Controllers\IntakeAdminController::class, fn ($c) => new \Modules\Intake\Controllers\IntakeAdminController($c->get(\Modules\Intake\Services\IntakeFormService::class), $c->get(\Core\Branch\BranchContext::class)));
$container->singleton(\Modules\Intake\Controllers\IntakePublicController::class, fn ($c) => new \Modules\Intake\Controllers\IntakePublicController($c->get(\Modules\Intake\Services\IntakeFormService::class), $c->get(\Modules\OnlineBooking\Services\PublicBookingAbuseGuardService::class)));

