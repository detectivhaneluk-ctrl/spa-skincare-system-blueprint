<?php

declare(strict_types=1);

$container->singleton(\Modules\Marketing\Repositories\MarketingCampaignRepository::class, fn ($c) => new \Modules\Marketing\Repositories\MarketingCampaignRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Marketing\Repositories\MarketingCampaignRunRepository::class, fn ($c) => new \Modules\Marketing\Repositories\MarketingCampaignRunRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Marketing\Repositories\MarketingCampaignRecipientRepository::class, fn ($c) => new \Modules\Marketing\Repositories\MarketingCampaignRecipientRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Marketing\Repositories\MarketingAutomationRepository::class, fn ($c) => new \Modules\Marketing\Repositories\MarketingAutomationRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Marketing\Repositories\MarketingAutomationExecutionRepository::class, fn ($c) => new \Modules\Marketing\Repositories\MarketingAutomationExecutionRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Marketing\Repositories\MarketingContactAudienceRepository::class, fn ($c) => new \Modules\Marketing\Repositories\MarketingContactAudienceRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Marketing\Repositories\MarketingContactListRepository::class, fn ($c) => new \Modules\Marketing\Repositories\MarketingContactListRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Marketing\Repositories\MarketingGiftCardTemplateRepository::class, fn ($c) => new \Modules\Marketing\Repositories\MarketingGiftCardTemplateRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Marketing\Repositories\MarketingSpecialOfferRepository::class, fn ($c) => new \Modules\Marketing\Repositories\MarketingSpecialOfferRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Marketing\Services\MarketingSegmentEvaluator::class, fn ($c) => new \Modules\Marketing\Services\MarketingSegmentEvaluator($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Marketing\Services\MarketingAutomationService::class, fn ($c) => new \Modules\Marketing\Services\MarketingAutomationService($c->get(\Modules\Marketing\Repositories\MarketingAutomationRepository::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Branch\BranchDirectory::class)));
$container->singleton(\Modules\Marketing\Services\MarketingAutomationExecutionService::class, fn ($c) => new \Modules\Marketing\Services\MarketingAutomationExecutionService($c->get(\Modules\Marketing\Services\MarketingAutomationService::class), $c->get(\Modules\Marketing\Repositories\MarketingAutomationExecutionRepository::class), $c->get(\Modules\Marketing\Services\MarketingSegmentEvaluator::class), $c->get(\Modules\Notifications\Services\OutboundMarketingEnqueueService::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Branch\BranchDirectory::class)));
$container->singleton(\Modules\Marketing\Services\MarketingCampaignService::class, fn ($c) => new \Modules\Marketing\Services\MarketingCampaignService($c->get(\Core\App\Database::class), $c->get(\Modules\Marketing\Repositories\MarketingCampaignRepository::class), $c->get(\Modules\Marketing\Repositories\MarketingCampaignRunRepository::class), $c->get(\Modules\Marketing\Repositories\MarketingCampaignRecipientRepository::class), $c->get(\Modules\Marketing\Services\MarketingSegmentEvaluator::class), $c->get(\Modules\Notifications\Services\OutboundMarketingEnqueueService::class), $c->get(\Core\Branch\BranchContext::class), $c->get(\Core\Organization\OrganizationContext::class), $c->get(\Core\Organization\OrganizationScopedBranchAssert::class), $c->get(\Core\Audit\AuditService::class), $c->get(\Core\Auth\AuthService::class)));
$container->singleton(\Modules\Marketing\Services\MarketingContactAudienceService::class, fn ($c) => new \Modules\Marketing\Services\MarketingContactAudienceService($c->get(\Modules\Marketing\Repositories\MarketingContactAudienceRepository::class)));
$container->singleton(\Modules\Marketing\Services\MarketingContactListService::class, fn ($c) => new \Modules\Marketing\Services\MarketingContactListService($c->get(\Core\App\Database::class), $c->get(\Modules\Marketing\Repositories\MarketingContactListRepository::class)));
$container->singleton(\Modules\Marketing\Services\MarketingSpecialOfferService::class, fn ($c) => new \Modules\Marketing\Services\MarketingSpecialOfferService(
    $c->get(\Modules\Marketing\Repositories\MarketingSpecialOfferRepository::class),
    $c->get(\Core\Branch\BranchContext::class),
    $c->get(\Core\Auth\AuthService::class)
));
$container->singleton(\Modules\Marketing\Services\MarketingGiftCardTemplateService::class, fn ($c) => new \Modules\Marketing\Services\MarketingGiftCardTemplateService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Marketing\Repositories\MarketingGiftCardTemplateRepository::class),
    $c->get(\Modules\Media\Services\MediaAssetUploadService::class),
    $c->get(\Core\Kernel\RequestContextHolder::class),
    $c->get(\Modules\Media\Services\MediaImageLibraryStatusPayloadBuilder::class),
    $c->get(\Core\Storage\Contracts\StorageProviderInterface::class)
));
$container->singleton(\Modules\Clients\Repositories\ClientProfileImageRepository::class, fn ($c) => new \Modules\Clients\Repositories\ClientProfileImageRepository(
    $c->get(\Core\App\Database::class),
    $c->get(\Core\Organization\OrganizationRepositoryScope::class)
));
$container->singleton(\Modules\Clients\Services\ClientProfileImageService::class, fn ($c) => new \Modules\Clients\Services\ClientProfileImageService(
    $c->get(\Core\App\Database::class),
    $c->get(\Modules\Clients\Repositories\ClientProfileImageRepository::class),
    $c->get(\Modules\Media\Services\MediaAssetUploadService::class),
    $c->get(\Core\Kernel\RequestContextHolder::class),
    $c->get(\Modules\Marketing\Services\MarketingGiftCardTemplateService::class),
    $c->get(\Modules\Media\Services\MediaImageLibraryStatusPayloadBuilder::class)
));
$container->singleton(\Modules\Marketing\Controllers\MarketingAutomationController::class, fn ($c) => new \Modules\Marketing\Controllers\MarketingAutomationController($c->get(\Modules\Marketing\Services\MarketingAutomationService::class), $c->get(\Core\App\SettingsService::class), $c->get(\Core\Auth\AuthService::class), $c->get(\Core\Permissions\PermissionService::class)));
$container->singleton(\Modules\Marketing\Controllers\MarketingCampaignController::class, fn ($c) => new \Modules\Marketing\Controllers\MarketingCampaignController($c->get(\Modules\Marketing\Repositories\MarketingCampaignRepository::class), $c->get(\Modules\Marketing\Repositories\MarketingCampaignRunRepository::class), $c->get(\Modules\Marketing\Repositories\MarketingCampaignRecipientRepository::class), $c->get(\Modules\Marketing\Services\MarketingCampaignService::class), $c->get(\Core\Branch\BranchDirectory::class), $c->get(\Core\Auth\AuthService::class), $c->get(\Core\Permissions\PermissionService::class)));
$container->singleton(\Modules\Marketing\Controllers\MarketingContactListsController::class, fn ($c) => new \Modules\Marketing\Controllers\MarketingContactListsController($c->get(\Modules\Marketing\Services\MarketingContactAudienceService::class), $c->get(\Modules\Marketing\Services\MarketingContactListService::class), $c->get(\Core\Auth\AuthService::class)));
$container->singleton(\Modules\Marketing\Controllers\MarketingPromotionsController::class, fn ($c) => new \Modules\Marketing\Controllers\MarketingPromotionsController($c->get(\Modules\Marketing\Services\MarketingSpecialOfferService::class)));
$container->singleton(\Modules\Marketing\Controllers\MarketingGiftCardTemplatesController::class, fn ($c) => new \Modules\Marketing\Controllers\MarketingGiftCardTemplatesController($c->get(\Modules\Marketing\Services\MarketingGiftCardTemplateService::class), $c->get(\Core\Auth\AuthService::class)));

